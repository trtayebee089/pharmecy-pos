<?php

use App\Models\Account;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\FinanceCategory;
use Illuminate\Testing\TestResponse;

/*
 * One day of trading, checked against figures worked out by hand:
 *  - stock: GOOD 200 pcs @ ৳1.00 (exp +200d), SOON 20 pcs @ ৳1.50 (exp +20d), OLD 10 pcs @ ৳1.00 (expired); strip = 10 pcs at ৳20
 *  - A walk-in 2 strips (FEFO: all of SOON, cost 30) paid ৳40 cash
 *  - B Rahim 1 strip (GOOD, cost 10), pays ৳5, owes ৳15; collects ৳10 later → owes ৳5
 *  - C walk-in custom "Bandage roll" ৳50 + 1 piece (cost 1) paid ৳52
 *  - D walk-in 1 piece ৳2, voided
 *  - purchase P 10 strips @ ৳15 = ৳150 (batch NEW, 100 pcs @ ৳1.50), ৳50 paid; 10 pcs returned → ৳15 credit, due ৳85
 *  - expense ৳20 from cash
 */
function tradingDay($test): array
{
    $m = posUser('Manager');
    $t = tabletWithStock([['GOOD', day(200), 200, 1.0], ['SOON', day(20), 20, 1.5], ['OLD', day(-3), 10, 1.0]]);
    $rahim = Customer::create(['code' => 'C-0777', 'name' => 'Rahim', 'phone' => '01711111111', 'phone_normalized' => '01711111111']);
    $key = fn () => ['Idempotency-Key' => uniqid('k', true)];
    $sell = fn (array $lines, float $paid, ?int $customer = null) => $test->actingAs($m)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $test->warehouse->id, 'customerId' => $customer ?? walkIn()->id, 'orderDiscount' => 0, 'lines' => $lines,
        'payments' => $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [],
    ], $key())->assertCreated()->json('id');
    $strip = fn ($n) => ['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => $n];
    $piece = ['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 1];

    $a = $sell([$strip(2)], 40);
    $b = $sell([$strip(1)], 5, $rahim->id);
    $c = $sell([['productId' => 0, 'unitId' => 0, 'qty' => 1, 'custom' => ['name' => 'Bandage roll', 'code' => 'BR', 'price' => 50]], $piece], 52);
    $d = $sell([$piece], 2);
    $test->actingAs($m)->postJson("/api/pos/v1/sales/$d/void", ['reason' => 'Wrong item'])->assertOk();
    $test->actingAs($m)->postJson("/api/pos/v1/customers/{$rahim->id}/payments", ['amount' => 10, 'method' => 'cash', 'allocations' => [['saleId' => $b, 'amount' => 10]]], $key())->assertCreated();

    $p = $test->actingAs($m)->postJson('/api/pos/v1/purchases', [
        'status' => 'received', 'warehouseId' => $test->warehouse->id, 'supplierId' => supplier()->id, 'supplierInvoiceNo' => 'INV-9',
        'paidAmount' => 50, 'paymentMethod' => 'cash',
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 10, 'unitCost' => 15, 'batchNo' => 'NEW', 'expiryDate' => day(300)]],
    ], $key())->assertCreated()->json('id');
    $test->actingAs($m)->postJson('/api/pos/v1/purchase-returns', [
        'warehouseId' => $test->warehouse->id, 'supplierId' => supplier()->id, 'reason' => 'Damaged', 'settlement' => 'reduce_due',
        'lines' => [['productId' => $t['product']->id, 'batchId' => Batch::where('batch_no', 'NEW')->value('id'), 'qtyBase' => 10]],
    ], $key())->assertCreated();

    $cat = FinanceCategory::create(['kind' => 'expense', 'name' => 'Supplies']);
    $test->actingAs($m)->postJson('/api/pos/v1/finance/expenses', ['date' => day(0), 'categoryId' => $cat->id, 'accountId' => Account::where('code', 'CASH')->value('id'), 'amount' => 20], $key())->assertCreated();

    return compact('m', 't', 'rahim', 'a', 'b', 'c', 'd', 'p');
}

function getReport($test, $user, string $name, array $q = []): TestResponse
{
    return $test->actingAs($user)->getJson("/api/pos/v1/reports/$name?".http_build_query($q + ['from' => day(-29), 'to' => day(0)]))->assertOk();
}

/** KPI values by label. */
function kpis(TestResponse $r): array
{
    return collect($r->json('kpis'))->pluck('value', 'label')->all();
}

it('reports sales, profit, billers and custom items from completed sales only', function () {
    $w = tradingDay($this);

    $sales = getReport($this, $w['m'], 'sales');
    expect(kpis($sales))->toEqual(['Net sales' => 112, 'Invoices' => 3, 'Average basket' => 37.33, 'Outstanding due' => 5])
        ->and($sales->json('totals'))->toEqual(['items' => 4, 'grandTotal' => 112, 'paidTotal' => 107, 'dueTotal' => 5]);
    expect(getReport($this, $w['m'], 'sales', ['payment' => 'partial'])->json('rows.0.id'))->toBe($w['b']);

    $profit = getReport($this, $w['m'], 'profit');
    expect(kpis($profit))->toEqual(['Revenue' => 112, 'Gross profit' => 71, 'Expenses' => 20, 'Net profit' => 51])
        ->and($profit->json('rows.0'))->toMatchArray(['day' => day(0), 'invoices' => 3]);
    $byProduct = getReport($this, $w['m'], 'profit', ['view' => 'product']);
    expect($byProduct->json('rows.0'))->toMatchArray(['productId' => $w['t']['product']->id, 'qty' => 31, 'revenue' => 62, 'cogs' => 41]);

    expect(kpis(getReport($this, $w['m'], 'billers', ['biller' => (string) $this->warehouse->id]))['Sales'])->toEqual(112);
    $custom = getReport($this, $w['m'], 'custom-products');
    expect($custom->json('rows'))->toHaveCount(1)->and($custom->json('rows.0'))->toMatchArray(['productName' => 'Bandage roll', 'total' => 50, 'saleId' => $w['c']]);
});

it('values stock at cost and retail and buckets batches by expiry', function () {
    $w = tradingDay($this);

    $stock = getReport($this, $w['m'], 'stock');
    // Sellable: GOOD 189 + NEW 90 (SOON sold out, OLD expired). Cost 189 × 1 + 90 × 1.5. Retail 279 × ৳2.
    expect($stock->json('rows.0'))->toMatchArray(['pieces' => 279, 'batches' => 2, 'costValue' => 324, 'retailValue' => 558, 'status' => 'ok']);

    $expiry = getReport($this, $w['m'], 'expiry');
    expect($expiry->json('rows'))->toHaveCount(1)->and($expiry->json('rows.0'))->toMatchArray(['batchNo' => 'OLD', 'bucket' => 'expired', 'days' => -3, 'value' => 10])
        ->and(kpis($expiry))->toEqual(['Expired (blocked)' => 10, 'Expiring ≤ 30 days' => 0, 'Expiring 31–90 days' => 0, 'Value at risk' => 10]);
    expect(getReport($this, $w['m'], 'expiry', ['window' => 'critical'])->json('rows'))->toBe([]);
});

it('reports purchases and supplier balances net of returns and payments', function () {
    $w = tradingDay($this);

    $p = getReport($this, $w['m'], 'purchases');
    expect($p->json('rows.0'))->toMatchArray(['id' => $w['p'], 'grandTotal' => 150, 'paidTotal' => 50, 'dueTotal' => 85, 'lines' => 1]);
    expect(getReport($this, $w['m'], 'purchases', ['view' => 'supplier'])->json('rows.0'))->toMatchArray(['supplier' => 'Test Distributor', 'invoices' => 1]);

    $s = collect(getReport($this, $w['m'], 'suppliers')->json('rows'))->firstWhere('supplier', 'Test Distributor');
    expect($s)->toMatchArray(['purchased' => 150, 'returns' => 15, 'paid' => 50, 'periodDue' => 85, 'balance' => 85]);
});

it('reports customer dues, a customer statement with an opening line, and the summary', function () {
    $w = tradingDay($this);

    $dues = getReport($this, $w['m'], 'customers', ['due' => 'yes']);
    expect($dues->json('rows'))->toHaveCount(1)->and($dues->json('rows.0'))->toMatchArray(['name' => 'Rahim', 'periodSpend' => 20, 'due' => 5, 'membership' => 'none']);
    expect(getReport($this, $w['m'], 'customers', ['membership' => 'Gold'])->json('rows'))->toBe([]); // no memberships yet

    $st = getReport($this, $w['m'], 'customer', ['customer' => (string) $w['rahim']->id]);
    expect(collect($st->json('rows'))->map(fn ($r) => [$r['type'], $r['debit'], $r['credit'], $r['balance']])->all())
        ->toEqual([['opening', 0, 0, 0], ['sale', 20, 0, 20], ['payment', 0, 5, 15], ['payment', 0, 10, 5]])
        ->and(kpis($st))->toEqual(['Purchases' => 20, 'Paid' => 15, 'Returns' => 0, 'Balance due' => 5]);

    expect(collect(getReport($this, $w['m'], 'customer')->json('rows'))->firstWhere('name', 'Rahim'))->toMatchArray(['invoices' => 1, 'purchases' => 20, 'paid' => 15, 'due' => 5]);
});

it('lists payments in and out with net cash flow and digital share', function () {
    $w = tradingDay($this);

    $pay = getReport($this, $w['m'], 'payments');
    // In: 40 + 5 + 52 + collection 10 (the voided sale's ৳2 is excluded). Out: ৳50 to the supplier.
    expect(kpis($pay))->toEqual(['Received' => 107, 'Paid out' => 50, 'Net cash flow' => 57, 'Digital share' => 0])
        ->and($pay->json('totals.amount'))->toEqual(57);
    $out = getReport($this, $w['m'], 'payments', ['direction' => 'out'])->json('rows');
    expect($out)->toHaveCount(1)->and($out[0])->toMatchArray(['docType' => 'purchase', 'docId' => $w['p'], 'party' => 'Test Distributor', 'method' => 'cash']);
});

it('summarises cashiers and each day of the month', function () {
    $w = tradingDay($this);

    expect(getReport($this, $w['m'], 'cashiers')->json('rows.0'))->toMatchArray(['cashier' => $w['m']->name, 'invoices' => 3, 'sales' => 112, 'voids' => 1]);

    $days = $this->actingAs($w['m'])->getJson('/api/pos/v1/reports/monthly-summary?year='.now()->year.'&month='.now()->month)->assertOk()->json();
    expect($days)->toHaveCount(now()->day)
        ->and(collect($days)->firstWhere('date', day(0)))->toMatchArray([
            'invoices' => 3, 'sales' => 112, 'netSales' => 112, 'cogs' => 41, 'grossProfit' => 71,
            'purchases' => 150, 'expenses' => 20, 'netProfit' => 51, 'cash' => 107,
        ]);
});

it('gives filter options and keeps reports behind their permissions', function () {
    $w = tradingDay($this);

    $this->actingAs($w['m'])->getJson('/api/pos/v1/reports/options')->assertOk()
        ->assertJsonPath('suppliers', ['Test Distributor'])->assertJsonPath('customItems', ['Bandage roll']);
    $this->actingAs($w['m'])->getJson('/api/pos/v1/billers')->assertJsonPath('0.id', $this->warehouse->id);
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/reports/profit')->assertForbidden();
    $this->actingAs(posUser('Inventory'))->getJson('/api/pos/v1/reports/stock')->assertOk();
});
