<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Rider;
use App\Models\Sale;
use App\Models\ShippingZone;
use App\Models\Warehouse;
use App\Services\OnlineOrders;

beforeEach(function () {
    $this->tablet = tabletWithStock([['LATE', day(400), 100, 1.0], ['SOON', day(30), 15, 1.2]]);
    $this->manager = posUser('Manager');
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'free_shipping_min' => 1000, 'partner' => 'own_rider']);
    $this->rider = Rider::create(['name' => 'Rasel', 'phone' => '01711000001', 'status' => 'active']);
});

/** Places a phone order for $strips strips (৳20 each) and returns its JSON. */
function phoneOrder($test, int $strips = 2, array $extra = [], string $key = 'o1')
{
    return $test->actingAs($test->manager)->postJson('/api/pos/v1/orders', $extra + [
        'name' => 'Nasima Akter', 'phone' => '+880 1712-345678', 'address' => 'House 12, Road 5', 'zoneId' => $test->zone->id,
        'lines' => [['productId' => $test->tablet['product']->id, 'unitId' => $test->tablet['strip']->id, 'qty' => $strips]],
    ], ['Idempotency-Key' => $key]);
}

function move($test, int $id, string $to, string $key, ?string $note = null)
{
    return $test->actingAs($test->manager)->postJson("/api/pos/v1/orders/$id/status", ['status' => $to, 'note' => $note], ['Idempotency-Key' => $key]);
}

it('places a phone order at server prices with zone shipping and creates the customer', function () {
    $res = phoneOrder($this, 2, ['lines' => [['productId' => $this->tablet['product']->id, 'unitId' => $this->tablet['strip']->id, 'qty' => 2, 'price' => 1]]])
        ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('subtotal', 40)->assertJsonPath('shipping', 60)->assertJsonPath('codAmount', 100)
        ->assertJsonPath('channel', 'phone')->assertJsonPath('zone', 'Dhanmondi, Dhaka')->assertJsonPath('history.0.status', 'pending');

    expect($res->json('number'))->toStartWith('OO-DHK1-')
        ->and(Customer::where('phone_normalized', '01712345678')->value('name'))->toBe('Nasima Akter')
        ->and(stockOf($this->tablet['batches'][0]) + stockOf($this->tablet['batches'][1]))->toBe(115); // nothing taken yet

    // Same phone again: same customer, no duplicate.
    phoneOrder($this, 1, ['name' => 'Someone else'], 'o2')->assertCreated();
    expect(Customer::where('phone_normalized', '01712345678')->count())->toBe(1);
});

it('gives free shipping at or above the zone minimum', function () {
    $this->zone->update(['free_shipping_min' => 40]);
    phoneOrder($this, 2)->assertCreated()->assertJsonPath('shipping', 0)->assertJsonPath('codAmount', 40);
});

it('refuses orders without enough sellable stock or with an inactive zone', function () {
    phoneOrder($this, 12)->assertJsonValidationErrors('lines'); // 120 pieces, 115 in stock
    $this->zone->update(['is_active' => false]);
    phoneOrder($this, 1, [], 'o2')->assertJsonValidationErrors('zoneId');
});

it('takes stock once at confirm (FEFO) and runs the delivery flow with COD held by the rider', function () {
    $id = phoneOrder($this, 2)->json('id');
    $confirmed = move($this, $id, 'confirmed', 'c')->assertOk()->assertJsonPath('status', 'confirmed');

    // 20 pieces: 15 from SOON, 5 from LATE
    expect(stockOf($this->tablet['batches'][1]))->toBe(0)->and(stockOf($this->tablet['batches'][0]))->toBe(95)
        ->and($confirmed->json('lines.0.batchNo'))->toContain('SOON');
    $sale = Sale::findOrFail($confirmed->json('saleId'));
    expect((float) $sale->grand_total)->toBe(100.0)->and((float) $sale->shipping_total)->toBe(60.0)->and($sale->payment_status)->toBe('unpaid');

    move($this, $id, 'packed', 'p')->assertOk();
    move($this, $id, 'in_transit', 't1')->assertJsonValidationErrors('status'); // no rider yet
    $this->actingAs($this->manager)->postJson("/api/pos/v1/orders/$id/rider", ['rider_id' => $this->rider->id], ['Idempotency-Key' => 'r'])->assertOk()->assertJsonPath('riderName', 'Rasel');
    move($this, $id, 'in_transit', 't2')->assertOk();
    move($this, $id, 'delivered', 'd')->assertOk()->assertJsonPath('status', 'delivered');

    expect($sale->fresh()->payment_status)->toBe('paid')
        ->and(OnlineOrders::cashHeld($this->rider->id))->toBe(100.0)
        ->and(AccountTransaction::where('source_type', 'rider_handover')->count())->toBe(0); // not in Cash in hand yet
    move($this, $id, 'cancelled', 'x', 'too late')->assertJsonValidationErrors('status');
});

it('moves rider cash into Cash in hand on handover, never more than held', function () {
    $id = phoneOrder($this, 2)->json('id');
    $this->actingAs($this->manager)->postJson("/api/pos/v1/orders/$id/rider", ['rider_id' => $this->rider->id], ['Idempotency-Key' => 'r']);
    foreach (['confirmed', 'packed', 'in_transit', 'delivered'] as $s) {
        move($this, $id, $s, $s)->assertOk();
    }
    $handover = fn ($amount, $key) => $this->actingAs($this->manager)->postJson("/api/pos/v1/delivery/riders/{$this->rider->id}/handover", ['amount' => $amount, 'note' => 'Evening'], ['Idempotency-Key' => $key]);

    $handover(150, 'h1')->assertJsonValidationErrors('amount');
    $handover(70, 'h2')->assertCreated()->assertJsonPath('cashHeld', 30);

    $cash = Account::where('type', 'cash')->orderByDesc('is_default')->value('id');
    expect((float) AccountTransaction::where(['account_id' => $cash, 'source_type' => 'rider_handover'])->sum('amount'))->toBe(70.0);
    $this->actingAs($this->manager)->deleteJson("/api/pos/v1/delivery/riders/{$this->rider->id}")->assertStatus(422); // still holds ৳30
});

it('puts stock back into the same batches when a confirmed order is cancelled', function () {
    $id = phoneOrder($this, 2)->json('id');
    move($this, $id, 'confirmed', 'c');
    move($this, $id, 'cancelled', 'x1')->assertJsonValidationErrors('note');
    move($this, $id, 'cancelled', 'x2', 'Customer not reachable')->assertOk()->assertJsonPath('status', 'cancelled');

    expect(stockOf($this->tablet['batches'][1]))->toBe(15)->and(stockOf($this->tablet['batches'][0]))->toBe(100)
        ->and(Sale::find(OnlineOrder::find($id)->sale_id)->status)->toBe('void');
});

it('follows the status order and keeps cashiers out', function () {
    $id = phoneOrder($this, 1)->json('id');
    move($this, $id, 'packed', 'p')->assertJsonValidationErrors('status');
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/orders')->assertForbidden();
    $this->actingAs(posUser('Cashier'))->postJson("/api/pos/v1/orders/$id/status", ['status' => 'confirmed'], ['Idempotency-Key' => 'k'])->assertForbidden();
});

it('ships from the online branch chosen in settings', function () {
    $utr = Warehouse::create(['code' => 'UTR1', 'name' => 'Uttara']);
    $admin = posUser('Admin');
    $this->actingAs($admin)->putJson('/api/pos/v1/orders/settings', ['warehouseId' => $utr->id])->assertOk()->assertJsonPath('warehouseId', $utr->id);

    phoneOrder($this, 1)->assertJsonValidationErrors('lines'); // Uttara has no stock of the tablet
    $this->actingAs($admin)->putJson('/api/pos/v1/orders/settings', ['warehouseId' => $this->warehouse->id])->assertOk();
    phoneOrder($this, 1, [], 'o2')->assertCreated();
});

it('manages riders and zones, keeping history instead of deleting', function () {
    $r = $this->actingAs($this->manager)->postJson('/api/pos/v1/delivery/riders', ['name' => 'New Rider', 'phone' => '01900000000'])->assertCreated()->json('id');
    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/riders/$r", ['name' => 'New Rider', 'phone' => '01900000000', 'status' => 'on_leave'])->assertJsonPath('status', 'on_leave');
    $this->actingAs($this->manager)->deleteJson("/api/pos/v1/delivery/riders/$r")->assertOk()->assertJsonPath('message', 'Rider deleted.');

    $this->actingAs($this->manager)->postJson('/api/pos/v1/delivery/zones', ['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 50, 'partner' => 'own_rider'])
        ->assertJsonValidationErrors('thana');
    phoneOrder($this, 1);
    $this->actingAs($this->manager)->deleteJson("/api/pos/v1/delivery/zones/{$this->zone->id}")->assertOk();
    expect($this->zone->fresh()->is_active)->toBeFalse();
    $this->actingAs($this->manager)->getJson('/api/pos/v1/delivery/zones?all=1')->assertJsonPath('0.isActive', false);
});

it('accepts partial rider and zone updates and keeps rider phones unique', function () {
    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/riders/{$this->rider->id}", ['status' => 'on_leave'])
        ->assertOk()->assertJsonPath('status', 'on_leave')->assertJsonPath('name', 'Rasel');
    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/zones/{$this->zone->id}", ['isActive' => false])
        ->assertOk()->assertJsonPath('isActive', false)->assertJsonPath('rate', 60);
    $this->actingAs($this->manager)->postJson('/api/pos/v1/delivery/riders', ['name' => 'Twin', 'phone' => '01711000001'])->assertJsonValidationErrors('phone');
});
