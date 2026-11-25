<?php

namespace App\Services;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends SMS through any HTTP gateway described in Administration › Integrations ›
 * SMS: method, URL, body type (form, JSON or query string), parameters and
 * headers. Values may use the placeholders
 *
 *   {to} {message} {sender} {api_key} {api_secret} {basic_auth}
 *
 * ({basic_auth} = base64 of "api_key:api_secret", for providers like Twilio).
 * A send counts as accepted on HTTP 2xx and, when set, when the response body
 * contains `successContains`. Every attempt is logged in `sms_messages`.
 */
class SmsGateway
{
    /** Starting points for common providers; check the provider's own documentation. */
    public const PRESETS = [
        'bulksmsbd' => [
            'label' => 'BulkSMSBD', 'method' => 'GET', 'url' => 'http://bulksmsbd.net/api/smsapi', 'bodyType' => 'query', 'phoneFormat' => '8801',
            'params' => [['key' => 'api_key', 'value' => '{api_key}'], ['key' => 'type', 'value' => 'text'], ['key' => 'number', 'value' => '{to}'], ['key' => 'senderid', 'value' => '{sender}'], ['key' => 'message', 'value' => '{message}']],
            'headers' => [], 'successContains' => '202',
        ],
        'smsnetbd' => [
            'label' => 'Alpha SMS (sms.net.bd)', 'method' => 'POST', 'url' => 'https://api.sms.net.bd/sendsms', 'bodyType' => 'form', 'phoneFormat' => '8801',
            'params' => [['key' => 'api_key', 'value' => '{api_key}'], ['key' => 'msg', 'value' => '{message}'], ['key' => 'to', 'value' => '{to}'], ['key' => 'sender_id', 'value' => '{sender}']],
            'headers' => [], 'successContains' => '"error":0',
        ],
        'revesms' => [
            'label' => 'REVE SMS', 'method' => 'GET', 'url' => 'https://smpp.revesms.com:7790/sendtext', 'bodyType' => 'query', 'phoneFormat' => '8801',
            'params' => [['key' => 'apikey', 'value' => '{api_key}'], ['key' => 'secretkey', 'value' => '{api_secret}'], ['key' => 'callerID', 'value' => '{sender}'], ['key' => 'toUser', 'value' => '{to}'], ['key' => 'messageContent', 'value' => '{message}']],
            'headers' => [], 'successContains' => '',
        ],
        'twilio' => [
            'label' => 'Twilio', 'method' => 'POST', 'url' => 'https://api.twilio.com/2010-04-01/Accounts/{api_key}/Messages.json', 'bodyType' => 'form', 'phoneFormat' => '+8801',
            'params' => [['key' => 'To', 'value' => '{to}'], ['key' => 'From', 'value' => '{sender}'], ['key' => 'Body', 'value' => '{message}']],
            'headers' => [['key' => 'Authorization', 'value' => 'Basic {basic_auth}']], 'successContains' => '',
        ],
    ];

    public function __construct(private Integrations $integrations) {}

    public function enabled(): bool
    {
        return $this->integrations->enabled('sms');
    }

    /** Sends one SMS (never throws); the returned log row says whether the gateway accepted it. */
    public function send(string $to, string $message, string $purpose, ?int $userId = null, bool $force = false): SmsMessage
    {
        $c = $this->integrations->config('sms');
        $phone = CustomerController::normalizePhone($to);
        $log = new SmsMessage(['to' => $phone ?? mb_substr($to, 0, 20), 'message' => $message, 'purpose' => $purpose, 'provider' => $c['provider'], 'user_id' => $userId, 'created_at' => now()]);

        $fail = function (string $error, ?int $status = null, ?string $body = null) use ($log) {
            $log->fill(['status' => 'failed', 'error' => mb_substr($error, 0, 255), 'http_status' => $status, 'response' => $body !== null ? mb_substr($body, 0, 1000) : null])->save();

            return $log;
        };
        if (! $c['enabled'] && ! $force) {
            return $fail('SMS is switched off in Administration › Integrations.');
        }
        if (! $phone) {
            return $fail('Not a valid Bangladeshi mobile number.');
        }
        if (! $c['url']) {
            return $fail('No gateway URL is set.');
        }

        $vars = [
            '{to}' => match ($c['phoneFormat']) {
                '01' => $phone, '+8801' => '+88'.$phone, default => '88'.$phone
            },
            '{message}' => $message, '{sender}' => (string) $c['senderId'],
            '{api_key}' => (string) $c['apiKey'], '{api_secret}' => (string) $c['apiSecret'],
            '{basic_auth}' => base64_encode($c['apiKey'].':'.$c['apiSecret']),
        ];
        $fill = fn (?string $v) => strtr((string) $v, $vars);
        $params = collect($c['params'] ?? [])->filter(fn ($p) => ($p['key'] ?? '') !== '')->mapWithKeys(fn ($p) => [$p['key'] => $fill($p['value'] ?? '')])->all();
        $headers = collect($c['headers'] ?? [])->filter(fn ($h) => ($h['key'] ?? '') !== '')->mapWithKeys(fn ($h) => [$h['key'] => $fill($h['value'] ?? '')])->all();
        // Placeholders in the URL itself (e.g. Twilio's account id) are URL-encoded.
        $url = strtr($c['url'], array_map('rawurlencode', $vars));

        try {
            $http = Http::timeout(15)->withHeaders($headers)->acceptJson();
            $res = match (true) {
                $c['method'] === 'GET' || $c['bodyType'] === 'query' => $c['method'] === 'GET' ? $http->get($url, $params) : $http->withQueryParameters($params)->post($url),
                $c['bodyType'] === 'json' => $http->asJson()->post($url, $params),
                default => $http->asForm()->post($url, $params),
            };
        } catch (Throwable $e) {
            return $fail('Could not reach the gateway: '.$e->getMessage());
        }
        $body = (string) $res->body();
        $ok = $res->successful() && ($c['successContains'] === '' || str_contains($body, $c['successContains']));
        if (! $ok) {
            return $fail($res->successful() ? 'The gateway answered without "'.$c['successContains'].'".' : 'The gateway answered HTTP '.$res->status().'.', $res->status(), $body);
        }
        $log->fill(['status' => 'sent', 'http_status' => $res->status(), 'response' => mb_substr($body, 0, 1000)])->save();

        return $log;
    }
}
