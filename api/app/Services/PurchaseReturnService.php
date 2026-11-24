<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnItem;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock sent back to a supplier, per batch (expired batches included).
 *
 * - Each line is priced server-side at the batch's average cost in the
 *   warehouse; client costs are ignored. Quantity is capped by the batch stock.
 * - A batch received from other suppliers only cannot be returned to this one.
 *   Opening/legacy batches without a purchase may go to any supplier.
 * - The supplier ledger is debited with the credit. Settlement:
 *   reduce_due  the credit lowers open received invoices (each line's value
 *               on the purchase its batch came from, the rest on the oldest);
 *               anything beyond that stays on the ledger as supplier credit.
 *   refund      the supplier pays the credit back: an incoming payment into the
 *               method's account, and a ledger credit, so the balance is unchanged.
 * - Void puts the pieces back into their batches and reverses the ledger, the
 *   invoice reductions or the refund payment.
 */
class PurchaseReturnService
{
    public function __construct(private StockLedger $stock, private DocumentNumbers $numbers) {}

    public static function hasPostedReturns(int $purchaseId): bool
    {
        $viaItems = PurchaseReturnItem::query()
            ->join('purchase_items', 'purchase_items.id', '=', 'purchase_return_items.purchase_item_id')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_items.purchase_id', $purchaseId)->where('purchase_returns.status', 'posted')->exists();

        return $viaItems || PurchaseReturnAllocation::query()
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_allocations.purchase_return_id')
            ->where('purchase_return_allocations.purchase_id', $purchaseId)->where('purchase_returns.status', 'posted')->exists();
    }

    public function create(User $user, array $data): PurchaseReturn
    {
        $warehouse = Warehouse::where('is_active', true)->find($data['warehouseId']);
        if (! $warehouse || ! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        $supplier = Supplier::find($data['supplierId']);
        if (! $supplier) {
            throw ValidationException::withMessages(['supplierId' => 'Supplier not found.']);
        }
        $settlement = $data['settlement'];
        $method = null;
        if ($settlement === 'refund') {
            $method = PaymentMethod::where('is_active', true)->where('code', $data['refundMethod'] ?? 'cash')->first();
            if (! $method || $method->code === 'customer_credit') {
                throw ValidationException::withMessages(['refundMethod' => 'Payment method is not enabled.']);
            }
            if ($method->requires_reference && empty($data['reference'])) {
                throw ValidationException::withMessages(['reference' => "{$method->name} needs a transaction reference."]);
            }
        }

        // Validate batches and find the receipt each batch came from (for this supplier).
        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            $batch = Batch::where('product_id', $l['productId'])->find($l['batchId']);
            if (! $batch) {
                throw ValidationException::withMessages(["lines.$i.batchId" => 'The selected batch does not belong to this product.']);
            }
            $receipts = PurchaseItem::query()->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->where('purchase_items.batch_id', $batch->id)->where('purchases.status', 'received')
                ->orderByRaw('CASE WHEN purchases.warehouse_id = ? THEN 0 ELSE 1 END', [$warehouse->id])
                ->orderByDesc('purchase_items.id')
                ->get(['purchase_items.id', 'purchase_items.purchase_id', 'purchases.supplier_id']);
            $fromSupplier = $receipts->firstWhere('supplier_id', $supplier->id);
            if ($receipts->isNotEmpty() && ! $fromSupplier) {
                $other = Supplier::whereKey($receipts->first()->supplier_id)->value('name');
                throw ValidationException::withMessages(["lines.$i.batchId" => "Batch {$batch->batch_no} was bought from {$other}, not {$supplier->name}."]);
            }
            $lines[] = ['batch' => $batch, 'productId' => (int) $l['productId'], 'qty' => (int) $l['qtyBase'],
                'purchaseItemId' => $fromSupplier?->id, 'purchaseId' => $fromSupplier?->purchase_id];
        }

        return DB::transaction(function () use ($user, $warehouse, $supplier, $data, $lines, $settlement, $method) {
            // Lock each batch balance once and check the total requested per batch.
            $wanted = [];
            foreach ($lines as $l) {
                $wanted[$l['batch']->id] = ($wanted[$l['batch']->id] ?? 0) + $l['qty'];
            }
            $balances = StockBalance::where('warehouse_id', $warehouse->id)->whereIn('batch_id', array_keys($wanted))
                ->lockForUpdate()->get()->keyBy('batch_id');
            foreach ($lines as $i => $l) {
                $have = (int) ($balances[$l['batch']->id]->qty_base ?? 0);
                if ($wanted[$l['batch']->id] > $have) {
                    throw ValidationException::withMessages(["lines.$i.qtyBase" => "Only {$have} pieces of batch {$l['batch']->batch_no} are in stock."]);
                }
            }

            $return = PurchaseReturn::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('purchase_return', $warehouse),
                'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'status' => 'posted', 'settlement' => $settlement,
                'credit_note_no' => ($data['creditNote'] ?? '') !== '' ? $data['creditNote'] : null, 'reason' => $data['reason'],
                'total' => 0, 'qty_base' => array_sum(array_column($lines, 'qty')), 'created_by' => $user->id,
            ]);

            $total = 0;
            $bySource = [];
            foreach ($lines as $l) {
                $cost = (float) $balances[$l['batch']->id]->avg_unit_cost;
                $lineTotal = (int) round($l['qty'] * $cost * 100);
                $total += $lineTotal;
                if ($l['purchaseId']) {
                    $bySource[$l['purchaseId']] = ($bySource[$l['purchaseId']] ?? 0) + $lineTotal;
                }
                $item = PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id, 'product_id' => $l['productId'], 'batch_id' => $l['batch']->id,
                    'purchase_item_id' => $l['purchaseItemId'], 'qty_base' => $l['qty'], 'unit_cost' => $cost,
                    'line_total' => SaleService::money($lineTotal),
                ]);
                $this->stock->move([
                    'warehouse_id' => $warehouse->id, 'product_id' => $l['productId'], 'batch_id' => $l['batch']->id,
                    'qty_base' => -$l['qty'], 'type' => 'purchase_return', 'unit_cost' => $cost,
                    'source_type' => 'purchase_return', 'source_id' => $return->id, 'source_line_id' => $item->id,
                    'reason' => $data['reason'], 'created_by' => $user->id,
                ]);
            }
            $money = SaleService::money($total);
            $return->update(['total' => $money]);

            if ($total > 0) {
                $this->ledger($supplier->id, 'return', debit: $money, credit: 0, sourceId: $return->id, note: $return->number, userId: $user->id);

                if ($settlement === 'reduce_due') {
                    $this->reduceInvoices($return, $supplier->id, $bySource, $total);
                } else {
                    $payment = Payment::create([
                        'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', $warehouse),
                        'direction' => 'in', 'party_type' => 'supplier', 'party_id' => $supplier->id,
                        'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money,
                        'reference' => $data['reference'] ?? null, 'paid_at' => now(), 'note' => 'Refund '.$return->number, 'created_by' => $user->id,
                    ]);
                    PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'purchase_return', 'allocatable_id' => $return->id, 'amount' => $money]);
                    if ($method->account_id) {
                        AccountTransaction::create([
                            'account_id' => $method->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'in',
                            'amount' => $money, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $return->number,
                        ]);
                    }
                    $this->ledger($supplier->id, 'refund', debit: 0, credit: $money, sourceId: $return->id, note: 'Refund '.$return->number, userId: $user->id);
                    $return->update(['payment_id' => $payment->id]);
                }
            }

            AuditLog::record($user->id, 'purchase_return.posted', 'purchase_return', $return->id, ['number' => $return->number, 'total' => $money, 'settlement' => $settlement]);

            return $return;
        });
    }

    public function void(User $user, PurchaseReturn $return, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($user, $return, $reason) {
            $return = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'posted') {
                throw ValidationException::withMessages(['purchaseReturn' => 'This return is already void.']);
            }

            foreach ($return->items()->get() as $item) {
                $this->stock->move([
                    'warehouse_id' => $return->warehouse_id, 'product_id' => $item->product_id, 'batch_id' => $item->batch_id,
                    'qty_base' => $item->qty_base, 'type' => 'purchase_return_void', 'unit_cost' => (float) $item->unit_cost,
                    'source_type' => 'purchase_return', 'source_id' => $return->id, 'source_line_id' => $item->id,
                    'reason' => $reason, 'created_by' => $user->id,
                ]);
            }

            if ((float) $return->total > 0) {
                $this->ledger($return->supplier_id, 'void', debit: 0, credit: $return->total, sourceId: $return->id, note: 'Void '.$return->number, userId: $user->id);

                foreach ($return->allocations()->get() as $a) {
                    $purchase = Purchase::whereKey($a->purchase_id)->lockForUpdate()->firstOrFail();
                    $amount = SaleService::paisa($a->amount);
                    $purchase->update([
                        'returned_total' => SaleService::money(SaleService::paisa($purchase->returned_total) - $amount),
                        'due_total' => SaleService::money(SaleService::paisa($purchase->due_total) + $amount),
                    ]);
                }

                if ($return->payment_id) {
                    $payment = Payment::whereKey($return->payment_id)->lockForUpdate()->firstOrFail();
                    $payment->update(['status' => 'void']);
                    if ($payment->account_id) {
                        AccountTransaction::create([
                            'account_id' => $payment->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'out',
                            'amount' => $payment->amount, 'source_type' => 'payment_void', 'source_id' => $payment->id, 'note' => 'Void '.$return->number,
                        ]);
                    }
                    $this->ledger($return->supplier_id, 'void', debit: $payment->amount, credit: 0, sourceId: $return->id, note: 'Void refund '.$return->number, userId: $user->id);
                }
            }

            $return->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'purchase_return.voided', 'purchase_return', $return->id, ['reason' => $reason]);

            return $return;
        });
    }

    /**
     * Lowers the due of open received invoices. Each line's value goes to the
     * invoice its batch came from; what is left goes to the oldest open
     * invoices; anything beyond that stays as supplier credit on the ledger.
     *
     * @param  array<int, int>  $bySource  purchase id => paisa of returned lines from it
     */
    private function reduceInvoices(PurchaseReturn $return, int $supplierId, array $bySource, int $paisa): void
    {
        $open = Purchase::where(['supplier_id' => $supplierId, 'status' => 'received'])->where('due_total', '>', 0)
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $taken = [];
        $left = $paisa;
        $apply = function (Purchase $p, int $want) use (&$taken, &$left) {
            $take = min($want, $left, SaleService::paisa($p->due_total) - ($taken[$p->id] ?? 0));
            if ($take > 0) {
                $taken[$p->id] = ($taken[$p->id] ?? 0) + $take;
                $left -= $take;
            }
        };
        foreach ($bySource as $purchaseId => $value) {
            if ($open->has($purchaseId)) {
                $apply($open[$purchaseId], $value);
            }
        }
        foreach ($open as $p) {
            if ($left <= 0) {
                break;
            }
            $apply($p, $left);
        }

        foreach ($taken as $purchaseId => $take) {
            $p = $open[$purchaseId];
            $p->update([
                'returned_total' => SaleService::money(SaleService::paisa($p->returned_total) + $take),
                'due_total' => SaleService::money(SaleService::paisa($p->due_total) - $take),
            ]);
            PurchaseReturnAllocation::create(['purchase_return_id' => $return->id, 'purchase_id' => $p->id, 'amount' => SaleService::money($take)]);
        }
    }

    private function ledger(int $supplierId, string $type, float|string $debit, float|string $credit, int $sourceId, string $note, int $userId): void
    {
        PartyLedgerEntry::create([
            'party_type' => 'supplier', 'party_id' => $supplierId, 'entry_date' => now()->toDateString(), 'type' => $type,
            'debit' => $debit, 'credit' => $credit, 'source_type' => 'purchase_return', 'source_id' => $sourceId,
            'note' => $note, 'created_by' => $userId,
        ]);
    }
}
