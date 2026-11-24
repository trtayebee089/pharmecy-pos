<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock moved between warehouses batch by batch.
 *
 * - send: transfer_out from the source at the batch cost; the pieces are in
 *   transit (in neither warehouse) until received. Only sellable stock moves:
 *   expired or blocked batches are written off or returned where they are.
 * - receive: transfer_in at the destination at the same cost.
 * - void: only while in transit; the pieces go back to the source.
 */
class StockTransferService
{
    public function __construct(private StockLedger $stock, private DocumentNumbers $numbers) {}

    public function send(User $user, array $data): StockTransfer
    {
        $from = Warehouse::where('is_active', true)->find($data['fromWarehouseId']);
        $to = Warehouse::where('is_active', true)->find($data['toWarehouseId']);
        if (! $from || ! $user->canAccessWarehouse($from->id)) {
            throw ValidationException::withMessages(['fromWarehouseId' => 'You are not assigned to the sending warehouse.']);
        }
        if (! $to || $to->id === $from->id) {
            throw ValidationException::withMessages(['toWarehouseId' => 'Choose a different, active destination warehouse.']);
        }
        $today = now()->toDateString();

        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            $batch = Batch::where('product_id', $l['productId'])->find($l['batchId']);
            if (! $batch) {
                throw ValidationException::withMessages(["lines.$i.batchId" => 'The selected batch does not belong to this product.']);
            }
            $expired = $batch->expiry_date ? $batch->expiry_date->toDateString() < $today : ! $batch->is_legacy_opening;
            if ($expired || $batch->status !== 'active') {
                throw ValidationException::withMessages(["lines.$i.batchId" => "Batch {$batch->batch_no} is not sellable (expired or blocked) and cannot be transferred."]);
            }
            $lines[] = ['batch' => $batch, 'productId' => (int) $l['productId'], 'qty' => (int) $l['qtyBase']];
        }

        return DB::transaction(function () use ($user, $from, $to, $data, $lines) {
            $wanted = [];
            foreach ($lines as $l) {
                $wanted[$l['batch']->id] = ($wanted[$l['batch']->id] ?? 0) + $l['qty'];
            }
            $balances = StockBalance::where('warehouse_id', $from->id)->whereIn('batch_id', array_keys($wanted))->lockForUpdate()->get()->keyBy('batch_id');
            foreach ($lines as $i => $l) {
                $have = (int) ($balances[$l['batch']->id]->qty_base ?? 0);
                if ($wanted[$l['batch']->id] > $have) {
                    throw ValidationException::withMessages(["lines.$i.qtyBase" => "Only {$have} pieces of batch {$l['batch']->batch_no} are in {$from->name}."]);
                }
            }

            $transfer = StockTransfer::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('stock_transfer', $from),
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'status' => 'sent',
                'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
                'qty_base' => array_sum(array_column($lines, 'qty')), 'created_by' => $user->id,
            ]);
            foreach ($lines as $l) {
                $cost = (float) $balances[$l['batch']->id]->avg_unit_cost;
                $item = StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id, 'product_id' => $l['productId'], 'batch_id' => $l['batch']->id,
                    'qty_base' => $l['qty'], 'unit_cost' => $cost,
                ]);
                $this->move($transfer, $item, $from->id, -$l['qty'], 'transfer_out', $user->id);
            }
            AuditLog::record($user->id, 'stock_transfer.sent', 'stock_transfer', $transfer->id, ['number' => $transfer->number]);

            return $transfer;
        });
    }

    public function receive(User $user, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($user, $transfer) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $user->canAccessWarehouse($transfer->to_warehouse_id)) {
                throw ValidationException::withMessages(['transfer' => 'Only staff of the receiving warehouse can receive this transfer.']);
            }
            if ($transfer->status !== 'sent') {
                throw ValidationException::withMessages(['transfer' => 'Only transfers in transit can be received.']);
            }
            foreach ($transfer->items()->get() as $item) {
                $this->move($transfer, $item, $transfer->to_warehouse_id, $item->qty_base, 'transfer_in', $user->id);
            }
            $transfer->update(['status' => 'received', 'received_by' => $user->id, 'received_at' => now()]);
            AuditLog::record($user->id, 'stock_transfer.received', 'stock_transfer', $transfer->id, null);

            return $transfer;
        });
    }

    public function void(User $user, StockTransfer $transfer, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $reason) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $user->canAccessWarehouse($transfer->from_warehouse_id)) {
                throw ValidationException::withMessages(['transfer' => 'Only staff of the sending warehouse can void this transfer.']);
            }
            if ($transfer->status !== 'sent') {
                throw ValidationException::withMessages(['transfer' => 'Only transfers in transit can be voided.']);
            }
            foreach ($transfer->items()->get() as $item) {
                $this->move($transfer, $item, $transfer->from_warehouse_id, $item->qty_base, 'transfer_void', $user->id, $reason);
            }
            $transfer->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'stock_transfer.voided', 'stock_transfer', $transfer->id, ['reason' => $reason]);

            return $transfer;
        });
    }

    private function move(StockTransfer $transfer, StockTransferItem $item, int $warehouseId, int $qty, string $type, int $userId, ?string $reason = null): void
    {
        $this->stock->move([
            'warehouse_id' => $warehouseId, 'product_id' => $item->product_id, 'batch_id' => $item->batch_id,
            'qty_base' => $qty, 'type' => $type, 'unit_cost' => (float) $item->unit_cost,
            'source_type' => 'stock_transfer', 'source_id' => $transfer->id, 'source_line_id' => $item->id,
            'reason' => $reason ?? $transfer->note, 'created_by' => $userId,
        ]);
    }
}
