<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Pricing;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Product writes (ProductInput in pos/app/types/domain.ts). Reads stay in CatalogController. */
class ProductController extends Controller
{
    /** The edit form's shape: ids, all units with retail and wholesale prices and barcodes. */
    public function form(Product $product, Pricing $pricing): JsonResponse
    {
        $product->load('units', 'barcodes');
        $ids = $product->units->pluck('id')->all();
        $retail = $pricing->forUnits($ids);
        $wholesale = $pricing->forUnits($ids, 'wholesale');

        return response()->json([
            'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'strength' => (string) $product->strength,
            'genericId' => $product->generic_id, 'manufacturerId' => $product->manufacturer_id,
            'dosageFormId' => $product->dosage_form_id, 'categoryId' => $product->category_id,
            'requiresPrescription' => $product->requires_prescription, 'isActive' => $product->is_active,
            'reorderLevelBase' => $product->reorder_level_base, 'description' => (string) $product->description,
            'units' => $product->units->sortBy('factor')->map(fn ($u) => [
                'id' => $u->id, 'code' => $u->unit_code, 'factor' => $u->factor,
                'price' => (float) ($retail[$u->id] ?? 0),
                'wholesalePrice' => isset($wholesale[$u->id]) ? (float) $wholesale[$u->id] : null,
                'barcode' => (string) $product->barcodes->firstWhere('product_unit_id', $u->id)?->barcode,
                'isSaleDefault' => $u->is_sale_default, 'isPurchaseDefault' => $u->is_purchase_default,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, ProductService $products): JsonResponse
    {
        $product = $products->save($request->user(), $this->rules($request));

        return response()->json(['id' => $product->id], 201);
    }

    public function update(Request $request, Product $product, ProductService $products): JsonResponse
    {
        $products->save($request->user(), $this->rules($request, $product), $product);

        return response()->json(['id' => $product->id]);
    }

    public function deactivate(Request $request, Product $product, ProductService $products): JsonResponse
    {
        $products->deactivate($request->user(), $product);

        return response()->json(['id' => $product->id, 'isActive' => false]);
    }

    public function duplicate(Request $request, Product $product, ProductService $products): JsonResponse
    {
        return response()->json(['id' => $products->duplicate($request->user(), $product)->id], 201);
    }

    public function import(Request $request, ProductService $products): JsonResponse
    {
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:2000'], 'rows.*' => ['array']]);

        return response()->json(['imported' => $products->import($request->user(), $data['rows'])], 201);
    }

    private function rules(Request $request, ?Product $product = null): array
    {
        $master = fn (string $table) => ['nullable', 'integer', Rule::exists($table, 'id')];

        return $request->validate([
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:100'],
            'genericId' => $master('generics'),
            'manufacturerId' => $master('manufacturers'),
            'dosageFormId' => $master('dosage_forms'),
            'categoryId' => $master('categories'),
            'requiresPrescription' => ['boolean'],
            'isActive' => ['boolean'],
            'reorderLevelBase' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'units' => ['required', 'array', 'min:1', 'max:4'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.code' => ['required', Rule::in(ProductService::UNIT_CODES)],
            'units.*.factor' => ['required', 'integer', 'min:1'],
            'units.*.price' => ['required', 'numeric', 'gt:0'],
            'units.*.wholesalePrice' => ['nullable', 'numeric', 'gt:0'],
            'units.*.barcode' => ['nullable', 'string', 'max:64'],
            'units.*.isSaleDefault' => ['boolean'],
            'units.*.isPurchaseDefault' => ['boolean'],
        ]);
    }
}
