<?php

use App\Models\AccountTransaction;
use App\Models\Batch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\SupplierPayments;

/** Posts a purchase for $supplier and returns its id. */
function buy($test, $user, Supplier $supplier, array $tablet, int $strips, float $stripCost, array $extra = [], ?string $batchNo = null): int
{
    $status = $extra['status'] ?? 'received';

    return $test->actingAs($user)->postJson('/api/pos/v1/purchases', $extra + [
        'status' => $status, 'warehouseId' => $test->warehouse->id, 'supplierId' => $supplier->id, 'supplierInvoiceNo' => 'INV',
        'lines' => [['productId' => $tablet['product']->id, 'unitId' => $tablet['strip']->id, 'qty' => $strips, 'unitCost' => $stripCost]
            + ($status === 'received' ? ['batchNo' => $batchNo ?? 'B'.uniqid(), 'expiryDate' => day(400)] : [])],
    ], ['Idempotency-Key' => uniqid('p', true)])->assertCreated()->json('id');
}

// ---------------------------------------------------------------- suppliers

it('creates, updates, lists and looks up suppliers', function () {
    $user = posUser('Inventory');

    $id = $this->actingAs($user)->postJson('/api/pos/v1/suppliers', ['name' => 'Square Distribution', 'phone' => '01711000000', 'paymentTermsDays' => 30])
        ->assertCreated()->assertJsonPath('dueBalance', 0)->json('id');
    expect(Supplier::find($id)->code)->toBe('S-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT));

    $this->actingAs($user)->postJson('/api/pos/v1/suppliers', ['name' => 'Square Distribution', 'phone' => '1'])->assertJsonValidationErrors('name');
    $this->actingAs($user)->putJson("/api/pos/v1/suppliers/$id", ['name' => 'Square Dist.', 'phone' => '01711000000', 'isActive' => true])
        ->assertOk()->assertJsonPath('name', 'Square Dist.');

    $this->actingAs($user)->getJson('/api/pos/v1/suppliers?search=square')->assertJsonPath('meta.total', 1);
    $this->actingAs($user)->getJson('/api/pos/v1/suppliers?all=1')->assertJsonCount(1);
    $this->actingAs($user)->getJson('/api/pos/v1/suppliers/lookup')->assertJsonPath('0.name', 'Square Dist.');
});

it('deletes unused suppliers but only deactivates suppliers with purchases', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $used = supplier();
    buy($this, $user, $used, $t, 1, 10);
    $unused = Supplier::create(['code' => 'S-900', 'name' => 'Unused']);

    $this->actingAs($user)->deleteJson("/api/pos/v1/suppliers/{$used->id}")->assertOk();
    $this->actingAs($user)->deleteJson("/api/pos/v1/suppliers/{$unused->id}")->assertOk();

    expect($used->fresh()->is_active)->toBeFalse()
        ->and(Supplier::find($unused->id))->toBeNull()
        ->and(Supplier::withTrashed()->find($unused->id))->not->toBeNull();
});

it('keeps cashiers out of supplier management', function () {
    $this->actingAs(posUser('Cashier'))->postJson('/api/pos/v1/suppliers', ['name' => 'X', 'phone' => '1'])->assertForbidden();
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/suppliers')->assertForbidden();
});

// ---------------------------------------------------------------- payments

it('pays one invoice up to its due and records ledger and account', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $id = buy($this, $user, supplier(), $t, 10, 15); // ৳150

    $pay = fn ($amount, $key) => $this->actingAs($user)->postJson("/api/pos/v1/purchases/$id/payments", ['amount' => $amount, 'method' => 'cash', 'reference' => ''], ['Idempotency-Key' => $key]);
    $pay(200, 'a')->assertJsonValidationErrors('amount');
    $pay(100, 'b')->assertCreated()->assertJsonPath('dueTotal', 50);

    $detail = $this->actingAs($user)->getJson("/api/pos/v1/purchases/$id")->assertOk();
    $detail->assertJsonPath('paidTotal', 100)->assertJsonPath('dueTotal', 50)->assertJsonPath('payments.0.amount', 100)->assertJsonPath('lines.0.pieces', 100);
    expect(SupplierPayments::balance(supplier()->id))->toBe(50.0)
        ->and((float) AccountTransaction::where('direction', 'out')->sum('amount'))->toBe(100.0);
});

it('allocates a supplier account payment to the oldest invoices first', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $old = buy($this, $user, supplier(), $t, 10, 10);   // ৳100
    $new = buy($this, $user, supplier(), $t, 10, 20);   // ৳200
    Purchase::whereKey($old)->update(['received_at' => now()->subDays(5)]);

    $this->actingAs($user)->postJson('/api/pos/v1/suppliers/'.supplier()->id.'/payments', ['amount' => 400, 'method' => 'cash', 'reference' => '', 'paidAt' => day(0), 'note' => ''], ['Idempotency-Key' => 'x'])
        ->assertJsonValidationErrors('amount');
    $this->actingAs($user)->postJson('/api/pos/v1/suppliers/'.supplier()->id.'/payments', ['amount' => 150, 'method' => 'cash', 'reference' => '', 'paidAt' => day(0), 'note' => ''], ['Idempotency-Key' => 'y'])
        ->assertCreated()->assertJsonPath('dueBalance', 150);

    expect((float) Purchase::find($old)->due_total)->toBe(0.0)
        ->and((float) Purchase::find($new)->due_total)->toBe(150.0);

    $ledger = $this->actingAs($user)->getJson('/api/pos/v1/suppliers/'.supplier()->id.'/ledger')->assertOk()->json();
    expect(array_column($ledger, 'type'))->toBe(['invoice', 'invoice', 'payment'])
        ->and(end($ledger)['balance'])->toEqual(150);
});

it('needs the pay-supplier permission', function () {
    $t = tabletWithStock([]);
    $id = buy($this, posUser('Manager'), supplier(), $t, 1, 10);

    $this->actingAs(posUser('Inventory'))->postJson("/api/pos/v1/purchases/$id/payments", ['amount' => 5, 'method' => 'cash'], ['Idempotency-Key' => 'k'])->assertForbidden();
});

// ---------------------------------------------------------------- list, orders, void, duplicate

it('lists purchases with orders shown as draft', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    buy($this, $user, supplier(), $t, 2, 10);
    buy($this, $user, supplier(), $t, 3, 10, ['status' => 'ordered', 'paidAmount' => 999]);

    $this->actingAs($user)->getJson('/api/pos/v1/purchases')->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.status', 'draft')->assertJsonPath('data.0.paidTotal', 0);
    $this->actingAs($user)->getJson('/api/pos/v1/purchases?status=draft')->assertJsonPath('meta.total', 1);
});

it('receives a purchase order and closes it; voiding the receipt reopens it', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $order = buy($this, $user, supplier(), $t, 3, 10, ['status' => 'ordered']);
    $grn = buy($this, $user, supplier(), $t, 3, 10, ['orderId' => $order], 'ORD-1');

    expect(Purchase::find($order)->status)->toBe('fulfilled')
        ->and((float) Purchase::find($order)->due_total)->toBe(0.0)
        ->and(Purchase::find($grn)->order_id)->toBe($order);
    $this->actingAs($user)->getJson('/api/pos/v1/purchases?status=closed')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'closed');
    $this->actingAs($user)->getJson("/api/pos/v1/purchases/$grn")->assertJsonPath('orderNumber', Purchase::find($order)->number);

    // The same order cannot be received twice.
    $this->actingAs($user)->postJson('/api/pos/v1/purchases', [
        'orderId' => $order, 'status' => 'received', 'warehouseId' => $this->warehouse->id, 'supplierId' => supplier()->id,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1, 'unitCost' => 10, 'batchNo' => 'Z', 'expiryDate' => day(300)]],
    ], ['Idempotency-Key' => 'again'])->assertJsonValidationErrors('orderId');

    // A closed order is voided through its receipt, not directly.
    $this->actingAs($user)->postJson("/api/pos/v1/purchases/$order/void", ['reason' => 'Supplier cancelled'], ['Idempotency-Key' => 'vo'])
        ->assertJsonValidationErrors(['purchase' => 'This order was received. Void its goods receipt instead.']);

    $this->actingAs($user)->postJson("/api/pos/v1/purchases/$grn/void", ['reason' => 'Wrong supplier invoice'], ['Idempotency-Key' => 'v'])
        ->assertOk()->assertJsonPath('status', 'void');
    expect(Purchase::find($order)->status)->toBe('ordered')
        ->and((float) Purchase::find($order)->due_total)->toBe(30.0)
        ->and(stockOf(Batch::where('batch_no', 'ORD-1')->first()))->toBe(0)
        ->and(SupplierPayments::balance(supplier()->id))->toBe(0.0);
});

it('refuses to void a purchase whose stock was sold or that was paid', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $sold = buy($this, $user, supplier(), $t, 1, 10, [], 'SOLD1');
    $this->actingAs($user)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2]],
    ], ['Idempotency-Key' => 's'])->assertCreated();

    $this->actingAs($user)->postJson("/api/pos/v1/purchases/$sold/void", ['reason' => 'test'], ['Idempotency-Key' => 'v1'])->assertJsonValidationErrors('purchase');

    $paid = buy($this, $user, supplier(), $t, 1, 10, ['paidAmount' => 10, 'paymentMethod' => 'cash']);
    $this->actingAs($user)->postJson("/api/pos/v1/purchases/$paid/void", ['reason' => 'test'], ['Idempotency-Key' => 'v2'])
        ->assertJsonValidationErrors(['purchase' => 'This purchase has supplier payments. Use a purchase return instead.']);
});

it('cancels an order and duplicates a purchase as a new order', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([]);
    $order = buy($this, $user, supplier(), $t, 4, 12, ['status' => 'ordered']);

    $this->actingAs($user)->postJson("/api/pos/v1/purchases/$order/void", ['reason' => 'Supplier out of stock'], ['Idempotency-Key' => 'c'])->assertOk();
    $copy = $this->actingAs($user)->postJson("/api/pos/v1/purchases/$order/duplicate", [], ['Idempotency-Key' => 'd'])->assertCreated();

    expect($copy->json('number'))->toStartWith('PO-DHK1-')
        ->and(Purchase::find($copy->json('id'))->status)->toBe('ordered')
        ->and((float) Purchase::find($copy->json('id'))->grand_total)->toBe(48.0);
});
