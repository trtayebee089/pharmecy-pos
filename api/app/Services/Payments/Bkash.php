<?php

namespace App\Services\Payments;

use App\Models\OnlineOrder;
use App\Models\OnlinePayment;
use App\Services\Integrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * bKash Tokenized Checkout (v1.2.0-beta): grant a token, create a payment, send
 * the customer to bkashURL, and on the callback *execute* it server side. Only
 * an executed payment with transactionStatus "Completed" counts.
 */
class Bkash
{
    public function __construct(private Integrations $integrations) {}

    private function config(): array
    {
        $c = $this->integrations->config('bkash');
        if (! $c['appKey'] || ! $c['appSecret'] || ! $c['username'] || ! $c['password']) {
            throw new RuntimeException('bKash app key, secret, username and password are not all set.');
        }

        return $c + ['base' => $c['sandbox'] ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta' : 'https://tokenized.pay.bka.sh/v1.2.0-beta'];
    }

    /** id_token, cached for 50 minutes (bKash tokens last one hour). */
    private function token(array $c): string
    {
        return Cache::remember('bkash.token.'.md5($c['base'].$c['appKey']), 3000, function () use ($c) {
            $res = Http::timeout(20)->acceptJson()->withHeaders(['username' => $c['username'], 'password' => $c['password']])
                ->post($c['base'].'/tokenized/checkout/token/grant', ['app_key' => $c['appKey'], 'app_secret' => $c['appSecret']]);
            $token = $res->json('id_token');
            if (! $token) {
                throw new RuntimeException('bKash sign-in failed: '.($res->json('statusMessage') ?? $res->json('msg') ?? 'HTTP '.$res->status()));
            }

            return $token;
        });
    }

    private function call(string $path, array $body): array
    {
        $c = $this->config();
        $res = Http::timeout(30)->acceptJson()->withHeaders(['Authorization' => $this->token($c), 'X-APP-Key' => $c['appKey']])
            ->post($c['base'].$path, $body);

        return (array) $res->json();
    }

    /** @return array{url: string, id: string, payload: array} */
    public function create(OnlinePayment $p, OnlineOrder $o, string $callbackUrl): array
    {
        $body = $this->call('/tokenized/checkout/create', [
            'mode' => '0011', 'payerReference' => $o->phone, 'callbackURL' => $callbackUrl,
            'amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'intent' => 'sale',
            'merchantInvoiceNumber' => $p->reference,
        ]);
        if (($body['statusCode'] ?? '') !== '0000' || empty($body['bkashURL'])) {
            throw new RuntimeException('bKash refused the payment: '.($body['statusMessage'] ?? 'no payment link'));
        }

        return ['url' => $body['bkashURL'], 'id' => $body['paymentID'], 'payload' => array_intersect_key($body, array_flip(['paymentID', 'statusCode', 'statusMessage']))];
    }

    public function execute(string $paymentId): array
    {
        return $this->call('/tokenized/checkout/execute', ['paymentID' => $paymentId]);
    }

    public function status(string $paymentId): array
    {
        return $this->call('/tokenized/checkout/payment/status', ['paymentID' => $paymentId]);
    }
}
