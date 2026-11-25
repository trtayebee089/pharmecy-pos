<?php

namespace App\Services\Couriers;

use App\Models\OnlineOrder;
use App\Services\Integrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pathao Courier merchant API ("aladdin"): an access token from the merchant
 * login, Pathao's own city / zone / area ids, and orders with the amount to
 * collect. Sandbox and live use different hosts.
 */
class Pathao
{
    public function __construct(private Integrations $integrations) {}

    private function config(): array
    {
        $c = $this->integrations->config('pathao');
        if (! $c['clientId'] || ! $c['clientSecret'] || ! $c['username'] || ! $c['password']) {
            throw new RuntimeException('Pathao client id, secret, merchant email and password are not all set.');
        }

        return $c + ['base' => $c['sandbox'] ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com'];
    }

    private function token(array $c): string
    {
        $key = 'pathao.token.'.md5($c['base'].$c['clientId'].$c['username']);
        if ($t = Cache::get($key)) {
            return $t;
        }
        $res = Http::timeout(25)->acceptJson()->post($c['base'].'/aladdin/api/v1/issue-token', [
            'client_id' => $c['clientId'], 'client_secret' => $c['clientSecret'], 'grant_type' => 'password',
            'username' => $c['username'], 'password' => $c['password'],
        ]);
        $token = $res->json('access_token');
        if (! $token) {
            throw new RuntimeException('Pathao sign-in failed: '.($res->json('message') ?? 'HTTP '.$res->status()));
        }
        Cache::put($key, $token, max(60, (int) $res->json('expires_in', 3600) - 300));

        return $token;
    }

    private function get(string $path): array
    {
        $c = $this->config();

        return (array) Http::timeout(25)->acceptJson()->withToken($this->token($c))->get($c['base'].$path)->json();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function cities(): array
    {
        return Cache::remember('pathao.cities', 86400, fn () => collect($this->get('/aladdin/api/v1/city-list')['data']['data'] ?? [])
            ->map(fn ($c) => ['id' => (int) $c['city_id'], 'name' => $c['city_name']])->values()->all());
    }

    public function zones(int $cityId): array
    {
        return Cache::remember("pathao.zones.$cityId", 86400, fn () => collect($this->get("/aladdin/api/v1/cities/$cityId/zone-list")['data']['data'] ?? [])
            ->map(fn ($z) => ['id' => (int) $z['zone_id'], 'name' => $z['zone_name']])->values()->all());
    }

    public function areas(int $zoneId): array
    {
        return Cache::remember("pathao.areas.$zoneId", 86400, fn () => collect($this->get("/aladdin/api/v1/zones/$zoneId/area-list")['data']['data'] ?? [])
            ->map(fn ($a) => ['id' => (int) $a['area_id'], 'name' => $a['area_name'], 'home_delivery' => (bool) ($a['home_delivery_available'] ?? true)])->values()->all());
    }

    /** @return array{consignment_id: string, tracking_code: ?string, status: ?string, fee: ?float, payload: array} */
    public function create(OnlineOrder $o, float $cod, array $place, ?string $note, ?float $weightKg): array
    {
        $c = $this->config();
        if (! $c['storeId']) {
            throw new RuntimeException('Set the Pathao store ID in Administration › Integrations › Couriers.');
        }
        $o->loadMissing('customer', 'lines');
        $res = Http::timeout(25)->acceptJson()->withToken($this->token($c))->post($c['base'].'/aladdin/api/v1/orders', array_filter([
            'store_id' => (int) $c['storeId'], 'merchant_order_id' => $o->number,
            'recipient_name' => mb_substr($o->customer?->name ?: 'Customer', 0, 100), 'recipient_phone' => $o->phone,
            'recipient_address' => mb_substr(trim($o->address.', '.$o->thana.', '.$o->district, ', '), 0, 220),
            'recipient_city' => $place['city_id'] ?? null, 'recipient_zone' => $place['zone_id'] ?? null, 'recipient_area' => $place['area_id'] ?? null,
            'delivery_type' => (int) $c['defaultDeliveryType'], 'item_type' => (int) $c['defaultItemType'],
            'special_instruction' => $note ? mb_substr($note, 0, 250) : null, 'item_quantity' => max(1, $o->lines->count()),
            'item_weight' => (string) ($weightKg ?: $c['defaultWeightKg']), 'amount_to_collect' => (int) round($cod),
            'item_description' => mb_substr($o->lines->pluck('description')->join(', '), 0, 250),
        ], fn ($v) => $v !== null));
        $d = (array) $res->json('data');
        if (! $res->successful() || empty($d['consignment_id'])) {
            $errors = $res->json('errors');
            throw new RuntimeException('Pathao refused the parcel: '.(is_array($errors) ? collect($errors)->flatten()->implode(' ') : ($res->json('message') ?? 'HTTP '.$res->status())));
        }

        return ['consignment_id' => (string) $d['consignment_id'], 'tracking_code' => (string) $d['consignment_id'], 'status' => $d['order_status'] ?? null, 'fee' => isset($d['delivery_fee']) ? (float) $d['delivery_fee'] : null, 'payload' => $d];
    }

    public function status(string $consignmentId): ?string
    {
        return $this->get('/aladdin/api/v1/orders/'.rawurlencode($consignmentId).'/info')['data']['order_status'] ?? null;
    }

    public static function trackingUrl(?string $consignmentId, ?string $phone): ?string
    {
        return $consignmentId ? 'https://merchant.pathao.com/tracking?consignment_id='.rawurlencode($consignmentId).($phone ? '&phone='.rawurlencode($phone) : '') : null;
    }
}
