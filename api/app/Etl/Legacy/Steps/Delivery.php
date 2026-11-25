<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Riders, shipping zones and legacy website orders (order_trackings).
 *
 * - Zones without a thana cover the whole district ("All areas").
 * - Delivered / returned orders link to their imported sale (closed as
 *   delivered; returns are on the sale).
 * - Cancelled orders are imported cancelled.
 * - Open orders (pending, processing, assigned, in transit) come in as
 *   **pending** online orders with their lines and no sale: staff confirm them
 *   again in the new POS, which takes the stock. Best practice is to finish
 *   them in the legacy system before cutover; the report lists them.
 */
class Delivery
{
    public const STATUS = ['delivered' => 'delivered', 'returned' => 'delivered', 'cancelled' => 'cancelled'];

    public function run(Context $c): void
    {
        foreach ($c->src->table('riders')->orderBy('id')->get() as $r) {
            $id = $c->dst->table('riders')->insertGetId(['name' => Str::limit(trim($r->full_name), 100, ''), 'phone' => Str::limit((string) $r->phone, 20, ''),
                'nid' => Str::limit((string) $r->nid, 30, '') ?: null, 'status' => $r->status === 'inactive' ? 'inactive' : 'active',
                'created_at' => Context::ts($r->created_at, now()), 'updated_at' => now()]);
            $c->map('rider', $r->id, $id);
            $c->count('riders', 'imported');
        }

        $seen = [];
        foreach ($c->src->table('shipping_zones')->orderBy('id')->get() as $z) {
            $area = [trim((string) $z->division) ?: 'Unknown', trim((string) $z->district) ?: 'Unknown', trim((string) $z->thana) ?: 'All areas'];
            $key = strtolower(implode('|', $area));
            if (isset($seen[$key])) {
                $c->count('shipping_zones', 'skipped: duplicate area');

                continue;
            }
            $seen[$key] = true;
            $id = $c->dst->table('shipping_zones')->insertGetId(['division' => $area[0], 'district' => $area[1], 'thana' => $area[2],
                'rate' => Context::money($z->rate), 'free_shipping_min' => null, 'partner' => $z->delivery_partner ? strtolower($z->delivery_partner) : 'own_rider',
                'is_active' => (bool) $z->is_active, 'created_at' => now(), 'updated_at' => now()]);
            $c->map('shipping_zone', $z->id, $id);
            $c->count('shipping_zones', 'imported');
        }

        $this->orders($c);
        $c->flush();
    }

    private function orders(Context $c): void
    {
        $history = $c->src->table('order_tracking_histories')->orderBy('id')->get()->groupBy('tracking_id');
        foreach ($c->src->table('order_trackings')->orderBy('id')->get() as $t) {
            $sale = $c->src->table('sales')->where('id', $t->sale_id)->first();
            if (! $sale) {
                $c->reject('online_orders', $t->id, 'sale missing', ['sale_id' => $t->sale_id]);

                continue;
            }
            $imported = isset($c->saleCustomer[$sale->id]);
            $status = self::STATUS[$t->current_status] ?? 'pending';
            if ($imported && $status === 'pending') {
                // A POS sale with a tracking row that was never closed: the sale already took the stock.
                $status = 'delivered';
            }
            if (! $imported && $status === 'delivered') {
                $status = 'pending';
            }
            $customerId = $c->saleCustomer[$sale->id] ?? $c->mapped('customer', (int) $sale->customer_id) ?? $c->walkIn;
            $customer = $c->dst->table('customers')->where('id', $customerId)->first();
            $placed = Context::ts($sale->created_at, now()->toDateTimeString());
            $orderId = $c->dst->table('online_orders')->insertGetId([
                'number' => $c->number('online_order', null, 'OO-L-', $t->id), 'tracking_code' => 'L'.str_pad((string) $t->id, 8, '0', STR_PAD_LEFT),
                'legacy_ref' => Str::limit((string) $t->tracking_no, 60, ''), 'channel' => $sale->sale_type === 'website' ? 'website' : 'phone',
                'customer_id' => $customerId, 'warehouse_id' => $c->defaultWarehouse, 'phone' => (string) ($customer->phone ?? ''),
                'address' => Str::limit((string) ($customer->address ?? '') ?: 'Unknown (legacy order)', 250, ''), 'zone_id' => null,
                'division' => 'Unknown', 'district' => 'Unknown', 'thana' => 'Unknown', 'status' => $status,
                'payment_method' => 'cod', 'payment_status' => $status === 'delivered' ? 'paid' : 'cod',
                'subtotal' => Context::money($sale->total_price), 'discount_total' => Context::money((float) $sale->order_discount + (float) $sale->coupon_discount),
                'shipping' => Context::money($sale->shipping_cost), 'grand_total' => Context::money($sale->grand_total),
                'sale_id' => $imported ? $sale->id : null, 'rider_id' => $c->mapped('rider', $t->assigned_rider_id ? (int) $t->assigned_rider_id : null),
                'placed_at' => $placed, 'delivered_at' => $status === 'delivered' ? Context::ts($t->updated_at, $placed) : null,
                'note' => Str::limit('Legacy order '.$sale->reference_no.' ('.$t->current_status.')', 250, ''),
                'cancel_reason' => $status === 'cancelled' ? 'Cancelled in the old system' : null,
                'created_at' => $placed, 'updated_at' => Context::ts($t->updated_at, $placed),
            ]);
            if (! $imported) {
                $lines = [];
                foreach ($c->src->table('product_sales')->where('sale_id', $sale->id)->get() as $l) {
                    if (! isset($c->pieceUnit[$l->product_id])) {
                        continue;
                    }
                    $lines[] = ['online_order_id' => $orderId, 'product_id' => $l->product_id, 'product_unit_id' => $c->pieceUnit[$l->product_id],
                        'description' => Str::limit($c->productName[$l->product_id], 250, ''), 'qty' => round((float) $l->qty, 3),
                        'qty_base' => max(0, (int) round((float) $l->qty)), 'unit_price' => round((float) $l->net_unit_price, 4),
                        'line_total' => Context::money($l->total), 'created_at' => now(), 'updated_at' => now()];
                }
                $c->insert('online_order_lines', $lines);
            }
            $rows = [];
            foreach ($history->get($t->id, collect()) as $h) {
                $rows[] = ['online_order_id' => $orderId, 'status' => self::STATUS[$h->status] ?? 'pending',
                    'note' => Str::limit('Legacy: '.$h->status.($h->note ? ' — '.$h->note : ''), 250, ''), 'user_id' => null, 'created_at' => Context::ts($h->created_at, $placed)];
            }
            $c->insert('online_order_history', $rows);
            $c->map('online_order', $t->id, $orderId);
            $c->count('online_orders', "imported {$status}".($status === 'pending' ? ' (was '.$t->current_status.', confirm again)' : ''));
        }
    }
}
