<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;

/**
 * Opening balances at cutover, the only entries that carry legacy effect into
 * the new ledgers (owner decisions 26 Nov 2026):
 *
 * - Stock: legacy qty per product (products.qty, or Σ product_warehouse with
 *   --stock-source=warehouse) onto the product's LEGACY batch (no expiry,
 *   sold last in FEFO) as an `opening` movement at the product's legacy cost.
 *   Negative legacy qty opens at 0 and is listed. Staff then count stock to
 *   give batches and expiry dates.
 * - Customer dues: Σ due of the customer's imported sales (`opening` debit).
 *   The walk-in customer gets none (listed).
 * - Supplier dues: Σ due of the supplier's received legacy purchases as-is
 *   (`opening` credit).
 * - Reward points: legacy customers.points (merged profiles summed, whole
 *   points) as an `opening` credit and wallet.
 * - Account balances are not imported: the business enters the confirmed
 *   cash/bank balance at cutover.
 */
class Openings
{
    public function run(Context $c): void
    {
        $this->stock($c);
        $this->dues($c);
        $this->points($c);
        $c->flush();
    }

    private function stock(Context $c): void
    {
        $qty = $c->stockSource === 'warehouse'
            ? $c->src->table('product_warehouse')->groupBy('product_id')->selectRaw('product_id id, SUM(qty) q')->pluck('q', 'id')
            : $c->src->table('products')->pluck('qty', 'id');
        $movements = [];
        $balances = [];
        $at = $c->cutoverDate.' 00:00:00';
        foreach ($qty as $productId => $q) {
            $q = (int) round((float) $q);
            if ($q < 0) {
                $c->reject('opening_stock', $productId, 'negative legacy qty opened at 0', ['qty' => $q]);

                continue;
            }
            if ($q === 0 || ! isset($c->pieceUnit[$productId])) {
                continue;
            }
            $cost = round($c->unitCost[$productId] ?? 0, 4);
            $movements[] = ['warehouse_id' => $c->defaultWarehouse, 'product_id' => $productId, 'batch_id' => $productId, 'qty_base' => $q,
                'type' => 'opening', 'unit_cost' => $cost, 'source_type' => 'legacy_opening', 'source_id' => null, 'source_line_id' => null,
                'reason' => 'Legacy opening stock', 'created_by' => $c->systemUser, 'created_at' => $at];
            $balances[] = ['warehouse_id' => $c->defaultWarehouse, 'product_id' => $productId, 'batch_id' => $productId, 'qty_base' => $q,
                'avg_unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()];
            $c->count('opening_stock', 'products with stock');
            $c->count('opening_stock', 'Σ pieces', $q);
            $c->count('opening_stock', 'Σ value at cost', round($q * $cost, 2));
            if ($cost <= 0) {
                $c->count('opening_stock', 'products with stock but no cost');
            }
        }
        $c->insert('stock_movements', $movements);
        $c->insert('stock_balances', $balances);
        $c->fact('opening_stock', 'source', $c->stockSource === 'warehouse' ? 'Σ product_warehouse.qty' : 'products.qty');
    }

    private function dues(Context $c): void
    {
        $customers = $c->dst->table('sales')->where('is_legacy', true)->where('due_total', '>', 0)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(due_total) due, COUNT(*) n')->get();
        $rows = [];
        foreach ($customers as $r) {
            if ((int) $r->customer_id === $c->walkIn) {
                $c->fact('customer_dues', 'walk-in sales with a due (not carried, review)', (int) $r->n);
                $c->fact('customer_dues', 'walk-in Σ due (not carried)', (float) $r->due);

                continue;
            }
            $rows[] = ['party_type' => 'customer', 'party_id' => $r->customer_id, 'entry_date' => $c->cutoverDate, 'type' => 'opening',
                'debit' => Context::money($r->due), 'credit' => 0, 'source_type' => 'legacy_opening', 'source_id' => null,
                'note' => 'Legacy opening due', 'created_by' => $c->systemUser, 'created_at' => now()];
            $c->count('customer_dues', 'customers with an opening due');
            $c->count('customer_dues', 'Σ opening due', round((float) $r->due, 2));
        }
        $c->insert('party_ledger_entries', $rows);

        $suppliers = $c->dst->table('purchases')->where('is_legacy', true)->where('due_total', '>', 0)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(due_total) due')->get();
        $rows = [];
        foreach ($suppliers as $r) {
            $rows[] = ['party_type' => 'supplier', 'party_id' => $r->supplier_id, 'entry_date' => $c->cutoverDate, 'type' => 'opening',
                'debit' => 0, 'credit' => Context::money($r->due), 'source_type' => 'legacy_opening', 'source_id' => null,
                'note' => 'Legacy opening due', 'created_by' => $c->systemUser, 'created_at' => now()];
            $c->count('supplier_dues', 'suppliers with an opening due');
            $c->count('supplier_dues', 'Σ opening due', round((float) $r->due, 2));
        }
        $c->insert('party_ledger_entries', $rows);
    }

    private function points(Context $c): void
    {
        $ledger = [];
        $wallets = [];
        foreach ($c->customerPoints as $customerId => $points) {
            $points = (int) floor($points);
            if ($points <= 0) {
                continue;
            }
            $ledger[] = ['customer_id' => $customerId, 'type' => 'opening', 'points' => $points, 'value' => 0, 'remaining' => $points,
                'expires_at' => null, 'source_type' => 'legacy_opening', 'source_id' => null, 'note' => 'Legacy points', 'created_by' => $c->systemUser, 'created_at' => now()];
            $wallets[] = ['customer_id' => $customerId, 'balance' => $points, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0, 'created_at' => now(), 'updated_at' => now()];
            $c->count('reward_points', 'customers with points');
            $c->count('reward_points', 'Σ points', $points);
        }
        $c->insert('reward_ledger', $ledger);
        $c->insert('reward_wallets', $wallets);
        $s = $c->src->table('reward_point_settings')->first();
        if ($s) {
            $c->fact('reward_points', 'legacy rule (enter in Administration › Reward points)', "1 point per {$s->per_point_amount} ৳, minimum bill {$s->minimum_amount} ৳, expiry {$s->duration} {$s->type}, active ".($s->is_active ? 'yes' : 'no'));
        }
    }
}
