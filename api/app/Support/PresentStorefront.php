<?php

namespace App\Support;

use App\Models\OnlineOrder;
use App\Services\OnlineOrders;
use Illuminate\Support\Carbon;

/**
 * Online orders as the customer sees them. Orders are addressed by their
 * number (OO-…), never the sequential id. `forTracking` drops everything
 * personal (no name, phone or address) for the public tracking page.
 */
class PresentStorefront
{
    public const LABELS = [
        'pending' => 'Order placed', 'confirmed' => 'Confirmed', 'packed' => 'Packed',
        'in_transit' => 'On the way', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
    ];

    public static function order(OnlineOrder $o, bool $forTracking = false): array
    {
        $o->loadMissing('lines', 'history', 'rider');
        $pointsValue = (float) $o->points_value;
        $base = [
            'id' => $o->number, 'number' => $o->number, 'tracking_code' => $o->tracking_code,
            'status' => $o->status, 'status_label' => self::LABELS[$o->status] ?? $o->status,
            'placed_at' => $o->placed_at?->toIso8601String(), 'delivered_at' => $o->delivered_at?->toIso8601String(),
            'items_count' => $o->lines->count(),
            'history' => $o->history->map(fn ($h) => [
                'status' => $h->status, 'label' => self::LABELS[$h->status] ?? $h->status, 'note' => $forTracking ? null : $h->note,
                'at' => Carbon::parse($h->created_at)->toIso8601String(),
            ])->values()->all(),
            'grand_total' => (float) $o->grand_total, 'cod_amount' => OnlineOrders::codAmount($o),
            'payment_method' => $o->payment_method ?? 'cod', 'payment_status' => $o->payment_status ?? 'cod', 'paid_online' => (float) $o->paid_online,
        ];
        if ($forTracking) {
            return $base;
        }

        return $base + [
            'channel' => $o->channel,
            'items' => $o->lines->map(fn ($l) => [
                'product_id' => $l->product_id, 'name' => $l->description, 'quantity' => fmod((float) $l->qty, 1.0) === 0.0 ? (int) $l->qty : (float) $l->qty,
                'unit_price' => (float) $l->unit_price, 'subtotal' => (float) $l->line_total,
            ])->values()->all(),
            'subtotal' => (float) $o->subtotal, 'discount_pct' => (float) $o->discount_pct, 'discount_total' => (float) $o->discount_total,
            'shipping' => (float) $o->shipping, 'points_redeemed' => (int) $o->points_redeemed, 'points_value' => $pointsValue,
            'delivery' => ['phone' => $o->phone, 'address' => $o->address, 'division' => $o->division, 'district' => $o->district, 'thana' => $o->thana],
            'rider' => $o->rider ? ['name' => $o->rider->name] : null,
            'cancel_reason' => $o->cancel_reason, 'note' => $o->note,
        ];
    }
}
