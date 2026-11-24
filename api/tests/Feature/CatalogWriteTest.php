<?php

use App\Models\Category;
use App\Models\DosageForm;
use App\Models\Generic;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use Illuminate\Testing\TestResponse;

const CAT = '/api/pos/v1/catalog';

/** A valid ProductInput with piece / strip (10) / box (100) at 1.50 per piece. */
function productInput(array $over = [], ?array $units = null): array
{
    return $over + [
        'sku' => 'NAPA500', 'name' => 'Napa', 'strength' => '500 mg', 'genericId' => null, 'manufacturerId' => null,
        'dosageFormId' => null, 'categoryId' => null, 'requiresPrescription' => false, 'isActive' => true,
        'reorderLevelBase' => 100, 'description' => 'Fever and pain',
        'units' => $units ?? [
            ['code' => 'piece', 'factor' => 1, 'price' => 1.5, 'wholesalePrice' => 1.3, 'barcode' => '8941100500011', 'isSaleDefault' => false, 'isPurchaseDefault' => false],
            ['code' => 'strip', 'factor' => 10, 'price' => 15, 'wholesalePrice' => null, 'barcode' => '8941100500028', 'isSaleDefault' => true, 'isPurchaseDefault' => false],
            ['code' => 'box', 'factor' => 100, 'price' => 150, 'wholesalePrice' => null, 'barcode' => '', 'isSaleDefault' => false, 'isPurchaseDefault' => false],
        ],
    ];
}

function createProduct($test, $user, array $input): TestResponse
{
    return $test->actingAs($user)->postJson(CAT.'/products', $input);
}

function unitsWith(array $changes): array
{
    $units = productInput()['units'];
    foreach ($changes as $i => $c) {
        $units[$i] = $c === null ? null : array_merge($units[$i], $c);
    }

    return array_values(array_filter($units));
}

// ---------------------------------------------------------------- products

it('creates a product with its unit ladder, retail and wholesale prices and barcodes', function () {
    $user = posUser('Manager');
    $sq = Manufacturer::create(['name' => 'Square Pharmaceuticals']);
    $id = createProduct($this, $user, productInput(['manufacturerId' => $sq->id]))->assertCreated()->json('id');

    $form = $this->actingAs($user)->getJson(CAT."/products/$id/form")->assertOk()
        ->assertJsonPath('sku', 'NAPA500')->assertJsonPath('manufacturerId', $sq->id)->assertJsonPath('description', 'Fever and pain')
        ->assertJsonCount(3, 'units')
        ->assertJsonPath('units.0.price', 1.5)->assertJsonPath('units.0.wholesalePrice', 1.3)->assertJsonPath('units.0.barcode', '8941100500011')
        ->assertJsonPath('units.1.isSaleDefault', true)
        ->assertJsonPath('units.2.isPurchaseDefault', true); // none chosen: the largest unit
    expect($form->json('units.1.wholesalePrice'))->toBeNull();

    // The counter finds it by the strip barcode, priced from the retail list.
    $this->actingAs(posUser('Cashier'))->getJson(CAT.'/products/search?q=8941100500028&warehouse_id='.$this->warehouse->id)
        ->assertJsonPath('0.id', $id)->assertJsonPath('0.units.1.price', 15);
});

it('enforces the unit ladder, unique SKU and unique barcodes', function () {
    $user = posUser('Manager');
    createProduct($this, $user, productInput())->assertCreated();
    $try = fn (array $over, ?array $units = null) => createProduct($this, $user, productInput(['sku' => 'NEW1'] + $over, $units));
    $noBarcodes = fn (array $changes) => array_map(fn ($u) => ['barcode' => ''] + $u, unitsWith($changes));

    createProduct($this, $user, productInput(['units' => $noBarcodes([])]))->assertJsonValidationErrors('sku');
    $try([], $noBarcodes([0 => ['factor' => 5]]))->assertJsonValidationErrors('units');                       // no base unit
    $try([], $noBarcodes([1 => ['factor' => 1]]))->assertJsonValidationErrors('units');                       // two base units
    $try([], $noBarcodes([2 => ['factor' => 10]]))->assertJsonValidationErrors('units');                      // same factor twice
    $try([], $noBarcodes([2 => ['code' => 'strip']]))->assertJsonValidationErrors('units');                   // same unit twice
    $try([], $noBarcodes([1 => ['price' => 0]]))->assertJsonValidationErrors('units.1.price');
    $try([], unitsWith([0 => ['barcode' => 'X1'], 1 => ['barcode' => 'X1'], 2 => ['barcode' => '']]))->assertJsonValidationErrors('units');
    $try([], unitsWith([0 => ['barcode' => '8941100500011'], 1 => ['barcode' => ''], 2 => ['barcode' => '']]))
        ->assertJsonValidationErrors('units')->assertJsonFragment(['Barcode 8941100500011 is already used by another product.']);

    // A deactivated product still owns its SKU.
    Product::where('sku', 'NAPA500')->first()->update(['is_active' => false]);
    createProduct($this, $user, productInput(['units' => $noBarcodes([])]))->assertJsonValidationErrors('sku');
});

it('versions prices and needs the price permission to change them', function () {
    $manager = posUser('Manager');
    $keeper = posUser('Inventory'); // products.edit without products.price
    $id = createProduct($this, $manager, productInput())->json('id');
    // Loads the edit form, applies $changes and saves it as $user.
    $edit = function ($user, array $changes) use ($manager, $id) {
        $form = $this->actingAs($manager)->getJson(CAT."/products/$id/form")->json();

        return $this->actingAs($user)->putJson(CAT."/products/$id", array_replace_recursive($form, $changes));
    };

    $edit($keeper, ['name' => 'Napa Tablet'])->assertOk(); // same prices: allowed
    $edit($keeper, ['units' => [0 => ['price' => 1.6]]])->assertJsonValidationErrors('units');
    $edit($manager, ['units' => [0 => ['price' => 1.6, 'wholesalePrice' => null]]])->assertOk();

    $piece = ProductUnit::where(['product_id' => $id, 'factor' => 1])->first();
    expect(Product::find($id)->name)->toBe('Napa Tablet')
        ->and(ProductPrice::where('product_unit_id', $piece->id)->count())->toBe(3) // retail 1.50 → 1.60, wholesale 1.30 (ended)
        ->and(ProductPrice::where('product_unit_id', $piece->id)->whereNull('effective_to')->count())->toBe(1);
    $this->actingAs($manager)->getJson(CAT."/products/$id/form")->assertJsonPath('units.0.price', 1.6)->assertJsonPath('units.0.wholesalePrice', null);
});

it('keeps units that were bought or sold: same factor, cannot be removed', function () {
    $user = posUser('Manager');
    $id = createProduct($this, $user, productInput())->json('id');
    $strip = ProductUnit::where(['product_id' => $id, 'unit_code' => 'strip'])->first();
    $this->actingAs($user)->postJson('/api/pos/v1/purchases', [
        'status' => 'received', 'warehouseId' => $this->warehouse->id, 'supplierId' => supplier()->id,
        'lines' => [['productId' => $id, 'unitId' => $strip->id, 'qty' => 5, 'unitCost' => 10, 'batchNo' => 'N1', 'expiryDate' => day(300)]],
    ], ['Idempotency-Key' => 'buy'])->assertCreated();

    $form = $this->actingAs($user)->getJson(CAT."/products/$id/form")->json();
    $put = fn (array $units) => $this->actingAs($user)->putJson(CAT."/products/$id", ['units' => $units] + $form);

    $put([$form['units'][0], ['factor' => 12] + $form['units'][1], $form['units'][2]])->assertJsonValidationErrors('units');
    $put([$form['units'][0], $form['units'][2]])->assertJsonValidationErrors('units');
    $put([$form['units'][0], $form['units'][1]])->assertOk(); // the unused box unit can go
    expect(ProductUnit::where('product_id', $id)->pluck('unit_code')->all())->toBe(['piece', 'strip']);
});

it('deactivates products out of the counter and duplicates them as inactive drafts', function () {
    $user = posUser('Manager');
    $id = createProduct($this, $user, productInput())->json('id');

    $this->actingAs($user)->postJson(CAT."/products/$id/duplicate", [], ['Idempotency-Key' => 'd1'])->assertCreated();
    $copy = Product::where('sku', 'NAPA500-C')->firstOrFail();
    expect($copy->name)->toBe('Napa (copy)')->and($copy->is_active)->toBeFalse()->and($copy->barcodes()->count())->toBe(0);
    $this->actingAs($user)->getJson(CAT."/products/{$copy->id}/form")->assertJsonPath('units.1.price', 15)->assertJsonPath('units.0.wholesalePrice', 1.3);

    $this->actingAs($user)->postJson(CAT."/products/$id/deactivate", [], ['Idempotency-Key' => 'x1'])->assertOk();
    $this->actingAs($user)->getJson(CAT.'/products/search?q=Napa&warehouse_id='.$this->warehouse->id)->assertJsonCount(0);
    $this->actingAs(posUser('Cashier'))->postJson(CAT.'/products', productInput(['sku' => 'Z']))->assertForbidden();
});

it('imports CSV rows all or nothing, creating missing masters by name', function () {
    $user = posUser('Manager');
    Manufacturer::create(['name' => 'Beximco Pharmaceuticals']);
    $row = fn ($sku) => ['sku' => $sku, 'name' => 'Napa '.$sku, 'strength' => '500 mg', 'generic' => 'Paracetamol', 'manufacturer' => 'beximco pharmaceuticals',
        'dosage_form' => 'Tablet', 'piece_price' => '1.2', 'pieces_per_strip' => '10', 'strips_per_box' => '20', 'reorder_level' => '200', 'requires_prescription' => 'no'];
    $import = fn ($rows, $key) => $this->actingAs($user)->postJson(CAT.'/products/import', ['rows' => $rows], ['Idempotency-Key' => $key]);

    $import([$row('I1'), $row('I2')], 'a')->assertCreated()->assertJsonPath('imported', 2);
    expect(Manufacturer::count())->toBe(1)->and(Generic::where('name', 'Paracetamol')->exists())->toBeTrue()
        ->and(DosageForm::where('name', 'Tablet')->count())->toBe(1);
    $p = Product::where('sku', 'I1')->first();
    $this->actingAs($user)->getJson(CAT."/products/{$p->id}/form")
        ->assertJsonPath('units.1.factor', 10)->assertJsonPath('units.2.factor', 200)->assertJsonPath('units.2.price', 240);

    $import([$row('I3'), $row('I1')], 'b')->assertJsonValidationErrors('rows.1');
    expect(Product::where('sku', 'I3')->exists())->toBeFalse();
});

// ---------------------------------------------------------------- masters

it('manages generics and manufacturers with unique names; used ones are deactivated, not deleted', function () {
    $user = posUser('Manager');
    $g = $this->actingAs($user)->postJson(CAT.'/generics', ['name' => 'Paracetamol', 'therapeuticClass' => 'Analgesic', 'isActive' => true])
        ->assertCreated()->assertJsonPath('products', 0)->json('id');
    $this->actingAs($user)->postJson(CAT.'/generics', ['name' => 'Paracetamol'])->assertJsonValidationErrors('name');
    $this->actingAs($user)->putJson(CAT."/generics/$g", ['name' => 'Paracetamol', 'therapeuticClass' => 'Analgesic / antipyretic'])
        ->assertOk()->assertJsonPath('therapeuticClass', 'Analgesic / antipyretic');

    $m = $this->actingAs($user)->postJson(CAT.'/manufacturers', ['name' => 'Square Pharmaceuticals', 'shortName' => 'SQUARE', 'country' => 'Bangladesh'])->json('id');
    createProduct($this, $user, productInput(['manufacturerId' => $m, 'genericId' => $g]))->assertCreated();

    $this->actingAs($user)->deleteJson(CAT."/manufacturers/$m")->assertJsonValidationErrors('id');
    $this->actingAs($user)->putJson(CAT."/manufacturers/$m", ['isActive' => false])->assertOk()->assertJsonPath('isActive', false)->assertJsonPath('name', 'Square Pharmaceuticals');
    $this->actingAs($user)->getJson(CAT.'/lookups')->assertJsonCount(0, 'manufacturers')->assertJsonPath('generics.0.name', 'Paracetamol');
    $this->actingAs($user)->getJson(CAT.'/manufacturers?search=square')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.products', 1);

    $unused = $this->actingAs($user)->postJson(CAT.'/generics', ['name' => 'Ibuprofen'])->json('id');
    $this->actingAs($user)->deleteJson(CAT."/generics/$unused")->assertOk();
    expect(Generic::find($unused))->toBeNull()->and(Generic::withTrashed()->find($unused))->not->toBeNull();
});

it('nests categories with full paths and refuses cycles', function () {
    $user = posUser('Manager');
    $root = $this->actingAs($user)->postJson(CAT.'/categories', ['name' => 'Medicine', 'sortOrder' => 1])->assertCreated()->json('id');
    $child = $this->actingAs($user)->postJson(CAT.'/categories', ['name' => 'Antibiotics', 'parentId' => $root])->assertJsonPath('path', 'Medicine › Antibiotics')->json('id');

    $this->actingAs($user)->putJson(CAT."/categories/$root", ['name' => 'Medicine', 'parentId' => $child])->assertJsonValidationErrors('parentId');
    $this->actingAs($user)->deleteJson(CAT."/categories/$root")->assertJsonValidationErrors('id');
    $this->actingAs($user)->getJson(CAT.'/lookups')->assertJsonFragment(['id' => $child, 'name' => 'Medicine › Antibiotics']);
    expect(Category::find($child)->slug)->toBe('antibiotics');
});

it('deactivates dosage forms and keeps master writes to catalog managers', function () {
    $user = posUser('Manager');
    $id = $this->actingAs($user)->postJson(CAT.'/dosage-forms', ['name' => 'Tablet'])->assertCreated()->json('id');
    $this->actingAs($user)->putJson(CAT."/dosage-forms/$id", ['isActive' => false])->assertJsonPath('isActive', false);
    $this->actingAs($user)->getJson(CAT.'/dosage-forms?all=1')->assertJsonCount(1);

    $this->actingAs(posUser('Inventory'))->postJson(CAT.'/generics', ['name' => 'X'])->assertForbidden();
    $this->actingAs(posUser('Cashier'))->getJson(CAT.'/generics')->assertOk();
});
