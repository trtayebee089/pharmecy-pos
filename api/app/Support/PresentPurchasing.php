<?php

namespace App\Support;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier and purchase shapes for the POS (SupplierRow, SupplierLedgerEntry
 * in pos/app/types/inventory.ts; PurchaseSummary, PurchaseDetail).
 * The POS calls an open purchase order "draft" and a fulfilled one "closed".
 */
class PresentPurchasing
{
    public static function status(string $status): string
    {
        return match ($status) {
            'ordered' => 'draft',
            'fulfilled' => 'closed',
            default => $status,
        };
    }

    /** @param  Collection<int, Supplier>  $suppliers */
    public static function supplierRows(Collection $suppliers): array
    {
        $ids = $suppliers->pluck('id');
        $balances = DB::table('party_ledger_entries')->where('party_type', 'supplier')->whereIn('party_id', $ids)
            ->groupBy('party_id')->selectRaw('party_id, SUM(credit) - SUM(debit) AS bal')->pluck('bal', 'party_id');
        $stats = DB::table('purchases')->where('status', 'received')->whereIn('supplier_id', $ids)
            ->groupBy('supplier_id')->selectRaw('supplier_id, COUNT(*) AS n, MAX(received_at) AS last')->get()->keyBy('supplier_id');

        return $suppliers->map(fn (Supplier $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'companyName' => (string) $s->company_name,
            'phone' => (string) $s->phone, 'email' => (string) $s->email, 'address' => (string) $s->address,
            'paymentTermsDays' => (int) $s->payment_terms_days,
            'dueBalance' => round((float) ($balances[$s->id] ?? 0), 2),
            'purchases' => (int) ($stats[$s->id]->n ?? 0),
            'lastPurchase' => isset($stats[$s->id]->last) ? substr((string) $stats[$s->id]->last, 0, 10) : null,
            'isActive' => (bool) $s->is_active,
        ])->values()->all();
    }

    /** Running statement, oldest first. increase = we owe more; decrease = paid, returned or voided. */
    public static function supplierLedger(Supplier $supplier): array
    {
        $balance = 0.0;

        return DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplier->id])
            ->orderBy('entry_date')->orderBy('id')->get()
            ->map(function ($e) use (&$balance) {
                $balance = round($balance + (float) $e->credit - (float) $e->debit, 2);

                return [
                    'id' => $e->id, 'date' => substr((string) $e->entry_date, 0, 10), 'type' => $e->type, 'ref' => (string) $e->note,
                    'increase' => (float) $e->credit, 'decrease' => (float) $e->debit, 'balance' => $balance,
                ];
            })->all();
    }

    public static function purchaseSummary(Purchase $p): array
    {
        return [
            'id' => $p->id, 'number' => $p->number,
            'date' => ($p->received_at ?? $p->invoice_date ?? $p->created_at)->toDateString(),
            'supplier' => $p->supplier?->name ?? '', 'supplierInvoiceNo' => (string) $p->supplier_invoice_no,
            'items' => (int) ($p->items_count ?? $p->items()->count()),
            'grandTotal' => (float) $p->grand_total, 'paidTotal' => (float) $p->paid_total, 'dueTotal' => (float) $p->due_total,
            'status' => self::status($p->status),
        ];
    }

    public static function purchaseDetail(Purchase $p): array
    {
        $p->loadMissing('items.unit', 'items.product', 'supplier', 'warehouse', 'creator');
        $payments = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where(['payment_allocations.allocatable_type' => 'purchase', 'payment_allocations.allocatable_id' => $p->id])
            ->where('payments.status', 'posted')->orderBy('payments.paid_at')
            ->get(['payments.number', 'payments.paid_at', 'payment_methods.name', 'payment_allocations.amount', 'payments.reference']);

        return [
            'id' => $p->id, 'number' => $p->number, 'status' => self::status($p->status),
            'supplierId' => $p->supplier_id, 'supplier' => $p->supplier?->name ?? '', 'supplierInvoiceNo' => (string) $p->supplier_invoice_no,
            'receivedAt' => ($p->received_at ?? $p->created_at)->toIso8601String(),
            'warehouse' => $p->warehouse?->name ?? '', 'receivedBy' => $p->creator?->name ?? '',
            'orderNumber' => $p->order_id ? Purchase::whereKey($p->order_id)->value('number') : null,
            'lines' => $p->items->map(fn ($i) => [
                'id' => $i->id, 'productId' => $i->product_id, 'unitId' => $i->product_unit_id,
                'product' => trim(($i->product?->name ?? '').' '.($i->product?->strength ?? '')), 'unitLabel' => $i->unit?->label ?? '',
                'qty' => (float) $i->qty, 'freeQty' => $i->factor > 0 ? $i->free_qty_base / $i->factor : 0,
                'pieces' => $i->qty_base + $i->free_qty_base, 'batchNo' => (string) $i->batch_no,
                'expiryDate' => $i->expiry_date?->toDateString() ?? '', 'unitCost' => (float) $i->unit_cost,
                'discountPct' => (float) $i->discount_pct, 'lineTotal' => (float) $i->line_total,
            ])->all(),
            'subtotal' => (float) $p->subtotal, 'otherCharges' => (float) $p->other_charges,
            'grandTotal' => (float) $p->grand_total, 'paidTotal' => (float) $p->paid_total, 'dueTotal' => (float) $p->due_total,
            'payments' => $payments->map(fn ($x) => [
                'number' => $x->number, 'date' => Carbon::parse($x->paid_at)->toIso8601String(), 'method' => $x->name,
                'amount' => (float) $x->amount, 'reference' => $x->reference,
            ])->all(),
            'returnedTotal' => (float) $p->returned_total,
            'returns' => self::returnsFor($p->id),
        ];
    }

    /** Posted returns that took stock from this purchase or reduced its due. */
    private static function returnsFor(int $purchaseId): array
    {
        $viaItems = DB::table('purchase_return_items')->join('purchase_items', 'purchase_items.id', '=', 'purchase_return_items.purchase_item_id')
            ->where('purchase_items.purchase_id', $purchaseId)->pluck('purchase_return_items.purchase_return_id');
        $viaAlloc = DB::table('purchase_return_allocations')->where('purchase_id', $purchaseId)->pluck('purchase_return_id');

        return PurchaseReturn::whereIn('id', $viaItems->merge($viaAlloc)->unique())->where('status', 'posted')->orderBy('id')->get()
            ->map(fn ($r) => ['number' => $r->number, 'date' => $r->created_at->toIso8601String(), 'total' => (float) $r->total])->all();
    }

    /** PurchaseReturnSummary in pos/app/types/inventory.ts. */
    public static function purchaseReturn(PurchaseReturn $r): array
    {
        $r->loadMissing('supplier');

        return [
            'id' => $r->id, 'number' => $r->number, 'date' => $r->created_at->toIso8601String(),
            'supplier' => $r->supplier?->name ?? '', 'creditNote' => (string) $r->credit_note_no,
            'lines' => (int) ($r->items_count ?? $r->items()->count()), 'qty' => (int) $r->qty_base,
            'total' => (float) $r->total, 'reason' => $r->reason, 'settlement' => $r->settlement, 'status' => $r->status,
        ];
    }
}
