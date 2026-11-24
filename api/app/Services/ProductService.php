<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DosageForm;
use App\Models\Generic;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product create/edit with its unit ladder, retail and wholesale prices and
 * barcodes.
 *
 * - Unique SKU; exactly one base unit (factor 1); one unit per code and per
 *   factor; prices above 0; barcodes unique across all products.
 * - Exactly one sale default and one purchase default unit (defaults to the
 *   base unit and the largest unit).
 * - A unit already used on a sale or purchase keeps its factor and cannot be
 *   removed (history and stock conversions depend on it).
 * - Changing an existing price needs products.price; prices are versioned
 *   (Pricing::setPrice), never overwritten.
 * - Products are never deleted: deactivate (hidden from the counter) instead.
 */
class ProductService
{
    public const UNIT_CODES = ['piece', 'strip', 'box', 'bottle', 'tube', 'vial'];

    public function __construct(private Pricing $pricing) {}

    public function save(User $user, array $data, ?Product $product = null): Product
    {
        $units = $this->validateUnits($data['units'], $product);

        return DB::transaction(function () use ($user, $data, $product, $units) {
            $creating = ! $product;
            $product ??= new Product;
            $product->fill([
                'sku' => trim($data['sku']), 'name' => trim($data['name']),
                'strength' => ($data['strength'] ?? '') !== '' ? trim($data['strength']) : null,
                'generic_id' => $data['genericId'] ?? null, 'manufacturer_id' => $data['manufacturerId'] ?? null,
                'dosage_form_id' => $data['dosageFormId'] ?? null, 'category_id' => $data['categoryId'] ?? null,
                'requires_prescription' => (bool) ($data['requiresPrescription'] ?? false),
                'is_active' => (bool) ($data['isActive'] ?? true),
                'reorder_level_base' => (int) ($data['reorderLevelBase'] ?? 0),
                'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            ])->save();

            $keep = [];
            $retail = $creating ? collect() : $this->pricing->forUnits($product->units()->pluck('id')->all());
            $wholesale = $creating ? collect() : $this->pricing->forUnits($product->units()->pluck('id')->all(), 'wholesale');
            $priceChanged = false;
            foreach ($units as $i => $u) {
                $unit = ! empty($u['id']) ? $product->units()->findOrFail($u['id']) : new ProductUnit(['product_id' => $product->id]);
                $unit->fill([
                    'unit_code' => $u['code'], 'label' => ucfirst($u['code']), 'factor' => (int) $u['factor'],
                    'is_base' => (int) $u['factor'] === 1, 'is_sale_default' => $u['isSaleDefault'], 'is_purchase_default' => $u['isPurchaseDefault'],
                    'sort_order' => $i,
                ])->save();
                $keep[] = $unit->id;

                $old = $retail->get($unit->id);
                if ($old === null || round((float) $old, 2) !== round((float) $u['price'], 2)) {
                    $priceChanged = $priceChanged || $old !== null;
                    $this->pricing->setPrice($unit->id, (float) $u['price'], $user->id);
                }
                $oldW = $wholesale->get($unit->id);
                $newW = $u['wholesalePrice'] ?? null;
                if ($newW === null && $oldW !== null) {
                    $this->pricing->endPrice($unit->id, 'wholesale');
                    $priceChanged = true;
                } elseif ($newW !== null && ($oldW === null || round((float) $oldW, 2) !== round((float) $newW, 2))) {
                    $priceChanged = $priceChanged || $oldW !== null;
                    $this->pricing->setPrice($unit->id, (float) $newW, $user->id, 'wholesale');
                }
            }
            if ($priceChanged && ! $user->can('products.price')) {
                throw ValidationException::withMessages(['units' => 'You are not allowed to change selling prices.']);
            }

            // Units dropped from the form (validateUnits already refused used ones).
            $product->units()->whereNotIn('id', $keep)->get()->each->delete();

            // Barcodes follow the form: one per unit, unique across products.
            ProductBarcode::where('product_id', $product->id)->delete();
            foreach ($units as $i => $u) {
                if (($u['barcode'] ?? '') !== '') {
                    ProductBarcode::create(['product_id' => $product->id, 'product_unit_id' => $keep[$i], 'barcode' => $u['barcode']]);
                }
            }

            AuditLog::record($user->id, $creating ? 'product.created' : 'product.updated', 'product', $product->id, ['sku' => $product->sku]);

            return $product;
        });
    }

    public function deactivate(User $user, Product $product): void
    {
        $product->update(['is_active' => false]);
        AuditLog::record($user->id, 'product.deactivated', 'product', $product->id, null);
    }

    /** Copies a product (units and prices, no barcodes or stock) as an inactive draft to adjust. */
    public function duplicate(User $user, Product $source): Product
    {
        $sku = $source->sku.'-C';
        for ($n = 2; Product::withTrashed()->where('sku', $sku)->exists(); $n++) {
            $sku = $source->sku.'-C'.$n;
        }
        $retail = $this->pricing->forUnits($source->units->pluck('id')->all());
        $wholesale = $this->pricing->forUnits($source->units->pluck('id')->all(), 'wholesale');

        return $this->save($user, [
            'sku' => $sku, 'name' => $source->name.' (copy)', 'strength' => $source->strength,
            'genericId' => $source->generic_id, 'manufacturerId' => $source->manufacturer_id,
            'dosageFormId' => $source->dosage_form_id, 'categoryId' => $source->category_id,
            'requiresPrescription' => $source->requires_prescription, 'isActive' => false,
            'reorderLevelBase' => $source->reorder_level_base, 'description' => $source->description,
            'units' => $source->units->sortBy('factor')->map(fn ($u) => [
                'code' => $u->unit_code, 'factor' => $u->factor, 'price' => (float) ($retail[$u->id] ?? 0),
                'wholesalePrice' => isset($wholesale[$u->id]) ? (float) $wholesale[$u->id] : null, 'barcode' => '',
                'isSaleDefault' => $u->is_sale_default, 'isPurchaseDefault' => $u->is_purchase_default,
            ])->values()->all(),
        ]);
    }

    /**
     * Imports CSV rows (all or nothing). New SKUs only; generic, manufacturer
     * and dosage form are matched by name and created when missing.
     *
     * @return int products imported
     */
    public function import(User $user, array $rows): int
    {
        return DB::transaction(function () use ($user, $rows) {
            $find = function (string $model, ?string $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    return null;
                }

                return $model::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id') ?? $model::create(['name' => $name])->id;
            };
            foreach ($rows as $i => $r) {
                $line = $i + 1;
                $sku = trim((string) ($r['sku'] ?? ''));
                $price = (float) ($r['piece_price'] ?? 0);
                $perStrip = (int) ($r['pieces_per_strip'] ?? 0);
                $perBox = (int) ($r['strips_per_box'] ?? 0);
                if ($sku === '' || trim((string) ($r['name'] ?? '')) === '' || $price <= 0) {
                    throw ValidationException::withMessages(["rows.$i" => "Row $line: SKU, name and a piece price above 0 are required."]);
                }
                if (Product::withTrashed()->where('sku', $sku)->exists()) {
                    throw ValidationException::withMessages(["rows.$i" => "Row $line: SKU $sku already exists."]);
                }
                $units = [['code' => 'piece', 'factor' => 1, 'price' => $price]];
                if ($perStrip > 1) {
                    $units[] = ['code' => 'strip', 'factor' => $perStrip, 'price' => round($price * $perStrip, 2)];
                    if ($perBox > 1) {
                        $units[] = ['code' => 'box', 'factor' => $perStrip * $perBox, 'price' => round($price * $perStrip * $perBox, 2)];
                    }
                }
                try {
                    $this->save($user, [
                        'sku' => $sku, 'name' => $r['name'], 'strength' => $r['strength'] ?? null,
                        'genericId' => $find(Generic::class, $r['generic'] ?? null),
                        'manufacturerId' => $find(Manufacturer::class, $r['manufacturer'] ?? null),
                        'dosageFormId' => $find(DosageForm::class, $r['dosage_form'] ?? null),
                        'requiresPrescription' => in_array(strtolower(trim((string) ($r['requires_prescription'] ?? ''))), ['yes', 'y', '1', 'true'], true),
                        'reorderLevelBase' => (int) ($r['reorder_level'] ?? 0), 'isActive' => true,
                        'units' => array_map(fn ($u) => $u + ['wholesalePrice' => null, 'barcode' => '', 'isSaleDefault' => false, 'isPurchaseDefault' => false], $units),
                    ]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["rows.$i" => "Row $line: ".collect($e->errors())->flatten()->first()]);
                }
            }

            return count($rows);
        });
    }

    /** Checks the unit ladder and returns the units with defaults resolved. */
    private function validateUnits(array $units, ?Product $product): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['units' => $msg]);
        if (count(array_filter($units, fn ($u) => (int) $u['factor'] === 1)) !== 1) {
            $fail('Exactly one base unit with factor 1 is required.');
        }
        foreach (['code' => 'unit', 'factor' => 'factor'] as $key => $label) {
            $values = array_map(fn ($u) => (string) $u[$key], $units);
            if (count(array_unique($values)) !== count($values)) {
                $fail("Each unit needs a different $label.");
            }
        }
        $barcodes = array_values(array_filter(array_map(fn ($u) => trim((string) ($u['barcode'] ?? '')), $units)));
        if (count(array_unique($barcodes)) !== count($barcodes)) {
            $fail('Barcodes must be unique.');
        }
        $taken = ProductBarcode::whereIn('barcode', $barcodes)->when($product, fn ($q) => $q->where('product_id', '!=', $product->id))->value('barcode');
        if ($taken) {
            $fail("Barcode $taken is already used by another product.");
        }

        if ($product) {
            $used = DB::table('sale_items')->whereIn('product_unit_id', $product->units()->pluck('id'))->pluck('product_unit_id')
                ->merge(DB::table('purchase_items')->whereIn('product_unit_id', $product->units()->pluck('id'))->pluck('product_unit_id'))
                ->unique();
            $current = $product->units()->get()->keyBy('id');
            $sent = collect($units)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            foreach ($sent as $id) {
                if (! $current->has($id)) {
                    $fail('A unit does not belong to this product.');
                }
            }
            foreach ($used as $id) {
                $u = collect($units)->firstWhere('id', $id);
                if (! $u) {
                    $fail("The {$current[$id]->unit_code} unit is used on sales or purchases and cannot be removed.");
                }
                if ((int) $u['factor'] !== $current[$id]->factor) {
                    $fail("The {$current[$id]->unit_code} unit is used on sales or purchases; its factor cannot change.");
                }
            }
        }

        // Defaults: exactly one each; fall back to the base unit (sale) and the largest unit (purchase).
        $units = array_values($units);
        $sale = array_keys(array_filter($units, fn ($u) => ! empty($u['isSaleDefault'])));
        $purchase = array_keys(array_filter($units, fn ($u) => ! empty($u['isPurchaseDefault'])));
        $factors = array_map(fn ($u) => (int) $u['factor'], $units);
        $saleIdx = count($sale) === 1 ? $sale[0] : array_search(1, $factors, true);
        $purchaseIdx = count($purchase) === 1 ? $purchase[0] : array_search(max($factors), $factors, true);
        foreach ($units as $i => &$u) {
            $u['isSaleDefault'] = $i === $saleIdx;
            $u['isPurchaseDefault'] = $i === $purchaseIdx;
            $u['barcode'] = trim((string) ($u['barcode'] ?? ''));
        }

        return $units;
    }
}
