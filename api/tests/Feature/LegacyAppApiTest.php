<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\OnlineOrder;
use App\Models\ShippingZone;
use App\Services\LoyaltySettings;
use App\Services\RewardLedger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The installed Android app (1.x): its paths, field names and response shapes,
 * taken from app/lib. No Accept header unless the app sends one.
 */

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]); // ৳2 a piece
    $this->tablet['product']->update(['slug' => 'testamol-500']);
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'free_shipping_min' => 1000, 'partner' => 'own_rider']);
    ShippingZone::create(['division' => 'Rajshahi', 'district' => 'Rajshahi', 'thana' => 'Boalia', 'rate' => 60, 'partner' => 'courier']);
    app(LoyaltySettings::class)->save(['active' => true, 'pointValue' => 1, 'maxRedeemPercent' => 100, 'minRedeemPoints' => 0, 'minRedeemSaleAmount' => 0]);
});

function appLogin($test): string
{
    signUp($test)->assertCreated();
    app('auth')->forgetGuards();

    return $test->post('/api/customer/login', ['phone_number' => '01812345678', 'password' => 'secret123', 'device_name' => 'redpharma-flutter'], ['Accept' => 'application/json'])
        ->assertOk()->json('token');
}

/** The old checkout body: client prices, discount and user_id are sent but ignored. */
function appCheckout($test, int $qty = 20, ?string $token = null, array $over = [])
{
    return $test->postJson('/api/checkout', array_replace_recursive([
        'user_id' => 999, 'warehouse_id' => 1, 'sale_status' => 0, 'payment_status' => 0, 'paid_amount' => 0, 'sale_type' => 'website',
        'customer' => ['name' => 'App Buyer', 'phone' => '01912345678', 'address' => 'Uposhohor, Rajshahi'],
        'subtotal' => 1, 'shipping_cost' => 60.0, 'reward_points' => 0, 'total' => 1, 'order_discount_type' => 'percentage', 'order_discount_value' => 5,
        'discount_value' => 0.05, 'discount_applied' => true,
        'items' => [['product_id' => (string) $test->tablet['product']->id, 'qty' => $qty, 'net_unit_price' => 0.01, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => 0.2]],
        'uniqueItems' => 1, 'total_qty' => $qty,
    ], $over), $token ? ['Authorization' => 'Bearer '.$token] : []);
}

it('signs in with the old payload: top-level token, user with avator, membership discount, orders', function () {
    $token = appLogin($this);
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/customer/profile', bearer($token))->assertOk()
        ->assertJsonPath('user.phone_number', '01812345678')->assertJsonPath('user.avator', null)
        ->assertJsonPath('membership.discount', 0.0)->assertJsonPath('reward_tiers', [])->assertJsonPath('orders', []);

    // A validation error is JSON even without an Accept header.
    $this->post('/api/customer/login', ['phone_number' => '01812345678', 'password' => 'wrong'])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
});

it('registers with a photo and updates the profile by multipart', function () {
    Storage::fake('public');
    $res = $this->post('/api/customer/register', [
        'name' => 'Rafi', 'phone_number' => '01712345678', 'email' => '', 'address' => 'Rajshahi', 'gender' => 'male',
        'password' => 'secret123', 'password_confirmation' => 'secret123', 'device_name' => 'redpharma-flutter',
        'image' => UploadedFile::fake()->image('me.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();
    expect($res->json('user.avator'))->toContain('avatars/')->and($res->json('token'))->toBeString();

    $this->app['auth']->forgetGuards();
    $this->post('/api/customer/profile/update', ['id' => 1, 'name' => 'Rafi Ahmed', 'phone_number' => '01000000000', 'gender' => 'Male', 'email' => 'rafi@example.test'], bearer($res->json('token')))
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.name', 'Rafi Ahmed')
        ->assertJsonPath('data.phone_number', '01712345678')->assertJsonPath('data.gender', 'male');
});

it('changes the password without the current one and signs out other devices', function () {
    $token = appLogin($this);
    $other = $this->postJson(V1.'/customer/login', ['identifier' => '01812345678', 'password' => 'secret123'])->json('token');
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/customer/profile/update-password', ['newPassword' => 'short', 'newPassword_confirmation' => 'short'], bearer($token))->assertStatus(422);
    $this->postJson('/api/customer/profile/update-password', ['newPassword' => 'newpass99', 'newPassword_confirmation' => 'newpass99'], bearer($token))
        ->assertOk()->assertJsonPath('success', true);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/customer/profile', bearer($other))->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/customer/profile', bearer($token))->assertOk();
});

it('resets a password through forget-password and verify-otp', function () {
    signUp($this)->assertCreated();
    $this->post('/api/customer/forget-password', ['mobile' => '01812345678'])->assertOk()->assertJsonPath('success', true);
    $this->postJson('/api/customer/verify-otp', ['mobile' => '01812345678', 'otp' => '000000', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])
        ->assertStatus(422)->assertJsonStructure(['message']);
});

it('serves the catalogue in the shapes the app reads', function () {
    $this->get('/api/categories')->assertOk()->assertJsonStructure(['data']);
    $this->get('/api/products/featured')->assertOk()->assertJsonPath('data.0.slug', 'testamol-500');
    $this->get('/api/products/suggestions?query=testa')->assertOk()->assertJsonPath('0.slug', 'testamol-500'); // bare list
    $this->get('/api/search/testa?page=1')->assertOk()->assertJsonPath('data.0.name', 'Testamol');
    $p = $this->get('/api/products/testamol-500')->assertOk()->assertJsonPath('success', true)->assertJsonPath('saleCount', 0);
    expect($p->json('data.price'))->toBeFloat()->and($p->json('data.unit'))->toBeString();
    $this->get('/api/products/nope')->assertNotFound()->assertJsonStructure(['message']);
    // The app assigns the rate straight into a Dart double: it must be a JSON float (60.0).
    expect($this->get('/api/shipping-zones')->assertJsonPath('success', true)->getContent())->toContain('"rate":60.0');
});

it('takes the old checkout at server prices, matching the zone by rate and the address', function () {
    $res = appCheckout($this)->assertOk()
        ->assertJsonPath('success', true)->assertJsonPath('sale.grand_total', 100.0)->assertJsonPath('is_new_customer', true)->assertJsonPath('temporary_password', null);
    $order = OnlineOrder::firstOrFail();
    expect($res->json('sale.reference_no'))->toBe($order->number)->and($res->json('tracking.tracking_no'))->toBe($order->tracking_code)
        ->and($order->district)->toBe('Rajshahi')->and($order->channel)->toBe('app')
        ->and((float) $order->discount_total)->toBe(0.0)->and($order->customer_id)->not->toBe(999);

    appCheckout($this, 20, null, ['shipping_cost' => 45.0])->assertStatus(422)->assertJsonStructure(['message']);
});

it('reports a stock shortage with available_stock and product_id', function () {
    appCheckout($this, 600)->assertStatus(422)
        ->assertJsonPath('available_stock', 500)->assertJsonPath('product_id', $this->tablet['product']->id);
    expect(OnlineOrder::count())->toBe(0);
});

it('applies the discount and points for a signed-in app customer only', function () {
    $token = appLogin($this);
    $c = Customer::where('phone_normalized', '01812345678')->first();
    $c->update(['customer_group_id' => CustomerGroup::create(['name' => 'Staff', 'discount_pct' => 10])->id]);
    app(RewardLedger::class)->credit($c->id, 'adjust', 20, [], 'Opening');
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/reward/redeem', ['points' => 10.0, 'order_total' => 40.0], bearer($token))->assertOk()->assertJsonPath('data.points', 10);
    appCheckout($this, 20, $token, ['reward_points' => 10.0])->assertOk()->assertJsonPath('sale.grand_total', 86.0); // 36 + 60 − 10

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/reward/profile', bearer($token))->assertOk()->assertJsonPath('data.wallet.available_points', 10);
    $this->getJson('/api/customer/orders', bearer($token))->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('data.0.status', 'Pending')->assertJsonPath('data.0.total', 86.0)->assertJsonPath('data.0.discount.amount', 14.0);
});

it('shows and reorders only the customer\'s own orders by id', function () {
    $token = appLogin($this);
    appCheckout($this, 5, $token)->assertOk();
    appCheckout($this, 5, null, ['customer' => ['phone' => '01512345678']])->assertOk();
    [$mine, $other] = OnlineOrder::orderBy('id')->pluck('id')->all();
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/customer/orders/$mine", bearer($token))->assertOk()
        ->assertJsonPath('data.status', 'Pending')->assertJsonPath('data.items.0.quantity', 5)->assertJsonPath('data.items.0.price', 2.0)
        ->assertJsonPath('data.tracking.histories.0.status', 'Order placed')->assertJsonPath('data.shipping_cost', 60.0);
    $this->getJson("/api/customer/orders/$other", bearer($token))->assertNotFound();
    $this->postJson("/api/orders/$mine/reorder", ['shipping_cost' => 0], bearer($token))->assertOk()->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.items.0.product.qty', 500); // stock is taken at confirm
    $this->postJson("/api/orders/$other/reorder", [], bearer($token))->assertNotFound();
});

it('uses the delivery area when a newer app build sends it', function () {
    appCheckout($this, 5, null, ['customer' => ['division' => 'Dhaka', 'district' => 'Dhaka', 'address' => 'Somewhere in Rajshahi']])->assertOk();
    expect(OnlineOrder::firstOrFail()->district)->toBe('Dhaka');
});

it('keeps the map pin the app sends at checkout', function () {
    appCheckout($this, 5, null, ['customer' => ['latitude' => 24.3745, 'longitude' => 88.6042]])->assertSuccessful();
    $number = OnlineOrder::latest('id')->value('number');
    $order = OnlineOrder::where('number', $number)->firstOrFail();
    expect($order->latitude)->toBe(24.3745)->and($order->longitude)->toBe(88.6042);
});
