<?php

namespace App\Services\Payments;

use App\Models\OnlineOrder;
use App\Models\OnlinePayment;
use App\Services\Integrations;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SSLCommerz hosted checkout (API v4): the customer pays on SSLCommerz's page
 * (cards, bKash, Nagad, Rocket, net banking). A payment counts only after the
 * validation API confirms it server to server; the browser's word is never
 * enough. Sandbox and live use different hosts.
 */
class SslCommerz
{
    public function __construct(private Integrations $integrations) {}

    private function config(): array
    {
        $c = $this->integrations->config('sslcommerz');
        if (! $c['storeId'] || ! $c['storePassword']) {
            throw new RuntimeException('SSLCommerz store ID and password are not set.');
        }

        return $c + ['base' => $c['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com'];
    }

    /** @return array{url: string, id: ?string, payload: array} */
    public function init(OnlinePayment $p, OnlineOrder $o, array $urls): array
    {
        $c = $this->config();
        $o->loadMissing('customer', 'lines');
        $res = Http::asForm()->timeout(20)->post($c['base'].'/gwprocess/v4/api.php', [
            'store_id' => $c['storeId'], 'store_passwd' => $c['storePassword'],
            'total_amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'tran_id' => $p->reference,
            'success_url' => $urls['success'], 'fail_url' => $urls['fail'], 'cancel_url' => $urls['cancel'], 'ipn_url' => $urls['ipn'],
            'cus_name' => $o->customer?->name ?: 'Customer', 'cus_email' => $o->customer?->email ?: 'customer@example.com',
            'cus_add1' => mb_substr((string) $o->address, 0, 200), 'cus_city' => $o->district ?: 'Dhaka', 'cus_country' => 'Bangladesh',
            'cus_phone' => $o->phone, 'shipping_method' => 'NO', 'num_of_item' => $o->lines->count(),
            'product_name' => mb_substr($o->lines->pluck('description')->join(', ') ?: 'Medicines', 0, 250),
            'product_category' => 'Medicine', 'product_profile' => 'general', 'value_a' => $o->number,
        ]);
        $body = (array) $res->json();
        if (! $res->successful() || ($body['status'] ?? '') !== 'SUCCESS' || empty($body['GatewayPageURL'])) {
            throw new RuntimeException('SSLCommerz refused the payment: '.($body['failedreason'] ?? 'HTTP '.$res->status()));
        }

        return ['url' => $body['GatewayPageURL'], 'id' => $body['sessionkey'] ?? null, 'payload' => array_intersect_key($body, array_flip(['status', 'sessionkey']))];
    }

    /** Server-to-server check of a payment SSLCommerz reported (success page or IPN). */
    public function validate(string $valId): array
    {
        $c = $this->config();
        $res = Http::timeout(20)->get($c['base'].'/validator/api/validationserverAPI.php', [
            'val_id' => $valId, 'store_id' => $c['storeId'], 'store_passwd' => $c['storePassword'], 'v' => 1, 'format' => 'json',
        ]);

        return (array) $res->json();
    }
}
