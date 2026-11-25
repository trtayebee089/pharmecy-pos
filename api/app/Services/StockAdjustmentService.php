<?php

namespace App\Services;

use App\Etl\Legacy\LegacyRecords;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock changes against specific batches, with a reason and a note.
 *
 * - damage, lost: pieces out. found: pieces in. correction: either way.
 * - expiry: pieces out of batches that have actually expired (write-off).
 * - Out never exceeds the batch stock in the warehouse; in is valued at the
 *   batch's current cost (or its last known cost elsewhere).
 * - Void reverses every movement on the same batch; voiding stock that came
 *   in fails if those pieces were sold or moved since.
 */
class StockAdjustmentService
{
    public const MOVEMENT = ['damage' => 'damage', 'expiry' => 'expiry_writeoff', 'lost' => 'adjustment', 'found' => 'adjustment', 'correction' => 'adjustment'];

    public function __construct(private StockLedger $stock, private DocumentNumbers $numbers) {}

    public function create(User $user, array $data): StockAdjustment
    {
        $warehouse = Warehouse::where('is_active', true)->find($data['warehouseId']);
        if (! $warehouse || ! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        $reason = $data['reason'];
        $today = now()->toDateString();

        $lines = [];
        foreach ($data['items'] as $i => $l) {
            $qty = (int) $l['qtyBase'];
            $batch = Batch::where('product_id', $l['productId'])->find($l['batchId']);
            if (! $batch) {
                throw ValidationException::withMessages(["items.$i.batchId" => 'The selected batch does not belong to this product.']);
            }
            $wrongWay = match ($reason) {
                'damage', 'lost', 'expiry' => $qty > 0,
                'found' => $qty < 0,
                default => false,
            };
            if ($qty === 0 || $wrongWay) {
                throw ValidationException::withMessages(["items.$i.qtyBase" => $reason === 'found' ? 'Found stock can only be added.' : ($qty === 0 ? 'Quantity cannot be 0.' : 'This reason can only remove stock.')]);
            }
            if ($reason === 'expiry' && (! $batch->expiry_date || $batch->expiry_date->toDateString() >= $today)) {
                throw ValidationException::withMessages(["items.$i.batchId" => "Batch {$batch->batch_no} has not expired. Use damage or correction instead."]);
            }
            $lines[] = ['batch' => $batch, 'productId' => (int) $l['productId'], 'qty' => $qty];
        }

        return DB::transaction(function () use ($user, $warehouse, $reason, $data, $lines) {
            $out = [];
            foreach ($lines as $l) {
                if ($l['qty'] < 0) {
                    $out[$l['batch']->id] = ($out[$l['batch']->id] ?? 0) - $l['qty'];
                }
            }
            $balances = StockBalance::where('warehouse_id', $warehouse->id)->whereIn('batch_id', array_column(array_column($lines, 'batch'), 'id'))
                ->lockForUpdate()->get()->keyBy('batch_id');
            foreach ($lines as $i => $l) {
                $have = (int) ($balances[$l['batch']->id]->qty_base ?? 0);
                if ($l['qty'] < 0 && $out[$l['batch']->id] > $have) {
                    throw ValidationException::withMessages(["items.$i.qtyBase" => "Only {$have} pieces of batch {$l['batch']->batch_no} are in stock."]);
                }
            }

            $adj = StockAdjustment::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('stock_adjustment', $warehouse),
                'warehouse_id' => $warehouse->id, 'reason' => $reason, 'note' => trim($data['note']), 'status' => 'posted', 'created_by' => $user->id,
            ]);
            $in = $outQty = 0;
            $value = 0;
            foreach ($lines as $l) {
                $cost = isset($balances[$l['batch']->id]) && $balances[$l['batch']->id]->qty_base > 0
                    ? (float) $balances[$l['batch']->id]->avg_unit_cost
                    : (float) (StockBalance::where('batch_id', $l['batch']->id)->where('avg_unit_cost', '>', 0)->value('avg_unit_cost') ?? 0);
                $item = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adj->id, 'product_id' => $l['productId'], 'batch_id' => $l['batch']->id,
                    'qty_base' => $l['qty'], 'unit_cost' => $cost,
                ]);
                $this->stock->move([
                    'warehouse_id' => $warehouse->id, 'product_id' => $l['productId'], 'batch_id' => $l['batch']->id,
                    'qty_base' => $l['qty'], 'type' => self::MOVEMENT[$reason], 'unit_cost' => $cost,
                    'source_type' => 'stock_adjustment', 'source_id' => $adj->id, 'source_line_id' => $item->id,
                    'reason' => $data['note'], 'created_by' => $user->id,
                ]);
                $l['qty'] > 0 ? $in += $l['qty'] : $outQty -= $l['qty'];
                $value += (int) round(abs($l['qty']) * $cost * 100);
            }
            $adj->update(['qty_in' => $in, 'qty_out' => $outQty, 'value' => SaleService::money($value)]);
            AuditLog::record($user->id, 'stock_adjustment.posted', 'stock_adjustment', $adj->id, ['number' => $adj->number, 'reason' => $reason]);

            return $adj;
        });
    }

    public function void(User $user, StockAdjustment $adj, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($user, $adj, $reason) {
            $adj = StockAdjustment::whereKey($adj->id)->lockForUpdate()->firstOrFail();
            if ($adj->status !== 'posted') {
                throw ValidationException::withMessages(['adjustment' => 'This adjustment is already void.']);
            }
            LegacyRecords::refuseVoid(LegacyRecords::is('stock_adjustment', $adj->id), 'adjustment', 'adjustment');
            foreach ($adj->items()->with('batch')->get() as $item) {
                $have = (int) StockBalance::where(['warehouse_id' => $adj->warehouse_id, 'batch_id' => $item->batch_id])->lockForUpdate()->value('qty_base');
                if ($item->qty_base > 0 && $have < $item->qty_base) {
                    throw ValidationException::withMessages(['adjustment' => "Only {$have} of the {$item->qty_base} pieces added to batch {$item->batch?->batch_no} are still in stock."]);
                }
                $this->stock->move([
                    'warehouse_id' => $adj->warehouse_id, 'product_id' => $item->product_id, 'batch_id' => $item->batch_id,
                    'qty_base' => -$item->qty_base, 'type' => 'adjustment_void', 'unit_cost' => (float) $item->unit_cost,
                    'source_type' => 'stock_adjustment', 'source_id' => $adj->id, 'source_line_id' => $item->id,
                    'reason' => $reason, 'created_by' => $user->id,
                ]);
            }
            $adj->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'stock_adjustment.voided', 'stock_adjustment', $adj->id, ['reason' => $reason]);

            return $adj;
        });
    }
}
