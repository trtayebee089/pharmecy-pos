<?php

namespace App\Services;

use App\Models\OnlineOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side purchase events, sent after the checkout response (so a slow ad
 * platform never delays the customer):
 *
 * - Facebook Conversions API: same `event_id` as the browser Pixel's Purchase,
 *   so Facebook counts it once. Phone, email and customer id are SHA-256 hashed.
 * - GA4 Measurement Protocol: `transaction_id` = order number, the GA client id
 *   from the `_ga` cookie when the website sends it.
 *
 * Switched on per platform in Administration › Integrations › Tracking.
 * Failures are logged, never shown to the customer.
 */
class ConversionTracker
{
    public const FB_GRAPH = 'https://graph.facebook.com/v21.0';

    public function __construct(private Integrations $integrations) {}

    /** @param  array{event_id?: ?string, fbp?: ?string, fbc?: ?string, ga_client_id?: ?string, page_url?: ?string, ip?: ?string, user_agent?: ?string}  $ctx */
    public function purchase(OnlineOrder $order, array $ctx): void
    {
        $t = $this->integrations->config('tracking');
        $order->loadMissing('lines', 'customer');
        if ($t['serverSideFacebook'] && $t['facebookPixelId'] && $t['facebookCapiToken']) {
            $this->facebook($order, $ctx, $t);
        }
        if ($t['serverSideGa4'] && $t['ga4MeasurementId'] && $t['ga4ApiSecret']) {
            $this->ga4($order, $ctx, $t);
        }
    }

    /** The event id the browser and the server share for one order. */
    public static function eventId(OnlineOrder $order, ?string $fromBrowser): string
    {
        return $fromBrowser && preg_match('/^[\w.-]{8,64}$/', $fromBrowser) ? $fromBrowser : 'purchase-'.$order->number;
    }

    private function facebook(OnlineOrder $order, array $ctx, array $t): void
    {
        $hash = fn (?string $v) => $v !== null && $v !== '' ? hash('sha256', mb_strtolower(trim($v))) : null;
        // Order phones are stored as 01XXXXXXXXX; Facebook wants the country code, digits only.
        $phone = $order->phone ? '88'.preg_replace('/\D/', '', $order->phone) : null;
        $user = array_filter([
            'ph' => $phone ? [$hash($phone)] : null, 'em' => $order->customer?->email ? [$hash($order->customer->email)] : null,
            'external_id' => [$hash((string) $order->customer_id)], 'country' => [$hash('bd')],
            'client_ip_address' => $ctx['ip'] ?? null, 'client_user_agent' => $ctx['user_agent'] ?? null,
            'fbp' => $ctx['fbp'] ?? null, 'fbc' => $ctx['fbc'] ?? null,
        ]);
        $event = array_filter([
            'event_name' => 'Purchase', 'event_time' => $order->placed_at?->timestamp ?? time(), 'event_id' => self::eventId($order, $ctx['event_id'] ?? null),
            'action_source' => $order->channel === 'website' ? 'website' : 'other', 'event_source_url' => $ctx['page_url'] ?? null,
            'user_data' => $user, 'custom_data' => $this->customData($order),
        ]);
        $body = array_filter(['data' => [$event], 'test_event_code' => $t['facebookTestEventCode'] ?: null]);
        $this->post('facebook', self::FB_GRAPH.'/'.$t['facebookPixelId'].'/events?access_token='.urlencode($t['facebookCapiToken']), $body, $order);
    }

    private function ga4(OnlineOrder $order, array $ctx, array $t): void
    {
        $clientId = $ctx['ga_client_id'] ?? null;
        $body = [
            // Without the browser's client id GA4 still records the sale under a stable per-customer id.
            'client_id' => $clientId && preg_match('/^\d+\.\d+$/', $clientId) ? $clientId : 'rp.'.$order->customer_id,
            'user_id' => (string) $order->customer_id,
            'events' => [[
                'name' => 'purchase',
                'params' => [
                    'transaction_id' => $order->number, 'currency' => 'BDT', 'value' => $this->value($order),
                    'shipping' => (float) $order->shipping, 'discount' => (float) $order->discount_total,
                    'items' => $order->lines->map(fn ($l) => ['item_id' => (string) $l->product_id, 'item_name' => $l->description, 'quantity' => (float) $l->qty, 'price' => (float) $l->unit_price])->values()->all(),
                ],
            ]],
        ];
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id='.urlencode($t['ga4MeasurementId']).'&api_secret='.urlencode($t['ga4ApiSecret']);
        $this->post('ga4', $url, $body, $order);
    }

    private function customData(OnlineOrder $order): array
    {
        return [
            'currency' => 'BDT', 'value' => $this->value($order), 'order_id' => $order->number, 'content_type' => 'product',
            'content_ids' => $order->lines->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
            'contents' => $order->lines->map(fn ($l) => ['id' => (string) $l->product_id, 'quantity' => (float) $l->qty, 'item_price' => (float) $l->unit_price])->values()->all(),
            'num_items' => (int) $order->lines->sum('qty'),
        ];
    }

    /** Goods after discount (shipping excluded), the usual purchase value for ads. */
    private function value(OnlineOrder $order): float
    {
        return round((float) $order->grand_total - (float) $order->shipping, 2);
    }

    private function post(string $platform, string $url, array $body, OnlineOrder $order): void
    {
        try {
            $res = Http::timeout(8)->asJson()->post($url, $body);
            if (! $res->successful()) {
                Log::warning("Server-side {$platform} purchase for {$order->number} was refused", ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
            }
        } catch (Throwable $e) {
            Log::warning("Server-side {$platform} purchase for {$order->number} failed: ".$e->getMessage());
        }
    }
}
