<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Expense and income history (finance_entries, posted, Cash in hand) and
 * legacy stock adjustments (posted, on the LEGACY batch). Like the other
 * legacy documents they post no account transactions or stock movements.
 */
class Finance
{
    public function run(Context $c): void
    {
        foreach (['expense' => ['expense_categories', 'expenses', 'expense_category_id', 'EXP-L-'], 'income' => ['income_categories', 'incomes', 'income_category_id', 'INC-L-']] as $kind => [$catTable, $table, $catColumn, $prefix]) {
            $categories = [];
            foreach ($c->src->table($catTable)->get() as $cat) {
                $name = Str::limit(trim($cat->name) ?: 'Category '.$cat->id, 100, '');
                $id = $c->dst->table('finance_categories')->where(['kind' => $kind, 'name' => $name])->value('id')
                    ?: $c->dst->table('finance_categories')->insertGetId(['kind' => $kind, 'name' => $name, 'is_active' => (bool) $cat->is_active, 'created_at' => now(), 'updated_at' => now()]);
                $categories[$cat->id] = $id;
            }
            $fallback = null;
            $rows = [];
            foreach ($c->src->table($table)->orderBy('id')->get() as $e) {
                $category = $categories[$e->{$catColumn}] ?? null;
                if (! $category) {
                    $fallback ??= $c->dst->table('finance_categories')->insertGetId(['kind' => $kind, 'name' => 'Uncategorised (legacy)', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
                    $category = $fallback;
                    $c->count($table, 'no category → Uncategorised (legacy)');
                }
                $date = Context::ts($e->created_at, now()->toDateTimeString());
                $rows[] = [
                    'kind' => $kind, 'number' => $c->number('finance', $e->reference_no, $prefix, $e->id),
                    'warehouse_id' => $c->mapped('warehouse', (int) $e->warehouse_id) ?? $c->defaultWarehouse, 'category_id' => $category,
                    'account_id' => $c->mapped('account', (int) $e->account_id) ?? $c->cashAccount, 'amount' => Context::money($e->amount),
                    'entry_date' => substr($date, 0, 10), 'note' => Str::limit(trim(strip_tags((string) $e->note)), 250, '') ?: null,
                    'source_type' => 'legacy', 'source_id' => $e->id, 'status' => 'posted', 'created_by' => MasterData::userOr($c, $e->user_id),
                    'created_at' => $date, 'updated_at' => $date,
                ];
                $c->count($table, 'imported');
                $c->count($table, 'Σ amount', round((float) $e->amount, 2));
            }
            $c->insert('finance_entries', $rows);
        }

        $this->adjustments($c);
        $c->flush();
    }

    private function adjustments(Context $c): void
    {
        $lines = $c->src->table('product_adjustments')->orderBy('id')->get()->groupBy('adjustment_id');
        foreach ($c->src->table('adjustments')->orderBy('id')->get() as $a) {
            $items = [];
            $in = $out = 0;
            $value = 0.0;
            foreach ($lines->get($a->id, collect()) as $l) {
                if (! isset($c->pieceUnit[$l->product_id])) {
                    $c->reject('stock_adjustment_items', $l->id, 'product missing', ['product_id' => $l->product_id]);

                    continue;
                }
                $qty = (int) round(abs((float) $l->qty)) * ($l->action === '-' ? -1 : 1);
                $qty > 0 ? $in += $qty : $out += -$qty;
                $cost = (float) $l->unit_cost > 0 ? (float) $l->unit_cost : ($c->unitCost[$l->product_id] ?? 0);
                $value += $qty * $cost;
                $items[] = ['product_id' => $l->product_id, 'batch_id' => $l->product_id, 'qty_base' => $qty, 'unit_cost' => round($cost, 4)];
            }
            $date = Context::ts($a->created_at, now()->toDateTimeString());
            $id = $c->dst->table('stock_adjustments')->insertGetId([
                'ulid' => Context::ulid(), 'number' => $c->number('adjustment', $a->reference_no, 'ADJ-L-', $a->id),
                'warehouse_id' => $c->mapped('warehouse', (int) $a->warehouse_id) ?? $c->defaultWarehouse, 'reason' => 'correction',
                'note' => Str::limit(trim(strip_tags((string) $a->note)) ?: 'Legacy adjustment', 250, ''), 'status' => 'posted',
                'qty_in' => $in, 'qty_out' => $out, 'value' => Context::money($value), 'created_by' => MasterData::userOr($c, $a->user_id),
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $c->insert('stock_adjustment_items', array_map(fn ($i) => $i + ['stock_adjustment_id' => $id, 'created_at' => $date, 'updated_at' => $date], $items));
            $c->map('stock_adjustment', $a->id, $id);
            $c->count('stock_adjustments', 'imported');
        }
    }
}
