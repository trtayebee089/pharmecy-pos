<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Due collection: money a customer pays later against credit sales.
 *
 * - The amount is at most what the customer owes (ledger balance).
 * - Each allocation is to one of the customer's completed sales and at most
 *   its due; allocations never exceed the amount. Whatever is not allocated
 *   may only cover debt that is not on an open invoice (e.g. a legacy opening
 *   balance).
 * - One incoming payment (number PAY-), an account transaction, a ledger
 *   credit, and paid/due/payment status updated on each sale. A collection
 *   in the collector's warehouse attaches to their open register session.
 */
class CustomerPayments
{
    public const NOTE = 'Due collection';

    public function __construct(private DocumentNumbers $numbers, private RegisterService $registers) {}

    /** Ledger balance the customer owes (debit − credit). */
    public static function balance(int $customerId): int
    {
        $row = DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customerId])
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal')->first();

        return SaleService::paisa($row->bal);
    }

    /** True when a due collection (not the sale's own payment) is allocated to the sale. */
    public static function hasCollections(int $saleId): bool
    {
        return PaymentAllocation::query()->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where(['payment_allocations.allocatable_type' => 'sale', 'payment_allocations.allocatable_id' => $saleId])
            ->where('payments.note', self::NOTE)->where('payments.status', 'posted')->exists();
    }

    /** @param  array<int, array{saleId: int, amount: float}>  $allocations */
    public function collect(User $user, Customer $customer, float $amount, string $methodCode, ?string $reference, array $allocations): Payment
    {
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['amount' => 'The walk-in customer has no account to collect for.']);
        }
        $method = PaymentMethod::where('is_active', true)->where('code', $methodCode)->first();
        if (! $method || $method->code === 'customer_credit') {
            throw ValidationException::withMessages(['method' => 'Payment method is not enabled.']);
        }
        if ($method->requires_reference && ! $reference) {
            throw ValidationException::withMessages(['reference' => "{$method->name} needs a transaction reference."]);
        }

        return DB::transaction(function () use ($user, $customer, $amount, $method, $reference, $allocations) {
            $paisa = SaleService::paisa($amount);
            $owed = self::balance($customer->id);
            if ($paisa <= 0 || $paisa > $owed) {
                throw ValidationException::withMessages(['amount' => 'Amount must be more than 0 and at most the due of '.SaleService::money(max(0, $owed)).'.']);
            }

            $open = Sale::where(['customer_id' => $customer->id, 'status' => 'completed'])->where('due_total', '>', 0)
                ->lockForUpdate()->get()->keyBy('id');
            $parts = [];
            $allocated = 0;
            foreach ($allocations as $i => $a) {
                $sale = $open->get((int) $a['saleId']);
                $take = SaleService::paisa($a['amount']);
                if (! $sale) {
                    throw ValidationException::withMessages(["allocations.$i.saleId" => 'This invoice is not open for this customer.']);
                }
                if ($take <= 0 || $take > SaleService::paisa($sale->due_total) || isset($parts[$sale->id])) {
                    throw ValidationException::withMessages(["allocations.$i.amount" => "Allocate more than 0 and at most the due of {$sale->due_total} on {$sale->number}, once."]);
                }
                $parts[$sale->id] = [$sale, $take];
                $allocated += $take;
            }
            if ($allocated > $paisa) {
                throw ValidationException::withMessages(['allocations' => 'Allocations are more than the amount received.']);
            }
            $offInvoice = $owed - $open->sum(fn ($s) => SaleService::paisa($s->due_total));
            if ($paisa - $allocated > max(0, $offInvoice)) {
                throw ValidationException::withMessages(['allocations' => 'Allocate the amount to the open invoices.']);
            }

            $warehouse = $parts ? Warehouse::find(reset($parts)[0]->warehouse_id) : Warehouse::find($user->default_warehouse_id);
            $session = $warehouse ? $this->registers->current($user, $warehouse->id) : null;
            $money = SaleService::money($paisa);
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', $warehouse ?? Warehouse::firstOrFail()),
                'direction' => 'in', 'party_type' => 'customer', 'party_id' => $customer->id,
                'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money,
                'reference' => $reference, 'paid_at' => now(), 'session_id' => $session?->id,
                'note' => self::NOTE, 'created_by' => $user->id,
            ]);
            foreach ($parts as [$sale, $take]) {
                PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'sale', 'allocatable_id' => $sale->id, 'amount' => SaleService::money($take)]);
                $paid = SaleService::paisa($sale->paid_total) + $take;
                $due = SaleService::paisa($sale->due_total) - $take;
                $sale->update([
                    'paid_total' => SaleService::money($paid), 'due_total' => SaleService::money($due),
                    'payment_status' => $due <= 0 ? 'paid' : 'partial',
                ]);
            }
            if ($method->account_id) {
                AccountTransaction::create([
                    'account_id' => $method->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'in',
                    'amount' => $money, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $payment->number,
                ]);
            }
            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $customer->id, 'entry_date' => now()->toDateString(), 'type' => 'payment',
                'debit' => 0, 'credit' => $money, 'source_type' => 'payment', 'source_id' => $payment->id,
                'note' => $payment->number, 'created_by' => $user->id,
            ]);
            AuditLog::record($user->id, 'customer.payment', 'customer', $customer->id, ['payment' => $payment->number, 'amount' => $money]);

            return $payment;
        });
    }
}
