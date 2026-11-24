<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\SupplierPayments;
use App\Support\Present;
use App\Support\PresentPurchasing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,ordered,closed,fulfilled,received,void'],
            'supplierId' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $status = ['draft' => 'ordered', 'closed' => 'fulfilled'][$data['status'] ?? ''] ?? ($data['status'] ?? null);
        $query = Purchase::query()->with('supplier')->withCount('items')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($data['supplierId'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhere('supplier_invoice_no', 'like', "%$s%")->orWhereHas('supplier', fn ($x) => $x->where('name', 'like', "%$s%"))))
            ->orderByDesc('id');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($p) => PresentPurchasing::purchaseSummary($p))->all()));
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($purchase->warehouse_id), 404);

        return response()->json(PresentPurchasing::purchaseDetail($purchase));
    }

    public function pay(Request $request, Purchase $purchase, SupplierPayments $payments): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($purchase->warehouse_id), 404);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:30'], 'reference' => ['nullable', 'string', 'max:100']]);
        $payment = $payments->payInvoice($request->user(), $purchase, (float) $data['amount'], $data['method'], ($data['reference'] ?? null) ?: null);

        return response()->json(['number' => $payment->number, 'dueTotal' => (float) $purchase->fresh()->due_total], 201);
    }

    public function void(Request $request, Purchase $purchase, PurchaseService $purchases): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($purchase->warehouse_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(PresentPurchasing::purchaseDetail($purchases->void($request->user(), $purchase, trim($data['reason']))->fresh()));
    }

    public function duplicate(Request $request, Purchase $purchase, PurchaseService $purchases): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($purchase->warehouse_id), 404);
        $copy = $purchases->duplicate($request->user(), $purchase);

        return response()->json(['id' => $copy->id, 'number' => $copy->number], 201);
    }

    public function store(Request $request, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate([
            'orderId' => ['nullable', 'integer'],
            'status' => ['required', 'in:received,ordered'],
            'warehouseId' => ['required', 'integer'],
            'supplierId' => ['required', 'integer'],
            'supplierInvoiceNo' => ['nullable', 'string', 'max:60'],
            'invoiceDate' => ['nullable', 'date'],
            'otherCharges' => ['nullable', 'numeric', 'min:0'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
            'paymentMethod' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.unitId' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.freeQty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batchNo' => ['nullable', 'string', 'max:60'],
            'lines.*.expiryDate' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.unitCost' => ['required', 'numeric', 'min:0'],
            'lines.*.discountPct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.updateRetailPrice' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $purchase = $purchases->create($request->user(), $data);

        return response()->json([
            'id' => $purchase->id, 'number' => $purchase->number, 'status' => $purchase->status,
            'grandTotal' => (float) $purchase->grand_total, 'dueTotal' => (float) $purchase->due_total,
        ], 201);
    }
}
