<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Sale;
use App\Services\CustomerPayments;
use App\Services\Memberships;
use App\Services\RewardLedger;
use App\Services\SaleService;
use App\Support\Present;
use App\Support\PresentLoyalty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer accounts: profile (CustomerProfile), edit and deactivate, the due
 * list, open invoices and due collection (types in pos/app/types/people.ts).
 */
class CustomerAccountController extends Controller
{
    public function show(Customer $customer): JsonResponse
    {
        $customer->loadMissing('group');
        $sales = Sale::where(['customer_id' => $customer->id, 'status' => 'completed']);
        $stats = (clone $sales)->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total),0) AS spent, MIN(sale_date) AS first, MAX(sale_date) AS last')->first();
        $visits = (int) $stats->n;
        $balance = 0;

        return response()->json([
            'id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'phone' => $customer->phone,
            'email' => $customer->email, 'address' => $customer->address, 'group' => $customer->group?->name,
            'membership' => app(Memberships::class)->active($customer->id)?->plan->code,
            'points' => app(RewardLedger::class)->balance($customer->id),
            'dueBalance' => max(0, CustomerPayments::balance($customer->id) / 100),
            'totalSpent' => round((float) $stats->spent, 2), 'visits' => $visits,
            'avgBasket' => $visits ? round((float) $stats->spent / $visits, 2) : 0,
            'firstVisit' => $stats->first ? substr((string) $stats->first, 0, 10) : null,
            'lastVisit' => $stats->last ? substr((string) $stats->last, 0, 10) : null,
            'purchases' => Sale::where('customer_id', $customer->id)->withCount('items')->orderByDesc('sale_date')->limit(50)->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'number' => $s->number, 'date' => $s->sale_date->toIso8601String(),
                    'items' => (int) $s->items_count, 'total' => (float) $s->grand_total, 'status' => $s->status,
                ])->all(),
            'ledger' => DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customer->id])
                ->orderBy('entry_date')->orderBy('id')->get()
                ->map(function ($e) use (&$balance) {
                    $balance += SaleService::paisa($e->debit) - SaleService::paisa($e->credit);

                    return [
                        'date' => substr((string) $e->entry_date, 0, 10), 'type' => $e->type, 'ref' => (string) $e->note,
                        'debit' => (float) $e->debit, 'credit' => (float) $e->credit, 'balance' => $balance / 100,
                    ];
                })->all(),
            'rewards' => PresentLoyalty::entries($customer->id, 50),
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['name' => 'The walk-in customer cannot be edited.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:60'],
        ]);
        $phone = null;
        if (($data['phone'] ?? '') !== '') {
            $phone = CustomerController::normalizePhone($data['phone']);
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
            }
            if (Customer::where('phone_normalized', $phone)->where('id', '!=', $customer->id)->exists()) {
                throw ValidationException::withMessages(['phone' => 'Another customer already uses this phone.']);
            }
        }
        $group = null;
        if (($data['group'] ?? '') !== '') {
            $group = CustomerGroup::where('name', $data['group'])->first();
            if (! $group) {
                throw ValidationException::withMessages(['group' => 'Choose an existing customer group.']);
            }
        }
        $customer->update([
            'name' => trim($data['name']), 'phone' => $phone, 'phone_normalized' => $phone, 'customer_group_id' => $group?->id,
        ] + (array_key_exists('email', $data) ? ['email' => $data['email']] : [])
          + (array_key_exists('address', $data) ? ['address' => $data['address']] : []));
        AuditLog::record($request->user()->id, 'customer.updated', 'customer', $customer->id, null, $request);

        return response()->json(Present::customers(collect([$customer->fresh()]))[0]);
    }

    public function deactivate(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['customer' => 'The walk-in customer cannot be deactivated.']);
        }
        if (CustomerPayments::balance($customer->id) > 0) {
            throw ValidationException::withMessages(['customer' => "{$customer->name} still owes money. Collect the due first."]);
        }
        $customer->update(['is_active' => false]);
        AuditLog::record($request->user()->id, 'customer.deactivated', 'customer', $customer->id, null, $request);

        return response()->json(['id' => $customer->id, 'isActive' => false]);
    }

    /** DueCustomer rows: everyone who owes money, largest due first. */
    public function due(): JsonResponse
    {
        $balances = DB::table('party_ledger_entries')->where('party_type', 'customer')->groupBy('party_id')
            ->havingRaw('SUM(debit) - SUM(credit) > 0')->selectRaw('party_id, SUM(debit) - SUM(credit) AS bal')->pluck('bal', 'party_id');
        $ids = $balances->keys();
        $open = Sale::whereIn('customer_id', $ids)->where('status', 'completed')->where('due_total', '>', 0)
            ->groupBy('customer_id')->selectRaw('customer_id, COUNT(*) AS n, MIN(sale_date) AS oldest')->get()->keyBy('customer_id');
        $lastPaid = DB::table('payments')->where(['party_type' => 'customer', 'direction' => 'in', 'status' => 'posted'])->whereIn('party_id', $ids)
            ->groupBy('party_id')->selectRaw('party_id, MAX(paid_at) AS last')->pluck('last', 'party_id');

        return response()->json(Customer::whereIn('id', $ids)->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone,
            'dueBalance' => round((float) $balances[$c->id], 2),
            'openInvoices' => (int) ($open[$c->id]->n ?? 0),
            'oldestDays' => isset($open[$c->id]) ? (int) Carbon::parse($open[$c->id]->oldest)->startOfDay()->diffInDays(now()->startOfDay()) : 0,
            'lastPaymentAt' => isset($lastPaid[$c->id]) ? Carbon::parse($lastPaid[$c->id])->toIso8601String() : null,
        ])->sortByDesc('dueBalance')->values()->all());
    }

    /** OpenInvoice rows, oldest first (the order due collection allocates in). */
    public function openInvoices(Customer $customer): JsonResponse
    {
        return response()->json(Sale::where(['customer_id' => $customer->id, 'status' => 'completed'])->where('due_total', '>', 0)
            ->orderBy('sale_date')->orderBy('id')->get()->map(fn ($s) => [
                'saleId' => $s->id, 'number' => $s->number, 'date' => $s->sale_date->toIso8601String(),
                'total' => (float) $s->grand_total, 'paid' => (float) $s->paid_total, 'due' => (float) $s->due_total,
            ])->all());
    }

    public function collect(Request $request, Customer $customer, CustomerPayments $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
            'allocations' => ['present', 'array', 'max:500'],
            'allocations.*.saleId' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric'],
        ]);
        $payment = $payments->collect($request->user(), $customer, (float) $data['amount'], $data['method'], ($data['reference'] ?? null) ?: null, $data['allocations']);

        return response()->json(['receiptNo' => $payment->number, 'dueBalance' => max(0, CustomerPayments::balance($customer->id) / 100)], 201);
    }

    /** No SMS gateway is configured yet: say so instead of pretending to send. */
    public function dueReminder(Customer $customer): JsonResponse
    {
        return response()->json(['message' => 'SMS reminders are not available yet: no SMS gateway is configured.', 'queued' => false], 503);
    }
}
