<?php

use App\Models\Account;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderHistory;
use App\Models\Payment;
use App\Models\ShippingZone;
use App\Services\Integrations;
use App\Services\OnlineOrders;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]);
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'partner' => 'steadfast']);
    $this->manager = posUser('Manager');
    $i = app(Integrations::class);
    $i->save('steadfast', ['enabled' => true, 'apiKey' => 'sf-key', 'secretKey' => 'sf-secret', 'webhookToken' => 'sf-hook-token']);
    $i->save('pathao', ['enabled' => true, 'sandbox' => true, 'clientId' => 'cid', 'clientSecret' => 'csecret', 'username' => 'm@example.test', 'password' => 'mpass', 'storeId' => '777', 'webhookSecret' => 'pt-hook']);
});

/** A confirmed COD order of 20 pieces (৳40 + ৳60 delivery). */
function confirmedOrder($test): OnlineOrder
{
    $orders = app(OnlineOrders::class);
    $o = $orders->place($test->manager, [
        'phone' => '01812345678', 'name' => 'Courier Buyer', 'address' => 'House 4, Road 7', 'zoneId' => $test->zone->id,
        'lines' => [['productId' => $test->tablet['product']->id, 'unitId' => $test->tablet['piece']->id, 'qty' => 20]],
    ]);

    return $orders->transition($test->manager, $o, 'confirmed', null);
}

function bookUrl(OnlineOrder $o): string
{
    return "/api/pos/v1/orders/{$o->id}/courier";
}

it('books a confirmed order with Steadfast for its cash amount and moves it in transit without a rider', function () {
    Http::fake(['portal.packzy.com/*' => Http::response(['status' => 200, 'consignment' => ['consignment_id' => 1424107, 'tracking_code' => '15BAEB8A', 'status' => 'in_review']])]);
    $o = confirmedOrder($this);

    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'steadfast', 'note' => 'Call before'], ['Idempotency-Key' => 'b1'])->assertOk()
        ->assertJsonPath('status', 'in_transit')->assertJsonPath('courier.name', 'steadfast')->assertJsonPath('courier.consignmentId', '1424107')
        ->assertJsonPath('courier.trackingUrl', 'https://steadfast.com.bd/t/15BAEB8A');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/create_order') && $r->hasHeader('Api-Key', 'sf-key') && $r->hasHeader('Secret-Key', 'sf-secret')
        && $r['invoice'] === $o->number && $r['cod_amount'] == 100 && $r['recipient_phone'] === '01812345678');

    // Booked once only.
    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'steadfast'], ['Idempotency-Key' => 'b2'])->assertJsonValidationErrors('courier');
});

it('refuses orders that are not confirmed, waiting for payment, or for a switched-off courier', function () {
    $orders = app(OnlineOrders::class);
    $pending = $orders->place($this->manager, [
        'phone' => '01812345678', 'name' => 'X', 'address' => 'Y', 'zoneId' => $this->zone->id,
        'lines' => [['productId' => $this->tablet['product']->id, 'unitId' => $this->tablet['piece']->id, 'qty' => 1]],
    ]);
    $this->actingAs($this->manager)->postJson(bookUrl($pending), ['courier' => 'steadfast'], ['Idempotency-Key' => 'p1'])->assertJsonValidationErrors('courier');
    app(Integrations::class)->save('steadfast', ['enabled' => false]);
    $this->actingAs($this->manager)->postJson(bookUrl(confirmedOrder($this)), ['courier' => 'steadfast'], ['Idempotency-Key' => 'p2'])->assertJsonValidationErrors('courier');
    $this->actingAs(posUser('Inventory'))->postJson(bookUrl($pending), ['courier' => 'pathao'], ['Idempotency-Key' => 'p3'])->assertForbidden();
});

it('marks the order delivered from the Steadfast webhook and books the cash to the courier receivable', function () {
    Http::fake(['portal.packzy.com/*' => Http::response(['status' => 200, 'consignment' => ['consignment_id' => 555, 'tracking_code' => 'TC555', 'status' => 'in_review']])]);
    $o = confirmedOrder($this);
    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'steadfast'], ['Idempotency-Key' => 'w1'])->assertOk();

    $payload = ['notification_type' => 'delivery_status', 'consignment_id' => 555, 'invoice' => $o->number, 'cod_amount' => 100, 'status' => 'delivered', 'delivery_charge' => 60];
    $this->postJson('/api/webhooks/steadfast', $payload, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    expect($o->fresh()->status)->toBe('in_transit');
    $this->postJson('/api/webhooks/steadfast', $payload, ['Authorization' => 'Bearer sf-hook-token'])->assertOk();

    $o = $o->fresh();
    expect($o->status)->toBe('delivered')->and((float) $o->courier_fee)->toBe(60.0)
        ->and(Payment::where('reference', '555')->firstOrFail()->account_id)->toBe(Account::where('code', 'COURIER')->value('id'))
        ->and(OnlineOrders::cashHeld(0))->toBe(0.0);
    $this->postJson('/api/webhooks/steadfast', $payload, ['Authorization' => 'Bearer sf-hook-token'])->assertOk(); // repeat is harmless
});

it('books with Pathao using its places and remembers them for the delivery zone', function () {
    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'PT-TOKEN', 'expires_in' => 3600]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/city-list' => Http::response(['data' => ['data' => [['city_id' => 1, 'city_name' => 'Dhaka']]]]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders' => Http::response(['data' => ['consignment_id' => 'DL121224VS8TTJ', 'order_status' => 'Pending', 'delivery_fee' => 80]]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders/*/info' => Http::response(['data' => ['order_status' => 'Delivered']]),
    ]);
    $this->actingAs($this->manager)->getJson('/api/pos/v1/orders/couriers')->assertOk()->assertJsonPath('pathao', true);
    $this->actingAs($this->manager)->getJson('/api/pos/v1/orders/couriers/pathao/places')->assertOk()->assertJsonPath('0.name', 'Dhaka');

    $o = confirmedOrder($this);
    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'pathao'], ['Idempotency-Key' => 'k0'])->assertJsonValidationErrors('zone_id');
    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'pathao', 'city_id' => 1, 'zone_id' => 52, 'area_id' => 7], ['Idempotency-Key' => 'k1'])->assertOk()
        ->assertJsonPath('courier.consignmentId', 'DL121224VS8TTJ')->assertJsonPath('courier.fee', 80);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/aladdin/api/v1/orders') && $r->hasHeader('Authorization', 'Bearer PT-TOKEN')
        && $r['store_id'] === 777 && $r['recipient_zone'] === 52 && $r['amount_to_collect'] === 100 && $r['merchant_order_id'] === $o->number);
    expect($this->zone->fresh()->pathao_zone_id)->toBe(52);

    // Refresh asks Pathao and closes the delivered order; the next order in this zone needs no choosing.
    $this->actingAs($this->manager)->postJson(bookUrl($o).'/refresh')->assertOk()->assertJsonPath('status', 'delivered');
    $this->actingAs($this->manager)->postJson(bookUrl(confirmedOrder($this)), ['courier' => 'pathao'], ['Idempotency-Key' => 'k2'])->assertOk();
});

it('takes Pathao webhooks only with the secret and notes returns for staff', function () {
    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'PT-TOKEN', 'expires_in' => 3600]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders' => Http::response(['data' => ['consignment_id' => 'CID9', 'order_status' => 'Pending']]),
    ]);
    $o = confirmedOrder($this);
    $this->actingAs($this->manager)->postJson(bookUrl($o), ['courier' => 'pathao', 'city_id' => 1, 'zone_id' => 52], ['Idempotency-Key' => 'r1'])->assertOk();

    $this->postJson('/api/webhooks/pathao', ['consignment_id' => 'CID9', 'event' => 'order.returned'])->assertUnauthorized();
    $this->postJson('/api/webhooks/pathao', ['consignment_id' => 'CID9', 'event' => 'order.returned'], ['X-PATHAO-Signature' => 'pt-hook'])
        ->assertStatus(202)->assertHeader('X-Pathao-Merchant-Webhook-Integration-Secret');
    $o = $o->fresh();
    expect($o->status)->toBe('in_transit')->and($o->courier_status)->toBe('returned')
        ->and(OnlineOrderHistory::where('online_order_id', $o->id)->orderByDesc('id')->value('note'))->toContain('coming back');
});
