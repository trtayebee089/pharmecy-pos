<?php

namespace App\Services\Couriers;

use App\Models\OnlineOrder;
use App\Services\Integrations;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Steadfast Courier (packzy) merchant API: Api-Key + Secret-Key headers.
 * create_order books a parcel with the cash to collect; status_by_cid reads it.
 */
class Steadfast
{
    public function __construct(private Integrations $integrations) {}

    private function http(): array
    {
        $c = $this->integrations->config('steadfast');
        if (! $c['apiKey'] || ! $c['secretKey']) {
            throw new RuntimeException('Steadfast API key and secret key are not set.');
        }

        return [Http::timeout(25)->acceptJson()->asJson()->withHeaders(['Api-Key' => $c['apiKey'], 'Secret-Key' => $c['secretKey']]), rtrim($c['baseUrl'], '/')];
    }

    /** @return array{consignment_id: string, tracking_code: ?string, status: ?string, fee: ?float, payload: array} */
    public function create(OnlineOrder $o, float $cod, ?string $note): array
    {
        [$http, $base] = $this->http();
        $o->loadMissing('customer', 'lines');
        $res = $http->post($base.'/create_order', [
            'invoice' => $o->number, 'recipient_name' => mb_substr($o->customer?->name ?: 'Customer', 0, 100),
            'recipient_phone' => $o->phone, 'recipient_address' => mb_substr(trim($o->address.', '.$o->thana.', '.$o->district, ', '), 0, 250),
            'cod_amount' => round($cod, 2), 'note' => $note ? mb_substr($note, 0, 250) : null,
            'item_description' => mb_substr($o->lines->pluck('description')->join(', '), 0, 250),
        ]);
        $c = (array) $res->json('consignment');
        if (! $res->successful() || (int) $res->json('status') !== 200 || empty($c['consignment_id'])) {
            throw new RuntimeException('Steadfast refused the parcel: '.$this->error($res->json()));
        }

        return ['consignment_id' => (string) $c['consignment_id'], 'tracking_code' => $c['tracking_code'] ?? null, 'status' => $c['status'] ?? null, 'fee' => null, 'payload' => $c];
    }

    public function status(string $consignmentId): ?string
    {
        [$http, $base] = $this->http();

        return $http->get($base.'/status_by_cid/'.rawurlencode($consignmentId))->json('delivery_status');
    }

    public static function trackingUrl(?string $code): ?string
    {
        return $code ? 'https://steadfast.com.bd/t/'.rawurlencode($code) : null;
    }

    private function error(mixed $body): string
    {
        $errors = is_array($body) ? ($body['errors'] ?? null) : null;

        return is_array($errors) ? collect($errors)->flatten()->implode(' ') : (is_array($body) ? ($body['message'] ?? 'unknown error') : 'no answer');
    }
}
