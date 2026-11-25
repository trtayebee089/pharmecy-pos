<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Legacy sales as read-only history (is_legacy = 1): headers, product lines
 * (each linked to the product's LEGACY batch), custom lines and returns.
 * They create no stock movements, ledger or reward entries; the opening
 * balances carry their effect forward.
 *
 * - Imported: completed (1) and returned (4) sales, and website orders
 *   (status 0) whose tracking says delivered or returned. Drafts and open or
 *   cancelled website orders are not sales (open/cancelled website orders
 *   become online orders, see Delivery).
 * - sales.id, sale_items.id and sale_item_batches.id keep the legacy ids;
 *   custom lines follow after the highest product line id.
 * - Number = legacy reference_no (duplicates get -2, -3).
 * - Line cost = qty × the product's legacy cost (or last purchase cost).
 * - paid/due are set by Payments: due = max(0, total − payments − returns).
 */
class Sales
{
    public function run(Context $c): void
    {
        $tracking = $c->src->table('order_trackings')->pluck('current_status', 'sale_id')->all();
        $returned = [];
        foreach ($c->src->select('SELECT r.sale_id, pr.product_id, SUM(pr.qty) q FROM product_returns pr JOIN returns r ON r.id = pr.return_id GROUP BY 1, 2') as $r) {
            $returned[$r->sale_id.'-'.$r->product_id] = (int) round((float) $r->q);
        }
        $returnSales = array_flip($c->src->table('returns')->distinct()->pluck('sale_id')->all());

        $this->headers($c, $tracking);
        $firstLine = $this->lines($c, $returned, $returnSales);
        $this->customLines($c);
        $this->returns($c, $firstLine);
        $c->dst->statement('UPDATE sales s JOIN (SELECT sale_id, SUM(cost_total) cost FROM sale_items GROUP BY sale_id) x ON x.sale_id = s.id SET s.cost_total = x.cost');
        $c->flush();
    }

    public static function imports(object $s, array $tracking): ?string
    {
        $status = (int) $s->sale_status;
        if (in_array($status, [1, 4], true)) {
            return null;
        }
        if ($status === 0 && in_array($tracking[$s->id] ?? null, ['delivered', 'returned'], true)) {
            return null;
        }

        return match ($status) {
            3 => 'draft sale',
            0 => 'open or cancelled website order (imported as an online order)',
            default => "sale status {$status}",
        };
    }

    private function headers(Context $c, array $tracking): void
    {
        $c->src->table('sales')->chunkById(5000, function ($rows) use ($c, $tracking) {
            $insert = [];
            foreach ($rows as $s) {
                if ($skip = self::imports($s, $tracking)) {
                    $c->count('sales', 'skipped: '.$skip);

                    continue;
                }
                $customer = $c->mapped('customer', (int) $s->customer_id);
                if (! $customer) {
                    $customer = $c->walkIn;
                    $c->count('sales', 'unknown customer → walk-in');
                }
                $date = Context::ts($s->created_at, Context::ts($s->updated_at, now()->toDateTimeString()));
                $note = trim(implode("\n", array_filter([trim((string) $s->sale_note), trim((string) $s->staff_note)])));
                $insert[] = [
                    'id' => $s->id, 'ulid' => Context::ulid(), 'number' => $c->number('sale', $s->reference_no, 'LEGACY-S-', $s->id),
                    'channel' => $s->sale_type === 'website' ? 'website' : 'pos',
                    'warehouse_id' => $c->mapped('warehouse', (int) $s->warehouse_id) ?? $c->defaultWarehouse,
                    'customer_id' => $customer, 'cashier_id' => MasterData::userOr($c, $s->user_id), 'session_id' => null,
                    'status' => 'completed', 'sale_date' => $date,
                    'subtotal' => Context::money($s->total_price), 'line_discount_total' => Context::money($s->total_discount),
                    'order_discount_total' => Context::money((float) $s->order_discount + (float) $s->coupon_discount),
                    'tax_total' => Context::money((float) $s->total_tax + (float) $s->order_tax), 'shipping_total' => Context::money($s->shipping_cost),
                    'rounding' => 0, 'grand_total' => Context::money($s->grand_total), 'paid_total' => 0, 'change_total' => 0, 'due_total' => 0,
                    'payment_status' => 'unpaid', 'cost_total' => 0, 'note' => $note !== '' ? $note : null, 'is_legacy' => true,
                    'created_at' => $date, 'updated_at' => Context::ts($s->updated_at, $date),
                ];
                $c->saleCustomer[$s->id] = $customer;
                $c->count('sales', 'imported');
                $c->count('sales', 'Σ grand_total', round((float) $s->grand_total, 2));
            }
            $c->insert('sales', $insert, 1000);
        });
    }

    /** @return array<string, int> "saleId-productId" => first product line id, only for sales with returns */
    private function lines(Context $c, array $returned, array $returnSales): array
    {
        $firstLine = [];
        $c->src->table('product_sales')->chunkById(10000, function ($rows) use ($c, &$returned, $returnSales, &$firstLine) {
            $items = [];
            $batches = [];
            foreach ($rows as $l) {
                if (! isset($c->saleCustomer[$l->sale_id])) {
                    $c->count('sale_items', 'skipped: sale not imported');

                    continue;
                }
                if (! isset($c->pieceUnit[$l->product_id])) {
                    $c->reject('sale_items', $l->id, 'product missing', ['sale_id' => $l->sale_id, 'product_id' => $l->product_id]);

                    continue;
                }
                $qtyBase = max(0, (int) round((float) $l->qty));
                if ((float) $l->qty <= 0) {
                    $c->count('sale_items', 'qty ≤ 0 kept with qty_base 0');
                }
                $cost = round($qtyBase * ($c->unitCost[$l->product_id] ?? 0), 2);
                $items[] = [
                    'id' => $l->id, 'sale_id' => $l->sale_id, 'line_type' => 'product', 'product_id' => $l->product_id,
                    'product_unit_id' => $c->pieceUnit[$l->product_id], 'description' => Str::limit($c->productName[$l->product_id] ?? 'Product', 250, ''),
                    'qty' => round((float) $l->qty, 3), 'factor' => 1, 'qty_base' => $qtyBase, 'unit_price' => round((float) $l->net_unit_price, 4),
                    'discount_pct' => 0, 'discount_total' => Context::money($l->discount), 'tax_total' => Context::money($l->tax),
                    'line_total' => Context::money($l->total), 'cost_total' => $cost,
                    'created_at' => Context::ts($l->created_at, now()), 'updated_at' => Context::ts($l->updated_at, now()),
                ];
                $key = $l->sale_id.'-'.$l->product_id;
                $back = min($qtyBase, $returned[$key] ?? 0);
                if ($back > 0) {
                    $returned[$key] -= $back;
                }
                $batches[] = ['id' => $l->id, 'sale_item_id' => $l->id, 'batch_id' => $l->product_id, 'qty_base' => $qtyBase,
                    'unit_cost' => round($c->unitCost[$l->product_id] ?? 0, 4), 'returned_qty_base' => $back, 'created_at' => now(), 'updated_at' => now()];
                if (isset($returnSales[$l->sale_id]) && ! isset($firstLine[$key])) {
                    $firstLine[$key] = $l->id;
                }
                $c->count('sale_items', 'imported');
            }
            $c->insert('sale_items', $items, 1000);
            $c->insert('sale_item_batches', $batches, 2000);
        });
        $over = array_sum(array_filter($returned, fn ($q) => $q > 0));
        $c->fact('sale_items', 'returned pieces beyond the sold qty (not tracked on lines)', $over);

        return $firstLine;
    }

    private function customLines(Context $c): void
    {
        $offset = (int) $c->dst->table('sale_items')->max('id');
        $c->customLineOffset = $offset;
        $c->src->table('custom_product_sales')->chunkById(5000, function ($rows) use ($c, $offset) {
            $items = [];
            foreach ($rows as $l) {
                if (! isset($c->saleCustomer[$l->sale_id])) {
                    $c->count('sale_items (custom)', 'skipped: sale not imported');

                    continue;
                }
                $items[] = [
                    'id' => $offset + $l->id, 'sale_id' => $l->sale_id, 'line_type' => 'misc', 'product_id' => null, 'product_unit_id' => null,
                    'description' => Str::limit(trim((string) $l->product_name) ?: 'Custom item', 250, ''), 'misc_code' => Str::limit((string) $l->product_code, 60, '') ?: null,
                    'qty' => round((float) $l->qty, 3), 'factor' => 1, 'qty_base' => 0, 'unit_price' => round((float) $l->net_unit_price, 4),
                    'discount_pct' => 0, 'discount_total' => Context::money($l->discount), 'tax_total' => Context::money($l->tax),
                    'line_total' => Context::money($l->total), 'cost_total' => 0,
                    'created_at' => Context::ts($l->created_at, now()), 'updated_at' => Context::ts($l->updated_at, now()),
                ];
                $c->count('sale_items (custom)', 'imported');
            }
            $c->insert('sale_items', $items, 1000);
        });
    }

    private function returns(Context $c, array $firstLine): void
    {
        $qty = [];
        foreach ($c->src->select('SELECT return_id, SUM(qty) q FROM product_returns GROUP BY return_id') as $r) {
            $qty[$r->return_id] = max(0, (int) round((float) $r->q));
        }
        $imported = [];
        $rows = [];
        foreach ($c->src->table('returns')->orderBy('id')->get() as $r) {
            if (! isset($c->saleCustomer[$r->sale_id])) {
                $c->reject('sale_returns', $r->id, 'sale not imported', ['sale_id' => $r->sale_id, 'grand_total' => $r->grand_total]);

                continue;
            }
            $date = Context::ts($r->created_at, now()->toDateTimeString());
            $rows[] = [
                'id' => $r->id, 'ulid' => Context::ulid(), 'number' => $c->number('sale_return', $r->reference_no, 'LEGACY-R-', $r->id),
                'sale_id' => $r->sale_id, 'warehouse_id' => $c->mapped('warehouse', (int) $r->warehouse_id) ?? $c->defaultWarehouse,
                'customer_id' => $c->saleCustomer[$r->sale_id], 'session_id' => null, 'refund_method' => 'cash',
                'total' => Context::money($r->grand_total), 'restocked_qty_base' => $qty[$r->id] ?? 0, 'written_off_qty_base' => 0,
                'reason' => Str::limit(trim(strip_tags((string) $r->return_note)) ?: 'Legacy return', 250, ''),
                'created_by' => MasterData::userOr($c, $r->user_id), 'created_at' => $date, 'updated_at' => $date,
            ];
            $imported[$r->id] = $r->sale_id;
            $c->count('sale_returns', 'imported');
            $c->count('sale_returns', 'Σ total', round((float) $r->grand_total, 2));
        }
        $c->insert('sale_returns', $rows);

        $items = [];
        foreach ($c->src->table('product_returns')->orderBy('id')->get() as $l) {
            $saleId = $imported[$l->return_id] ?? null;
            if (! $saleId) {
                $c->count('sale_return_items', 'skipped: return not imported');

                continue;
            }
            $line = $firstLine[$saleId.'-'.$l->product_id] ?? null;
            if (! $line) {
                $c->reject('sale_return_items', $l->id, 'no matching sale line', ['return_id' => $l->return_id, 'product_id' => $l->product_id]);

                continue;
            }
            $items[] = [
                'id' => $l->id, 'sale_return_id' => $l->return_id, 'sale_item_id' => $line, 'sale_item_batch_id' => $line, 'batch_id' => $l->product_id,
                'qty_base' => max(0, (int) round((float) $l->qty)), 'unit_refund' => round((float) $l->net_unit_price, 4),
                'line_total' => Context::money($l->total), 'restock' => true,
                'created_at' => Context::ts($l->created_at, now()), 'updated_at' => now(),
            ];
            $c->count('sale_return_items', 'imported');
        }
        $c->insert('sale_return_items', $items);
    }
}
