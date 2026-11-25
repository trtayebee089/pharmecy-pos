<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Catalogue for the website and app. Shows active products with their retail
 * price per piece and the sellable stock (non-expired, active batches) of the
 * online branch (`OnlineOrders::warehouseId()`), the stock an order is taken from.
 * Prices are always the server's; clients only display them.
 */
class Storefront
{
    public const SORTS = ['popular', 'newest', 'price_asc', 'price_desc'];

    private ?int $warehouseId = null;

    public function __construct(private Pricing $pricing) {}

    public function warehouseId(): int
    {
        return $this->warehouseId ??= app(OnlineOrders::class)->warehouseId();
    }

    /** Products filtered like the website's category/search page, paginated. */
    public function products(array $f): LengthAwarePaginator
    {
        $perPage = max(1, min(60, (int) ($f['per_page'] ?? 24)));
        $q = $this->base()
            ->when($f['category'] ?? null, fn ($q, $slug) => $q->whereIn('products.category_id', $this->categoryIds((string) $slug)))
            ->when(trim((string) ($f['search'] ?? $f['q'] ?? '')), fn ($q, $s) => $this->search($q, $s))
            ->when(filter_var($f['prescription_required'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('products.requires_prescription', true))
            ->when(filter_var($f['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereRaw('COALESCE(stock.qty, 0) > 0'));
        if (preg_match('/^(\d+)-(\d+)$/', (string) ($f['price'] ?? ''), $m)) {
            $q->whereBetween('piece.price', [(float) $m[1], (float) $m[2]]);
        }
        match ($f['sort'] ?? 'popular') {
            'newest' => $q->orderByDesc('products.id'),
            'price_asc' => $q->orderBy('piece.price'),
            'price_desc' => $q->orderByDesc('piece.price'),
            default => $q->orderByDesc(DB::raw('COALESCE(sold.qty, 0)'))->orderBy('products.name'),
        };
        // No promotions exist yet: "discounted" filters to nothing rather than ignoring the request.
        if (filter_var($f['discounted'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereRaw('1 = 0');
        }
        $page = $q->paginate($perPage, ['products.*'], 'page', max(1, (int) ($f['page'] ?? 1)));
        $page->setCollection(collect($this->present($page->getCollection())));

        return $page;
    }

    /** @return array<int, array> */
    public function featured(int $limit = 12): array
    {
        return $this->present($this->base()->whereRaw('COALESCE(stock.qty, 0) > 0')->orderByDesc('products.id')->limit($limit)->get(['products.*']));
    }

    /** @return array<int, array> */
    public function bestSelling(int $limit = 12): array
    {
        return $this->present($this->base()->orderByDesc(DB::raw('COALESCE(sold.qty, 0)'))->orderBy('products.name')->limit($limit)->get(['products.*']));
    }

    /** @return array<int, array{name: string, slug: string}> */
    public function suggestions(string $term, int $limit = 8): array
    {
        if (mb_strlen(trim($term)) < 2) {
            return [];
        }

        return $this->search($this->base(), trim($term))->orderBy('products.name')->limit($limit)->get(['products.*'])
            ->map(fn ($p) => ['name' => trim($p->name.' '.$p->strength), 'slug' => $this->slug($p)])->all();
    }

    public function product(string $slugOrId): ?array
    {
        $p = $this->base()->where(fn ($q) => $q->where('products.slug', $slugOrId)->orWhere('products.id', ctype_digit($slugOrId) ? (int) $slugOrId : 0))
            ->first(['products.*']);

        return $p ? $this->present(collect([$p]), detail: true)[0] : null;
    }

    /** @return array<int, array> active categories that have active products */
    public function categories(): array
    {
        $counts = Product::where('is_active', true)->whereNotNull('category_id')->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS n')->pluck('n', 'category_id');

        return Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(function ($c) use ($counts) {
                $n = (int) $counts->only($this->categoryIds($c->slug))->sum();

                return ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'image' => null, 'description' => null, 'product_count' => $n, 'total_products' => $n];
            })->filter(fn ($c) => $c['product_count'] > 0)->values()->all();
    }

    public function category(string $slug): ?array
    {
        return collect($this->categories())->firstWhere('slug', $slug);
    }

    /** The shape the website and app read (both naming styles are included). */
    public function present(Collection $products, bool $detail = false): array
    {
        if ($products->isEmpty()) {
            return [];
        }
        $products = new EloquentCollection($products->all());
        $products->loadMissing('units', 'generic', 'manufacturer', 'dosageForm', 'category');
        $ids = $products->pluck('id')->all();
        $stock = DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->where('sb.warehouse_id', $this->warehouseId())->whereIn('sb.product_id', $ids)->where('sb.qty_base', '>', 0)->where('b.status', 'active')
            ->where(fn ($q) => $q->where('b.expiry_date', '>=', now()->toDateString())->orWhere(fn ($q) => $q->whereNull('b.expiry_date')->where('b.is_legacy_opening', true)))
            ->groupBy('sb.product_id')->selectRaw('sb.product_id, SUM(sb.qty_base) AS q')->pluck('q', 'product_id');
        $prices = $this->pricing->forUnits($products->flatMap(fn ($p) => $p->units->pluck('id'))->all());
        $sold = $detail ? $this->soldCounts($ids) : collect();

        return $products->map(function (Product $p) use ($stock, $prices, $sold, $detail) {
            $base = $p->units->firstWhere('factor', 1);
            $strip = $p->units->firstWhere('unit_code', 'strip');
            $box = $p->units->firstWhere('unit_code', 'box');
            $perStrip = (int) ($strip?->factor ?? 1);
            $price = $base && $prices->has($base->id) ? round((float) $prices[$base->id], 2) : null;
            $qty = (int) ($stock[$p->id] ?? 0);
            $default = $p->units->firstWhere('is_sale_default', true) ?? $base;
            $manufacturer = $p->manufacturer?->name;
            $category = $p->category ? ['id' => $p->category->id, 'name' => $p->category->name, 'slug' => $p->category->slug, 'image' => null] : null;

            return array_filter([
                'id' => $p->id, 'slug' => $this->slug($p), 'name' => $p->name, 'strength' => $p->strength,
                'generic_name' => $p->generic?->name, 'manufacturer' => $manufacturer, 'brand' => $manufacturer ? ['title' => $manufacturer] : null,
                'dosage_form' => $p->dosageForm?->name,
                // Price, stock and order quantity are per piece (the base unit); `pack` is how it is usually sold.
                'unit' => $base?->label, 'pack' => $default && $default->factor > 1 ? $default->label : null,
                'price' => $price, 'discount_price' => null, 'promotion_price' => null,
                'stock' => $qty, 'qty' => $qty, 'available_qty' => $qty,
                'prescription_required' => (bool) $p->requires_prescription,
                'image' => $this->imageUrl($p->image_path),
                'category' => $category, 'category_slug' => $category['slug'] ?? null,
                'piece_qty' => $perStrip, 'stripe_qty' => $box && $perStrip ? intdiv($box->factor, max(1, $perStrip)) : 1, 'box_qty' => 1,
                'description' => $detail ? $p->description : null,
                'saleCount' => $detail ? (int) ($sold[$p->id] ?? 0) : null,
            ], fn ($v) => $v !== null || $detail);
        })->values()->all();
    }

    public function slug(Product $p): string
    {
        return $p->slug ?: (string) $p->id;
    }

    /** Active products with their piece price, online stock and units sold (for sorting). */
    private function base(): Builder
    {
        $wh = $this->warehouseId();
        $today = now()->toDateString();
        $listId = $this->pricing->defaultListId();
        $piece = DB::table('product_units as u')->join('product_prices as pp', 'pp.product_unit_id', '=', 'u.id')
            ->where('u.factor', 1)->where('pp.price_list_id', $listId)->where('pp.effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('pp.effective_to')->orWhere('pp.effective_to', '>', now()))
            ->groupBy('u.product_id')->selectRaw('u.product_id, MAX(pp.price) AS price');
        $stock = DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->where('sb.warehouse_id', $wh)->where('sb.qty_base', '>', 0)->where('b.status', 'active')
            ->where(fn ($q) => $q->where('b.expiry_date', '>=', $today)->orWhere(fn ($q) => $q->whereNull('b.expiry_date')->where('b.is_legacy_opening', true)))
            ->groupBy('sb.product_id')->selectRaw('sb.product_id, SUM(sb.qty_base) AS qty');
        $sold = DB::table('sale_items')->where('line_type', 'product')->groupBy('product_id')->selectRaw('product_id, SUM(qty_base) AS qty');

        // Only products with a retail price for one piece can be sold online.
        return Product::query()->where('products.is_active', true)
            ->joinSub($piece, 'piece', 'piece.product_id', '=', 'products.id')
            ->leftJoinSub($stock, 'stock', 'stock.product_id', '=', 'products.id')
            ->leftJoinSub($sold, 'sold', 'sold.product_id', '=', 'products.id');
    }

    private function search(Builder $q, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(fn ($q) => $q->where('products.name', 'like', $like)->orWhere('products.strength', 'like', $like)->orWhere('products.sku', $term)
            ->orWhereIn('products.generic_id', DB::table('generics')->where('name', 'like', $like)->select('id'))
            ->orWhereIn('products.manufacturer_id', DB::table('manufacturers')->where('name', 'like', $like)->select('id')));
    }

    /** The category and its sub-categories. */
    private function categoryIds(string $slug): array
    {
        $root = Category::where('slug', $slug)->value('id');
        if (! $root) {
            return [0];
        }
        $ids = [$root];
        $frontier = [$root];
        while ($frontier) {
            $frontier = Category::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function soldCounts(array $ids): Collection
    {
        return DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.status', 'completed')
            ->whereIn('si.product_id', $ids)->groupBy('si.product_id')->selectRaw('si.product_id, SUM(si.qty_base) AS q')->pluck('q', 'product_id');
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return preg_match('#^https?://#', $path) ? $path : Storage::disk('public')->url($path);
    }
}
