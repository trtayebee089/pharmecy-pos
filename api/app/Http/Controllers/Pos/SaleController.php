<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json(Present::saleReceipt($sale->load('items.batches.batch', 'customer')));
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
