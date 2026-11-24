<?php

use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Pricing;
use Illuminate\Testing\TestResponse;

const HELD = '/api/pos/v1/sales/held';

/** Holds $lines (strips of the tablet unless given) for $customer. */
function holdCart($test, $user, array $tablet, array $extra = [], ?array $lines = null): TestResponse
{
    return $test->actingAs($user)->postJson(HELD, $extra + [
        'warehouseId' => $test->warehouse->id, 'customerId' => null, 'orderDiscount' => 0,
        'lines' => $lines ?? [['productId' => $tablet['product']->id, 'unitId' => $tablet['strip']->id, 'qty' => 2, 'discount' => 0]],
    ], ['Idempotency-Key' => uniqid('h', true)]);
}

it('holds a cart without touching stock and lists it at current prices', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 100, 1.0]]); // strip ৳20
    $c = Customer::create(['code' => 'C-0500', 'name' => 'Rahim', 'phone_normalized' => '01711111111']);

    $res = holdCart($this, $user, $t, ['customerId' => $c->id, 'orderDiscount' => 5], [
        ['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2, 'discount' => 10],                  // 36
        ['productId' => 0, 'unitId' => 0, 'qty' => 1, 'custom' => ['name' => 'Bandage roll', 'code' => '', 'price' => 30]], // 30
    ])->assertCreated()->assertJsonPath('items', 2)->assertJsonPath('total', 61)->assertJsonPath('customer', 'Rahim')
        ->assertJsonPath('heldBy', $user->name)->assertJsonPath('source', 'server');
    expect($res->json('id'))->toStartWith('H-DHK1-')
        ->and(StockMovement::where('type', 'sale')->count())->toBe(0)
        ->and(stockOf($t['batches'][0]))->toBe(100);

    // A price change shows in the list: totals are never frozen at hold time.
    app(Pricing::class)->setPrice($t['strip']->id, 25, $user->id);
    $this->actingAs(posUser('Cashier'))->getJson(HELD)->assertOk()->assertJsonCount(1)->assertJsonPath('0.total', 70);
});

it('resumes a held sale once, with live products, and closes it', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 100, 1.0]]);
    $number = holdCart($this, $user, $t)->json('id');
    $other = posUser('Cashier');

    $this->actingAs($other)->postJson(HELD."/$number/resume", [], ['Idempotency-Key' => 'r1'])->assertOk()
        ->assertJsonPath('id', $number)->assertJsonPath('customer', null)
        ->assertJsonPath('lines.0.product.id', $t['product']->id)->assertJsonPath('lines.0.product.units.1.price', 20)
        ->assertJsonPath('lines.0.product.batches.0.batchNo', 'A')->assertJsonPath('lines.0.qty', 2)->assertJsonPath('skipped', []);

    $this->actingAs($user)->postJson(HELD."/$number/resume", [], ['Idempotency-Key' => 'r2'])
        ->assertJsonValidationErrors('held')->assertJsonFragment(["$number was already resumed by {$other->name} at ".now()->format('H:i').'.']);
    $this->actingAs($user)->getJson(HELD)->assertJsonCount(0);
    expect(HeldSale::where('number', $number)->value('status'))->toBe('resumed');
});

it('drops lines whose product was deactivated and reports them', function () {
    $user = posUser('Cashier');
    $a = tabletWithStock([['A', day(200), 100, 1.0]]);
    $b = tabletWithStock([['B', day(200), 100, 1.0]]);
    $number = holdCart($this, $user, $a, [], [
        ['productId' => $a['product']->id, 'unitId' => $a['strip']->id, 'qty' => 1],
        ['productId' => $b['product']->id, 'unitId' => $b['piece']->id, 'qty' => 3],
    ])->json('id');
    Product::whereKey($b['product']->id)->update(['is_active' => false]);

    $this->actingAs($user)->postJson(HELD."/$number/resume", [], ['Idempotency-Key' => 'r'])
        ->assertJsonCount(1, 'lines')->assertJsonPath('skipped', ['Testamol']);
});

it('discards a held sale once and keeps it on record', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 100, 1.0]]);
    $number = holdCart($this, $user, $t)->json('id');

    $this->actingAs($user)->deleteJson(HELD."/$number")->assertOk();
    $this->actingAs($user)->deleteJson(HELD."/$number")->assertJsonValidationErrors('held');
    $this->actingAs($user)->postJson(HELD."/$number/resume", [], ['Idempotency-Key' => 'r'])->assertJsonValidationErrors('held');
    expect(HeldSale::where('number', $number)->value('status'))->toBe('discarded');
});

it('keeps held sales inside the warehouse and validates the cart', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 100, 1.0]]);
    $number = holdCart($this, $user, $t)->json('id');
    $elsewhere = posUser('Cashier', Warehouse::create(['code' => 'UTR1', 'name' => 'Uttara']));

    $this->actingAs($elsewhere)->getJson(HELD)->assertJsonCount(0);
    $this->actingAs($elsewhere)->postJson(HELD."/$number/resume", [], ['Idempotency-Key' => 'x'])->assertNotFound();
    $this->actingAs($elsewhere)->getJson(HELD.'?warehouse_id='.$this->warehouse->id)->assertJsonValidationErrors('warehouse_id');

    holdCart($this, $user, $t, [], [['productId' => $t['product']->id, 'unitId' => 999999, 'qty' => 1]])->assertJsonValidationErrors('lines.0.unitId');
    holdCart($this, $user, $t, [], [])->assertJsonValidationErrors('lines');
    holdCart($this, posUser('Inventory'), $t)->assertForbidden();
});
