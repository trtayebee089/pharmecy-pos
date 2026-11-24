<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a supplier purchase. status=received posts stock immediately: each
 * line finds or creates its batch (product + batch no), adds a purchase stock
 * movement at landed unit cost (free goods dilute the cost), and the supplier
 * ledger is credited. status=ordered only stores the purchase order.
 */
class PurchaseService
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumbers $numbers,
        private Pricing $pricing,
    ) {}

    public function create(User $user, array $data): Purchase
    {
        $warehouse = Warehouse::where('is_active', true)->findOrFail($data['warehouseId']);
        if (! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        $supplier = Supplier::where('is_active', true)->find($data['supplierId']);
        if (! $supplier) {
            throw ValidationException::withMessages(['supplierId' => 'Supplier not found.']);
        }
        $receiving = $data['status'] === 'received';
        $units = ProductUnit::whereIn('id', array_column($data['lines'], 'unitId'))->get()->keyBy('id');
        $today = now()->toDateString();

        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            $unit = $units->get($l['unitId']);
            if (! $unit || $unit->product_id !== (int) $l['productId']) {
                throw ValidationException::withMessages(["lines.$i.unitId" => 'Unit does not belong to the product.']);
            }
            if ($receiving) {
                if (empty($l['batchNo'])) {
                    throw ValidationException::withMessages(["lines.$i.batchNo" => 'Batch number is required to receive stock.']);
                }
                if (empty($l['expiryDate']) || $l['expiryDate'] <= $today) {
                    throw ValidationException::withMessages(["lines.$i.expiryDate" => 'Expiry date must be in the future.']);
                }
            }
            $qtyBase = $l['qty'] * $unit->factor;
            $freeBase = ($l['freeQty'] ?? 0) * $unit->factor;
            if (abs($qtyBase - round($qtyBase)) > 1e-9 || abs($freeBase - round($freeBase)) > 1e-9) {
                throw ValidationException::withMessages(["lines.$i.qty" => 'Quantity must be a whole number of pieces.']);
            }
            $gross = SaleService::paisa($l['unitCost']) * $l['qty'];
            $total = (int) round($gross * (1 - ($l['discountPct'] ?? 0) / 100));
            $lines[] = $l + ['unit' => $unit, 'qtyBase' => (int) round($qtyBase), 'freeBase' => (int) round($freeBase), 'total' => $total];
        }

        $subtotal = array_sum(array_column($lines, 'total'));
        $grand = $subtotal + SaleService::paisa($data['otherCharges'] ?? 0);
        // Advance payments on orders are not supported: money is only posted with a received purchase.
        $paid = $receiving ? min($grand, SaleService::paisa($data['paidAmount'] ?? 0)) : 0;
        $method = null;
        if ($paid > 0) {
            $method = PaymentMethod::where('is_active', true)->where('code', $data['paymentMethod'] ?? 'cash')->first();
            if (! $method) {
                throw ValidationException::withMessages(['paymentMethod' => 'Payment method is not enabled.']);
            }
        }
        if (collect($lines)->contains(fn ($l) => ($l['updateRetailPrice'] ?? null) !== null) && ! $user->can('products.price')) {
            throw ValidationException::withMessages(['lines' => 'You are not allowed to change selling prices.']);
        }

        return DB::transaction(function () use ($user, $warehouse, $supplier, $data, $lines, $subtotal, $grand, $paid, $method, $receiving) {
            $order = null;
            if (! empty($data['orderId'])) {
                $order = Purchase::whereKey($data['orderId'])->lockForUpdate()->first();
                if (! $receiving || ! $order || $order->status !== 'ordered' || $order->supplier_id !== $supplier->id || $order->warehouse_id !== $warehouse->id) {
                    throw ValidationException::withMessages(['orderId' => 'This purchase order is not open for this supplier and warehouse.']);
                }
            }
            $purchase = Purchase::create([
                'order_id' => $order?->id,
                'ulid' => (string) Str::ulid(),
                'number' => $this->numbers->next($receiving ? 'purchase' : 'purchase_order', $warehouse),
                'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $data['supplierInvoiceNo'] ?? null, 'invoice_date' => $data['invoiceDate'] ?? now()->toDateString(),
                'received_at' => $receiving ? now() : null, 'status' => $data['status'],
                'subtotal' => SaleService::money($subtotal), 'other_charges' => $data['otherCharges'] ?? 0,
                'grand_total' => SaleService::money($grand), 'paid_total' => SaleService::money($paid),
                'due_total' => SaleService::money($grand - $paid), 'note' => $data['note'] ?? null, 'created_by' => $user->id,
            ]);

            foreach ($lines as $i => $l) {
                $batchId = null;
                if ($receiving) {
                    $batch = Batch::firstOrCreate(
                        ['product_id' => $l['productId'], 'batch_no' => strtoupper(trim($l['batchNo']))],
                        ['expiry_date' => $l['expiryDate'], 'status' => 'active'],
                    );
                    if ($batch->expiry_date && $batch->expiry_date->toDateString() !== $l['expiryDate']) {
                        throw ValidationException::withMessages(["lines.$i.expiryDate" => "Batch {$batch->batch_no} already exists with expiry {$batch->expiry_date->toDateString()}."]);
                    }
                    $batchId = $batch->id;
                }
                $item = PurchaseItem::create([
                    'purchase_id' => $purchase->id, 'product_id' => $l['productId'], 'product_unit_id' => $l['unitId'],
                    'qty' => $l['qty'], 'factor' => $l['unit']->factor, 'qty_base' => $l['qtyBase'], 'free_qty_base' => $l['freeBase'],
                    'batch_no' => $l['batchNo'] ?? null, 'expiry_date' => $l['expiryDate'] ?? null,
                    'unit_cost' => $l['unitCost'], 'discount_pct' => $l['discountPct'] ?? 0,
                    'line_total' => SaleService::money($l['total']), 'batch_id' => $batchId,
                ]);
                if ($receiving) {
                    $pieces = $l['qtyBase'] + $l['freeBase'];
                    $this->stock->move([
                        'warehouse_id' => $warehouse->id, 'product_id' => $l['productId'], 'batch_id' => $batchId,
                        'qty_base' => $pieces, 'type' => 'purchase', 'unit_cost' => round($l['total'] / 100 / max(1, $pieces), 4),
                        'source_type' => 'purchase', 'source_id' => $purchase->id, 'source_line_id' => $item->id, 'created_by' => $user->id,
                    ]);
                    if (($l['updateRetailPrice'] ?? null) !== null) {
                        $this->pricing->setPrice($l['unitId'], (float) $l['updateRetailPrice'], $user->id);
                    }
                }
            }

            if ($receiving) {
                PartyLedgerEntry::create([
                    'party_type' => 'supplier', 'party_id' => $supplier->id, 'entry_date' => now()->toDateString(), 'type' => 'invoice',
                    'debit' => 0, 'credit' => SaleService::money($grand), 'source_type' => 'purchase', 'source_id' => $purchase->id,
                    'note' => $purchase->number, 'created_by' => $user->id,
                ]);
                if ($paid > 0) {
                    $payment = Payment::create([
                        'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', $warehouse),
                        'direction' => 'out', 'party_type' => 'supplier', 'party_id' => $supplier->id,
                        'payment_method_id' => $method->id, 'account_id' => $method->account_id,
                        'amount' => SaleService::money($paid), 'paid_at' => now(), 'created_by' => $user->id,
                    ]);
                    PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'purchase', 'allocatable_id' => $purchase->id, 'amount' => SaleService::money($paid)]);
                    if ($method->account_id) {
                        AccountTransaction::create([
                            'account_id' => $method->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'out',
                            'amount' => SaleService::money($paid), 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $purchase->number,
                        ]);
                    }
                    PartyLedgerEntry::create([
                        'party_type' => 'supplier', 'party_id' => $supplier->id, 'entry_date' => now()->toDateString(), 'type' => 'payment',
                        'debit' => SaleService::money($paid), 'credit' => 0, 'source_type' => 'purchase', 'source_id' => $purchase->id,
                        'note' => $purchase->number, 'created_by' => $user->id,
                    ]);
                }
            }

            // The order is closed by this receipt: the GRN carries the stock, cost and supplier due.
            $order?->update(['status' => 'fulfilled', 'received_at' => now(), 'due_total' => 0]);

            return $purchase;
        });
    }

    /**
     * Cancels an order, or voids a received purchase. A received purchase can
     * only be voided while all its pieces are still in their batches and before
     * any payment; otherwise a purchase return is the correct document.
     */
    public function void(User $user, Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $reason) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status === 'void') {
                throw ValidationException::withMessages(['purchase' => 'This purchase is already void.']);
            }
            if ($purchase->status === 'fulfilled') {
                throw ValidationException::withMessages(['purchase' => 'This order was received. Void its goods receipt instead.']);
            }
            if ($purchase->status === 'received') {
                if ((float) $purchase->paid_total > 0) {
                    throw ValidationException::withMessages(['purchase' => 'This purchase has supplier payments. Use a purchase return instead.']);
                }
                foreach ($purchase->items()->get() as $item) {
                    $pieces = $item->qty_base + $item->free_qty_base;
                    $inStock = (int) StockBalance::where(['warehouse_id' => $purchase->warehouse_id, 'batch_id' => $item->batch_id])->lockForUpdate()->value('qty_base');
                    if ($inStock < $pieces) {
                        throw ValidationException::withMessages(['purchase' => "Only {$inStock} of {$pieces} pieces of batch {$item->batch_no} are still in stock. Use a purchase return instead."]);
                    }
                    $this->stock->move([
                        'warehouse_id' => $purchase->warehouse_id, 'product_id' => $item->product_id, 'batch_id' => $item->batch_id,
                        'qty_base' => -$pieces, 'type' => 'purchase_void', 'source_type' => 'purchase', 'source_id' => $purchase->id,
                        'source_line_id' => $item->id, 'reason' => $reason, 'created_by' => $user->id,
                    ]);
                }
                PartyLedgerEntry::create([
                    'party_type' => 'supplier', 'party_id' => $purchase->supplier_id, 'entry_date' => now()->toDateString(), 'type' => 'void',
                    'debit' => $purchase->grand_total, 'credit' => 0, 'source_type' => 'purchase', 'source_id' => $purchase->id,
                    'note' => 'Void '.$purchase->number, 'created_by' => $user->id,
                ]);
                // The order this GRN fulfilled becomes open again.
                if ($purchase->order_id) {
                    $order = Purchase::find($purchase->order_id);
                    $order?->update(['status' => 'ordered', 'received_at' => null, 'due_total' => $order->grand_total]);
                }
            }
            $purchase->update(['status' => 'void', 'due_total' => 0, 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'purchase.voided', 'purchase', $purchase->id, ['reason' => $reason]);

            return $purchase;
        });
    }

    /** Copies a purchase as a new open order (no batches, expiry or payments). */
    public function duplicate(User $user, Purchase $source): Purchase
    {
        $lines = $source->items()->get()->map(fn ($i) => [
            'productId' => $i->product_id, 'unitId' => $i->product_unit_id, 'qty' => (float) $i->qty,
            'freeQty' => $i->factor > 0 ? $i->free_qty_base / $i->factor : 0, 'unitCost' => (float) $i->unit_cost, 'discountPct' => (float) $i->discount_pct,
        ])->all();

        return $this->create($user, [
            'status' => 'ordered', 'warehouseId' => $source->warehouse_id, 'supplierId' => $source->supplier_id,
            'otherCharges' => 0, 'note' => 'Copy of '.$source->number, 'lines' => $lines,
        ]);
    }
}
