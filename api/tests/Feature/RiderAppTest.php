<?php

use App\Models\OnlineOrder;
use App\Models\Rider;
use App\Models\RiderCashEntry;
use App\Models\RiderLocation;
use App\Models\ShippingZone;
use App\Models\Warehouse;
use App\Services\Integrations;
use Illuminate\Support\Facades\Http;

const RIDER = '/api/rider/v1';

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]); // ৳2 a piece
    $this->manager = posUser('Manager');
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'free_shipping_min' => 1000, 'partner' => 'own_rider']);
    Warehouse::query()->update(['latitude' => 23.7465, 'longitude' => 90.3760]); // the store marker
    $this->rider = Rider::create(['name' => 'Rasel', 'phone' => '01711000001', 'status' => 'active', 'password' => 'secret123']);
});

function riderLogin($test, string $password = 'secret123', string $phone = '+880 1711-000001')
{
    return $test->postJson(RIDER.'/login', ['phone' => $phone, 'password' => $password, 'device_name' => 'Pixel']);
}

/** A website order with the customer's pin, confirmed and assigned to the test rider; returns the order. */
function pinnedOrder($test, string $status = 'packed'): OnlineOrder
{
    $number = $test->postJson(V1.'/checkout', [
        'customer' => ['name' => 'Guest Buyer', 'phone' => '01812345678', 'address' => 'House 1, Road 2', 'latitude' => 23.7509, 'longitude' => 90.3935],
        'zone_id' => $test->zone->id, 'payment_method' => 'cod',
        'items' => [['product_id' => $test->tablet['product']->id, 'qty' => 20]],
    ])->assertCreated()->json('data.order.number');
    $order = OnlineOrder::where('number', $number)->firstOrFail();
    $test->actingAs($test->manager)->postJson("/api/pos/v1/orders/{$order->id}/status", ['status' => 'confirmed'], ['Idempotency-Key' => 'c'.$order->id])->assertOk();
    if ($status === 'packed') {
        $test->actingAs($test->manager)->postJson("/api/pos/v1/orders/{$order->id}/status", ['status' => 'packed'], ['Idempotency-Key' => 'p'.$order->id])->assertOk();
    }
    $test->actingAs($test->manager)->postJson("/api/pos/v1/orders/{$order->id}/rider", ['rider_id' => $test->rider->id], ['Idempotency-Key' => 'r'.$order->id])->assertOk();
    app('auth')->forgetGuards(); // the next calls use the rider's bearer token, not the staff session

    return $order->fresh();
}

it('stores the customer pin from checkout and refuses pins outside Bangladesh', function () {
    $order = pinnedOrder($this);
    expect($order->latitude)->toBe(23.7509)->and($order->longitude)->toBe(90.3935);

    $this->postJson(V1.'/checkout', [
        'customer' => ['name' => 'X', 'phone' => '01812345679', 'address' => 'A', 'latitude' => 51.5, 'longitude' => -0.12],
        'zone_id' => $this->zone->id, 'items' => [['product_id' => $this->tablet['product']->id, 'qty' => 1]],
    ])->assertJsonValidationErrors(['customer.latitude', 'customer.longitude']);
});

it('signs riders in by phone and password and keeps their token off staff and customer routes', function () {
    riderLogin($this, 'wrong')->assertJsonValidationErrors('phone');
    Rider::create(['name' => 'No app', 'phone' => '01711000002', 'status' => 'active']);
    riderLogin($this, 'secret123', '01711000002')->assertJsonValidationErrors('phone');

    $token = riderLogin($this)->assertOk()->assertJsonPath('data.rider.name', 'Rasel')->json('data.token');
    $this->getJson(RIDER.'/me', bearer($token))->assertOk()->assertJsonPath('data.on_duty', false);
    $this->getJson('/api/pos/v1/orders', bearer($token))->assertStatus(403);
    $this->getJson(V1.'/customer/profile', bearer($token))->assertStatus(401);

    // A customer token never reaches the rider app.
    $customer = signUp($this)->json('token');
    app('auth')->forgetGuards(); // tests share one app: drop the cached rider
    $this->getJson(RIDER.'/me', bearer($customer))->assertStatus(401);

    // Deactivating the rider signs the app out.
    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/riders/{$this->rider->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('appAccess', true);
    expect($this->rider->tokens()->count())->toBe(0);
});

it('lets staff set and revoke the rider app password', function () {
    $other = Rider::create(['name' => 'Karim', 'phone' => '01711000003', 'status' => 'active']);
    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/riders/{$other->id}", ['password' => 'newpass1'])->assertOk()->assertJsonPath('appAccess', true);
    app('auth')->forgetGuards();
    riderLogin($this, 'newpass1', '01711000003')->assertOk();

    $this->actingAs($this->manager)->putJson("/api/pos/v1/delivery/riders/{$other->id}", ['revokeAppAccess' => true])->assertOk()->assertJsonPath('appAccess', false);
    app('auth')->forgetGuards();
    riderLogin($this, 'newpass1', '01711000003')->assertJsonValidationErrors('phone');
});

it('runs a delivery from the rider app: three markers, pickup, live ETA, delivery time and COD', function () {
    $order = pinnedOrder($this);
    $token = riderLogin($this)->json('data.token');

    $this->getJson(RIDER.'/orders', bearer($token))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $order->number)->assertJsonPath('data.0.can_pickup', true)
        ->assertJsonPath('data.0.markers.store.lat', 23.7465)->assertJsonPath('data.0.markers.customer.lng', 90.3935)
        ->assertJsonPath('data.0.markers.rider', null)->assertJsonPath('data.0.cod_amount', 100.0);

    $this->putJson(RIDER.'/duty', ['on_duty' => true], bearer($token))->assertOk()->assertJsonPath('data.on_duty', true);
    $this->postJson(RIDER.'/locations', ['points' => [['lat' => 23.7466, 'lng' => 90.3761, 'accuracy' => 8, 'speed' => 0]]], bearer($token))->assertOk();

    $this->travel(1)->minutes();
    $picked = $this->postJson(RIDER."/orders/{$order->id}/pickup", [], bearer($token))->assertOk()
        ->assertJsonPath('data.status', 'in_transit')->assertJsonPath('data.can_deliver', true)->json('data');
    // No Google key: straight-line × 1.35 at 18 km/h (about 2.2 km → 6–8 minutes).
    expect($picked['eta']['source'])->toBe('estimate')->and($picked['eta']['distance_m'])->toBeGreaterThan(1500)->toBeLessThan(3000)
        ->and($picked['picked_up_at'])->not->toBeNull();

    // The customer sees the rider and the arrival time; the public tracking page never shows the pin.
    $live = $this->getJson(V1."/tracking/{$order->tracking_code}/live")->assertOk()
        ->assertJsonPath('data.customer', null)->assertJsonPath('data.rider.name', 'Rasel')->assertJsonPath('data.rider.phone', null)
        ->assertJsonPath('data.rider.position.lat', 23.7466)->assertJsonPath('data.poll_seconds', 15)->json('data');
    expect($live['eta']['minutes'])->toBeGreaterThan(0);

    // Positions while carrying the parcel are tagged with the order (its route).
    $this->travel(5)->minutes();
    $this->postJson(RIDER.'/locations', ['points' => [
        ['lat' => 23.7480, 'lng' => 90.3800, 'recorded_at' => now()->subSeconds(30)->toIso8601String()],
        ['lat' => 23.7500, 'lng' => 90.3900],
    ]], bearer($token))->assertOk()->assertJsonPath('data.received', 2);
    expect(RiderLocation::where('online_order_id', $order->id)->count())->toBe(2)
        ->and($this->rider->fresh()->last_lat)->toBe(23.75)
        ->and($order->fresh()->eta_distance_m)->toBeLessThan($picked['eta']['distance_m']);

    $this->travel(10)->minutes();
    $this->postJson(RIDER."/orders/{$order->id}/deliver", ['cash_collected' => 50], bearer($token))->assertJsonValidationErrors('cash_collected');
    $done = $this->postJson(RIDER."/orders/{$order->id}/deliver", ['cash_collected' => 100], bearer($token))->assertOk()
        ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.delivery_minutes', 15)->assertJsonPath('data.eta', null);

    expect(RiderCashEntry::where(['rider_id' => $this->rider->id, 'type' => 'collect'])->sum('amount'))->toEqual(100)
        ->and($order->fresh()->history->last()->note)->toBe('By rider Rasel');
    $this->getJson(RIDER.'/me', bearer($token))->assertJsonPath('data.cash_held', 100.0)->assertJsonPath('data.today.delivered', 1)->assertJsonPath('data.today.avg_minutes', 15);

    // Staff see the delivery time on the rider and the route on the order.
    app('auth')->forgetGuards();
    $this->actingAs($this->manager)->getJson("/api/pos/v1/delivery/riders/{$this->rider->id}")->assertJsonPath('avgDeliveryMinutes30d', 15)->assertJsonPath('deliveries30d', 1);
    $this->actingAs($this->manager)->getJson("/api/pos/v1/orders/{$order->id}/route")->assertOk()->assertJsonCount(2, 'points')->assertJsonPath('deliveryMinutes', 15);
    $this->actingAs($this->manager)->getJson("/api/pos/v1/orders/{$order->id}")->assertJsonPath('deliveryMinutes', 15)->assertJsonPath('latitude', 23.7509);
});

it('lets the rider collect a confirmed order (marked packed on the way) but not someone else\'s', function () {
    $order = pinnedOrder($this, 'confirmed');
    $other = Rider::create(['name' => 'Karim', 'phone' => '01711000003', 'status' => 'active', 'password' => 'secret123']);
    $otherToken = riderLogin($this, 'secret123', '01711000003')->json('data.token');
    $this->postJson(RIDER."/orders/{$order->id}/pickup", [], bearer($otherToken))->assertNotFound();
    app('auth')->forgetGuards();

    $token = riderLogin($this)->json('data.token');
    $this->postJson(RIDER."/orders/{$order->id}/deliver", [], bearer($token))->assertJsonValidationErrors('status');
    $this->postJson(RIDER."/orders/{$order->id}/pickup", [], bearer($token))->assertOk()->assertJsonPath('data.status', 'in_transit');
    expect($order->fresh()->history->pluck('status')->all())->toBe(['pending', 'confirmed', 'confirmed', 'packed', 'in_transit']);
});

it('uses Google Routes for the arrival time when Maps is switched on, falling back to driving', function () {
    app(Integrations::class)->save('maps', ['enabled' => true, 'browserKey' => 'browser-key', 'serverKey' => 'server-key']);
    Http::fake(function ($request) {
        return $request['travelMode'] === 'TWO_WHEELER'
            ? Http::response([], 200) // not offered here
            : Http::response(['routes' => [['duration' => '754s', 'distanceMeters' => 3120]]], 200);
    });
    $order = pinnedOrder($this);
    $token = riderLogin($this)->json('data.token');
    $this->postJson(RIDER.'/locations', ['points' => [['lat' => 23.7466, 'lng' => 90.3761]]], bearer($token))->assertOk();
    $res = $this->postJson(RIDER."/orders/{$order->id}/pickup", [], bearer($token))->assertOk();

    expect($res->json('data.eta.source'))->toBe('google')->and($res->json('data.eta.seconds'))->toBe(754)->and($res->json('data.eta.distance_m'))->toBe(3120);
    Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-Api-Key', 'server-key') && str_contains($r->url(), 'computeRoutes'));

    // The website gets the browser key only.
    $this->getJson(V1.'/site-config')->assertJsonPath('data.maps.enabled', true)->assertJsonPath('data.maps.browser_key', 'browser-key');
    expect(json_encode($this->getJson(V1.'/site-config')->json()))->not->toContain('server-key');
});

it('shows the live map to staff with stores, riders and open orders', function () {
    $order = pinnedOrder($this);
    $token = riderLogin($this)->json('data.token');
    $this->postJson(RIDER.'/locations', ['points' => [['lat' => 23.7466, 'lng' => 90.3761]]], bearer($token))->assertOk();
    app('auth')->forgetGuards();

    $this->actingAs($this->manager)->getJson('/api/pos/v1/delivery/live')->assertOk()
        ->assertJsonPath('stores.0.lat', 23.7465)->assertJsonPath('riders.0.name', 'Rasel')->assertJsonPath('riders.0.position.is_live', true)
        ->assertJsonPath('riders.0.orders.0', $order->number)->assertJsonPath('orders.0.lat', 23.7509);
});

it('stores phone times sent in UTC at the right moment', function () {
    $token = riderLogin($this)->json('data.token');
    $sent = now()->subSeconds(20)->utc();
    $this->postJson(RIDER.'/locations', ['points' => [['lat' => 23.7466, 'lng' => 90.3761, 'recorded_at' => $sent->format('Y-m-d\TH:i:s\Z')]]], bearer($token))->assertOk();

    expect(abs(RiderLocation::latest('id')->first()->recorded_at->diffInSeconds($sent)))->toBeLessThan(2)
        ->and(abs($this->rider->fresh()->last_seen_at->diffInSeconds($sent)))->toBeLessThan(2);
});
