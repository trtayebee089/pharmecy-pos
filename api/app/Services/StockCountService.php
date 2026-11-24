<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Physical stock counts per batch.
 *
 * - start: lists every batch on hand in the warehouse within the scope (all,
 *   or one dosage form, manufacturer or category matched by name).
 * - counted quantities are saved as drafts; system quantities are never typed.
 * - post: for each counted line the difference against the system quantity
 *   at posting time becomes a `count` movement. Uncounted lines change nothing.
 * - cancel discards the counts; no stock changes.
 */
class StockCountService
{
    public const SCOPES = ['all' => 'All products', 'dosage' => 'Dosage form', 'manufacturer' => 'Manufacturer', 'category' => 'Category'];

    public function __construct(private StockLedger $stock, private DocumentNumbers $numbers) {}

    public function start(User $user, array $data): StockCount
    {
        $warehouse = Warehouse::where('is_active', true)->find($data['warehouseId']);
        if (! $warehouse || ! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        $type = $data['scopeType'];
        $value = trim((string) ($data['scopeValue'] ?? ''));
        if ($type !== 'all' && $value === '') {
            throw ValidationException::withMessages(['scopeValue' => 'Enter the '.strtolower(self::SCOPES[$type]).' to count.']);
        }

        return DB::transaction(function () use ($user, $warehouse, $data, $type, $value) {
            $table = ['dosage' => 'dosage_forms', 'manufacturer' => 'manufacturers', 'category' => 'categories'][$type] ?? null;
            $column = ['dosage' => 'dosage_form_id', 'manufacturer' => 'manufacturer_id', 'category' => 'category_id'][$type] ?? null;
            $balances = StockBalance::query()->join('products', 'products.id', '=', 'stock_balances.product_id')
                ->where('stock_balances.warehouse_id', $warehouse->id)->where('stock_balances.qty_base', '>', 0)
                ->when($table, fn ($q) => $q->whereIn("products.$column", DB::table($table)->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $value).'%')->select('id')))
                ->orderBy('products.name')->orderBy('stock_balances.batch_id')
                ->get(['stock_balances.product_id', 'stock_balances.batch_id', 'stock_balances.qty_base']);
            if ($balances->isEmpty()) {
                throw ValidationException::withMessages(['scopeValue' => 'No stock on hand matches this scope.']);
            }

            $count = StockCount::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('stock_count', $warehouse),
                'warehouse_id' => $warehouse->id, 'name' => trim($data['name']), 'scope_type' => $type,
                'scope_value' => $type === 'all' ? null : $value, 'status' => 'counting', 'started_by' => $user->id,
            ]);
            foreach ($balances as $b) {
                StockCountItem::create(['stock_count_id' => $count->id, 'product_id' => $b->product_id, 'batch_id' => $b->batch_id, 'start_qty_base' => $b->qty_base]);
            }
            AuditLog::record($user->id, 'stock_count.started', 'stock_count', $count->id, ['number' => $count->number]);

            return $count;
        });
    }

    /** @param  array<int, array{batchId: int, countedQty: int|null}>  $counts */
    public function saveCounts(StockCount $count, array $counts): void
    {
        DB::transaction(function () use ($count, $counts) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($count);
            $items = $count->items()->get()->keyBy('batch_id');
            foreach ($counts as $i => $c) {
                $item = $items->get((int) $c['batchId']);
                if (! $item) {
                    throw ValidationException::withMessages(["counts.$i.batchId" => 'This batch is not part of the count.']);
                }
                $item->update(['counted_qty_base' => $c['countedQty'] === null ? null : (int) $c['countedQty']]);
            }
        });
    }

    public function post(User $user, StockCount $count): StockCount
    {
        return DB::transaction(function () use ($user, $count) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($count);
            $items = $count->items()->whereNotNull('counted_qty_base')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['count' => 'Count at least one batch before posting.']);
            }
            $balances = StockBalance::where('warehouse_id', $count->warehouse_id)->whereIn('batch_id', $items->pluck('batch_id'))
                ->lockForUpdate()->get()->keyBy('batch_id');

            $varianceQty = 0;
            $varianceValue = 0;
            foreach ($items as $item) {
                $system = (int) ($balances[$item->batch_id]->qty_base ?? 0);
                $cost = (float) ($balances[$item->batch_id]->avg_unit_cost ?? 0);
                $diff = $item->counted_qty_base - $system;
                $item->update(['system_qty_base' => $system, 'difference' => $diff, 'unit_cost' => $cost]);
                if ($diff !== 0) {
                    $this->stock->move([
                        'warehouse_id' => $count->warehouse_id, 'product_id' => $item->product_id, 'batch_id' => $item->batch_id,
                        'qty_base' => $diff, 'type' => 'count', 'unit_cost' => $cost,
                        'source_type' => 'stock_count', 'source_id' => $count->id, 'source_line_id' => $item->id,
                        'reason' => $count->number, 'created_by' => $user->id,
                    ]);
                }
                $varianceQty += $diff;
                $varianceValue += (int) round($diff * $cost * 100);
            }
            $count->update([
                'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(),
                'variance_qty' => $varianceQty, 'variance_value' => SaleService::money($varianceValue),
            ]);
            AuditLog::record($user->id, 'stock_count.posted', 'stock_count', $count->id, ['varianceQty' => $varianceQty]);

            return $count;
        });
    }

    public function cancel(User $user, StockCount $count, string $reason): StockCount
    {
        return DB::transaction(function () use ($user, $count, $reason) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($count);
            $count->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            AuditLog::record($user->id, 'stock_count.cancelled', 'stock_count', $count->id, ['reason' => $reason]);

            return $count;
        });
    }

    private function mustBeOpen(StockCount $count): void
    {
        if ($count->status !== 'counting') {
            throw ValidationException::withMessages(['count' => "This count is {$count->status} and can no longer change."]);
        }
    }
}
