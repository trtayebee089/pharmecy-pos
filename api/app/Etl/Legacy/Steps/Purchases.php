<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Legacy purchases (is_legacy = 1), their lines and purchase returns.
 *
 * - Status 1/2 (received/partial) → received, lines on the LEGACY batch.
 *   Status 3/4 (pending/ordered) → ordered (no stock or money), lines without
 *   a batch, so they can still be received in the new POS.
 * - NULL supplier → "Unknown supplier (legacy)".
 * - Returns settle as reduce_due against their purchase.
 * - paid/returned/due are set by Payments: due = max(0, total − paid − returned).
 */
class Purchases
{
    public function run(Context $c): void
    {
        $received = [];
        $c->src->table('purchases')->chunkById(5000, function ($rows) use ($c, &$received) {
            $insert = [];
            foreach ($rows as $p) {
                $isReceived = in_array((int) $p->status, [1, 2], true);
                $supplier = $c->mapped('supplier', $p->supplier_id ? (int) $p->supplier_id : null);
                if (! $supplier) {
                    $supplier = $c->unknownSupplier;
                    $c->count('purchases', 'no supplier → Unknown supplier');
                }
                $date = Context::ts($p->created_at, now()->toDateTimeString());
                $insert[] = [
                    'id' => $p->id, 'ulid' => Context::ulid(), 'number' => $c->number('purchase', $p->reference_no, 'LEGACY-P-', $p->id),
                    'warehouse_id' => $c->mapped('warehouse', (int) $p->warehouse_id) ?? $c->defaultWarehouse, 'supplier_id' => $supplier,
                    'supplier_invoice_no' => Str::limit(trim((string) $p->bill_no), 60, '') ?: null, 'invoice_date' => substr($date, 0, 10),
                    'received_at' => $isReceived ? $date : null, 'status' => $isReceived ? 'received' : 'ordered',
                    'subtotal' => Context::money($p->total_cost), 'discount_total' => Context::money((float) $p->total_discount + (float) $p->order_discount),
                    'tax_total' => Context::money((float) $p->total_tax + (float) $p->order_tax), 'other_charges' => Context::money($p->shipping_cost),
                    'grand_total' => Context::money($p->grand_total), 'paid_total' => 0, 'returned_total' => 0, 'due_total' => 0,
                    'note' => trim(strip_tags((string) $p->note)) ?: null, 'is_legacy' => true, 'created_by' => MasterData::userOr($c, $p->user_id),
                    'created_at' => $date, 'updated_at' => Context::ts($p->updated_at, $date),
                ];
                $c->purchaseSupplier[$p->id] = $supplier;
                $received[$p->id] = $isReceived;
                $c->count('purchases', $isReceived ? 'imported as received' : 'imported as ordered');
                $c->count('purchases', 'Σ grand_total', round((float) $p->grand_total, 2));
            }
            $c->insert('purchases', $insert);
        });

        $firstLine = [];
        $c->src->table('product_purchases')->chunkById(10000, function ($rows) use ($c, $received, &$firstLine) {
            $items = [];
            foreach ($rows as $l) {
                if (! isset($received[$l->purchase_id])) {
                    $c->reject('purchase_items', $l->id, 'purchase missing', ['purchase_id' => $l->purchase_id]);

                    continue;
                }
                if (! isset($c->pieceUnit[$l->product_id])) {
                    $c->reject('purchase_items', $l->id, 'product missing', ['product_id' => $l->product_id]);

                    continue;
                }
                $qtyBase = max(0, (int) round((float) $l->qty));
                $items[] = [
                    'id' => $l->id, 'purchase_id' => $l->purchase_id, 'product_id' => $l->product_id, 'product_unit_id' => $c->pieceUnit[$l->product_id],
                    'qty' => round((float) $l->qty, 3), 'factor' => 1, 'qty_base' => $qtyBase, 'free_qty_base' => 0,
                    'unit_cost' => round((float) $l->net_unit_cost, 4), 'discount_pct' => 0, 'tax' => Context::money($l->tax),
                    'line_total' => Context::money($l->total), 'batch_id' => $received[$l->purchase_id] ? $l->product_id : null,
                    'created_at' => Context::ts($l->created_at, now()), 'updated_at' => now(),
                ];
                $firstLine[$l->purchase_id.'-'.$l->product_id] ??= $l->id;
                $c->count('purchase_items', 'imported');
            }
            $c->insert('purchase_items', $items);
        });

        $this->returns($c, $firstLine);
        $c->flush();
    }

    private function returns(Context $c, array $firstLine): void
    {
        $lines = $c->src->table('purchase_product_return')->orderBy('id')->get()->groupBy('return_id');
        foreach ($c->src->table('return_purchases')->orderBy('id')->get() as $r) {
            $supplier = $c->mapped('supplier', $r->supplier_id ? (int) $r->supplier_id : null) ?? $c->purchaseSupplier[$r->purchase_id] ?? $c->unknownSupplier;
            $items = $lines->get($r->id, collect());
            $date = Context::ts($r->created_at, now()->toDateTimeString());
            $c->dst->table('purchase_returns')->insert([
                'id' => $r->id, 'ulid' => Context::ulid(), 'number' => $c->number('purchase_return', $r->reference_no, 'LEGACY-PR-', $r->id),
                'warehouse_id' => $c->mapped('warehouse', (int) $r->warehouse_id) ?? $c->defaultWarehouse, 'supplier_id' => $supplier,
                'status' => 'posted', 'settlement' => 'reduce_due', 'reason' => Str::limit(trim(strip_tags((string) $r->return_note)) ?: 'Legacy purchase return', 250, ''),
                'total' => Context::money($r->grand_total), 'qty_base' => (int) round($items->sum('qty')),
                'created_by' => MasterData::userOr($c, $r->user_id), 'created_at' => $date, 'updated_at' => $date,
            ]);
            foreach ($items as $l) {
                if (! isset($c->pieceUnit[$l->product_id])) {
                    $c->reject('purchase_return_items', $l->id, 'product missing', ['product_id' => $l->product_id]);

                    continue;
                }
                $c->dst->table('purchase_return_items')->insert([
                    'id' => $l->id, 'purchase_return_id' => $r->id, 'product_id' => $l->product_id, 'batch_id' => $l->product_id,
                    'purchase_item_id' => $firstLine[$r->purchase_id.'-'.$l->product_id] ?? null, 'qty_base' => max(0, (int) round((float) $l->qty)),
                    'unit_cost' => round((float) $l->net_unit_cost, 4), 'line_total' => Context::money($l->total), 'created_at' => $date, 'updated_at' => $date,
                ]);
            }
            if ($r->purchase_id && isset($c->purchaseSupplier[$r->purchase_id])) {
                $c->dst->table('purchase_return_allocations')->insert([
                    'purchase_return_id' => $r->id, 'purchase_id' => $r->purchase_id, 'amount' => Context::money($r->grand_total), 'created_at' => $date, 'updated_at' => $date,
                ]);
            } else {
                $c->count('purchase_returns', 'not linked to a purchase (supplier credit)');
            }
            $c->map('purchase_return', $r->id, $r->id);
            $c->count('purchase_returns', 'imported');
        }
    }
}
