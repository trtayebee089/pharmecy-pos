<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Sale;
use Illuminate\Testing\TestResponse;

/** Senior citizen (7 %) member. */
function groupMember(float $pct = 7): Customer
{
    $g = CustomerGroup::create(['name' => 'Senior citizen '.uniqid(), 'discount_pct' => $pct]);

    return Customer::create(['code' => 'C-'.uniqid(), 'name' => 'Abdul', 'phone_normalized' => '017'.random_int(10000000, 99999999), 'customer_group_id' => $g->id]);
}

/** Sells 5 strips (৳20 each = ৳100) with the given manual line discount, paid in cash. */
function sellTo($test, $user, Customer $c, array $tablet, float $manual = 0, array $extraLines = [], float $orderDiscount = 0): TestResponse
{
    return $test->actingAs($user)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $test->warehouse->id, 'customerId' => $c->id, 'orderDiscount' => $orderDiscount,
        'lines' => [['productId' => $tablet['product']->id, 'unitId' => $tablet['strip']->id, 'qty' => 5, 'discount' => $manual], ...$extraLines],
        'payments' => [['method' => 'cash', 'amount' => 200]],
    ], ['Idempotency-Key' => uniqid('g', true)]);
}

it('applies the group discount to every catalogue line automatically and records it', function () {
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = groupMember(7);

    $res = sellTo($this, posUser('Cashier'), $c, $t)->assertCreated()
        ->assertJsonPath('grandTotal', 93)->assertJsonPath('lines.0.discountPct', 7);
    expect((float) Sale::find($res->json('id'))->group_discount_pct)->toBe(7.0);

    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/customers/lookup?q=Abdul')->assertJsonPath('0.groupDiscountPct', 7);
});

it('lets the larger discount win and checks only a larger manual discount against the role limit', function () {
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = groupMember(7);
    $cashier = posUser('Cashier');  // line limit 5 %
    $manager = posUser('Manager');  // line limit 15 %

    sellTo($this, $cashier, $c, $t, 5)->assertCreated()->assertJsonPath('lines.0.discountPct', 7)->assertJsonPath('grandTotal', 93);
    sellTo($this, $cashier, $c, $t, 6.5)->assertCreated()->assertJsonPath('lines.0.discountPct', 7); // below the group %: no limit error
    sellTo($this, $cashier, $c, $t, 10)->assertJsonValidationErrors('lines.0.discount');
    sellTo($this, $manager, $c, $t, 12)->assertCreated()->assertJsonPath('lines.0.discountPct', 12)->assertJsonPath('grandTotal', 88);
});

it('gives the group discount above the cashier limit but not on custom items or to walk-ins', function () {
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = groupMember(10); // above the cashier's 5 % limit: set by a manager, applies in full
    $cashier = posUser('Cashier');
    $cashier->givePermissionTo('pos.custom_item');
    $custom = [['productId' => 0, 'unitId' => 0, 'qty' => 1, 'custom' => ['name' => 'Bandage', 'code' => '', 'price' => 50]]];

    sellTo($this, $cashier, $c, $t, 0, $custom)->assertCreated()
        ->assertJsonPath('lines.0.discountPct', 10)->assertJsonPath('lines.1.discountPct', 0)->assertJsonPath('grandTotal', 140);
    sellTo($this, $cashier, walkIn(), $t)->assertCreated()->assertJsonPath('lines.0.discountPct', 0)->assertJsonPath('grandTotal', 100);
});

it('measures the order discount limit after the group discount', function () {
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = groupMember(7); // ৳93 after the group discount; cashier order limit 5 % = ৳4.65

    sellTo($this, posUser('Cashier'), $c, $t, 0, [], 4.65)->assertCreated()->assertJsonPath('grandTotal', 88.35);
    sellTo($this, posUser('Cashier'), $c, $t, 0, [], 4.7)->assertJsonValidationErrors('orderDiscount');
});
