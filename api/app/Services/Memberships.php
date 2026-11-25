<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer memberships (owner decision: automatic by annual spend + manual).
 *
 * - One active membership per customer; it is active while today is within
 *   starts_at..ends_at. Expired rows are marked by `expireDue()` (daily).
 * - After every completed sale the customer's last-12-month spend (completed
 *   sales minus returns) is reviewed; when it reaches a higher active plan's
 *   minimum, that plan is assigned automatically. Automatic review never
 *   downgrades: a tier runs to its end date.
 * - Staff can renew (extends from the later of today and the end date; a plan
 *   fee is collected in cash), change the plan or cancel, always with a reason.
 */
class Memberships
{
    public function __construct(private DocumentNumbers $numbers) {}

    public function active(int $customerId): ?CustomerMembership
    {
        $today = now()->toDateString();

        return CustomerMembership::with('plan')->where(['customer_id' => $customerId, 'status' => 'active'])
            ->where('starts_at', '<=', $today)->where('ends_at', '>=', $today)
            ->latest('id')->first();
    }

    public function annualSpend(int $customerId): float
    {
        $since = now()->subYear();
        $sales = (float) DB::table('sales')->where(['customer_id' => $customerId, 'status' => 'completed'])
            ->where('sale_date', '>=', $since)->sum('grand_total');
        $returns = (float) DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.customer_id', $customerId)->where('sales.status', 'completed')
            ->where('sales.sale_date', '>=', $since)->sum('sale_returns.total');

        return round($sales - $returns, 2);
    }

    /** Assigns a higher tier automatically when the customer's spend qualifies. */
    public function review(Customer $customer): ?CustomerMembership
    {
        if ($customer->is_walk_in) {
            return null;
        }
        $spend = $this->annualSpend($customer->id);
        $plan = MembershipPlan::where('is_active', true)->where('min_annual_spend', '>', 0)->where('min_annual_spend', '<=', $spend)
            ->orderByDesc('min_annual_spend')->first();
        if (! $plan) {
            return null;
        }
        $current = $this->active($customer->id);
        if ($current && (float) $current->plan->min_annual_spend >= (float) $plan->min_annual_spend) {
            return null;
        }
        $current?->update(['status' => 'cancelled', 'reason' => 'Upgraded to '.$plan->code]);

        return CustomerMembership::create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths($plan->duration_months)->toDateString(), 'status' => 'active', 'source' => 'auto',
            'reason' => 'Annual spend '.number_format($spend, 2),
        ]);
    }

    public function renew(User $user, CustomerMembership $m, int $warehouseId): CustomerMembership
    {
        return DB::transaction(function () use ($user, $m, $warehouseId) {
            $m = CustomerMembership::with('plan', 'customer')->whereKey($m->id)->lockForUpdate()->firstOrFail();
            if ($m->status === 'cancelled') {
                throw ValidationException::withMessages(['membership' => 'A cancelled membership cannot be renewed. Assign a plan instead.']);
            }
            $from = max(now()->toDateString(), $m->ends_at);
            $m->update([
                'ends_at' => now()->parse($from)->addMonths($m->plan->duration_months)->toDateString(),
                'status' => 'active', 'source' => (float) $m->plan->fee > 0 ? 'purchase' : $m->source,
            ]);
            $this->collectFee($user, $m, $warehouseId, 'Renewal');
            AuditLog::record($user->id, 'membership.renewed', 'customer', $m->customer_id, ['plan' => $m->plan->code, 'endsAt' => $m->ends_at]);

            return $m;
        });
    }

    public function changePlan(User $user, CustomerMembership $m, string $planCode, string $reason): CustomerMembership
    {
        $plan = MembershipPlan::where('code', $planCode)->first();
        if (! $plan || ! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'That plan is not active.']);
        }

        return DB::transaction(function () use ($user, $m, $plan, $reason) {
            $m = CustomerMembership::whereKey($m->id)->lockForUpdate()->firstOrFail();
            if ($m->plan_id === $plan->id) {
                throw ValidationException::withMessages(['plan' => 'The member is already on this plan.']);
            }
            $m->update(['status' => 'cancelled', 'reason' => 'Changed to '.$plan->code.': '.$reason]);
            $new = CustomerMembership::create([
                'customer_id' => $m->customer_id, 'plan_id' => $plan->id, 'starts_at' => now()->toDateString(),
                'ends_at' => max($m->ends_at, now()->addMonths($plan->duration_months)->toDateString()),
                'status' => 'active', 'source' => 'manual', 'reason' => $reason, 'assigned_by' => $user->id,
            ]);
            AuditLog::record($user->id, 'membership.changed', 'customer', $m->customer_id, ['plan' => $plan->code, 'reason' => $reason]);

            return $new;
        });
    }

    public function cancel(User $user, CustomerMembership $m, string $reason): void
    {
        if ($m->status === 'cancelled') {
            throw ValidationException::withMessages(['membership' => 'This membership is already cancelled.']);
        }
        $m->update(['status' => 'cancelled', 'reason' => $reason]);
        AuditLog::record($user->id, 'membership.cancelled', 'customer', $m->customer_id, ['reason' => $reason]);
    }

    /** Marks memberships past their end date as expired (daily). */
    public function expireDue(): int
    {
        return CustomerMembership::where('status', 'active')->where('ends_at', '<', now()->toDateString())->update(['status' => 'expired']);
    }

    /** Plan fee paid in cash: an incoming payment and a customer ledger charge + payment (net zero). */
    private function collectFee(User $user, CustomerMembership $m, int $warehouseId, string $what): void
    {
        $fee = (float) $m->plan->fee;
        if ($fee <= 0) {
            return;
        }
        $method = PaymentMethod::where('code', 'cash')->firstOrFail();
        $warehouse = Warehouse::findOrFail($warehouseId);
        $money = SaleService::money(SaleService::paisa($fee));
        $note = "{$what} {$m->plan->name}";
        $payment = Payment::create([
            'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', $warehouse),
            'direction' => 'in', 'party_type' => 'customer', 'party_id' => $m->customer_id,
            'payment_method_id' => $method->id, 'account_id' => $method->account_id ?? Account::where('type', 'cash')->value('id'),
            'amount' => $money, 'paid_at' => now(), 'note' => $note, 'created_by' => $user->id,
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'membership', 'allocatable_id' => $m->id, 'amount' => $money]);
        if ($payment->account_id) {
            AccountTransaction::create([
                'account_id' => $payment->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'in',
                'amount' => $money, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $note,
            ]);
        }
        foreach ([['membership', $money, 0], ['payment', 0, $money]] as [$type, $debit, $credit]) {
            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $m->customer_id, 'entry_date' => now()->toDateString(), 'type' => $type,
                'debit' => $debit, 'credit' => $credit, 'source_type' => 'membership', 'source_id' => $m->id, 'note' => $note, 'created_by' => $user->id,
            ]);
        }
    }
}
