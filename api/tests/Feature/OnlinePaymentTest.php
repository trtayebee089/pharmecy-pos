<?php

use App\Models\OnlineOrder;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\Sale;
use App\Models\ShippingZone;
use App\Services\Integrations;
use App\Services\OnlineOrders;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tablet = tabletWithStock([['L1', day(400), 500, 1.0]]); // ৳2 a piece
    $this->zone = ShippingZone::create(['division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'rate' => 60, 'partner' => 'own_rider']);
    $i = app(Integrations::class);
    $i->save('storefront', ['siteName' => 'Red Pharma', 'websiteUrl' => 'https://shop.example.test', 'codEnabled' => true]);
    $i->save('sslcommerz', ['enabled' => true, 'sandbox' => true, 'storeId' => 'teststore', 'storePassword' => 'testpass']);
    $i->save('bkash', ['enabled' => true, 'sandbox' => true, 'appKey' => 'key', 'appSecret' => 'secret', 'username' => 'user', 'password' => 'pass']);
});

function payOrder($test, string $method, int $pieces = 20)
{
    return $test->postJson(V1.'/checkout', [
        'customer' => ['name' => 'Online Buyer', 'phone' => '01812345678', 'address' => 'Road 1'], 'zone_id' => $test->zone->id,
        'payment_method' => $method, 'items' => [['product_id' => $test->tablet['product']->id, 'qty' => $pieces]],
    ]);
}

function sslFakes(string $validation = 'VALID', string $amount = '100.00')
{
    Http::fake([
        'sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/abc', 'sessionkey' => 'SESS1']),
        'sandbox.sslcommerz.com/validator/*' => fn (HttpRequest $r) => Http::response([
            'status' => $validation, 'tran_id' => OnlinePayment::latest('id')->value('reference'), 'amount' => $amount, 'currency_amount' => $amount,
            'currency_type' => 'BDT', 'bank_tran_id' => 'BANK123', 'card_type' => 'VISA-Dutch Bangla', 'card_no' => '4111XXXXXXXX1111',
        ]),
    ]);
}

it('sends an SSLCommerz order to the gateway and confirms it only after server validation', function () {
    sslFakes();
    $res = payOrder($this, 'sslcommerz')->assertCreated()
        ->assertJsonPath('data.payment.redirect_url', 'https://sandbox.sslcommerz.com/pay/abc')
        ->assertJsonPath('data.order.payment_status', 'pending')->assertJsonPath('data.order.cod_amount', 100.0);
    $order = OnlineOrder::firstOrFail();
    $p = OnlinePayment::firstOrFail();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gwprocess') && $r['total_amount'] === '100.00' && $r['tran_id'] === $p->reference
        && $r['store_id'] === 'teststore' && str_ends_with($r['ipn_url'], '/api/v1/payments/sslcommerz/ipn'));

    // Staff cannot confirm before the money arrives.
    app(OnlineOrders::class)->transition(posUser('Manager'), $order, 'confirmed', null);
})->throws(ValidationException::class, 'Waiting for the online payment');

it('marks the order paid from the success return, posts the payment at confirm and leaves no cash for the rider', function () {
    sslFakes();
    payOrder($this, 'sslcommerz')->assertCreated();
    $order = OnlineOrder::firstOrFail();
    $p = OnlinePayment::firstOrFail();

    $this->post(V1.'/payments/sslcommerz/success', ['tran_id' => $p->reference, 'val_id' => 'VAL1', 'status' => 'VALID'])
        ->assertRedirect("https://shop.example.test/order-confirmation/{$order->number}?payment=paid&code={$order->tracking_code}");
    $this->post(V1.'/payments/sslcommerz/ipn', ['tran_id' => $p->reference, 'val_id' => 'VAL1'])->assertOk(); // repeat is harmless
    expect($order->fresh())->payment_status->toBe('paid')->and((float) $order->fresh()->paid_online)->toBe(100.0)
        ->and($p->fresh()->transaction_id)->toBe('BANK123')->and(json_encode($p->fresh()->payload))->not->toContain('4111');
    $this->getJson(V1.'/tracking/'.$order->tracking_code)->assertJsonPath('data.cod_amount', 0.0)->assertJsonPath('data.payment_status', 'paid');

    $manager = posUser('Manager');
    $orders = app(OnlineOrders::class);
    $orders->transition($manager, $order, 'confirmed', null);
    $sale = Sale::findOrFail($order->fresh()->sale_id);
    expect($sale->payment_status)->toBe('paid')->and((float) $sale->due_total)->toBe(0.0)
        ->and(Payment::where('note', 'like', 'Paid online%')->value('reference'))->toBe('BANK123');

    $rider = Rider::create(['name' => 'Rasel', 'phone' => '01711000001', 'status' => 'active']);
    $orders->assignRider($manager, $order->fresh(), $rider->id);
    $orders->transition($manager, $order->fresh(), 'packed', null);
    $orders->transition($manager, $order->fresh(), 'in_transit', null);
    $orders->transition($manager, $order->fresh(), 'delivered', null);
    expect(OnlineOrders::cashHeld($rider->id))->toBe(0.0);
});

it('never trusts the browser: a failed validation or a wrong amount is not paid', function () {
    sslFakes('INVALID_TRANSACTION');
    payOrder($this, 'sslcommerz')->assertCreated();
    $p = OnlinePayment::firstOrFail();
    $this->post(V1.'/payments/sslcommerz/success', ['tran_id' => $p->reference, 'val_id' => 'VAL1', 'status' => 'VALID'])->assertRedirectContains('payment=failed');
    expect(OnlineOrder::first()->payment_status)->toBe('pending');

    sslFakes('VALID', '1.00');
    $this->post(V1.'/payments/sslcommerz/ipn', ['tran_id' => $p->reference, 'val_id' => 'VAL2'])->assertOk();
    expect(OnlineOrder::first()->payment_status)->toBe('pending')->and($p->fresh()->status)->toBe('failed');

    $this->post(V1.'/payments/sslcommerz/cancel', ['tran_id' => $p->reference])->assertRedirectContains('payment=cancelled');
});

it('pays with bKash: create, then execute on the callback', function () {
    Http::fake([
        'tokenized.sandbox.bka.sh/*/token/grant' => Http::response(['id_token' => 'TOKEN1', 'statusCode' => '0000']),
        'tokenized.sandbox.bka.sh/*/checkout/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'PAY1', 'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId=PAY1']),
        'tokenized.sandbox.bka.sh/*/checkout/execute' => fn () => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'TRX9', 'amount' => '100.00', 'merchantInvoiceNumber' => OnlinePayment::latest('id')->value('reference')]),
    ]);
    payOrder($this, 'bkash')->assertCreated()->assertJsonPath('data.payment.redirect_url', 'https://sandbox.payment.bkash.com/?paymentId=PAY1');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'checkout/create') && $r->hasHeader('Authorization', 'TOKEN1') && $r['amount'] === '100.00');

    $order = OnlineOrder::firstOrFail();
    $this->get(V1.'/payments/bkash/callback?paymentID=PAY1&status=success')->assertRedirectContains('payment=paid');
    expect($order->fresh()->payment_status)->toBe('paid')->and(OnlinePayment::first()->transaction_id)->toBe('TRX9');
    $this->get(V1.'/payments/bkash/callback?paymentID=PAY1&status=failure')->assertRedirectContains('payment=paid'); // a late failure never undoes it
});

it('lets the customer retry or switch to cash on delivery, and flags refunds for cancelled paid orders', function () {
    Http::fake(['sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store inactive'])]);
    payOrder($this, 'sslcommerz')->assertCreated()->assertJsonPath('data.payment.redirect_url', null)
        ->assertJsonPath('data.payment.error', 'The payment could not be started. Try again, or choose cash on delivery.');
    $order = OnlineOrder::firstOrFail();

    $this->postJson(V1.'/payments/cod', ['tracking_code' => $order->tracking_code])->assertOk()->assertJsonPath('data.payment_status', 'cod');
    app(OnlineOrders::class)->transition(posUser('Manager'), $order->fresh(), 'confirmed', null); // now allowed
    $this->postJson(V1.'/payments/start', ['tracking_code' => 'RPNOPE0000', 'gateway' => 'bkash'])->assertJsonValidationErrors('tracking_code');

    // Paid, then cancelled: the money is owed back.
    sslFakes();
    payOrder($this, 'sslcommerz', 5)->assertCreated();
    $paid = OnlineOrder::latest('id')->firstOrFail();
    $this->post(V1.'/payments/sslcommerz/ipn', ['tran_id' => OnlinePayment::latest('id')->value('reference'), 'val_id' => 'V'])->assertOk();
    // sslFakes answers 100.00 but this order is 70.00: not paid.
    expect($paid->fresh()->payment_status)->toBe('pending');
});

it('refuses switched-off methods', function () {
    app(Integrations::class)->save('bkash', ['enabled' => false]);
    payOrder($this, 'bkash')->assertJsonValidationErrors('payment_method');
    app(Integrations::class)->save('storefront', ['codEnabled' => false]);
    payOrder($this, 'cod')->assertJsonValidationErrors('payment_method');
});

it('shows a refund is due when a paid order is cancelled', function () {
    sslFakes();
    payOrder($this, 'sslcommerz')->assertCreated();
    $order = OnlineOrder::firstOrFail();
    $this->post(V1.'/payments/sslcommerz/ipn', ['tran_id' => OnlinePayment::value('reference'), 'val_id' => 'V'])->assertOk();
    app(OnlineOrders::class)->transition(posUser('Manager'), $order->fresh(), 'cancelled', 'Out of stock at the branch');
    expect($order->fresh()->payment_status)->toBe('refund_due');
    $this->actingAs(posUser('Manager'))->getJson('/api/pos/v1/orders/'.$order->id)->assertJsonPath('paymentStatus', 'refund_due')->assertJsonPath('paidOnline', 100);
});
