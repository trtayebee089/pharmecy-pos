<?php

namespace App\Services;

use App\Models\OnlineOrder;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Couriers\Pathao;
use App\Services\Couriers\Steadfast;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sending online orders with Steadfast or Pathao (owner decision 26 Nov 2026:
 * a "Send to courier" button per order). Booking needs a confirmed (or packed)
 * order that is not waiting for an online payment; the courier collects the
 * order's COD amount. The order then counts as in transit without a rider, and
 * the courier's status updates (webhook or Refresh) mark it delivered, which
 * books the cash to "Courier COD receivable". Cancelled / returned parcels are
 * only noted: staff decide what happens to the order.
 */
class CourierBookings
{
    public const COURIERS = ['steadfast' => 'Steadfast', 'pathao' => 'Pathao'];

    public function __construct(private Integrations $integrations, private OnlineOrders $orders) {}

    /** @return array<string, bool> */
    public function available(): array
    {
        return collect(array_keys(self::COURIERS))->mapWithKeys(fn ($c) => [$c => $this->integrations->enabled($c)])->all();
    }

    /** @param  array{city_id?: ?int, zone_id?: ?int, area_id?: ?int, weight?: ?float, note?: ?string}  $opts */
    public function book(User $user, OnlineOrder $order, string $courier, array $opts = []): OnlineOrder
    {
        if (! isset(self::COURIERS[$courier]) || ! $this->integrations->enabled($courier)) {
            throw ValidationException::withMessages(['courier' => 'This courier is not switched on in Administration › Integrations.']);
        }
        $order = OnlineOrder::whereKey($order->id)->firstOrFail();
        if ($order->courier) {
            throw ValidationException::withMessages(['courier' => "Already sent with {$this->label($order->courier)} ({$order->courier_consignment_id})."]);
        }
        if (! in_array($order->status, ['confirmed', 'packed'], true)) {
            throw ValidationException::withMessages(['courier' => 'Confirm the order first (that takes the stock), then send it.']);
        }
        if ($order->payment_status === 'pending') {
            throw ValidationException::withMessages(['courier' => 'The order is waiting for its online payment.']);
        }
        $zone = ShippingZone::find($order->zone_id);
        $place = [
            'city_id' => $opts['city_id'] ?? $zone?->pathao_city_id, 'zone_id' => $opts['zone_id'] ?? $zone?->pathao_zone_id,
            'area_id' => $opts['area_id'] ?? $zone?->pathao_area_id,
        ];
        if ($courier === 'pathao' && (! $place['city_id'] || ! $place['zone_id'])) {
            throw ValidationException::withMessages(['zone_id' => 'Choose the Pathao city and zone for this address.']);
        }

        $cod = OnlineOrders::codAmount($order);
        try {
            $r = $courier === 'steadfast'
                ? app(Steadfast::class)->create($order, $cod, $opts['note'] ?? null)
                : app(Pathao::class)->create($order, $cod, $place, $opts['note'] ?? null, $opts['weight'] ?? null);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['courier' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($user, $order, $courier, $r, $zone, $place, $cod) {
            $order->update([
                'courier' => $courier, 'courier_consignment_id' => $r['consignment_id'], 'courier_tracking_code' => $r['tracking_code'],
                'courier_status' => $r['status'], 'courier_status_at' => now(), 'courier_fee' => $r['fee'],
            ]);
            // Remember Pathao's ids for this delivery zone so the next booking needs no choosing.
            if ($courier === 'pathao' && $zone && ! $zone->pathao_zone_id) {
                $zone->update(['pathao_city_id' => $place['city_id'], 'pathao_zone_id' => $place['zone_id'], 'pathao_area_id' => $place['area_id']]);
            }
            $note = "Sent with {$this->label($courier)} · consignment {$r['consignment_id']} · collect ৳".number_format($cod, 2);
            if ($order->status === 'confirmed') {
                $order = $this->orders->transition($user, $order, 'packed', null);
            }

            return $this->orders->transition($user, $order->fresh(), 'in_transit', $note);
        });
    }

    /** Asks the courier for the parcel's status now. */
    public function refresh(OnlineOrder $order): OnlineOrder
    {
        if (! $order->courier) {
            throw ValidationException::withMessages(['courier' => 'This order was not sent with a courier.']);
        }
        try {
            $status = $order->courier === 'steadfast'
                ? app(Steadfast::class)->status($order->courier_consignment_id)
                : app(Pathao::class)->status($order->courier_consignment_id);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['courier' => $e->getMessage()]);
        }

        return $status ? $this->apply($order, $status) : $order;
    }

    /** A status from the courier (webhook or refresh): recorded, and "delivered" closes the order. */
    public function apply(OnlineOrder $order, string $status, ?float $fee = null): OnlineOrder
    {
        $normal = strtolower(str_replace(['order.', '-', ' '], ['', '_', '_'], trim($status)));
        $changed = $normal !== strtolower((string) $order->courier_status);
        $order->update(['courier_status' => $normal, 'courier_status_at' => now()] + ($fee !== null ? ['courier_fee' => $fee] : []));
        if ($normal === 'delivered' && $order->status === 'in_transit') {
            return $this->orders->transition(null, $order, 'delivered', "{$this->label($order->courier)}: delivered");
        }
        if ($changed) {
            $hint = match (true) {
                str_contains($normal, 'partial') => ' — partly delivered: check with the courier and record a return for what came back.',
                str_contains($normal, 'cancel') || str_contains($normal, 'return') => ' — the parcel is coming back: cancel or re-send the order.',
                default => '',
            };
            $this->orders->history($order, $order->status, "{$this->label($order->courier)}: ".str_replace('_', ' ', $normal).$hint, null);
        }

        return $order->fresh();
    }

    public function trackingUrl(OnlineOrder $o): ?string
    {
        return match ($o->courier) {
            'steadfast' => Steadfast::trackingUrl($o->courier_tracking_code),
            'pathao' => Pathao::trackingUrl($o->courier_consignment_id, $o->phone),
            default => null,
        };
    }

    private function label(?string $courier): string
    {
        return self::COURIERS[$courier] ?? (string) $courier;
    }
}
