<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use App\Support\Present;
use App\Support\PresentPurchasing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:posted,void'],
            'supplierId' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $query = PurchaseReturn::query()->with('supplier')->withCount('items')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['supplierId'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhere('credit_note_no', 'like', "%$s%")->orWhere('reason', 'like', "%$s%")
                ->orWhereHas('supplier', fn ($x) => $x->where('name', 'like', "%$s%"))))
            ->orderByDesc('id');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($r) => PresentPurchasing::purchaseReturn($r))->all()));
    }

    public function store(Request $request, PurchaseReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'integer'],
            'supplierId' => ['required', 'integer'],
            'creditNote' => ['nullable', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'settlement' => ['required', 'in:reduce_due,refund'],
            'refundMethod' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.batchId' => ['required', 'integer'],
            'lines.*.qtyBase' => ['required', 'integer', 'min:1'],
            'lines.*.unitCost' => ['nullable', 'numeric'], // ignored: the server prices at batch cost
        ]);

        $return = $returns->create($request->user(), $data);

        return response()->json(PresentPurchasing::purchaseReturn($return->fresh()), 201);
    }

    public function void(Request $request, PurchaseReturn $purchaseReturn, PurchaseReturnService $returns): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($purchaseReturn->warehouse_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(PresentPurchasing::purchaseReturn($returns->void($request->user(), $purchaseReturn, trim($data['reason']))->fresh()));
    }
}
