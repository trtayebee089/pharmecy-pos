<?php

use App\Models\ShippingZone;
use App\Models\StorefrontBanner;
use App\Services\Integrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]);
    $this->tablet['product']->update(['slug' => 'testamol-500', 'description' => 'Pain & fever <b>relief</b>']);
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'partner' => 'own_rider']);
});

it('gives the website its public settings without any secret', function () {
    $i = app(Integrations::class);
    $i->save('tracking', ['gtmId' => 'GTM-ABC123', 'facebookPixelId' => '123456789012', 'facebookCapiToken' => 'capi-secret', 'serverSideFacebook' => true]);
    $i->save('storefront', ['siteName' => 'Red Pharma', 'phone' => '09610000000', 'codEnabled' => true]);
    $i->save('sslcommerz', ['enabled' => true, 'sandbox' => true, 'storeId' => 'test', 'storePassword' => 'ssl-secret']);

    $res = $this->getJson(V1.'/site-config')->assertOk()
        ->assertJsonPath('data.tracking.gtm_id', 'GTM-ABC123')->assertJsonPath('data.tracking.facebook_pixel_id', '123456789012')
        ->assertJsonPath('data.shop.phone', '09610000000')->assertJsonPath('data.payments.0.code', 'cod')->assertJsonPath('data.payments.1.code', 'sslcommerz')
        ->assertJsonPath('data.banners.hero', []);
    expect($res->getContent())->not->toContain('capi-secret')->not->toContain('ssl-secret');
});

it('uses a neutral brand until the shop names itself', function () {
    $i = app(Integrations::class);
    $this->getJson(V1.'/site-config')->assertOk()->assertJsonPath('data.shop.siteName', 'Your Brand');
    expect($i->brand())->toBe('Your Brand');

    $i->save('storefront', ['siteName' => '  Green Leaf Pharmacy ']);
    expect($i->brand())->toBe('Green Leaf Pharmacy');
    $i->save('storefront', ['siteName' => '']);
    expect($i->brand())->toBe('Your Brand');
});

it('publishes a product feed for Facebook and Google only when switched on', function () {
    $this->get('/api/feeds/products.xml')->assertNotFound();
    app(Integrations::class)->save('feed', ['enabled' => true, 'siteUrl' => 'https://shop.example.test', 'inStockOnly' => true, 'includePrescription' => false]);

    $xml = $this->get('/api/feeds/products.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($xml)->toContain('<g:id>'.$this->tablet['product']->id.'</g:id>')->toContain('<g:link>https://shop.example.test/product/testamol-500</g:link>')
        ->toContain('<g:price>2.00 BDT</g:price>')->toContain('<g:availability>in stock</g:availability>')->toContain('Pain &amp; fever relief');
    expect(simplexml_load_string($xml))->not->toBeFalse();
    $this->get('/api/feeds/products.csv')->assertOk()->assertSee('testamol-500');

    // Prescription medicines stay out unless included; a token locks the feed.
    $this->tablet['product']->update(['requires_prescription' => true]);
    cache()->flush();
    expect($this->get('/api/feeds/products.xml')->getContent())->not->toContain('<g:id>');
    app(Integrations::class)->save('feed', ['token' => 'feed-token-123456']);
    $this->get('/api/feeds/products.xml')->assertForbidden();
    $this->get('/api/feeds/products.xml?token=feed-token-123456')->assertOk();
});

it('sends server-side purchases to Facebook and GA4 with the browser event id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1]), 'www.google-analytics.com/*' => Http::response('', 204)]);
    app(Integrations::class)->save('tracking', [
        'facebookPixelId' => '123456789012', 'facebookCapiToken' => 'capi-secret', 'serverSideFacebook' => true, 'facebookTestEventCode' => 'TEST1',
        'ga4MeasurementId' => 'G-TEST123', 'ga4ApiSecret' => 'ga-secret', 'serverSideGa4' => true,
    ]);

    $res = $this->postJson(V1.'/checkout', [
        'customer' => ['name' => 'Buyer', 'phone' => '01812345678', 'address' => 'Road 1'], 'zone_id' => $this->zone->id,
        'items' => [['product_id' => $this->tablet['product']->id, 'qty' => 10]],
        'tracking' => ['event_id' => 'evt-12345678', 'fbp' => 'fb.1.1700000000.123', 'ga_client_id' => '123.456', 'page_url' => 'https://shop.example.test/checkout'],
    ])->assertCreated()->assertJsonPath('data.order.event_id', 'evt-12345678');
    $number = $res->json('data.order.number');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/123456789012/events') && $r['data'][0]['event_id'] === 'evt-12345678'
        && $r['data'][0]['event_name'] === 'Purchase' && $r['data'][0]['custom_data']['value'] === 20.0 && $r['data'][0]['custom_data']['currency'] === 'BDT'
        && $r['data'][0]['user_data']['ph'][0] === hash('sha256', '8801812345678') && $r['data'][0]['user_data']['fbp'] === 'fb.1.1700000000.123'
        && $r['test_event_code'] === 'TEST1');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'mp/collect') && $r['client_id'] === '123.456'
        && $r['events'][0]['name'] === 'purchase' && $r['events'][0]['params']['transaction_id'] === $number);
});

it('sends nothing when server-side tracking is off', function () {
    Http::fake();
    $this->postJson(V1.'/checkout', [
        'customer' => ['name' => 'Buyer', 'phone' => '01812345678', 'address' => 'Road 1'], 'zone_id' => $this->zone->id,
        'items' => [['product_id' => $this->tablet['product']->id, 'qty' => 1]],
    ])->assertCreated()->assertJsonPath('data.order.event_id', fn ($id) => str_starts_with($id, 'purchase-OO-'));
    Http::assertNothingSent();
});

it('manages the home page banners: one rotating hero and three tiles', function () {
    Storage::fake('public');
    $admin = posUser('Admin');
    $make = fn (string $placement, string $title, array $over = []) => $this->actingAs($admin)->post('/api/pos/v1/admin/banners', $over + [
        'placement' => $placement, 'title' => $title, 'link_url' => '/category/tablets', 'image' => UploadedFile::fake()->image('b.jpg', 1600, 533),
    ], ['Accept' => 'application/json']);

    $hero = $make('hero', 'Winter care')->assertCreated()->json();
    $make('hero', 'Old offer', ['ends_on' => day(-1)])->assertCreated();
    foreach (['Vitamins', 'Baby care', 'Diabetes', 'Fourth tile'] as $t) {
        $make('tile', $t)->assertCreated();
    }
    $make('tile', 'Bad link', ['link_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('link_url');
    $make('tile', 'No image', ['image' => null])->assertJsonValidationErrors('image');
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/admin/banners')->assertForbidden();

    $this->getJson(V1.'/site-config')->assertJsonCount(1, 'data.banners.hero')->assertJsonPath('data.banners.hero.0.title', 'Winter care')
        ->assertJsonCount(3, 'data.banners.tiles')->assertJsonPath('data.banners.tiles.0.title', 'Vitamins');

    // Edit with a new image (old file removed), reorder tiles, delete.
    $old = StorefrontBanner::find($hero['id'])->image_path;
    $this->actingAs($admin)->post("/api/pos/v1/admin/banners/{$hero['id']}", ['placement' => 'hero', 'title' => 'Winter care 2', 'image' => UploadedFile::fake()->image('n.jpg')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('title', 'Winter care 2');
    Storage::disk('public')->assertMissing($old);
    $tiles = StorefrontBanner::where('placement', 'tile')->orderBy('sort_order')->pluck('id')->all();
    $this->actingAs($admin)->postJson('/api/pos/v1/admin/banners/reorder', ['ids' => array_reverse($tiles)])->assertOk();
    $this->getJson(V1.'/site-config')->assertJsonPath('data.banners.tiles.0.title', 'Fourth tile');
    $this->actingAs($admin)->deleteJson("/api/pos/v1/admin/banners/{$hero['id']}")->assertOk();
    $this->getJson(V1.'/site-config')->assertJsonCount(0, 'data.banners.hero');
});

it('keeps the checkout limit separate from browsing', function () {
    foreach (range(1, 30) as $i) {
        $this->getJson(V1.'/products?page='.$i)->assertOk();
    }
    $this->postJson(V1.'/checkout', [
        'customer' => ['name' => 'Buyer', 'phone' => '01812345678', 'address' => 'Road 1'], 'zone_id' => $this->zone->id,
        'items' => [['product_id' => $this->tablet['product']->id, 'qty' => 1]],
    ])->assertCreated();
});
