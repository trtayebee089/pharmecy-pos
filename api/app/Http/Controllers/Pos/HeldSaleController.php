<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\HeldSale;
use App\Services\HeldSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Held (parked) sales, addressed by their number (H-DHK1-2026-00001). */
class HeldSaleController extends Controller
{
    public function index(Request $request, HeldSales $held): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer']]);
        $wh = (int) ($data['warehouse_id'] ?? $request->user()->default_warehouse_id);
        if (! $request->user()->canAccessWarehouse($wh)) {
            throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
        }

        return response()->json(HeldSale::where(['warehouse_id' => $wh, 'status' => 'held'])->orderByDesc('id')->get()
            ->map(fn ($h) => $held->summary($h))->all());
    }

    public function store(Request $request, HeldSales $held): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'integer'],
            'customerId' => ['nullable', 'integer'],
            'orderDiscount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
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
        ]);

        return response()->json($held->summary($held->hold($request->user(), $data)), 201);
    }

    public function resume(Request $request, string $number, HeldSales $held): JsonResponse
    {
        return response()->json($held->resume($request->user(), $this->find($request, $number)));
    }

    public function destroy(Request $request, string $number, HeldSales $held): JsonResponse
    {
        $held->discard($request->user(), $this->find($request, $number));

        return response()->json(['discarded' => true]);
    }

    private function find(Request $request, string $number): HeldSale
    {
        $h = HeldSale::where('number', $number)->firstOrFail();
        abort_unless($request->user()->canAccessWarehouse($h->warehouse_id), 404);

        return $h;
    }
}
