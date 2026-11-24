<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Money paid to suppliers. A payment against one invoice is capped at its due;
 * a payment on the supplier account is allocated to the oldest open received
 * purchases first and may not exceed the total owed. Each payment posts an
 * outgoing account transaction and a debit on the supplier ledger.
 */
class SupplierPayments
{
    public function __construct(private DocumentNumbers $numbers) {}

    /** Balance we owe the supplier (credit − debit on their ledger). */
    public static function balance(int $supplierId): float
    {
        $row = DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplierId])
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS bal')->first();

        return round((float) $row->bal, 2);
    }

    public function payInvoice(User $user, Purchase $purchase, float $amount, string $methodCode, ?string $reference): Payment
    {
        return DB::transaction(function () use ($user, $purchase, $amount, $methodCode, $reference) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== 'received') {
                throw ValidationException::withMessages(['amount' => 'Only received purchases can be paid.']);
            }
            $paisa = SaleService::paisa($amount);
            if ($paisa <= 0 || $paisa > SaleService::paisa($purchase->due_total)) {
                throw ValidationException::withMessages(['amount' => 'Amount must be more than 0 and at most the due of '.$purchase->due_total.'.']);
            }

            return $this->post($user, $purchase->supplier_id, $purchase->warehouse_id, [[$purchase, $paisa]], $paisa, $methodCode, $reference, null, null);
        });
    }

    public function payAccount(User $user, Supplier $supplier, float $amount, string $methodCode, ?string $reference, ?string $paidAt, ?string $note): Payment
    {
        return DB::transaction(function () use ($user, $supplier, $amount, $methodCode, $reference, $paidAt, $note) {
            $open = Purchase::where(['supplier_id' => $supplier->id, 'status' => 'received'])->where('due_total', '>', 0)
                ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
                ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
            $paisa = SaleService::paisa($amount);
            $owed = SaleService::paisa(self::balance($supplier->id));
            if ($paisa <= 0 || $paisa > $owed) {
                throw ValidationException::withMessages(['amount' => 'Amount must be more than 0 and at most what we owe ('.SaleService::money(max(0, $owed)).').']);
            }
            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['amount' => 'There are no open purchases in your warehouses to pay.']);
            }

            $left = $paisa;
            $allocations = [];
            foreach ($open as $p) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, SaleService::paisa($p->due_total));
                $allocations[] = [$p, $take];
                $left -= $take;
            }

            // Anything beyond open invoices (e.g. an opening balance) stays unallocated on the ledger.
            return $this->post($user, $supplier->id, $open->first()->warehouse_id, $allocations, $paisa, $methodCode, $reference, $paidAt, $note);
        });
    }

    /** @param  array<int, array{0: Purchase, 1: int}>  $allocations */
    private function post(User $user, int $supplierId, int $warehouseId, array $allocations, int $paisa, string $methodCode, ?string $reference, ?string $paidAt, ?string $note): Payment
    {
        $method = PaymentMethod::where('is_active', true)->where('code', $methodCode)->first();
        if (! $method || $method->code === 'customer_credit') {
            throw ValidationException::withMessages(['method' => 'Payment method is not enabled.']);
        }
        if ($method->requires_reference && ! $reference) {
            throw ValidationException::withMessages(['reference' => "{$method->name} needs a transaction reference."]);
        }
        $money = SaleService::money($paisa);
        $payment = Payment::create([
            'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', Warehouse::findOrFail($warehouseId)),
            'direction' => 'out', 'party_type' => 'supplier', 'party_id' => $supplierId,
            'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money,
            'reference' => $reference, 'paid_at' => $paidAt ? now()->parse($paidAt) : now(), 'note' => $note, 'created_by' => $user->id,
        ]);
        foreach ($allocations as [$purchase, $take]) {
            PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'purchase', 'allocatable_id' => $purchase->id, 'amount' => SaleService::money($take)]);
            $paid = SaleService::paisa($purchase->paid_total) + $take;
            $purchase->update(['paid_total' => SaleService::money($paid), 'due_total' => SaleService::money(SaleService::paisa($purchase->grand_total) - $paid)]);
        }
        if ($method->account_id) {
            AccountTransaction::create([
                'account_id' => $method->account_id, 'txn_date' => $payment->paid_at->toDateString(), 'direction' => 'out',
                'amount' => $money, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $payment->number,
            ]);
        }
        PartyLedgerEntry::create([
            'party_type' => 'supplier', 'party_id' => $supplierId, 'entry_date' => $payment->paid_at->toDateString(), 'type' => 'payment',
            'debit' => $money, 'credit' => 0, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $payment->number, 'created_by' => $user->id,
        ]);
        AuditLog::record($user->id, 'supplier.paid', 'supplier', $supplierId, ['payment' => $payment->number, 'amount' => $money]);

        return $payment;
    }
}
