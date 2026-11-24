<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Present;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    /** POS search: exact barcode/SKU first, then name, generic, strength or manufacturer. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'warehouse_id' => ['required', 'integer'], 'includeExpired' => ['nullable', 'boolean']]);
        $warehouseId = $this->warehouse($request, (int) $data['warehouse_id']);
        $term = trim($data['q'] ?? '');
        $onHand = $this->onHand($request, $data['includeExpired'] ?? false);

        $query = Product::query()->where('is_active', true);
        if ($term !== '') {
            $exact = Product::query()->where('is_active', true)
                ->where(fn ($q) => $q->where('sku', $term)->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $term)))
                ->limit(1)->get();
            if ($exact->isNotEmpty()) {
                return response()->json(Present::products($exact, $warehouseId, $onHand));
            }
            $this->applySearch($query, $term);
        }

        return response()->json(Present::products($query->orderBy('name')->limit($term === '' ? 24 : 40)->get(), $warehouseId, $onHand));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'warehouse_id' => ['nullable', 'integer'],
            'dosageForm' => ['nullable', 'string'], 'rx' => ['nullable'],
        ]);
        $warehouseId = $this->warehouse($request, (int) ($data['warehouse_id'] ?? $request->user()->default_warehouse_id ?? 1));

        $query = Product::query()
            ->when($data['search'] ?? null, fn ($q, $s) => $this->applySearch($q, $s))
            ->when($data['dosageForm'] ?? null, fn ($q, $f) => $q->whereHas('dosageForm', fn ($d) => $d->where('name', $f)))
            ->when(filter_var($data['rx'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('requires_prescription', true))
            ->orderBy('name');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, Present::products(collect($page->items()), $warehouseId)));
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $warehouseId = $this->warehouse($request, (int) ($request->query('warehouse_id') ?? $request->user()->default_warehouse_id ?? 1));

        return response()->json(Present::products(collect([$product]), $warehouseId, $this->onHand($request, $request->query('includeExpired')))[0]);
    }

    /**
     * Every batch on hand (expired too) instead of the sellable ones: only for
     * staff who return, write off, count or move stock, never for selling.
     */
    private function onHand(Request $request, mixed $asked): bool
    {
        return filter_var($asked, FILTER_VALIDATE_BOOLEAN)
            && collect(['purchase_returns.create', 'inventory.adjust', 'inventory.count', 'inventory.transfer'])->contains(fn ($p) => $request->user()->can($p));
    }

    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $query->where(fn ($q) => $q->where('name', 'like', $like)
            ->orWhere('sku', $term)
            ->orWhere('strength', 'like', $like)
            ->orWhereHas('generic', fn ($g) => $g->where('name', 'like', $like))
            ->orWhereHas('manufacturer', fn ($m) => $m->where('name', 'like', $like)));
    }

    private function warehouse(Request $request, int $warehouseId): int
    {
        if (! $request->user()->canAccessWarehouse($warehouseId)) {
            throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
        }

        return $warehouseId;
    }
}
