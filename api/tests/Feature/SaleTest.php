<?php

use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Warehouse;

function sell($test, $user, array $payload, string $key = 'key-1')
{
    return $test->actingAs($user)->postJson('/api/pos/v1/sales', $payload + [
        'warehouseId' => $test->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
    ], ['Idempotency-Key' => $key]);
}

it('prices on the server and ignores client totals', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.5]]);

    $res = sell($this, posUser(), [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2, 'price' => 0.01, 'total' => 0.01]],
        'payments' => [['method' => 'cash', 'amount' => 50]],
        'grandTotal' => 1,
    ])->assertCreated();

    // 2 strips × 10 pieces × ৳2.00 = ৳40; ৳50 tendered → ৳10 change.
    $res->assertJsonPath('grandTotal', 40)->assertJsonPath('change', 10)->assertJsonPath('due', 0)->assertJsonPath('paymentStatus', 'paid');
    expect($res->json('number'))->toStartWith('S-DHK1-'.now()->year.'-00001');
    expect((float) Sale::first()->paid_total)->toBe(40.0);
});

it('allocates FEFO across batches and records cost', function () {
    $t = tabletWithStock([['LATE', day(400), 50, 1.0], ['SOON', day(30), 15, 1.2]]);
    [$late, $soon] = $t['batches'];

    $res = sell($this, posUser(), [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 40]],
    ])->assertCreated();

    expect(stockOf($soon))->toBe(0)->and(stockOf($late))->toBe(45);
    expect($res->json('lines.0.batches'))->toHaveCount(2)
        ->and($res->json('lines.0.batches.0.batchNo'))->toBe('SOON');
    // 15 × 1.2 + 5 × 1.0
    expect((float) Sale::first()->cost_total)->toBe(23.0);
    // MySQL returns SUM() of an INT column as DECIMAL (a string), SQLite as an int.
    expect((int) StockMovement::where('type', 'sale')->sum('qty_base'))->toBe(-20);
});

it('never sells expired stock', function () {
    $t = tabletWithStock([['OLD', day(-1), 100, 1.0], ['NEW', day(100), 5, 1.0]]);

    sell($this, posUser(), [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 10]],
        'payments' => [['method' => 'cash', 'amount' => 20]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.qty']);

    expect(stockOf($t['batches'][0]))->toBe(100)->and(Sale::count())->toBe(0);
});

it('rejects an explicitly chosen expired batch', function () {
    $t = tabletWithStock([['OLD', day(-1), 100, 1.0], ['NEW', day(100), 50, 1.0]]);
    $manager = posUser('Manager');

    sell($this, $manager, [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 1, 'batchId' => $t['batches'][0]->id]],
        'payments' => [['method' => 'cash', 'amount' => 2]],
    ])->assertUnprocessable()->assertJsonFragment(['lines.0.qty' => ['Batch OLD expired on '.day(-1).' and cannot be sold.']]);
});

it('rejects insufficient stock and leaves nothing behind', function () {
    $t = tabletWithStock([['A1', day(200), 15, 1.0]]);

    sell($this, posUser(), [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 40]],
    ])->assertUnprocessable();

    expect(stockOf($t['batches'][0]))->toBe(15)->and(Sale::count())->toBe(0)->and(StockMovement::where('type', 'sale')->count())->toBe(0);
});

it('replays an idempotent retry without selling twice', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    $payload = ['lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20]]];

    $first = sell($this, $user, $payload, 'same-key')->assertCreated();
    $again = sell($this, $user, $payload, 'same-key')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('number'))->toBe($first->json('number'));
    expect(Sale::count())->toBe(1)->and(stockOf($t['batches'][0]))->toBe(90);
});

it('rejects a reused key with a different body', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    $line = fn ($q) => ['lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => $q]], 'payments' => [['method' => 'cash', 'amount' => 100]]];

    sell($this, $user, $line(1), 'k')->assertCreated();
    sell($this, $user, $line(2), 'k')->assertUnprocessable();
    expect(Sale::count())->toBe(1);
});

it('releases the key when the sale fails so a corrected retry works', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();

    sell($this, $user, ['lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 50]], 'payments' => []], 'k')->assertUnprocessable();
    sell($this, $user, ['lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 50]], 'payments' => []], 'k')->assertUnprocessable();
    expect(IdempotencyKey::count())->toBe(0);
});

it('requires an idempotency key', function () {
    $this->actingAs(posUser())->postJson('/api/pos/v1/sales', [])->assertStatus(428);
});

it('enforces role discount limits', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $cashier = posUser('Cashier'); // 5 % line and order limit

    sell($this, $cashier, [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1, 'discount' => 10]],
        'payments' => [['method' => 'cash', 'amount' => 20]],
    ])->assertJsonValidationErrors('lines.0.discount');

    sell($this, $cashier, [
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1, 'discount' => 5]],
        'payments' => [['method' => 'cash', 'amount' => 19]],
    ], 'k2')->assertCreated()->assertJsonPath('grandTotal', 19);
});

it('blocks credit for walk-in customers but allows it for registered ones', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    $line = [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]];

    sell($this, $user, ['lines' => $line, 'payments' => [['method' => 'cash', 'amount' => 5]]])->assertJsonValidationErrors('payments');

    $customer = Customer::create(['code' => 'C-0100', 'name' => 'Registered', 'phone_normalized' => '01711111111']);
    sell($this, $user, ['customerId' => $customer->id, 'lines' => $line, 'payments' => [['method' => 'cash', 'amount' => 5]]], 'k2')
        ->assertCreated()->assertJsonPath('due', 15)->assertJsonPath('paymentStatus', 'partial');

    $this->actingAs($user)->getJson('/api/pos/v1/customers/lookup?q=Registered')->assertJsonPath('0.dueBalance', 15);
});

it('needs a reference for mobile wallet payments and rejects non-cash overpayment', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    $line = [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]];

    sell($this, $user, ['lines' => $line, 'payments' => [['method' => 'bkash', 'amount' => 20]]])->assertJsonValidationErrors('payments.0.reference');
    sell($this, $user, ['lines' => $line, 'payments' => [['method' => 'bkash', 'amount' => 30, 'reference' => 'TX1']]], 'k2')->assertJsonValidationErrors('payments');
    sell($this, $user, ['lines' => $line, 'payments' => [['method' => 'bkash', 'amount' => 20, 'reference' => 'TX1']]], 'k3')->assertCreated();
});

it('allows custom items only with permission and moves no stock', function () {
    $user = posUser();
    $custom = ['lines' => [['productId' => 0, 'unitId' => 0, 'qty' => 2, 'custom' => ['name' => 'Bandage', 'code' => 'B1', 'price' => 15]]], 'payments' => [['method' => 'cash', 'amount' => 30]]];

    sell($this, $user, $custom)->assertJsonValidationErrors('lines.0');

    $user->givePermissionTo('pos.custom_item');
    sell($this, $user->fresh(), $custom, 'k2')->assertCreated()->assertJsonPath('grandTotal', 30);
    expect(StockMovement::where('type', 'sale')->count())->toBe(0);
});

it('does not let a cashier sell from another warehouse', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $other = Warehouse::create(['code' => 'UTR1', 'name' => 'Uttara']);

    sell($this, posUser(), ['warehouseId' => $other->id, 'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20]]])
        ->assertJsonValidationErrors('warehouseId');
});

it('numbers sales sequentially and lists them', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    foreach (['a', 'b'] as $k) {
        sell($this, $user, ['lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2]]], $k)->assertCreated();
    }

    $list = $this->actingAs($user)->getJson('/api/pos/v1/sales')->assertOk();
    expect($list->json('meta.total'))->toBe(2)
        ->and($list->json('data.0.number'))->toEndWith('00002')
        ->and($list->json('data.0.items'))->toBe(1);
});
