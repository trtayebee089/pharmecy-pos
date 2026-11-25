<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MembershipPlan;
use App\Models\OnlineOrder;
use App\Models\ProductPrice;
use App\Models\ShippingZone;
use App\Services\LoyaltySettings;
use App\Services\OnlineOrders;
use App\Services\RewardLedger;

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]); // ৳2 a piece
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'free_shipping_min' => 1000, 'partner' => 'own_rider']);
    app(LoyaltySettings::class)->save([
        'active' => true, 'soldAmountPerPoint' => 10, 'minimumSoldAmount' => 0, 'expiryDuration' => 12, 'expiryType' => 'Month',
        'pointValue' => 1, 'minRedeemPoints' => 0, 'minRedeemSaleAmount' => 0, 'maxRedeemPercent' => 100,
        'excludeDiscountedItems' => false, 'membershipDiscountMode' => 'off',
    ]);
});

/** Checkout of $pieces of the test tablet; guest unless a token is given. */
function shop($test, int $pieces = 20, array $over = [], ?string $token = null, ?string $key = null)
{
    $headers = ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []) + ($key ? ['Idempotency-Key' => $key] : []);

    return $test->postJson(V1.'/checkout', array_replace_recursive([
        'customer' => ['name' => 'Guest Buyer', 'phone' => '01812345678', 'address' => 'House 1, Road 2'],
        'zone_id' => $test->zone->id, 'payment_method' => 'cod',
        'items' => [['product_id' => $test->tablet['product']->id, 'qty' => $pieces]],
    ], $over), $headers);
}

function discountedMember($test): string
{
    $token = signUp($test)->json('token');
    $group = CustomerGroup::create(['name' => 'Doctors', 'discount_pct' => 10]);
    Customer::where('phone_normalized', '01812345678')->update(['customer_group_id' => $group->id]);
    app(RewardLedger::class)->credit(Customer::where('phone_normalized', '01812345678')->value('id'), 'adjust', 30, [], 'Opening');

    return $token;
}

it('takes a guest order at server prices with zone shipping and no discount', function () {
    $res = shop($this, 20, ['subtotal' => 1, 'total' => 1])->assertCreated()
        ->assertJsonPath('data.order.subtotal', 40.0)->assertJsonPath('data.order.shipping', 60.0)->assertJsonPath('data.order.cod_amount', 100.0)
        ->assertJsonPath('data.order.status', 'pending')->assertJsonPath('data.is_new_customer', true);

    expect($res->json('data.order.number'))->toStartWith('OO-')->and($res->json('data.order.tracking_code'))->toMatch('/^RP[A-Z0-9]{8}$/')
        ->and(OnlineOrder::first()->channel)->toBe('website');
});

it('resolves the delivery area by division and district and refuses unknown areas', function () {
    shop($this, 5, ['zone_id' => null, 'customer' => ['division' => 'Dhaka', 'district' => 'Dhaka']])->assertCreated();
    shop($this, 5, ['zone_id' => null, 'customer' => ['division' => 'Sylhet', 'district' => 'Sylhet']])->assertJsonValidationErrors('zone_id');
});

it('gives a signed-in customer their discount and points, but a guest with the same phone neither', function () {
    $token = discountedMember($this);
    $c = Customer::where('phone_normalized', '01812345678')->first();

    // Guest using the member's phone: no discount, no points, no account access, linked to the customer.
    shop($this, 20, ['customer' => ['name' => 'Impostor']])->assertCreated()
        ->assertJsonPath('data.order.discount_total', 0.0)->assertJsonPath('data.is_new_customer', false);
    shop($this, 20, ['reward_points' => 10], key: 'g2')->assertJsonValidationErrors('reward_points');
    expect(app(RewardLedger::class)->balance($c->id))->toBe(30)->and($c->fresh()->name)->toBe('Nusrat Jahan');

    // Signed in: 10% off ৳40 = ৳36, 30 points (৳30) off the cash due.
    shop($this, 20, ['reward_points' => 30, 'customer' => ['phone' => null, 'name' => null]], $token)->assertCreated()
        ->assertJsonPath('data.order.discount_total', 4.0)->assertJsonPath('data.order.grand_total', 96.0)
        ->assertJsonPath('data.order.points_redeemed', 30)->assertJsonPath('data.order.cod_amount', 66.0);
    expect(app(RewardLedger::class)->balance($c->id))->toBe(0);

    // Cancelling the order gives the points back.
    $order = OnlineOrder::where('points_redeemed', 30)->firstOrFail();
    app(OnlineOrders::class)->transition(posUser('Manager'), $order, 'cancelled', 'Customer changed mind');
    expect(app(RewardLedger::class)->balance($c->id))->toBe(30);
});

it('refuses a bad token instead of treating it as a guest', function () {
    shop($this, 5, [], 'nonsense')->assertUnauthorized();
});

it('returns the first order when a checkout is retried with the same key', function () {
    $first = shop($this, 5, key: 'retry-1')->assertCreated()->json('data.order.number');
    shop($this, 5, key: 'retry-1')->assertOk()->assertJsonPath('data.order.number', $first);
    shop($this, 5, ['customer' => ['phone' => '01912345678']], key: 'retry-1')->assertCreated(); // another phone: another order
    expect(OnlineOrder::count())->toBe(2);
});

it('reports a stock shortage as a validation error', function () {
    shop($this, 600)->assertJsonValidationErrors('lines');
});

it('lets a new guest leave an email and set a password later', function () {
    shop($this, 5, ['customer' => ['email' => 'Guest@Example.test']])->assertCreated();
    expect(Customer::where('phone_normalized', '01812345678')->value('email'))->toBe('guest@example.test');
});

it('shows orders to their owner only, by number, and tracks without personal data', function () {
    $token = signUp($this)->json('token');
    $mine = shop($this, 5, [], $token)->json('data.order');
    $other = shop($this, 5, ['customer' => ['phone' => '01912345678', 'name' => 'Other']])->json('data.order');

    $this->getJson(V1.'/orders', bearer($token))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $mine['number']);
    $this->getJson(V1.'/orders/'.$mine['number'], bearer($token))->assertOk()->assertJsonPath('data.items.0.quantity', 5);
    $this->getJson(V1.'/orders/'.$other['number'], bearer($token))->assertNotFound();
    $this->getJson(V1.'/orders/'.OnlineOrder::where('number', $other['number'])->value('id'), bearer($token))->assertNotFound();

    $this->app['auth']->forgetGuards();
    $track = $this->getJson(V1.'/tracking/'.strtolower($other['tracking_code']))->assertOk()->assertJsonPath('data.status', 'pending');
    expect($track->json('data'))->not->toHaveKeys(['delivery', 'items', 'note'])
        ->and(json_encode($track->json()))->not->toContain('01912345678');
    $this->getJson(V1.'/tracking/RPNOPE0000')->assertNotFound();
    $this->getJson(V1.'/orders')->assertUnauthorized();
});

it('rebuilds the cart from a past order at today\'s price and stock', function () {
    $token = signUp($this)->json('token');
    $number = shop($this, 20, [], $token)->json('data.order.number');
    ProductPrice::where('product_unit_id', $this->tablet['piece']->id)->update(['price' => 3]);

    $this->postJson(V1."/orders/$number/reorder", [], bearer($token))->assertOk()
        ->assertJsonPath('data.items.0.quantity', 20)->assertJsonPath('data.items.0.unit_price', 3.0)
        ->assertJsonPath('data.warnings.0.type', 'price_changed');
});

// ---------------------------------------------------------------- rewards and membership

it('shows the wallet with running balances and quotes a redemption by the rules', function () {
    $token = discountedMember($this); // 30 points
    shop($this, 20, ['reward_points' => 10, 'customer' => ['phone' => null, 'name' => null]], $token)->assertCreated();

    $this->getJson(V1.'/customer/rewards', bearer($token))->assertOk()
        ->assertJsonPath('data.wallet.available_points', 20)->assertJsonPath('data.wallet.lifetime_redeemed', 10)
        ->assertJsonPath('data.settings.active', true)->assertJsonPath('data.membership', null);
    $this->getJson(V1.'/customer/rewards/transactions', bearer($token))->assertOk()
        ->assertJsonPath('data.0.points', -10)->assertJsonPath('data.0.balance_after', 20)->assertJsonPath('data.0.reference', OnlineOrder::value('number'))
        ->assertJsonPath('data.1.points', 30)->assertJsonPath('data.1.balance_after', 30);

    $this->postJson(V1.'/customer/rewards/quote', ['points' => 15, 'order_total' => 100], bearer($token))->assertOk()
        ->assertJsonPath('data.points', 15)->assertJsonPath('data.amount', 15.0);
    $this->postJson(V1.'/customer/rewards/quote', ['points' => 25, 'order_total' => 100], bearer($token))->assertJsonValidationErrors('reward_points');
    $this->app['auth']->forgetGuards();
    $this->getJson(V1.'/customer/rewards')->assertUnauthorized();
});

it('shows membership progress and sends renewals to the counter', function () {
    $token = signUp($this)->json('token');
    MembershipPlan::where('code', 'Silver')->update(['is_active' => true, 'min_annual_spend' => 5000, 'discount_pct' => 2]);

    $this->getJson(V1.'/customer/membership', bearer($token))->assertOk()
        ->assertJsonPath('data.membership', null)->assertJsonPath('data.progress.next.code', 'Silver')
        ->assertJsonPath('data.progress.remaining_purchase', 5000.0)->assertJsonPath('data.progress.percentage', 0);
    $this->getJson(V1.'/customer/membership/history', bearer($token))->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson(V1.'/customer/membership/renew', [], bearer($token))->assertStatus(422)->assertJsonPath('success', false);
});
