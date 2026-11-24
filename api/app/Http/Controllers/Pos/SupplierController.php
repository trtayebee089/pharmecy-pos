<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\SupplierPayments;
use App\Support\Present;
use App\Support\PresentPurchasing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:500'],
            'search' => ['nullable', 'string', 'max:100'], 'isActive' => ['nullable'], 'all' => ['nullable'],
        ]);
        $query = Supplier::query()
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")
                ->orWhere('company_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('code', $s)))
            ->when(isset($data['isActive']) && $data['isActive'] !== '', fn ($q) => $q->where('is_active', filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name');

        if (filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(PresentPurchasing::supplierRows($query->get()));
        }
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, PresentPurchasing::supplierRows(collect($page->items()))));
    }

    /** Pickers (purchase form): active suppliers with what we owe them. */
    public function lookup(Request $request): JsonResponse
    {
        $s = trim((string) $request->query('q', ''));
        $rows = Supplier::where('is_active', true)
            ->when($s !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('company_name', 'like', "%$s%")))
            ->orderBy('name')->limit(200)->get();

        return response()->json(collect(PresentPurchasing::supplierRows($rows))
            ->map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'dueBalance' => $r['dueBalance']])->values());
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(PresentPurchasing::supplierRows(collect([$supplier]))[0]);
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request) {
            $s = Supplier::create(['code' => 'TMP-'.uniqid()] + $this->validated($request));
            $s->update(['code' => 'S-'.str_pad((string) $s->id, 3, '0', STR_PAD_LEFT)]);

            return $s;
        });
        AuditLog::record($request->user()->id, 'supplier.created', 'supplier', $supplier->id, null, $request);

        return response()->json(PresentPurchasing::supplierRows(collect([$supplier]))[0], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request, $supplier));
        AuditLog::record($request->user()->id, 'supplier.updated', 'supplier', $supplier->id, null, $request);

        return response()->json(PresentPurchasing::supplierRows(collect([$supplier->fresh()]))[0]);
    }

    /** Suppliers with purchases are deactivated (their history must stay); unused ones are removed. */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        if (Purchase::where('supplier_id', $supplier->id)->exists()) {
            $supplier->update(['is_active' => false]);
            $message = 'Supplier has purchases, so it was deactivated instead of deleted.';
        } else {
            $supplier->delete();
            $message = 'Supplier deleted.';
        }
        AuditLog::record($request->user()->id, 'supplier.removed', 'supplier', $supplier->id, ['result' => $message], $request);

        return response()->json(['message' => $message]);
    }

    public function ledger(Supplier $supplier): JsonResponse
    {
        return response()->json(PresentPurchasing::supplierLedger($supplier));
    }

    public function pay(Request $request, Supplier $supplier, SupplierPayments $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'], 'paidAt' => ['nullable', 'date', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $payment = $payments->payAccount($request->user(), $supplier, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $data['paidAt'] ?? null, $data['note'] ?? null);

        return response()->json(['number' => $payment->number, 'amount' => (float) $payment->amount, 'dueBalance' => SupplierPayments::balance($supplier->id)], 201);
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($supplier?->id)->whereNull('deleted_at')],
            'companyName' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => trim($d['name']), 'company_name' => $d['companyName'] ?? null, 'phone' => $d['phone'],
            'email' => $d['email'] ?? null, 'address' => $d['address'] ?? null,
            'payment_terms_days' => $d['paymentTermsDays'] ?? 0, 'is_active' => $d['isActive'] ?? true,
        ];
    }
}
