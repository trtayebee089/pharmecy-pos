<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReversalService;
use App\Services\SaleService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string'], 'channel' => ['nullable', 'string'],
        ]);
        $user = $request->user();
        $query = Sale::query()->with('customer', 'cashier')->withCount('items')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($data['status'] ?? null, fn ($q, $s) => in_array($s, ['paid', 'partial', 'unpaid'], true)
                ? $q->where('payment_status', $s) : $q->where('status', $s))
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('sale_date')->orderByDesc('id');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($s) => Present::saleSummary($s))->all()));
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($sale->warehouse_id), 404);

        return response()->json(Present::saleDetail($sale));
    }

    public function void(Request $request, Sale $sale, SaleReversalService $reversals): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($sale->warehouse_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(Present::saleDetail($reversals->void($request->user(), $sale, trim($data['reason']))->fresh()));
    }

    public function storeReturn(Request $request, Sale $sale, SaleReversalService $reversals): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($sale->warehouse_id), 404);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.saleItemBatchId' => ['required', 'integer'],
            'items.*.qtyBase' => ['required', 'integer', 'min:0'],
            'items.*.restock' => ['required', 'boolean'],
            'refundMethod' => ['required', 'in:cash,bkash,credit'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $items = array_values(array_filter($data['items'], fn ($i) => $i['qtyBase'] > 0));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one item to return.']);
        }

        $return = $reversals->createReturn($request->user(), $sale, $items, $data['refundMethod'], trim($data['reason']));

        return response()->json(Present::saleReturn($return), 201);
    }

    public function returns(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'refundMethod' => ['nullable', 'in:cash,bkash,credit'],
        ]);
        $user = $request->user();
        $query = SaleReturn::query()->with('items.batch', 'items.saleItem', 'sale', 'customer', 'creator')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
            ->when($data['refundMethod'] ?? null, fn ($q, $m) => $q->where('refund_method', $m))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")->orWhere('reason', 'like', "%$s%")
                ->orWhereHas('sale', fn ($x) => $x->where('number', 'like', "%$s%"))->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->orderByDesc('id');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($r) => Present::saleReturn($r))->all()));
    }

    public function store(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'integer'],
            'customerId' => ['required', 'integer'],
            'orderDiscount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.unitId' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.batchId' => ['nullable', 'integer'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.custom' => ['nullable', 'array'],
            'lines.*.custom.name' => ['required_with:lines.*.custom', 'string', 'max:150'],
            'lines.*.custom.code' => ['nullable', 'string', 'max:60'],
            'lines.*.custom.price' => ['required_with:lines.*.custom', 'numeric', 'min:0'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', 'string', 'max:30'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        $sale = $sales->complete($request->user(), $data);

        return response()->json(Present::saleReceipt($sale), 201);
    }
}
