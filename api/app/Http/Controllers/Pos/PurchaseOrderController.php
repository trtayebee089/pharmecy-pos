<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use App\Models\PurchaseOrderDraft;
use App\Models\PurchaseOrderRun;
use App\Models\Warehouse;
use App\Services\PurchaseOrderPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Purchase orders by company: report, forecast, drafts, conversion and batch runs (warehouse = the user's current one). */
class PurchaseOrderController extends Controller
{
    public function companies(): JsonResponse
    {
        return response()->json(Manufacturer::where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get()
            ->filter(fn ($m) => $m->products_count > 0)
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'products' => $m->products_count])->values()->all());
    }

    public function report(Request $request, PurchaseOrderPlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'warehouse_id' => ['nullable', 'integer'],
        ]);

        return response()->json($planner->report($this->warehouse($request), (int) $data['company_id'], $data['from'], $data['to']));
    }

    public function forecast(Request $request, PurchaseOrderPlanner $planner): JsonResponse
    {
        $data = $request->validate(['product_ids' => ['required', 'array', 'max:500'], 'product_ids.*' => ['integer'], 'refresh' => ['nullable', 'boolean']]);

        return response()->json((object) $planner->forecast($this->warehouse($request), array_map('intval', $data['product_ids'])));
    }

    public function drafts(Request $request, PurchaseOrderPlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'companyId' => ['nullable', 'integer'], 'status' => ['nullable', 'in:draft,converted'],
        ]);

        return response()->json(PurchaseOrderDraft::whereIn('warehouse_id', $this->warehouseIds($request))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '<=', $d))
            ->when($data['companyId'] ?? null, fn ($q, $c) => $q->where('manufacturer_id', $c))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('order_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn ($d) => $planner->present($d))->all());
    }

    public function show(Request $request, PurchaseOrderDraft $draft, PurchaseOrderPlanner $planner): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($draft->warehouse_id), 404);

        return response()->json($planner->present($draft, true));
    }

    public function store(Request $request, PurchaseOrderPlanner $planner): JsonResponse
    {
        $data = $request->validate($this->rowRules() + [
            'company_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'source' => ['required', 'in:manual,ai'],
        ]);
        $draft = $planner->saveDraft($request->user(), $this->warehouse($request), (int) $data['company_id'], $data['date'], $data['rows'], $data['source']);

        return response()->json($planner->present($draft->fresh(), true), 201);
    }

    public function update(Request $request, PurchaseOrderDraft $draft, PurchaseOrderPlanner $planner): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($draft->warehouse_id), 404);
        $data = $request->validate($this->rowRules());

        return response()->json($planner->present($planner->updateDraft($request->user(), $draft, $data['rows'])->fresh(), true));
    }

    public function destroy(Request $request, PurchaseOrderDraft $draft, PurchaseOrderPlanner $planner): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($draft->warehouse_id), 404);
        $planner->deleteDraft($request->user(), $draft);

        return response()->json(['deleted' => true]);
    }

    public function convert(Request $request, PurchaseOrderDraft $draft, PurchaseOrderPlanner $planner): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($draft->warehouse_id), 404);
        $data = $request->validate(['supplier_id' => ['required', 'integer']]);

        return response()->json($planner->convert($request->user(), $draft, (int) $data['supplier_id']), 201);
    }

    public function startRun(Request $request, PurchaseOrderPlanner $planner): JsonResponse
    {
        return response()->json($this->presentRun($planner->startRun($request->user(), $this->warehouse($request))), 201);
    }

    public function processRun(Request $request, PurchaseOrderRun $run, PurchaseOrderPlanner $planner): JsonResponse
    {
        abort_unless($run->created_by === $request->user()->id, 404);

        return response()->json($this->presentRun($run->status === 'done' ? $run : $planner->processRun($run)));
    }

    private function presentRun(PurchaseOrderRun $r): array
    {
        return ['id' => $r->id, 'total' => $r->total, 'processed' => $r->processed, 'created' => $r->created, 'status' => $r->status];
    }

    private function rowRules(): array
    {
        return [
            'rows' => ['required', 'array', 'max:1000'],
            'rows.*.productId' => ['required', 'integer'],
            'rows.*.orderQty' => ['required', 'integer', 'min:0'],
            'rows.*.soldQty' => ['nullable', 'integer'],
            'rows.*.inStock' => ['nullable', 'integer'],
            'rows.*.unitCost' => ['nullable', 'numeric', 'min:0'],
            'rows.*.forecast' => ['nullable', 'array'],
        ];
    }

    private function warehouse(Request $request): int
    {
        $id = (int) ($request->input('warehouse_id') ?? $request->user()->default_warehouse_id);
        if (! $id || ! $request->user()->canAccessWarehouse($id)) {
            throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
        }

        return $id;
    }

    private function warehouseIds(Request $request)
    {
        $user = $request->user();

        return $user->isAdmin() ? Warehouse::pluck('id') : $user->warehouses()->pluck('warehouses.id');
    }
}
