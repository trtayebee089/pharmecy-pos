<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Integrations;
use App\Services\MailSettings;
use App\Services\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administration › Integrations: email, SMS, tracking, product feed, payment
 * gateways, couriers and website details. Needs `settings.manage`. Secrets are
 * write-only (see Integrations).
 */
class IntegrationController extends Controller
{
    public function __construct(private Integrations $integrations) {}

    public function index(): JsonResponse
    {
        return response()->json(collect(array_keys(Integrations::GROUPS))
            ->mapWithKeys(fn ($g) => [$g => $this->integrations->public($g)])
            ->put('smsPresets', SmsGateway::PRESETS)
            ->put('urls', $this->urls())->all());
    }

    public function save(Request $request, string $group): JsonResponse
    {
        abort_unless(Integrations::has($group), 404);
        $rules = $this->rules($group) + ['clear' => ['nullable', 'array'], 'clear.*' => ['string']];
        $data = $request->validate($rules);
        $saved = $this->integrations->save($group, $data, $data['clear'] ?? []);
        // Secrets never reach the audit log: only which ones were set.
        AuditLog::record($request->user()->id, 'settings.integration', 'settings', null, ['group' => $group] + $saved, $request);

        return response()->json($saved);
    }

    public function testMail(Request $request, MailSettings $mail): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:120']]);
        if (! $this->integrations->enabled('mail')) {
            throw ValidationException::withMessages(['to' => 'Turn email on and save the settings first.']);
        }
        try {
            $mail->apply(force: true);
            Mail::raw("This is a test email from Red Pharma.\n\nIf you can read this, the email settings work.", function ($m) use ($data) {
                $m->to($data['to'])->subject('Red Pharma test email');
            });
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Sending failed: '.$this->short($e->getMessage())], 422);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$data['to']}. Check the inbox (and spam)."]);
    }

    public function testSms(Request $request, SmsGateway $sms): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:20'], 'message' => ['nullable', 'string', 'max:300']]);
        $log = $sms->send($data['to'], ($data['message'] ?? null) ?: 'Test SMS from Red Pharma.', 'test', $request->user()->id, force: true);

        return response()->json(['ok' => $log->status === 'sent', 'message' => $log->status === 'sent' ? 'Gateway accepted the SMS.' : 'Sending failed: '.$log->error, 'response' => $log->response], $log->status === 'sent' ? 200 : 422);
    }

    private function rules(string $group): array
    {
        $secret = ['nullable', 'string', 'max:2000'];

        return match ($group) {
            'mail' => [
                'enabled' => ['required', 'boolean'], 'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'], 'encryption' => ['required', 'in:none,tls,ssl'],
                'username' => ['nullable', 'string', 'max:190'], 'password' => $secret,
                'fromAddress' => ['required_if:enabled,true', 'nullable', 'email', 'max:190'], 'fromName' => ['nullable', 'string', 'max:100'],
            ],
            'sms' => [
                'enabled' => ['required', 'boolean'], 'provider' => ['required', 'string', 'max:40'], 'method' => ['required', 'in:GET,POST'],
                'url' => ['required_if:enabled,true', 'nullable', 'url', 'max:500'], 'bodyType' => ['required', 'in:form,json,query'],
                'params' => ['nullable', 'array', 'max:20'], 'params.*.key' => ['required', 'string', 'max:60'], 'params.*.value' => ['nullable', 'string', 'max:500'],
                'headers' => ['nullable', 'array', 'max:10'], 'headers.*.key' => ['required', 'string', 'max:60'], 'headers.*.value' => ['nullable', 'string', 'max:500'],
                'senderId' => ['nullable', 'string', 'max:40'], 'phoneFormat' => ['required', 'in:01,8801,+8801'],
                'successContains' => ['nullable', 'string', 'max:100'], 'sendOrderUpdates' => ['nullable', 'boolean'], 'sendAfterSale' => ['nullable', 'boolean'],
                'apiKey' => $secret, 'apiSecret' => $secret,
            ],
            'tracking' => [
                'gtmId' => ['nullable', 'regex:/^GTM-[A-Z0-9]+$/'], 'facebookPixelId' => ['nullable', 'regex:/^\d{5,20}$/'],
                'ga4MeasurementId' => ['nullable', 'regex:/^G-[A-Z0-9]+$/'], 'googleAdsId' => ['nullable', 'regex:/^AW-\d+$/'],
                'googleAdsPurchaseLabel' => ['nullable', 'string', 'max:60'], 'serverSideFacebook' => ['nullable', 'boolean'],
                'facebookTestEventCode' => ['nullable', 'string', 'max:40'], 'serverSideGa4' => ['nullable', 'boolean'],
                'facebookCapiToken' => $secret, 'ga4ApiSecret' => $secret,
            ],
            'feed' => [
                'enabled' => ['required', 'boolean'], 'siteUrl' => ['required_if:enabled,true', 'nullable', 'url', 'max:190'],
                'brandFallback' => ['nullable', 'string', 'max:60'], 'inStockOnly' => ['nullable', 'boolean'], 'includePrescription' => ['nullable', 'boolean'],
                'token' => ['nullable', 'string', 'min:12', 'max:100'],
            ],
            'sslcommerz' => [
                'enabled' => ['required', 'boolean'], 'sandbox' => ['required', 'boolean'], 'storeId' => ['required_if:enabled,true', 'nullable', 'string', 'max:100'],
                'label' => ['nullable', 'string', 'max:60'], 'storePassword' => $secret,
            ],
            'bkash' => [
                'enabled' => ['required', 'boolean'], 'sandbox' => ['required', 'boolean'], 'appKey' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'],
                'username' => ['required_if:enabled,true', 'nullable', 'string', 'max:100'], 'label' => ['nullable', 'string', 'max:60'],
                'appSecret' => $secret, 'password' => $secret,
            ],
            'steadfast' => [
                'enabled' => ['required', 'boolean'], 'apiKey' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'],
                'baseUrl' => ['required', 'url', 'max:190'], 'secretKey' => $secret, 'webhookToken' => $secret,
            ],
            'pathao' => [
                'enabled' => ['required', 'boolean'], 'sandbox' => ['required', 'boolean'], 'clientId' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'],
                'username' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'], 'storeId' => ['nullable', 'string', 'max:40'],
                'defaultItemType' => ['required', 'in:1,2'], 'defaultDeliveryType' => ['required', 'in:12,48'],
                'defaultWeightKg' => ['required', 'numeric', 'min:0.1', 'max:50'], 'clientSecret' => $secret, 'password' => $secret, 'webhookSecret' => $secret,
            ],
            'maps' => [
                'enabled' => ['required', 'boolean'], 'browserKey' => ['required_if:enabled,true', 'nullable', 'string', 'max:200'], 'serverKey' => $secret,
                'defaultLat' => ['required', 'numeric', 'between:-90,90'], 'defaultLng' => ['required', 'numeric', 'between:-180,180'],
                'averageSpeedKmh' => ['required', 'numeric', 'min:5', 'max:80'],
            ],
            'storefront' => [
                'siteName' => ['required', 'string', 'max:80'], 'websiteUrl' => ['nullable', 'url', 'max:190'], 'phone' => ['nullable', 'string', 'max:30'], 'whatsapp' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:120'], 'address' => ['nullable', 'string', 'max:255'],
                'facebookUrl' => ['nullable', 'url', 'max:255'], 'instagramUrl' => ['nullable', 'url', 'max:255'], 'youtubeUrl' => ['nullable', 'url', 'max:255'],
                'metaTitle' => ['nullable', 'string', 'max:70'], 'metaDescription' => ['nullable', 'string', 'max:170'],
                'announcement' => ['nullable', 'string', 'max:160'], 'codEnabled' => ['required', 'boolean'],
            ],
        };
    }

    /** Addresses the admin copies into the provider dashboards (webhooks, feeds). */
    private function urls(): array
    {
        $api = rtrim(url('/api'), '/');

        return [
            'productFeedXml' => $api.'/feeds/products.xml', 'productFeedCsv' => $api.'/feeds/products.csv',
            'steadfastWebhook' => $api.'/webhooks/steadfast', 'pathaoWebhook' => $api.'/webhooks/pathao',
            'sslcommerzIpn' => $api.'/v1/payments/sslcommerz/ipn',
        ];
    }

    private function short(string $m): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $m)), 0, 300);
    }
}
