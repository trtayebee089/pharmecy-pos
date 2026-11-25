<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\RewardCampaign;
use App\Models\RewardLedgerEntry;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Services\LoyaltySettings;
use App\Services\RewardLedger;

/** Reward rules for the tests: 1 point per ৳10, 1 point = ৳1. */
function rewardRules(array $over = []): void
{
    app(LoyaltySettings::class)->save($over + [
        'active' => true, 'soldAmountPerPoint' => 10, 'minimumSoldAmount' => 0, 'expiryDuration' => 12, 'expiryType' => 'Month',
        'pointValue' => 1, 'minRedeemPoints' => 0, 'minRedeemSaleAmount' => 0, 'maxRedeemPercent' => 100,
        'excludeDiscountedItems' => false, 'membershipDiscountMode' => 'off',
    ]);
}

function member(string $name = 'Loyal', ?int $groupId = null): Customer
{
    return Customer::create(['code' => 'C-'.uniqid(), 'name' => $name, 'phone_normalized' => '017'.random_int(10000000, 99999999), 'customer_group_id' => $groupId]);
}

/** Strips of the test tablet (৳20 each) for a customer; returns the API response. */
function buyStrips($test, $user, Customer $c, int $strips, array $payments, string $key, array $extra = [])
{
    return $test->actingAs($user)->postJson('/api/pos/v1/sales', $extra + [
        'warehouseId' => $test->warehouse->id, 'customerId' => $c->id, 'orderDiscount' => 0,
        'lines' => [['productId' => test()->tablet['product']->id, 'unitId' => test()->tablet['strip']->id, 'qty' => $strips]],
        'payments' => $payments,
    ], ['Idempotency-Key' => $key]);
}

function points(Customer $c): int
{
    return app(RewardLedger::class)->balance($c->id);
}

function plan(string $code, array $attrs): MembershipPlan
{
    $p = MembershipPlan::where('code', $code)->firstOrFail();
    $p->update($attrs + ['is_active' => true]);

    return $p->fresh();
}

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 1000, 1.0]]);
    $this->manager = posUser('Manager');
});

// ---------------------------------------------------------------- earning

it('earns points on the bill after discounts for registered customers only', function () {
    rewardRules();
    $c = member();

    buyStrips($this, $this->manager, $c, 3, [['method' => 'cash', 'amount' => 60]], 'a')->assertCreated(); // ৳60 → 6 points
    buyStrips($this, $this->manager, walkIn(), 3, [['method' => 'cash', 'amount' => 60]], 'b')->assertCreated();

    expect(points($c))->toBe(6)->and(Sale::where('customer_id', $c->id)->value('points_earned'))->toBe(6)
        ->and(RewardLedgerEntry::where('customer_id', walkIn()->id)->count())->toBe(0);
});

it('earns nothing when switched off or under the minimum bill', function () {
    $c = member();
    rewardRules(['active' => false]);
    buyStrips($this, $this->manager, $c, 3, [['method' => 'cash', 'amount' => 60]], 'a')->assertCreated();
    rewardRules(['minimumSoldAmount' => 100]);
    buyStrips($this, $this->manager, $c, 3, [['method' => 'cash', 'amount' => 60]], 'b')->assertCreated();

    expect(points($c))->toBe(0);
});

it('applies the membership and campaign multipliers', function () {
    rewardRules();
    $c = member();
    $gold = plan('Gold', ['points_multiplier' => 1.5, 'min_annual_spend' => 0]);
    CustomerMembership::create(['customer_id' => $c->id, 'plan_id' => $gold->id, 'starts_at' => day(-1), 'ends_at' => day(300), 'status' => 'active', 'source' => 'manual']);
    RewardCampaign::create(['name' => 'Eid', 'multiplier' => 2, 'starts_at' => day(-1), 'ends_at' => day(5), 'is_active' => true]);

    buyStrips($this, $this->manager, $c, 2, [['method' => 'cash', 'amount' => 40]], 'a')->assertCreated(); // 4 × 1.5 × 2

    expect(points($c))->toBe(12);
});

it('excludes custom items and, when set, discounted lines', function () {
    rewardRules(['excludeDiscountedItems' => true]);
    $c = member();
    $this->manager->givePermissionTo('pos.custom_item');

    $this->actingAs($this->manager)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => $c->id, 'orderDiscount' => 0,
        'lines' => [
            ['productId' => $this->tablet['product']->id, 'unitId' => $this->tablet['strip']->id, 'qty' => 2],                  // ৳40 earns
            ['productId' => $this->tablet['product']->id, 'unitId' => $this->tablet['piece']->id, 'qty' => 10, 'discount' => 10], // discounted: excluded
            ['productId' => 0, 'unitId' => 0, 'qty' => 1, 'custom' => ['name' => 'Bandage', 'code' => 'B', 'price' => 50]],      // custom: excluded
        ],
        'payments' => [['method' => 'cash', 'amount' => 108]],
    ], ['Idempotency-Key' => 'x'])->assertCreated()->assertJsonPath('grandTotal', 108);

    expect(points($c))->toBe(4);
});

// ---------------------------------------------------------------- redeeming

it('pays with points within the settings and earns only on the money part', function () {
    rewardRules(['minRedeemPoints' => 20, 'minRedeemSaleAmount' => 50, 'maxRedeemPercent' => 50]);
    $c = member();
    app(RewardLedger::class)->credit($c->id, 'adjust', 30, [], 'Opening');

    // ৳100 bill: ৳30 points + ৳70 cash; earns on ৳70 = 7 points
    $res = buyStrips($this, $this->manager, $c, 5, [['method' => 'points', 'amount' => 30], ['method' => 'cash', 'amount' => 70]], 'a')->assertCreated();

    expect(points($c))->toBe(7)
        ->and(Sale::find($res->json('id'))->points_redeemed)->toBe(30)
        ->and(Payment::whereHas('method', fn ($q) => $q->where('code', 'points'))->value('account_id'))->toBeNull();
    $this->actingAs($this->manager)->getJson("/api/pos/v1/loyalty/wallets/{$c->id}/ledger")->assertJsonPath('0.type', 'earn')->assertJsonPath('1.type', 'redeem');
});

it('enforces minimum points, minimum bill, max percent, whole points and balance', function () {
    rewardRules(['minRedeemPoints' => 200, 'minRedeemSaleAmount' => 100, 'maxRedeemPercent' => 50, 'pointValue' => 0.5]);
    $c = member();
    $pay = fn ($strips, $points, $key) => buyStrips($this, $this->manager, $c, $strips, [['method' => 'points', 'amount' => $points], ['method' => 'cash', 'amount' => $strips * 20 - $points]], $key);

    app(RewardLedger::class)->credit($c->id, 'adjust', 150, [], 'x');
    $pay(10, 20, 'a')->assertJsonFragment(['payments' => ['The customer needs at least 200 points to redeem (has 150).']]);

    app(RewardLedger::class)->credit($c->id, 'adjust', 100, [], 'x'); // 250 points = ৳125
    $pay(4, 20, 'b')->assertJsonFragment(['payments' => ['Points can be used only on bills of at least ৳100.00.']]);
    $pay(10, 120, 'c')->assertJsonFragment(['payments' => ['Points can pay at most 50% of the bill.']]);
    $pay(10, 20.25, 'd')->assertJsonValidationErrors('payments');
    $pay(10, 100, 'e')->assertCreated(); // 200 points
    expect(points($c))->toBe(50 + 10); // 250 − 200 redeemed + 10 earned on ৳100 cash

    buyStrips($this, $this->manager, walkIn(), 5, [['method' => 'points', 'amount' => 10], ['method' => 'cash', 'amount' => 90]], 'f')
        ->assertJsonValidationErrors('payments');
});

// ---------------------------------------------------------------- reversals

it('gives spent points back and takes earned points away on void', function () {
    rewardRules();
    $c = member();
    app(RewardLedger::class)->credit($c->id, 'adjust', 50, [], 'Opening');
    $id = buyStrips($this, $this->manager, $c, 5, [['method' => 'points', 'amount' => 40], ['method' => 'cash', 'amount' => 60]], 'a')->json('id');
    expect(points($c))->toBe(10 + 6);

    $this->actingAs($this->manager)->postJson("/api/pos/v1/sales/$id/void", ['reason' => 'Customer changed mind'])->assertOk();

    expect(points($c))->toBe(50);
});

it('returns the points-paid share as points and only the rest as money', function () {
    rewardRules();
    $c = member();
    app(RewardLedger::class)->credit($c->id, 'adjust', 50, [], 'Opening');
    // ৳100: ৳50 points + ৳50 cash; earns 5
    $id = buyStrips($this, $this->manager, $c, 5, [['method' => 'points', 'amount' => 50], ['method' => 'cash', 'amount' => 50]], 'a')->json('id');
    expect(points($c))->toBe(5);

    // Return half (25 of 50 pieces): 25 points back, 2.5→3 earned taken back (rounded), ৳25 cash refund
    $this->actingAs($this->manager)->postJson("/api/pos/v1/sales/$id/returns", [
        'items' => [['saleItemBatchId' => SaleItemBatch::first()->id, 'qtyBase' => 25, 'restock' => true]],
        'refundMethod' => 'cash', 'reason' => 'Half not needed',
    ], ['Idempotency-Key' => 'r'])->assertCreated()->assertJsonPath('total', 50);

    expect(points($c))->toBe(5 + 25 - 3)
        ->and((float) Payment::where('direction', 'out')->value('amount'))->toBe(25.0);
});

// ---------------------------------------------------------------- membership discount modes

it('applies the membership discount per the admin mode', function () {
    $group = CustomerGroup::create(['name' => 'Senior', 'discount_pct' => 5]);
    $c = member('Senior member', $group->id);
    $gold = plan('Gold', ['discount_pct' => 10]);
    CustomerMembership::create(['customer_id' => $c->id, 'plan_id' => $gold->id, 'starts_at' => day(-1), 'ends_at' => day(300), 'status' => 'active', 'source' => 'manual']);
    $sell = fn ($key) => buyStrips($this, $this->manager, $c, 5, [], $key, ['payments' => [['method' => 'cash', 'amount' => 100]]]);

    rewardRules(['active' => false, 'membershipDiscountMode' => 'off']);
    $sell('off')->assertJsonPath('grandTotal', 95);      // group 5 % only
    rewardRules(['active' => false, 'membershipDiscountMode' => 'larger']);
    $sell('larger')->assertJsonPath('grandTotal', 90);   // max(5, 10)
    rewardRules(['active' => false, 'membershipDiscountMode' => 'stack']);
    $sell('stack')->assertJsonPath('grandTotal', 85.5);  // 1 − 0.95 × 0.90

    expect((float) Sale::latest('id')->value('membership_discount_pct'))->toBe(10.0);
    $this->actingAs($this->manager)->getJson('/api/pos/v1/customers/lookup?q=Senior')
        ->assertJsonPath('0.membership', 'Gold')->assertJsonPath('0.membershipDiscountPct', 10);
});

// ---------------------------------------------------------------- tiers

it('upgrades automatically by annual spend and never downgrades', function () {
    rewardRules(['active' => false]);
    plan('Silver', ['min_annual_spend' => 100, 'duration_months' => 12]);
    plan('Gold', ['min_annual_spend' => 300]);
    $c = member();

    buyStrips($this, $this->manager, $c, 4, [['method' => 'cash', 'amount' => 80]], 'a');
    expect(CustomerMembership::where('customer_id', $c->id)->count())->toBe(0);
    buyStrips($this, $this->manager, $c, 2, [['method' => 'cash', 'amount' => 40]], 'b'); // 120 → Silver
    buyStrips($this, $this->manager, $c, 10, [['method' => 'cash', 'amount' => 200]], 'c'); // 320 → Gold

    $active = CustomerMembership::with('plan')->where(['customer_id' => $c->id, 'status' => 'active'])->get();
    expect($active)->toHaveCount(1)->and($active->first()->plan->code)->toBe('Gold')->and($active->first()->source)->toBe('auto');
    $this->actingAs($this->manager)->getJson('/api/pos/v1/loyalty/members')->assertJsonPath('0.plan', 'Gold');
});

it('renews with the plan fee in cash, changes plans and cancels with a reason', function () {
    $c = member();
    $gold = plan('Gold', ['fee' => 500, 'duration_months' => 12]);
    plan('Platinum', []);
    $this->actingAs($this->manager)->postJson("/api/pos/v1/loyalty/customers/{$c->id}/membership", ['plan' => 'Gold', 'reason' => 'Doctor referral'])->assertCreated();
    $m = CustomerMembership::where('customer_id', $c->id)->firstOrFail();

    $ends = $this->actingAs($this->manager)->postJson("/api/pos/v1/loyalty/members/{$m->id}/renew", [], ['Idempotency-Key' => 'r'])->assertOk()->json('endsAt');
    expect($ends)->toBe(now()->addMonths(24)->toDateString())
        ->and((float) Payment::where(['party_id' => $c->id, 'direction' => 'in'])->value('amount'))->toBe(500.0);

    $this->actingAs($this->manager)->postJson("/api/pos/v1/loyalty/members/{$m->id}/plan", ['plan' => 'Platinum', 'reason' => 'Family plan'])->assertOk()->assertJsonPath('plan', 'Platinum');
    $new = CustomerMembership::where(['customer_id' => $c->id, 'status' => 'active'])->firstOrFail();
    $this->actingAs($this->manager)->postJson("/api/pos/v1/loyalty/members/{$new->id}/cancel", ['reason' => 'Moved away'])->assertOk();

    expect(CustomerMembership::where(['customer_id' => $c->id, 'status' => 'active'])->count())->toBe(0);
    $this->actingAs($this->manager)->deleteJson("/api/pos/v1/loyalty/membership-plans/{$gold->id}")->assertJsonValidationErrors('plan');
});

it('saves plans and settings only with permission', function () {
    $silver = MembershipPlan::where('code', 'Silver')->first();
    $body = ['name' => 'Silver Care', 'minAnnualSpend' => 25000, 'discountPct' => 2, 'pointsMultiplier' => 1.25, 'fee' => 0, 'durationMonths' => 12, 'benefits' => ['Refill SMS'], 'isActive' => true];

    $this->actingAs(posUser('Cashier'))->putJson("/api/pos/v1/loyalty/membership-plans/{$silver->id}", $body)->assertForbidden();
    $this->actingAs($this->manager)->putJson("/api/pos/v1/loyalty/membership-plans/{$silver->id}", $body)->assertOk()->assertJsonPath('discountPct', 2);

    $settings = ['active' => true, 'soldAmountPerPoint' => 100, 'minimumSoldAmount' => 200, 'expiryDuration' => 12, 'expiryType' => 'Month', 'pointValue' => 1,
        'minRedeemPoints' => 200, 'minRedeemSaleAmount' => 500, 'maxRedeemPercent' => 50, 'excludeDiscountedItems' => false, 'membershipDiscountMode' => 'larger'];
    $this->actingAs($this->manager)->putJson('/api/pos/v1/settings/reward-points', $settings)->assertForbidden(); // Manager lacks settings.manage
    $admin = posUser('Admin');
    $this->actingAs($admin)->putJson('/api/pos/v1/settings/reward-points', $settings)->assertOk()->assertJsonPath('minRedeemSaleAmount', 500);
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/settings/reward-points')->assertOk()->assertJsonPath('membershipDiscountMode', 'larger');
});

// ---------------------------------------------------------------- wallet, adjustments, expiry, campaigns

it('adjusts points with a reason and refuses to go below zero', function () {
    $c = member();
    $adjust = fn ($points, $key) => $this->actingAs($this->manager)->postJson("/api/pos/v1/loyalty/wallets/{$c->id}/adjustments", ['points' => $points, 'reason' => 'Goodwill'], ['Idempotency-Key' => $key]);

    $adjust(40, 'a')->assertCreated()->assertJsonPath('balance', 40);
    $adjust(-50, 'b')->assertJsonValidationErrors('points');
    $adjust(-15, 'c')->assertCreated()->assertJsonPath('balance', 25);
    $this->actingAs($this->manager)->getJson('/api/pos/v1/loyalty/wallets')->assertJsonPath('0.balance', 25)->assertJsonPath('0.customerId', $c->id);
    $this->actingAs(posUser('Cashier'))->postJson("/api/pos/v1/loyalty/wallets/{$c->id}/adjustments", ['points' => 5, 'reason' => 'x'], ['Idempotency-Key' => 'd'])->assertForbidden();
});

it('expires the oldest unused points first', function () {
    rewardRules(['expiryDuration' => 10, 'expiryType' => 'Day']);
    $c = member();
    $ledger = app(RewardLedger::class);
    $this->travel(-20)->days();
    $ledger->credit($c->id, 'earn', 30, [], 'old');   // expires 10 days ago
    $this->travelBack();
    $ledger->credit($c->id, 'earn', 20, [], 'new');   // expires in 10 days
    $ledger->debit($c->id, 'redeem', 10, [], 'spent'); // taken from the oldest

    $this->artisan('loyalty:expire')->assertSuccessful();

    expect(points($c))->toBe(20) // 50 − 10 spent − 20 left of the old batch
        ->and(RewardLedgerEntry::where('type', 'expire')->value('points'))->toBe(-20);
});

it('manages point campaigns', function () {
    $id = $this->actingAs($this->manager)->postJson('/api/pos/v1/loyalty/campaigns', ['name' => 'Eid double', 'multiplier' => 2, 'startsAt' => day(0), 'endsAt' => day(3), 'isActive' => true, 'description' => ''])
        ->assertCreated()->json('id');
    $this->actingAs($this->manager)->putJson("/api/pos/v1/loyalty/campaigns/$id", ['name' => 'Eid triple', 'multiplier' => 3, 'startsAt' => day(0), 'endsAt' => day(3)])->assertOk()->assertJsonPath('multiplier', 3);
    $this->actingAs($this->manager)->getJson('/api/pos/v1/loyalty/campaigns')->assertJsonPath('meta.total', 1);
    $this->actingAs($this->manager)->postJson('/api/pos/v1/loyalty/campaigns', ['name' => 'Bad', 'multiplier' => 2, 'startsAt' => day(3), 'endsAt' => day(0)])->assertJsonValidationErrors('endsAt');
    $this->actingAs($this->manager)->deleteJson("/api/pos/v1/loyalty/campaigns/$id")->assertOk();
});
