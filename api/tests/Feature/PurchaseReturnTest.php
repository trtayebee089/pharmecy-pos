<?php

use App\Models\AccountTransaction;
use App\Models\Batch;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\SupplierPayments;
use Illuminate\Testing\TestResponse;

/** Receives $strips strips at $stripCost into batch $batchNo and returns the purchase. */
function receiveFrom($test, $user, Supplier $supplier, array $tablet, int $strips, float $stripCost, string $batchNo): Purchase
{
    $id = $test->actingAs($user)->postJson('/api/pos/v1/purchases', [
        'status' => 'received', 'warehouseId' => $test->warehouse->id, 'supplierId' => $supplier->id, 'supplierInvoiceNo' => 'INV',
        'lines' => [['productId' => $tablet['product']->id, 'unitId' => $tablet['strip']->id, 'qty' => $strips, 'unitCost' => $stripCost, 'batchNo' => $batchNo, 'expiryDate' => day(400)]],
    ], ['Idempotency-Key' => uniqid('p', true)])->assertCreated()->json('id');

    return Purchase::findOrFail($id);
}

/** Posts a purchase return; $lines are [batch, qtyBase]. */
function returnToSupplier($test, $user, Supplier $supplier, array $tablet, array $lines, string $settlement = 'reduce_due', array $extra = []): TestResponse
{
    return $test->actingAs($user)->postJson('/api/pos/v1/purchase-returns', $extra + [
        'warehouseId' => $test->warehouse->id, 'supplierId' => $supplier->id, 'reason' => 'Expired', 'creditNote' => 'CN-1',
        'settlement' => $settlement,
        'lines' => array_map(fn ($l) => ['productId' => $tablet['product']->id, 'batchId' => $l[0]->id, 'qtyBase' => $l[1], 'unitCost' => 999], $lines),
    ], ['Idempotency-Key' => uniqid('r', true)]);
}

function batchNamed(string $no): Batch
{
    return Batch::where('batch_no', $no)->firstOrFail();
}

it('takes the pieces out of the batch at batch cost and reduces the source invoice', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $purchase = receiveFrom($this, $user, supplier(), $t, 10, 15, 'A1'); // 100 pcs @ ৳1.50 = ৳150

    $res = returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 30]])->assertCreated()
        ->assertJsonPath('total', 45)->assertJsonPath('qty', 30)->assertJsonPath('lines', 1)
        ->assertJsonPath('status', 'posted')->assertJsonPath('creditNote', 'CN-1'); // client unitCost 999 ignored
    expect($res->json('number'))->toStartWith('PR-DHK1-'.now()->year.'-00001');

    expect(stockOf(batchNamed('A1')))->toBe(70)
        ->and((int) StockMovement::where('type', 'purchase_return')->sum('qty_base'))->toBe(-30)
        ->and((float) $purchase->fresh()->due_total)->toBe(105.0)
        ->and((float) $purchase->fresh()->returned_total)->toBe(45.0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(105.0);

    $this->actingAs($user)->getJson("/api/pos/v1/purchases/{$purchase->id}")
        ->assertJsonPath('returnedTotal', 45)->assertJsonPath('returns.0.number', $res->json('number'));
});

it('returns expired stock that can no longer be sold', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([['OLD', day(-5), 20, 1.0]]);

    returnToSupplier($this, $user, supplier(), $t, [[$t['batches'][0], 20]])->assertCreated()->assertJsonPath('total', 20);
    expect(stockOf($t['batches'][0]))->toBe(0);
});

it('never returns more than the batch holds, even split over lines', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([['B', day(100), 10, 1.0]]);
    $other = tabletWithStock([['X', day(100), 10, 1.0]]);

    returnToSupplier($this, $user, supplier(), $t, [[$t['batches'][0], 11]])->assertJsonValidationErrors('lines.0.qtyBase');
    returnToSupplier($this, $user, supplier(), $t, [[$t['batches'][0], 6], [$t['batches'][0], 5]])->assertJsonValidationErrors('lines.0.qtyBase');
    returnToSupplier($this, $user, supplier(), $t, [[$other['batches'][0], 1]])->assertJsonValidationErrors('lines.0.batchId');
    expect(stockOf($t['batches'][0]))->toBe(10)->and(PurchaseReturn::count())->toBe(0);
});

it('refuses a batch bought from another supplier but allows opening stock', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([['OPEN', day(100), 10, 1.0]]);
    $acme = Supplier::create(['code' => 'S-002', 'name' => 'Acme Pharma']);
    receiveFrom($this, $user, $acme, $t, 1, 10, 'ACME1');

    returnToSupplier($this, $user, supplier(), $t, [[batchNamed('ACME1'), 5]])
        ->assertJsonValidationErrors('lines.0.batchId')->assertJsonFragment(['Batch ACME1 was bought from Acme Pharma, not Test Distributor.']);
    returnToSupplier($this, $user, supplier(), $t, [[batchNamed('OPEN'), 5]])->assertCreated();
});

it('applies the credit to the source invoice first, then the oldest, and keeps the rest as supplier credit', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $old = receiveFrom($this, $user, supplier(), $t, 2, 10, 'OLD');  // ৳20, 20 pcs @ 1.00
    $mid = receiveFrom($this, $user, supplier(), $t, 5, 10, 'MID');  // ৳50, 50 pcs @ 1.00
    $src = receiveFrom($this, $user, supplier(), $t, 1, 30, 'SRC');  // ৳30, 10 pcs @ 3.00
    $pay = fn (Purchase $p, $amount, $key) => $this->actingAs($user)->postJson("/api/pos/v1/purchases/{$p->id}/payments", ['amount' => $amount, 'method' => 'cash'], ['Idempotency-Key' => $key]);
    $pay($src, 30, 'p0')->assertCreated();

    // SRC is paid, so its ৳30 credit goes to the oldest: ৳20 on OLD, ৳10 on MID.
    returnToSupplier($this, $user, supplier(), $t, [[batchNamed('SRC'), 10]])->assertCreated()->assertJsonPath('total', 30);
    expect((float) $old->fresh()->due_total)->toBe(0.0)->and((float) $mid->fresh()->due_total)->toBe(40.0);

    // ৳50 of MID stock: ৳40 clears MID's own due, ৳10 stays as credit with the supplier.
    returnToSupplier($this, $user, supplier(), $t, [[batchNamed('MID'), 50]])->assertCreated()->assertJsonPath('total', 50);
    expect((float) $mid->fresh()->due_total)->toBe(0.0)
        ->and((float) $mid->fresh()->returned_total)->toBe(50.0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(-10.0);
});

it('keeps invoice dues net of returns when paying', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $p = receiveFrom($this, $user, supplier(), $t, 2, 10, 'A1'); // ৳20
    returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 10]])->assertCreated(); // ৳10 off

    $pay = fn ($amount, $key) => $this->actingAs($user)->postJson("/api/pos/v1/purchases/{$p->id}/payments", ['amount' => $amount, 'method' => 'cash'], ['Idempotency-Key' => $key]);
    $pay(11, 'p1')->assertJsonValidationErrors('amount');
    $pay(4, 'p2')->assertCreated()->assertJsonPath('dueTotal', 6);
});

it('records a supplier cash refund without changing what we owe', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $purchase = receiveFrom($this, $user, supplier(), $t, 10, 15, 'A1');

    $id = returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 20]], 'refund')->assertCreated()->assertJsonPath('total', 30)->json('id');

    $payment = Payment::findOrFail(PurchaseReturn::find($id)->payment_id);
    expect($payment->direction)->toBe('in')->and((float) $payment->amount)->toBe(30.0)
        ->and((float) AccountTransaction::where(['source_type' => 'payment', 'source_id' => $payment->id, 'direction' => 'in'])->sum('amount'))->toBe(30.0)
        ->and((float) $purchase->fresh()->due_total)->toBe(150.0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(150.0)
        ->and(stockOf(batchNamed('A1')))->toBe(80);
});

it('voids a return: stock goes back and the invoice due is restored', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $purchase = receiveFrom($this, $user, supplier(), $t, 10, 15, 'A1');
    $id = returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 30]])->json('id');

    $void = fn () => $this->actingAs($user)->postJson("/api/pos/v1/purchase-returns/$id/void", ['reason' => 'Supplier refused'], ['Idempotency-Key' => uniqid()]);
    $void()->assertOk()->assertJsonPath('status', 'void');
    $void()->assertJsonValidationErrors('purchaseReturn');

    expect(stockOf(batchNamed('A1')))->toBe(100)
        ->and((float) $purchase->fresh()->due_total)->toBe(150.0)
        ->and((float) $purchase->fresh()->returned_total)->toBe(0.0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(150.0);
    $this->actingAs($user)->getJson("/api/pos/v1/purchases/{$purchase->id}")->assertJsonPath('returns', []);
});

it('voids a refunded return by reversing the refund payment', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    receiveFrom($this, $user, supplier(), $t, 10, 15, 'A1');
    $id = returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 20]], 'refund')->json('id');
    $payment = Payment::findOrFail(PurchaseReturn::find($id)->payment_id);

    $this->actingAs($user)->postJson("/api/pos/v1/purchase-returns/$id/void", ['reason' => 'Refund bounced'], ['Idempotency-Key' => 'v'])->assertOk();

    expect($payment->fresh()->status)->toBe('void')
        ->and((float) AccountTransaction::where(['source_type' => 'payment_void', 'source_id' => $payment->id, 'direction' => 'out'])->sum('amount'))->toBe(30.0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(150.0)
        ->and(stockOf(batchNamed('A1')))->toBe(100);
});

it('blocks voiding a purchase whose stock was returned', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $purchase = receiveFrom($this, $user, supplier(), $t, 10, 15, 'A1');
    $id = returnToSupplier($this, $user, supplier(), $t, [[batchNamed('A1'), 1]])->json('id');

    $voidPurchase = fn ($key) => $this->actingAs($user)->postJson("/api/pos/v1/purchases/{$purchase->id}/void", ['reason' => 'Wrong supplier'], ['Idempotency-Key' => $key]);
    $voidPurchase('a')->assertJsonValidationErrors('purchase');

    // Once the return is void, the purchase can be voided again (all 100 pieces are back).
    $this->actingAs($user)->postJson("/api/pos/v1/purchase-returns/$id/void", ['reason' => 'Mistake'], ['Idempotency-Key' => 'v'])->assertOk();
    $voidPurchase('b')->assertOk();
});

it('lists returns and needs the return permissions', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 1.0]]);
    returnToSupplier($this, $user, supplier(), $t, [[$t['batches'][0], 2]])->assertCreated();

    $this->actingAs($user)->getJson('/api/pos/v1/purchase-returns?search=distributor')
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.supplier', 'Test Distributor')->assertJsonPath('data.0.total', 2);
    $this->actingAs($user)->getJson('/api/pos/v1/purchase-returns?status=void')->assertJsonPath('meta.total', 0);

    $cashier = posUser('Cashier');
    $this->actingAs($cashier)->getJson('/api/pos/v1/purchase-returns')->assertForbidden();
    returnToSupplier($this, $cashier, supplier(), $t, [[$t['batches'][0], 1]])->assertForbidden();
    $this->actingAs($user)->postJson('/api/pos/v1/purchase-returns', [])->assertStatus(428); // Idempotency-Key required
});

it('shows expired batches in search only when asked by someone who can return stock', function () {
    $t = tabletWithStock([['OLD', day(-1), 5, 1.0], ['NEW', day(100), 5, 1.0]]);
    $search = fn ($user, $extra) => $this->actingAs($user)->getJson('/api/pos/v1/catalog/products/search?'.http_build_query(['q' => 'Testamol', 'warehouse_id' => $this->warehouse->id] + $extra));

    $search(posUser('Inventory'), ['includeExpired' => 1])->assertJsonPath('0.batches.0.batchNo', 'OLD')->assertJsonPath('0.stockBase', 10);
    $search(posUser('Inventory'), [])->assertJsonCount(1, '0.batches');
    $search(posUser('Cashier'), ['includeExpired' => 1])->assertJsonCount(1, '0.batches')->assertJsonPath('0.batches.0.batchNo', 'NEW');
});
