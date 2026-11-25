<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ShippingZone;
use App\Services\Storefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1 catalogue: categories, products, search, delivery zones. Public. */
class CatalogController extends V1Controller
{
    public function __construct(private Storefront $store) {}

    public function categories(): JsonResponse
    {
        return $this->ok($this->store->categories());
    }

    /** The category in `data` and its products (paginated) in `products`, as the website's shelves read it. */
    public function category(Request $request, string $slug): JsonResponse
    {
        $category = $this->store->category($slug);
        abort_unless($category, 404, 'Category not found.');
        $page = $this->store->products(['category' => $slug] + $request->only('page', 'per_page', 'sort'));

        return $this->ok($category, null, 200, ['products' => [
            'data' => $page->items(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function products(Request $request): JsonResponse
    {
        $request->validate(['sort' => ['nullable', 'in:'.implode(',', Storefront::SORTS)], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:60']]);

        return $this->page($this->store->products($request->only('category', 'search', 'q', 'sort', 'price', 'in_stock', 'discounted', 'prescription_required', 'page', 'per_page')));
    }

    public function featured(): JsonResponse
    {
        return $this->ok($this->store->featured());
    }

    public function bestSelling(): JsonResponse
    {
        return $this->ok($this->store->bestSelling());
    }

    public function suggestions(Request $request): JsonResponse
    {
        return $this->ok($this->store->suggestions((string) $request->query('query', $request->query('q', ''))));
    }

    public function product(string $slug): JsonResponse
    {
        $p = $this->store->product($slug);
        abort_unless($p, 404, 'Product not found.');

        return $this->ok($p);
    }

    /** Active delivery zones with their charge and free-delivery threshold. */
    public function zones(): JsonResponse
    {
        return $this->ok(ShippingZone::where('is_active', true)->orderBy('division')->orderBy('district')->orderBy('thana')->get()
            ->map(fn ($z) => [
                'id' => $z->id, 'division' => $z->division, 'district' => $z->district, 'thana' => $z->thana,
                'rate' => (float) $z->rate, 'free_shipping_min' => $z->free_shipping_min !== null ? (float) $z->free_shipping_min : null,
            ])->all());
    }
}
