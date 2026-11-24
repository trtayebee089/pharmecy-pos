<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use App\Services\StockCountService;
use App\Services\StockTransferService;
use App\Support\Present;
use App\Support\PresentInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Adjustments, stock counts and transfers (inventory documents). */
class StockDocumentController extends Controller
{
    // ------------------------------------------------------------ adjustments

    public function adjustments(Request $request): JsonResponse
    {
        $data = $this->listRules($request, ['reason' => ['nullable', 'in:damage,expiry,correction,lost,found']]);
        $query = StockAdjustment::query()->with('creator')->withCount('items')
            ->whereIn('warehouse_id', $this->warehouseIds($request))
            ->when($data['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhere('reason', 'like', "%$s%")->orWhere('note', 'like', "%$s%")->orWhereHas('creator', fn ($u) => $u->where('name', 'like', "%$s%"))))
            ->orderByDesc('id');

        return $this->page($query, $data, fn ($a) => PresentInventory::adjustment($a));
    }

    public function storeAdjustment(Request $request, StockAdjustmentService $service): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'integer'],
            'reason' => ['required', 'in:damage,expiry,correction,lost,found'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.productId' => ['required', 'integer'],
            'items.*.batchId' => ['required', 'integer'],
            'items.*.qtyBase' => ['required', 'integer'],
        ]);

        return response()->json(PresentInventory::adjustment($service->create($request->user(), $data)->fresh()), 201);
    }

    public function voidAdjustment(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($adjustment->warehouse_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(PresentInventory::adjustment($service->void($request->user(), $adjustment, trim($data['reason']))->fresh()));
    }

    // ------------------------------------------------------------ counts

    public function counts(Request $request): JsonResponse
    {
        $data = $this->listRules($request, ['status' => ['nullable', 'in:open,counting,posted,cancelled']]);
        $status = ($data['status'] ?? null) === 'open' ? 'counting' : ($data['status'] ?? null);
        $query = StockCount::query()->with('creator')
            ->whereIn('warehouse_id', $this->warehouseIds($request))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhere('name', 'like', "%$s%")->orWhere('scope_value', 'like', "%$s%")))
            ->orderByDesc('id');

        return $this->page($query, $data, fn ($c) => PresentInventory::count($c));
    }

    public function showCount(Request $request, StockCount $count): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($count->warehouse_id), 404);

        return response()->json(['session' => PresentInventory::count($count), 'lines' => PresentInventory::countLines($count)]);
    }

    public function startCount(Request $request, StockCountService $service): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'scopeType' => ['required', 'in:all,dosage,manufacturer,category'],
            'scopeValue' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(PresentInventory::count($service->start($request->user(), $data)->fresh()), 201);
    }

    public function saveCounts(Request $request, StockCount $count, StockCountService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($count->warehouse_id), 404);
        $data = $request->validate([
            'counts' => ['required', 'array', 'max:5000'],
            'counts.*.batchId' => ['required', 'integer'],
            'counts.*.countedQty' => ['present', 'nullable', 'integer', 'min:0'],
        ]);
        $service->saveCounts($count, $data['counts']);

        return response()->json(PresentInventory::count($count->fresh()));
    }

    public function postCount(Request $request, StockCount $count, StockCountService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($count->warehouse_id), 404);

        return response()->json(PresentInventory::count($service->post($request->user(), $count)->fresh()));
    }

    public function cancelCount(Request $request, StockCount $count, StockCountService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessWarehouse($count->warehouse_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(PresentInventory::count($service->cancel($request->user(), $count, trim($data['reason']))->fresh()));
    }

    // ------------------------------------------------------------ transfers

    public function transfers(Request $request): JsonResponse
    {
        $data = $this->listRules($request, ['status' => ['nullable', 'in:sent,received,void']]);
        $ids = $this->warehouseIds($request);
        $query = StockTransfer::query()->with('from', 'to', 'creator', 'items.product', 'items.batch')
            ->where(fn ($q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")
                ->orWhereHas('from', fn ($w) => $w->where('name', 'like', "%$s%"))->orWhereHas('to', fn ($w) => $w->where('name', 'like', "%$s%"))
                ->orWhereHas('creator', fn ($u) => $u->where('name', 'like', "%$s%"))))
            ->orderByDesc('id');

        return $this->page($query, $data, fn ($t) => PresentInventory::transfer($t));
    }

    public function sendTransfer(Request $request, StockTransferService $service): JsonResponse
    {
        $data = $request->validate([
            'fromWarehouseId' => ['required', 'integer'],
            'toWarehouseId' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.batchId' => ['required', 'integer'],
            'lines.*.qtyBase' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(PresentInventory::transfer($service->send($request->user(), $data)->fresh()), 201);
    }

    public function receiveTransfer(Request $request, StockTransfer $transfer, StockTransferService $service): JsonResponse
    {
        $this->canSeeTransfer($request, $transfer);

        return response()->json(PresentInventory::transfer($service->receive($request->user(), $transfer)->fresh()));
    }

    public function voidTransfer(Request $request, StockTransfer $transfer, StockTransferService $service): JsonResponse
    {
        $this->canSeeTransfer($request, $transfer);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(PresentInventory::transfer($service->void($request->user(), $transfer, trim($data['reason']))->fresh()));
    }

    // ------------------------------------------------------------ helpers

    private function canSeeTransfer(Request $request, StockTransfer $t): void
    {
        abort_unless($request->user()->canAccessWarehouse($t->from_warehouse_id) || $request->user()->canAccessWarehouse($t->to_warehouse_id), 404);
    }

    private function listRules(Request $request, array $extra): array
    {
        return $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'],
        ] + $extra);
    }

    private function warehouseIds(Request $request)
    {
        $user = $request->user();

        return $user->isAdmin() ? Warehouse::pluck('id') : $user->warehouses()->pluck('warehouses.id');
    }

    private function page($query, array $data, callable $present): JsonResponse
    {
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map($present)->all()));
    }
}
