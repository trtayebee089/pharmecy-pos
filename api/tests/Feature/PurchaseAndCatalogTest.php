<?php

use App\Models\Batch;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\StockBalance;

function receive($test, $user, array $lines, array $extra = [], string $key = 'p-1')
{
    return $test->actingAs($user)->postJson('/api/pos/v1/purchases', $extra + [
        'status' => 'received', 'warehouseId' => $test->warehouse->id, 'supplierId' => supplier()->id,
        'supplierInvoiceNo' => 'INV-1', 'invoiceDate' => day(0), 'otherCharges' => 0, 'paidAmount' => 0, 'paymentMethod' => 'cash', 'note' => '',
        'lines' => $lines,
    ], ['Idempotency-Key' => $key]);
}

it('receives stock into a new batch with landed cost and supplier due', function () {
    $t = tabletWithStock([]);
    $line = ['productId' => $t['product']->id, 'unitId' => $t['box']->id, 'qty' => 2, 'freeQty' => 0, 'batchNo' => 'nb-77', 'expiryDate' => day(365), 'unitCost' => 150, 'discountPct' => 0, 'updateRetailPrice' => null];

    receive($this, posUser('Inventory'), [$line])->assertCreated()->assertJsonPath('grandTotal', 300)->assertJsonPath('dueTotal', 300);

    $batch = Batch::where('batch_no', 'NB-77')->firstOrFail();
    expect(stockOf($batch))->toBe(200)
        ->and((float) StockBalance::where('batch_id', $batch->id)->value('avg_unit_cost'))->toBe(1.5)
        ->and((float) PartyLedgerEntry::where('party_type', 'supplier')->sum('credit'))->toBe(300.0);
});

it('adds to an existing batch and averages the cost; free goods dilute cost', function () {
    $t = tabletWithStock([['EX1', day(300), 100, 1.0]]);
    $line = ['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 10, 'freeQty' => 10, 'batchNo' => 'EX1', 'expiryDate' => day(300), 'unitCost' => 20, 'discountPct' => 0];

    receive($this, posUser('Inventory'), [$line])->assertCreated();

    // 100 @1.00 + 200 pieces costing 200 (1.00 each incl. free) → avg 1.00, qty 300
    expect(stockOf($t['batches'][0]))->toBe(300)
        ->and((float) StockBalance::where('batch_id', $t['batches'][0]->id)->value('avg_unit_cost'))->toBe(1.0);
});

it('rejects past expiry and a batch number with a different expiry', function () {
    $t = tabletWithStock([['EX1', day(300), 100, 1.0]]);
    $base = ['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 1, 'unitCost' => 10];

    receive($this, posUser('Inventory'), [$base + ['batchNo' => 'Z', 'expiryDate' => day(-5)]])->assertJsonValidationErrors('lines.0.expiryDate');
    receive($this, posUser('Inventory'), [$base + ['batchNo' => 'EX1', 'expiryDate' => day(200)]], [], 'p-2')->assertJsonValidationErrors('lines.0.expiryDate');
    expect(Purchase::count())->toBe(0)->and(stockOf($t['batches'][0]))->toBe(100);
});

it('stores a purchase order without touching stock', function () {
    $t = tabletWithStock([]);
    receive($this, posUser('Inventory'), [['productId' => $t['product']->id, 'unitId' => $t['box']->id, 'qty' => 3, 'unitCost' => 150]], ['status' => 'ordered'])
        ->assertCreated()->assertJsonPath('status', 'ordered');

    expect(StockBalance::count())->toBe(0)->and(Purchase::first()->number)->toStartWith('PO-DHK1-');
});

it('records a supplier payment and can update the retail price with permission', function () {
    $t = tabletWithStock([]);
    $user = posUser('Inventory');
    $line = ['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 5, 'batchNo' => 'Q1', 'expiryDate' => day(400), 'unitCost' => 15, 'updateRetailPrice' => 25];

    receive($this, $user, [$line])->assertJsonValidationErrors('lines');

    $user->givePermissionTo('products.price');
    receive($this, $user->fresh(), [$line], ['paidAmount' => 50], 'p-2')->assertCreated()->assertJsonPath('dueTotal', 25);

    $this->actingAs($user)->getJson("/api/pos/v1/catalog/products/{$t['product']->id}?warehouse_id={$this->warehouse->id}")
        ->assertJsonPath('units.1.price', 25)->assertJsonPath('stockBase', 50);
});

it('searches the catalogue with FEFO batches and sellable stock only', function () {
    $t = tabletWithStock([['LATE', day(400), 30, 1.0], ['GONE', day(-2), 99, 1.0], ['SOON', day(20), 10, 1.0]]);
    $user = posUser();

    $res = $this->actingAs($user)->getJson('/api/pos/v1/catalog/products/search?q=testa&warehouse_id='.$this->warehouse->id)->assertOk();

    expect($res->json('0.stockBase'))->toBe(40)
        ->and(array_column($res->json('0.batches'), 'batchNo'))->toBe(['SOON', 'LATE'])
        ->and(array_column($res->json('0.units'), 'code'))->toBe(['piece', 'strip', 'box'])
        ->and($res->json('0.units.1.price'))->toEqual(20);

    $this->actingAs($user)->getJson('/api/pos/v1/catalog/products/search?q='.$t['product']->sku.'&warehouse_id='.$this->warehouse->id)
        ->assertJsonCount(1);
});

it('reports today on the dashboard', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0], ['X', day(-1), 5, 1.0]]);
    $user = posUser();
    $this->actingAs($user)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 3]],
        'payments' => [['method' => 'cash', 'amount' => 60]],
    ], ['Idempotency-Key' => 'd1'])->assertCreated();

    $this->actingAs($user)->getJson('/api/pos/v1/dashboard/summary?warehouse_id='.$this->warehouse->id)
        ->assertOk()
        ->assertJsonPath('todaySales', 60)
        ->assertJsonPath('todayInvoices', 1)
        ->assertJsonPath('todayProfit', 30)
        ->assertJsonPath('cashInHand', 60)
        ->assertJsonPath('expiredCount', 1)
        ->assertJsonPath('paymentMix.0.method', 'Cash');
});

it('creates customers with a unique normalised phone', function () {
    $user = posUser();
    $this->actingAs($user)->postJson('/api/pos/v1/customers', ['name' => 'Rina', 'phone' => '+8801712345678'])
        ->assertCreated()->assertJsonPath('phone', '01712345678');
    $this->actingAs($user)->postJson('/api/pos/v1/customers', ['name' => 'Rina 2', 'phone' => '01712345678'])
        ->assertJsonValidationErrors('phone');
    $this->actingAs($user)->postJson('/api/pos/v1/customers', ['name' => 'Bad', 'phone' => '12345'])
        ->assertJsonValidationErrors('phone');
});

it('lists the catalogue and customers paginated', function () {
    tabletWithStock([['A1', day(200), 100, 1.0]]);
    $user = posUser('Manager');

    $this->actingAs($user)->getJson('/api/pos/v1/catalog/products?perPage=10&search=testa&warehouse_id='.$this->warehouse->id)
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stockBase', 100);
    $this->actingAs($user)->getJson('/api/pos/v1/customers?perPage=5')
        ->assertOk()->assertJsonPath('data.0.isWalkIn', true)->assertJsonPath('meta.page', 1);
});
