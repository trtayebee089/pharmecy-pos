<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stock. Every change is an append-only stock_movements row
 * plus an update of the stock_balances projection for (warehouse, batch), done
 * under a row lock inside the caller's transaction.
 *
 * Sellable = batch status active AND (expiry_date >= today OR legacy opening
 * batch without expiry). Two admin switches (General settings, owner decision
 * 25 Nov 2026) relax this; both default to the safe rule:
 * - blockExpiredSale = false: expired batches become sellable, but only after
 *   every unexpired batch (FEFO among the unexpired first).
 * - allowNegativeStock = true: a sale may take more than is on hand; the
 *   shortfall goes onto the last allocated batch (or the product's latest
 *   batch) as a negative balance that a purchase or count must correct.
 */
class StockLedger
{
    public static function sellableQuery(int $warehouseId, int $productId)
    {
        $today = now()->toDateString();
        $blockExpired = (bool) app(AppSettings::class)->general('blockExpiredSale');

        return StockBalance::query()
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.warehouse_id', $warehouseId)
            ->where('stock_balances.product_id', $productId)
            ->where('stock_balances.qty_base', '>', 0)
            ->where('batches.status', 'active')
            ->when($blockExpired, fn ($q) => $q->where(fn ($q) => $q->where('batches.expiry_date', '>=', $today)
                ->orWhere(fn ($q) => $q->whereNull('batches.expiry_date')->where('batches.is_legacy_opening', true))))
            // FEFO: unexpired before expired (expired only reachable when the switch allows it),
            // earliest expiry first, legacy batches without expiry last.
            ->orderByRaw('CASE WHEN batches.expiry_date < ? THEN 1 ELSE 0 END', [$today])
            ->orderByRaw('CASE WHEN batches.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('batches.expiry_date')
            ->orderBy('batches.id')
            ->select('stock_balances.*', 'batches.batch_no', 'batches.expiry_date');
    }

    /** Every batch with pieces on hand (expired and inactive included), earliest expiry first. For returns and counts, never for sales. */
    public static function onHandQuery(int $warehouseId, int $productId)
    {
        return StockBalance::query()
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.warehouse_id', $warehouseId)
            ->where('stock_balances.product_id', $productId)
            ->where('stock_balances.qty_base', '>', 0)
            ->orderByRaw('CASE WHEN batches.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('batches.expiry_date')
            ->orderBy('batches.id')
            ->select('stock_balances.*', 'batches.batch_no', 'batches.expiry_date');
    }

    /**
     * Locks and allocates $qtyBase pieces FEFO. With $batchId (override) only
     * that batch is used. Returns [[balance, qty]] or throws 422.
     *
     * @return Collection<int, array{balance: StockBalance, qty: int}>
     */
    public function allocate(int $warehouseId, int $productId, int $qtyBase, string $field, ?int $batchId = null): Collection
    {
        if ($batchId) {
            $batch = Batch::where('product_id', $productId)->find($batchId);
            if (! $batch) {
                throw ValidationException::withMessages([$field => 'The selected batch does not belong to this product.']);
            }
            if ($batch->expiry_date && $batch->expiry_date->lt(now()->startOfDay()) && app(AppSettings::class)->general('blockExpiredSale')) {
                throw ValidationException::withMessages([$field => "Batch {$batch->batch_no} expired on {$batch->expiry_date->toDateString()} and cannot be sold."]);
            }
            if ($batch->status !== 'active') {
                throw ValidationException::withMessages([$field => "Batch {$batch->batch_no} is {$batch->status} and cannot be sold."]);
            }
        }

        $rows = self::sellableQuery($warehouseId, $productId)
            ->when($batchId, fn ($q) => $q->where('stock_balances.batch_id', $batchId))
            ->lockForUpdate()
            ->get();

        $available = (int) $rows->sum('qty_base');
        $allowNegative = (bool) app(AppSettings::class)->general('allowNegativeStock');
        if ($available < $qtyBase && ! $allowNegative) {
            throw ValidationException::withMessages([
                $field => "Insufficient sellable stock: {$qtyBase} pieces requested, {$available} available.",
            ]);
        }

        $parts = collect();
        $need = $qtyBase;
        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }
            $take = min($row->qty_base, $need);
            $parts->push(['balance' => $row, 'qty' => $take]);
            $need -= $take;
        }
        if ($need > 0) {
            // Allowed shortfall: on the last batch used, the chosen batch, or the product's latest batch.
            $batchIdForShort = $parts->last()['balance']->batch_id ?? $batchId ?? Batch::where('product_id', $productId)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderByDesc('expiry_date')->orderByDesc('id')->value('id');
            if (! $batchIdForShort) {
                throw ValidationException::withMessages([$field => 'This product has no batch to sell from yet. Receive it first.']);
            }
            $balance = StockBalance::firstOrCreate(
                ['warehouse_id' => $warehouseId, 'batch_id' => $batchIdForShort],
                ['product_id' => $productId, 'qty_base' => 0, 'avg_unit_cost' => 0],
            );
            $parts->push(['balance' => $balance, 'qty' => $need, 'negative' => true]);
        }

        return $parts;
    }

    /** Applies a signed movement and updates the balance projection (weighted average cost on receipts). */
    public function move(array $attrs): StockMovement
    {
        $allowNegative = (bool) ($attrs['allow_negative'] ?? false);
        unset($attrs['allow_negative']);
        $qty = (int) $attrs['qty_base'];
        $balance = StockBalance::where('warehouse_id', $attrs['warehouse_id'])
            ->where('batch_id', $attrs['batch_id'])
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $balance = StockBalance::create([
                'warehouse_id' => $attrs['warehouse_id'], 'product_id' => $attrs['product_id'],
                'batch_id' => $attrs['batch_id'], 'qty_base' => 0, 'avg_unit_cost' => 0,
            ]);
        }

        $newQty = $balance->qty_base + $qty;
        if ($newQty < 0 && ! $allowNegative) {
            throw ValidationException::withMessages(['stock' => 'Stock would become negative for batch '.$attrs['batch_id'].'.']);
        }

        if ($qty > 0 && isset($attrs['unit_cost'])) {
            $oldValue = max(0, $balance->qty_base) * (float) $balance->avg_unit_cost;
            $balance->avg_unit_cost = $newQty > 0 ? round(($oldValue + $qty * (float) $attrs['unit_cost']) / $newQty, 4) : $attrs['unit_cost'];
        }
        $balance->qty_base = $newQty;
        $balance->save();

        return StockMovement::create($attrs + ['unit_cost' => $balance->avg_unit_cost, 'created_at' => now()]);
    }
}
