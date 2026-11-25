<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Manufacturers (brands), categories and dosage forms, products with the
 * box / strip / piece ladder, retail and wholesale prices, barcodes, and one
 * LEGACY batch per product.
 *
 * - products.id = legacy products.id (target must be empty), so history lines
 *   keep their product without a lookup. Sale/purchase lines that point at
 *   hard-deleted legacy products get an inactive placeholder product
 *   "Deleted product (legacy #id)" with the same id.
 * - Units: piece (factor 1) always; strip = piece_qty when > 1; box =
 *   stripe_qty × piece_qty when larger than the strip. Legacy price is per
 *   piece; strip and box prices are factor × piece price.
 * - Products with price ≤ 0 are imported inactive with no price ("needs price").
 * - Categories: legacy roots and categories with children stay categories;
 *   other (leaf) categories are dosage forms (Tablet, Syrup…), and the
 *   product's category becomes the leaf's parent.
 * - The LEGACY batch (id = product id, no expiry, is_legacy_opening) carries
 *   the opening stock and links legacy sale/purchase lines to a batch, so
 *   legacy sales can still be returned in the new POS.
 */
class Catalog
{
    public const STRENGTH = '/\d+(?:\.\d+)?\s?(?:mg|mcg|gm|g|ml|%|iu)(?:\s?\/\s?\d*(?:\.\d+)?\s?(?:ml|mg|g|gm|l))?/i';

    public function run(Context $c): void
    {
        $this->manufacturers($c);
        [$categoryOf, $formOf] = $this->categories($c);
        $this->products($c, $categoryOf, $formOf);
        $this->placeholders($c);
        $this->units($c);
        $this->batches($c);
        $c->flush();
    }

    private function manufacturers(Context $c): void
    {
        $byName = [];
        foreach ($c->src->table('brands')->orderByDesc('is_active')->orderBy('id')->get() as $b) {
            $name = preg_replace('/\s+/', ' ', trim((string) $b->title));
            if ($name === '') {
                $c->count('manufacturers', 'skipped: empty name');

                continue;
            }
            $key = strtolower($name);
            if (isset($byName[$key])) {
                $c->map('manufacturer', $b->id, $byName[$key]);
                $c->count('manufacturers', 'merged duplicate name');

                continue;
            }
            $id = $c->dst->table('manufacturers')->insertGetId([
                'name' => Str::limit($name, 250, ''), 'is_active' => (bool) $b->is_active, 'legacy_id' => $b->id,
                'created_at' => Context::ts($b->created_at, now()), 'updated_at' => now(),
            ]);
            $byName[$key] = $id;
            $c->map('manufacturer', $b->id, $id);
            $c->count('manufacturers', 'imported');
        }
    }

    /** @return array{0: array<int, ?int>, 1: array<int, ?int>} legacy category id => new category id / dosage form id */
    private function categories(Context $c): array
    {
        $rows = $c->src->table('categories')->orderBy('id')->get()->keyBy('id');
        $hasChildren = [];
        foreach ($rows as $r) {
            if ($r->parent_id && isset($rows[$r->parent_id])) {
                $hasChildren[$r->parent_id] = true;
            }
        }
        $isCategory = fn ($r) => ! $r->parent_id || ! isset($rows[$r->parent_id]) || isset($hasChildren[$r->id]);

        $slugs = [];
        $categoryId = [];
        // Parents before children.
        $pending = $rows->filter($isCategory)->all();
        while ($pending) {
            $progress = false;
            foreach ($pending as $id => $r) {
                $parent = $r->parent_id && isset($rows[$r->parent_id]) ? $r->parent_id : null;
                if ($parent && ! isset($categoryId[$parent])) {
                    continue;
                }
                $slug = Str::slug($r->name) ?: 'category';
                for ($n = 2, $base = $slug; isset($slugs[$slug]); $n++) {
                    $slug = $base.'-'.$n;
                }
                $slugs[$slug] = true;
                $categoryId[$id] = $c->dst->table('categories')->insertGetId([
                    'name' => trim($r->name), 'parent_id' => $parent ? $categoryId[$parent] : null, 'slug' => $slug,
                    'is_active' => (bool) $r->is_active, 'created_at' => Context::ts($r->created_at, now()), 'updated_at' => now(),
                ]);
                $c->map('category', $id, $categoryId[$id]);
                $c->count('categories', 'imported as category');
                unset($pending[$id]);
                $progress = true;
            }
            if (! $progress) {
                break;
            }
        }

        $forms = [];
        $categoryOf = [];
        $formOf = [];
        foreach ($rows as $id => $r) {
            if ($isCategory($r)) {
                $categoryOf[$id] = $categoryId[$id] ?? null;
                $formOf[$id] = null;

                continue;
            }
            $key = strtolower(trim($r->name));
            if (! isset($forms[$key])) {
                $forms[$key] = $c->dst->table('dosage_forms')->insertGetId([
                    'name' => Str::limit(trim($r->name), 100, ''), 'is_active' => (bool) $r->is_active, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $c->count('categories', 'imported as dosage form');
            } else {
                $c->count('categories', 'merged into an existing dosage form');
            }
            $c->map('dosage_form', $id, $forms[$key]);
            $formOf[$id] = $forms[$key];
            $categoryOf[$id] = $categoryId[$r->parent_id] ?? null;
        }

        return [$categoryOf, $formOf];
    }

    private function products(Context $c, array $categoryOf, array $formOf): void
    {
        $lastCost = $c->src->select('SELECT pp.product_id, pp.net_unit_cost FROM product_purchases pp
            JOIN (SELECT product_id, MAX(id) id FROM product_purchases WHERE net_unit_cost > 0 GROUP BY product_id) m ON m.id = pp.id');
        $lastCost = collect($lastCost)->pluck('net_unit_cost', 'product_id')->all();

        $slugs = [];
        $c->src->table('products')->orderBy('id')->chunk(2000, function ($rows) use ($c, $categoryOf, $formOf, $lastCost, &$slugs) {
            $insert = [];
            foreach ($rows as $p) {
                $slug = Str::limit(Str::slug($p->slug ?: $p->name) ?: 'product', 230, '');
                if (isset($slugs[$slug])) {
                    $slug .= '-'.$p->id;
                }
                $slugs[$slug] = true;
                $price = (float) $p->price;
                preg_match_all(self::STRENGTH, (string) $p->name, $m);
                $strength = $m[0] ? Str::limit(implode(' + ', array_unique(array_map('trim', $m[0]))), 100, '') : null;
                if (! $strength) {
                    $c->count('products', 'strength not parsed (review list)');
                }
                if ($price <= 0) {
                    $c->count('products', 'inactive: needs a price');
                }
                $insert[] = [
                    'id' => $p->id, 'sku' => trim($p->code), 'name' => trim($p->name), 'strength' => $strength,
                    'dosage_form_id' => $formOf[$p->category_id] ?? null, 'manufacturer_id' => $c->mapped('manufacturer', (int) $p->brand_id),
                    'category_id' => $categoryOf[$p->category_id] ?? null,
                    'description' => $p->product_details ? strip_tags($p->product_details) : null,
                    'requires_prescription' => false, 'is_batch_tracked' => true,
                    'is_active' => $p->is_active && $price > 0, 'reorder_level_base' => max(0, (int) round((float) $p->alert_quantity)),
                    'image_path' => $p->image && $p->image !== 'zummXD2dvAtI.png' ? 'products/legacy/'.$p->image : null,
                    'slug' => $slug, 'legacy_id' => $p->id,
                    'created_at' => Context::ts($p->created_at, now()), 'updated_at' => Context::ts($p->updated_at, now()),
                ];
                $c->unitCost[$p->id] = (float) $p->cost > 0 ? (float) $p->cost : (float) ($lastCost[$p->id] ?? 0);
                $c->productName[$p->id] = trim($p->name);
                $c->legacyProduct[$p->id] = [(float) $p->piece_qty, (float) $p->stripe_qty, $price, (float) $p->wholesale_price, trim($p->code)];
                $c->count('products', 'imported');
            }
            $c->insert('products', $insert, 500);
        });
    }

    /** Lines that point at hard-deleted products get an inactive placeholder with the same id. */
    private function placeholders(Context $c): void
    {
        $ids = collect($c->src->select('SELECT DISTINCT product_id id FROM (
                SELECT product_id FROM product_sales UNION SELECT product_id FROM product_purchases
                UNION SELECT product_id FROM product_returns UNION SELECT product_id FROM purchase_product_return
                UNION SELECT product_id FROM product_adjustments) x
            WHERE product_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = x.product_id)'))->pluck('id');
        $rows = [];
        foreach ($ids as $id) {
            $name = "Deleted product (legacy #{$id})";
            $rows[] = ['id' => $id, 'sku' => 'LEGACY-DEL-'.$id, 'name' => $name, 'is_active' => false, 'slug' => 'legacy-deleted-'.$id,
                'legacy_id' => $id, 'created_at' => now(), 'updated_at' => now()];
            $c->unitCost[$id] = 0;
            $c->productName[$id] = $name;
            $c->legacyProduct[$id] = [0, 0, 0, 0, null];
            $c->count('products', 'placeholder for a deleted legacy product');
        }
        $c->insert('products', $rows);
    }

    private function units(Context $c): void
    {
        $units = [];
        foreach ($c->legacyProduct as $id => [$perStrip, $strips]) {
            $perStrip = (int) round($perStrip);
            $strips = (int) round($strips);
            $ladder = [['piece', 1]];
            if ($perStrip > 1) {
                $ladder[] = ['strip', $perStrip];
            }
            $box = max(1, $strips) * max(1, $perStrip);
            if ($strips >= 1 && $box > end($ladder)[1]) {
                $ladder[] = ['box', $box];
            }
            $max = end($ladder)[1];
            foreach ($ladder as $i => [$code, $factor]) {
                $units[] = ['product_id' => $id, 'unit_code' => $code, 'label' => ucfirst($code), 'factor' => $factor, 'is_base' => $factor === 1,
                    'is_sale_default' => $factor === 1, 'is_purchase_default' => $factor === $max, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()];
            }
            $c->count('product_units', count($ladder).' unit(s)');
        }
        $c->insert('product_units', $units);

        $retail = (int) $c->dst->table('price_lists')->where('code', 'retail')->value('id');
        $wholesale = (int) $c->dst->table('price_lists')->where('code', 'wholesale')->value('id');
        $prices = [];
        $barcodes = [];
        $c->dst->table('product_units')->select('id', 'product_id', 'unit_code', 'factor')->orderBy('id')->chunk(10000, function ($rows) use ($c, $retail, $wholesale, &$prices, &$barcodes) {
            foreach ($rows as $u) {
                [, , $price, $wholesalePrice, $code] = $c->legacyProduct[$u->product_id];
                if ($u->unit_code === 'piece') {
                    $c->pieceUnit[$u->product_id] = $u->id;
                    if ($code !== null && preg_match('/^\d{4,}$/', $code)) {
                        $barcodes[] = ['product_id' => $u->product_id, 'product_unit_id' => $u->id, 'barcode' => $code, 'created_at' => now(), 'updated_at' => now()];
                    }
                    if ($wholesalePrice > 0) {
                        $prices[] = ['price_list_id' => $wholesale, 'product_unit_id' => $u->id, 'price' => round($wholesalePrice, 4), 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                if ($price > 0) {
                    $prices[] = ['price_list_id' => $retail, 'product_unit_id' => $u->id, 'price' => round($price * $u->factor, 2), 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()];
                }
            }
        });
        $c->insert('product_prices', $prices);
        $c->insert('product_barcodes', $barcodes);
        $c->fact('products', 'retail prices', count(array_filter($prices, fn ($p) => $p['price_list_id'] === $retail)));
        $c->fact('products', 'barcodes (numeric legacy code)', count($barcodes));
    }

    private function batches(Context $c): void
    {
        $rows = [];
        foreach (array_keys($c->legacyProduct) as $id) {
            $rows[] = ['id' => $id, 'product_id' => $id, 'batch_no' => 'LEGACY', 'expiry_date' => null, 'status' => 'active',
                'is_legacy_opening' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        $c->insert('batches', $rows);
        $c->fact('batches', 'LEGACY batches', count($rows));
        $c->legacyProduct = [];
    }
}
