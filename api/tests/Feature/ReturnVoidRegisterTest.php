<?php

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItemBatch;

function register(string $name = 'Counter 1'): CashRegister
{
    return CashRegister::firstOrCreate(['warehouse_id' => test()->warehouse->id, 'name' => $name]);
}

function openSession($test, $user, float $float = 1000)
{
    return $test->actingAs($user)->postJson('/api/pos/v1/cash-registers/sessions', ['register_id' => register()->id, 'opening_float' => $float]);
}

/** Sells $lines and returns the sale id. */
function saleOf($test, $user, array $lines, array $payments, string $key, ?int $customerId = null, float $orderDiscount = 0): int
{
    return $test->actingAs($user)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $test->warehouse->id, 'customerId' => $customerId ?? walkIn()->id, 'orderDiscount' => $orderDiscount,
        'lines' => $lines, 'payments' => $payments,
    ], ['Idempotency-Key' => $key])->assertCreated()->json('id');
}

function customerBalance(int $id): float
{
    return round((float) PartyLedgerEntry::where(['party_type' => 'customer', 'party_id' => $id])->sum('debit')
        - (float) PartyLedgerEntry::where(['party_type' => 'customer', 'party_id' => $id])->sum('credit'), 2);
}

// ---------------------------------------------------------------- register

it('opens a register, tracks cash and closes with the difference', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $cashier = posUser();
    $sessionId = openSession($this, $cashier, 500)->assertCreated()->assertJsonPath('expectedCash', 500)->json('id');

    saleOf($this, $cashier, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 50]], 's1');
    saleOf($this, $cashier, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'bkash', 'amount' => 20, 'reference' => 'TX9']], 's2');

    $this->actingAs($cashier)->postJson("/api/pos/v1/cash-registers/sessions/$sessionId/movements", ['type' => 'cash_out', 'amount' => 100, 'reason' => 'Tea and snacks'], ['Idempotency-Key' => 'm1'])
        ->assertCreated()->assertJsonPath('cashOut', 100);

    $this->actingAs($cashier)->getJson('/api/pos/v1/cash-registers/sessions')->assertOk()
        ->assertJsonPath('0.cashSales', 40)            // ৳50 tendered, ৳10 change
        ->assertJsonPath('0.expectedCash', 440)        // 500 + 40 − 100
        ->assertJsonPath('0.nonCash.0.method', 'bKash')
        ->assertJsonPath('0.nonCash.0.amount', 20);

    $this->actingAs($cashier)->postJson("/api/pos/v1/cash-registers/sessions/$sessionId/close", ['counted_cash' => 430, 'note' => 'short'])
        ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('difference', -10)->assertJsonPath('expectedCash', 440);

    expect(Sale::whereNotNull('session_id')->count())->toBe(2);
    $this->actingAs($cashier)->postJson("/api/pos/v1/cash-registers/sessions/$sessionId/close", ['counted_cash' => 430])->assertUnprocessable();
});

it('allows one open session per register and per cashier', function () {
    $a = posUser();
    $b = posUser();
    openSession($this, $a)->assertCreated();
    openSession($this, $b)->assertJsonValidationErrors('register_id');
    openSession($this, $a)->assertJsonValidationErrors('register_id');
});

it('keeps cashiers out of other cashiers registers', function () {
    $a = posUser();
    $id = openSession($this, $a)->json('id');

    $this->actingAs(posUser())->postJson("/api/pos/v1/cash-registers/sessions/$id/close", ['counted_cash' => 0])->assertForbidden();
    $this->actingAs(posUser('Manager'))->postJson("/api/pos/v1/cash-registers/sessions/$id/close", ['counted_cash' => 1000])->assertOk();
});

it('refuses a cash out larger than the drawer', function () {
    $cashier = posUser();
    $id = openSession($this, $cashier, 100)->json('id');

    $this->actingAs($cashier)->postJson("/api/pos/v1/cash-registers/sessions/$id/movements", ['type' => 'cash_out', 'amount' => 101, 'reason' => 'Too much'], ['Idempotency-Key' => 'm1'])
        ->assertJsonValidationErrors('amount');
});

// ---------------------------------------------------------------- void

it('voids a sale in the open session and reverses stock, payments and ledger', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $manager = posUser('Manager');
    openSession($this, $manager, 0);
    $saleId = saleOf($this, $manager, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 3]], [['method' => 'cash', 'amount' => 60]], 'v1');
    expect(stockOf($t['batches'][0]))->toBe(70);

    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'Customer changed mind'])
        ->assertOk()->assertJsonPath('status', 'void');

    expect(stockOf($t['batches'][0]))->toBe(100)
        ->and(Payment::first()->status)->toBe('void')
        ->and(customerBalance(walkIn()->id))->toBe(0.0);
    $this->actingAs($manager)->getJson('/api/pos/v1/cash-registers/sessions')->assertJsonPath('0.expectedCash', 0);
    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'again'])->assertUnprocessable();
});

it('does not void after the session closed, or without permission, or with a blank reason', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $manager = posUser('Manager');
    $sessionId = openSession($this, $manager, 0)->json('id');
    $saleId = saleOf($this, $manager, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 20]], 'v1');

    $this->actingAs(posUser('Cashier'))->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'nope'])->assertForbidden();
    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => ''])->assertJsonValidationErrors('reason');

    $this->actingAs($manager)->postJson("/api/pos/v1/cash-registers/sessions/$sessionId/close", ['counted_cash' => 20])->assertOk();
    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'too late'])
        ->assertJsonValidationErrors(['sale' => 'The register session of this sale is closed. Use a return instead.']);
});

it('does not void a sale from an earlier day without a session', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $manager = posUser('Manager');
    $saleId = saleOf($this, $manager, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 20]], 'v1');
    Sale::whereKey($saleId)->update(['sale_date' => now()->subDay()]);

    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'old'])->assertJsonValidationErrors('sale');
});

// ---------------------------------------------------------------- returns

it('returns part of a sale per batch with restock and write-off', function () {
    $t = tabletWithStock([['SOON', day(30), 15, 1.0], ['LATE', day(300), 100, 1.0]]);
    $cashier = posUser();
    $sessionId = openSession($this, $cashier, 0)->json('id');
    $saleId = saleOf($this, $cashier, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 40]], 'r0');
    [$soon, $late] = SaleItemBatch::orderBy('id')->get()->all(); // 15 from SOON, 5 from LATE

    $res = $this->actingAs($cashier)->postJson("/api/pos/v1/sales/$saleId/returns", [
        'items' => [
            ['saleItemBatchId' => $soon->id, 'qtyBase' => 5, 'restock' => true],
            ['saleItemBatchId' => $late->id, 'qtyBase' => 2, 'restock' => false],
        ],
        'refundMethod' => 'cash', 'reason' => 'Doctor changed prescription',
    ], ['Idempotency-Key' => 'r1'])->assertCreated();

    // 7 pieces × ৳2.00
    $res->assertJsonPath('total', 14)->assertJsonPath('restockedQty', 5)->assertJsonPath('writtenOffQty', 2);
    expect($res->json('number'))->toStartWith('R-DHK1-');
    expect(stockOf($t['batches'][0]))->toBe(5)      // 15 − 15 sold + 5 restocked
        ->and(stockOf($t['batches'][1]))->toBe(95)  // written-off pieces stay out
        ->and($soon->fresh()->returned_qty_base)->toBe(5);

    $this->actingAs($cashier)->getJson('/api/pos/v1/cash-registers/sessions')->assertJsonPath('0.cashRefunds', 14)->assertJsonPath('0.expectedCash', 26);
    $this->actingAs($cashier)->getJson("/api/pos/v1/sales/$saleId")->assertOk()
        ->assertJsonPath('returns.0.total', 14)->assertJsonPath('lines.0.batches.0.returnedQtyBase', 5);
    $this->actingAs($cashier)->getJson('/api/pos/v1/sales/returns')->assertOk()->assertJsonPath('data.0.saleId', $saleId)->assertJsonCount(2, 'data.0.lines');
});

it('never returns more than sold minus already returned', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $cashier = posUser();
    $saleId = saleOf($this, $cashier, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 20]], 'r0');
    $sib = SaleItemBatch::first();
    $ret = fn ($qty, $key) => $this->actingAs($cashier)->postJson("/api/pos/v1/sales/$saleId/returns", [
        'items' => [['saleItemBatchId' => $sib->id, 'qtyBase' => $qty, 'restock' => true]], 'refundMethod' => 'cash', 'reason' => 'Returned',
    ], ['Idempotency-Key' => $key]);

    $ret(11, 'a')->assertJsonValidationErrors('items.0.qtyBase');
    $ret(6, 'b')->assertCreated();
    $ret(5, 'c')->assertJsonValidationErrors('items.0.qtyBase');
    $ret(4, 'd')->assertCreated();
    expect(stockOf($t['batches'][0]))->toBe(100);

    // A sale with returns can no longer be voided.
    $this->actingAs(posUser('Manager'))->postJson("/api/pos/v1/sales/$saleId/void", ['reason' => 'late'])->assertJsonValidationErrors('sale');
});

it('refunds the order discount share and can credit the customer instead of cash', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $manager = posUser('Manager');
    $customer = Customer::create(['code' => 'C-0200', 'name' => 'Regular', 'phone_normalized' => '01811111111']);
    // 5 strips = ৳100, order discount ৳10 → ৳90; each piece refunds at ৳1.80
    $saleId = saleOf($this, $manager, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 5]], [['method' => 'cash', 'amount' => 90]], 'r0', $customer->id, 10);

    $this->actingAs($manager)->postJson("/api/pos/v1/sales/$saleId/returns", [
        'items' => [['saleItemBatchId' => SaleItemBatch::first()->id, 'qtyBase' => 10, 'restock' => true]], 'refundMethod' => 'credit', 'reason' => 'Extra strips',
    ], ['Idempotency-Key' => 'r1'])->assertCreated()->assertJsonPath('total', 18);

    // Customer now has ৳18 in credit (negative balance) and no cash left the till.
    expect(customerBalance($customer->id))->toBe(-18.0)
        ->and(Payment::where('direction', 'out')->count())->toBe(0);
});

it('rejects batch lines from another sale and needs the returns permission', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser();
    $first = saleOf($this, $user, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 20]], 'x1');
    $second = saleOf($this, $user, [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 20]], 'x2');
    $foreign = SaleItemBatch::orderByDesc('id')->first(); // belongs to the second sale

    $this->actingAs($user)->postJson("/api/pos/v1/sales/$first/returns", [
        'items' => [['saleItemBatchId' => $foreign->id, 'qtyBase' => 1, 'restock' => true]], 'refundMethod' => 'cash', 'reason' => 'Wrong',
    ], ['Idempotency-Key' => 'x3'])->assertJsonValidationErrors('items.0.saleItemBatchId');

    $this->actingAs(posUser('Inventory'))->postJson("/api/pos/v1/sales/$second/returns", [
        'items' => [['saleItemBatchId' => $foreign->id, 'qtyBase' => 1, 'restock' => true]], 'refundMethod' => 'cash', 'reason' => 'No perm',
    ], ['Idempotency-Key' => 'x4'])->assertForbidden();

    expect(CashRegisterSession::count())->toBe(0);
});
