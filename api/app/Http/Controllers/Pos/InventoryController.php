<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AppSettings;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only stock views for one warehouse: the stock overview (StockRow) and
 * the batch list (BatchRow). Stock value is the sellable stock at batch cost
 * (expired stock is reported as expiredBase, not valued here). Sellable follows StockLedger::sellableQuery:
 * active batch and not expired (legacy opening batches without expiry count).
 */
class InventoryController extends Controller
{
    public function stock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:out,low,expiring'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $wh = $this->warehouse($request, $data['warehouse_id'] ?? null);

        $query = $this->productsWithStock($wh)
            ->when($data['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($q) => $q->where('products.name', 'like', $like)->orWhere('products.sku', $s)
                    ->orWhere('products.strength', 'like', $like)
                    ->orWhereIn('products.manufacturer_id', DB::table('manufacturers')->where('name', 'like', $like)->select('id')));
            })
            ->when(($data['status'] ?? null) === 'out', fn ($q) => $q->whereRaw('COALESCE(agg.sellable, 0) = 0'))
            ->when(($data['status'] ?? null) === 'low', fn ($q) => $q->whereRaw('COALESCE(agg.sellable, 0) > 0 AND COALESCE(agg.sellable, 0) <= products.reorder_level_base'))
            ->when(($data['status'] ?? null) === 'expiring', fn ($q) => $q->whereRaw('COALESCE(agg.expiring, 0) > 0'))
            ->orderBy('products.name');

        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);
        $rows = collect($page->items())->keyBy('id');
        $products = Product::whereIn('id', $rows->keys())->get()->sortBy(fn ($p) => $rows->keys()->search($p->id))->values();
        $presented = collect(Present::products($products, $wh, onHand: true))->keyBy('id');

        $data = $rows->map(function ($r) use ($presented) {
            $p = $presented[$r->id];
            $sellable = (int) $r->sellable;

            return [
                'id' => $r->id, 'sku' => $p['sku'], 'name' => $p['name'], 'strength' => $p['strength'],
                'manufacturer' => $p['manufacturer'], 'dosageForm' => $p['dosageForm'], 'units' => $p['units'],
                'sellableBase' => $sellable, 'expiredBase' => (int) $r->expired, 'valueAtCost' => round((float) $r->value, 2),
                'reorderLevelBase' => (int) $r->reorder_level_base,
                'status' => $sellable === 0 ? 'out' : ($sellable <= (int) $r->reorder_level_base ? 'low' : 'ok'),
                'expiringSoon' => (int) $r->expiring, 'batches' => $p['batches'],
            ];
        })->values()->all();

        return response()->json(Present::paginated($page, $data));
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer']]);
        $wh = $this->warehouse($request, $data['warehouse_id'] ?? null);

        $t = DB::query()->fromSub($this->productsWithStock($wh)->toBase(), 's')->selectRaw(
            'COUNT(*) AS products, COALESCE(SUM(value), 0) AS value,
             SUM(CASE WHEN COALESCE(sellable, 0) = 0 THEN 1 ELSE 0 END) AS out_n,
             SUM(CASE WHEN COALESCE(sellable, 0) > 0 AND COALESCE(sellable, 0) <= reorder_level_base THEN 1 ELSE 0 END) AS low_n,
             SUM(CASE WHEN COALESCE(expiring, 0) > 0 THEN 1 ELSE 0 END) AS expiring_n'
        )->first();

        return response()->json([
            'value' => round((float) $t->value, 2), 'out' => (int) $t->out_n, 'low' => (int) $t->low_n,
            'expiring' => (int) $t->expiring_n, 'products' => (int) $t->products,
        ]);
    }

    public function batches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'within' => ['nullable', 'string', 'max:10'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $wh = $this->warehouse($request, $data['warehouse_id'] ?? null);
        $today = now()->toDateString();
        $within = $data['within'] ?? '0';

        $query = DB::table('stock_balances as sb')
            ->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->leftJoin('manufacturers as m', 'm.id', '=', 'p.manufacturer_id')
            ->where('sb.warehouse_id', $wh)->where('sb.qty_base', '>', 0)
            ->when($within === 'expired', fn ($q) => $q->where('b.expiry_date', '<', $today))
            ->when(ctype_digit($within) && (int) $within > 0, fn ($q) => $q->whereBetween('b.expiry_date', [$today, now()->addDays((int) $within)->toDateString()]))
            ->when($data['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($q) => $q->where('p.name', 'like', $like)->orWhere('b.batch_no', 'like', $like)->orWhere('m.name', 'like', $like));
            })
            ->orderByRaw('CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('b.expiry_date')->orderBy('p.name')
            ->select('b.id as batch_id', 'b.batch_no', 'b.expiry_date', 'b.status', 'p.id as product_id', 'p.name', 'p.strength', 'm.name as manufacturer', 'sb.qty_base', 'sb.avg_unit_cost');

        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($r) => [
            'batchId' => $r->batch_id, 'batchNo' => $r->batch_no, 'productId' => $r->product_id,
            'productName' => trim($r->name.' '.($r->strength ?? '')), 'manufacturer' => (string) $r->manufacturer,
            'expiryDate' => $r->expiry_date ? substr((string) $r->expiry_date, 0, 10) : '9999-12-31',
            'qtyBase' => (int) $r->qty_base, 'value' => round($r->qty_base * (float) $r->avg_unit_cost, 2), 'status' => $r->status,
        ])->all()));
    }

    /** Active products with their stock aggregates in warehouse $wh (products without stock included). */
    private function productsWithStock(int $wh)
    {
        $today = now()->toDateString();
        $soon = now()->addDays((int) app(AppSettings::class)->general('nearExpiryDays'))->toDateString();
        $sellable = "b.status = 'active' AND (b.expiry_date >= ? OR (b.expiry_date IS NULL AND b.is_legacy_opening = 1))";

        $agg = DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->where('sb.warehouse_id', $wh)->where('sb.qty_base', '>', 0)
            ->groupBy('sb.product_id')
            ->selectRaw("sb.product_id,
                SUM(CASE WHEN $sellable THEN sb.qty_base ELSE 0 END) AS sellable,
                SUM(CASE WHEN b.expiry_date < ? THEN sb.qty_base ELSE 0 END) AS expired,
                SUM(CASE WHEN $sellable THEN sb.qty_base * sb.avg_unit_cost ELSE 0 END) AS value,
                SUM(CASE WHEN b.expiry_date >= ? AND b.expiry_date <= ? THEN 1 ELSE 0 END) AS expiring", [$today, $today, $today, $today, $soon]);

        return Product::query()->where('products.is_active', true)
            ->leftJoinSub($agg, 'agg', 'agg.product_id', '=', 'products.id')
            ->select('products.id', 'products.reorder_level_base', 'agg.sellable', 'agg.expired', 'agg.value', 'agg.expiring');
    }

    private function warehouse(Request $request, mixed $warehouseId): int
    {
        $id = (int) ($warehouseId ?? $request->user()->default_warehouse_id ?? 0);
        if (! $id || ! $request->user()->canAccessWarehouse($id)) {
            throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
        }

        return $id;
    }
}
