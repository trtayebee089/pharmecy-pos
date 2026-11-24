<?php

use App\Models\Manufacturer;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockLedger;
use Illuminate\Testing\TestResponse;

const INV = '/api/pos/v1/inventory';

function uttara(): Warehouse
{
    return Warehouse::firstOrCreate(['code' => 'UTR1'], ['name' => 'Uttara']);
}

function invPost($test, $user, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->postJson(INV.$path, $body, ['Idempotency-Key' => uniqid('i', true)]);
}

function adjust($test, $user, string $reason, array $items, string $note = 'Checked on shelf'): TestResponse
{
    return invPost($test, $user, '/adjustments', [
        'warehouseId' => $test->warehouse->id, 'reason' => $reason, 'note' => $note,
        'items' => array_map(fn ($i) => ['productId' => $i[0]->product_id, 'batchId' => $i[0]->id, 'qtyBase' => $i[1]], $items),
    ]);
}

function avgCost($batch, ?Warehouse $w = null): float
{
    return (float) StockBalance::where(['batch_id' => $batch->id, 'warehouse_id' => ($w ?? test()->warehouse)->id])->value('avg_unit_cost');
}

// ---------------------------------------------------------------- stock & batches

it('shows sellable and expired stock, value at cost and status per product', function () {
    $user = posUser('Inventory');
    $a = tabletWithStock([['OLD', day(-3), 4, 1.0], ['SOON', day(20), 6, 2.0], ['LATER', day(300), 10, 2.0]]); // sellable 16 > reorder 10; value 6×2 + 10×2 = 32 (expired not valued)
    $b = tabletWithStock([['B1', day(200), 5, 3.0]]);                                                          // low: 5 <= 10
    $c = tabletWithStock([]);                                                                                  // out
    tabletWithStock([['ELSEWHERE', day(200), 99, 1.0]], warehouse: uttara());                                 // other warehouse: out here

    $res = $this->actingAs($user)->getJson(INV.'/stock?perPage=50')->assertOk()->assertJsonPath('meta.total', 4);
    $row = collect($res->json('data'))->firstWhere('id', $a['product']->id);
    expect($row['sellableBase'])->toBe(16)->and($row['expiredBase'])->toBe(4)->and($row['valueAtCost'])->toBe(32)
        ->and($row['status'])->toBe('ok')->and($row['expiringSoon'])->toBe(1)->and($row['batches'])->toHaveCount(3)
        ->and($row['units'])->toHaveCount(3);

    $ids = fn ($status) => collect($this->actingAs($user)->getJson(INV."/stock?status=$status")->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids('low'))->toBe([$b['product']->id])
        ->and($ids('expiring'))->toBe([$a['product']->id])
        ->and($ids('out'))->toContain($c['product']->id)->not->toContain($a['product']->id);

    $this->actingAs($user)->getJson(INV.'/stock/summary')->assertExactJson(['value' => 47, 'out' => 2, 'low' => 1, 'expiring' => 1, 'products' => 4]);
    $this->actingAs($user)->getJson(INV.'/stock?warehouse_id='.uttara()->id)->assertJsonValidationErrors('warehouse_id');
});

it('lists batches by expiry with expired and window filters', function () {
    $user = posUser('Cashier'); // inventory.view
    tabletWithStock([['OLD', day(-3), 4, 1.0], ['SOON', day(20), 6, 2.5], ['LATER', day(300), 10, 2.0]]);

    $nos = fn ($within) => collect($this->actingAs($user)->getJson(INV."/batches?within=$within")->json('data'))->pluck('batchNo')->all();
    expect($nos('expired'))->toBe(['OLD'])->and($nos('30'))->toBe(['SOON'])->and($nos('0'))->toBe(['OLD', 'SOON', 'LATER']);
    $this->actingAs($user)->getJson(INV.'/batches?search=SOON')->assertJsonPath('data.0.value', 15)->assertJsonPath('data.0.qtyBase', 6);
});

// ---------------------------------------------------------------- adjustments

it('writes off damaged stock at batch cost', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 1.5]]);

    $res = adjust($this, $user, 'damage', [[$t['batches'][0], -4]])->assertCreated()
        ->assertJsonPath('qtyOut', 4)->assertJsonPath('qtyIn', 0)->assertJsonPath('value', 6)->assertJsonPath('status', 'posted');
    expect($res->json('number'))->toStartWith('ADJ-DHK1-')
        ->and(stockOf($t['batches'][0]))->toBe(6)
        ->and((int) StockMovement::where('type', 'damage')->sum('qty_base'))->toBe(-4);
});

it('enforces the direction of each reason and the batch stock', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 1.0], ['X', day(-1), 3, 1.0]]);
    [$good, $expired] = $t['batches'];

    adjust($this, $user, 'damage', [[$good, 2]])->assertJsonValidationErrors('items.0.qtyBase');
    adjust($this, $user, 'lost', [[$good, 0]])->assertJsonValidationErrors('items.0.qtyBase');
    adjust($this, $user, 'found', [[$good, -2]])->assertJsonValidationErrors('items.0.qtyBase');
    adjust($this, $user, 'expiry', [[$good, -2]])->assertJsonValidationErrors('items.0.batchId');
    adjust($this, $user, 'correction', [[$good, -6], [$good, -5]])->assertJsonValidationErrors('items.0.qtyBase');
    adjust($this, $user, 'damage', [[$good, -1]], 'x')->assertJsonValidationErrors('note');

    adjust($this, $user, 'expiry', [[$expired, -3]])->assertCreated();
    expect(stockOf($expired))->toBe(0)->and(stockOf($good))->toBe(10)
        ->and((int) StockMovement::where('type', 'expiry_writeoff')->sum('qty_base'))->toBe(-3);
});

it('adds found stock at the batch cost and corrects in both directions', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 2.0], ['C', day(100), 10, 1.0]]);

    adjust($this, $user, 'found', [[$t['batches'][0], 5]])->assertCreated()->assertJsonPath('value', 10);
    adjust($this, $user, 'correction', [[$t['batches'][0], -1], [$t['batches'][1], 2]])->assertCreated()
        ->assertJsonPath('qtyIn', 2)->assertJsonPath('qtyOut', 1);

    expect(stockOf($t['batches'][0]))->toBe(14)->and(avgCost($t['batches'][0]))->toBe(2.0)->and(stockOf($t['batches'][1]))->toBe(12);
});

it('voids an adjustment by reversing it, unless the added stock is gone', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 1.0]]);
    $out = adjust($this, $user, 'damage', [[$t['batches'][0], -4]])->json('id');
    $in = adjust($this, $user, 'found', [[$t['batches'][0], 3]])->json('id');

    invPost($this, $user, "/adjustments/$out/void", ['reason' => 'Not damaged'])->assertOk()->assertJsonPath('status', 'void');
    invPost($this, $user, "/adjustments/$out/void", ['reason' => 'Again'])->assertJsonValidationErrors('adjustment');
    expect(stockOf($t['batches'][0]))->toBe(13);

    // The found pieces are sold: the "found" adjustment can no longer be undone.
    app(StockLedger::class)->move(['warehouse_id' => $this->warehouse->id, 'product_id' => $t['product']->id, 'batch_id' => $t['batches'][0]->id, 'qty_base' => -12, 'type' => 'sale']);
    invPost($this, $user, "/adjustments/$in/void", ['reason' => 'Mistake'])->assertJsonValidationErrors('adjustment');
    expect(StockAdjustment::find($in)->status)->toBe('posted');

    $this->actingAs($user)->getJson(INV.'/adjustments?reason=found')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.createdBy', $user->name);
});

it('keeps cashiers out of adjustments', function () {
    $t = tabletWithStock([['B', day(100), 10, 1.0]]);
    adjust($this, posUser('Cashier'), 'damage', [[$t['batches'][0], -1]])->assertForbidden();
});

// ---------------------------------------------------------------- transfers

it('sends stock into transit and receives it at the destination at the same cost', function () {
    $user = posUser('Inventory');
    $user->warehouses()->attach(uttara()->id);
    $t = tabletWithStock([['B', day(100), 10, 1.75]]);
    $send = fn ($lines, $to = null) => invPost($this, $user, '/transfers', [
        'fromWarehouseId' => $this->warehouse->id, 'toWarehouseId' => ($to ?? uttara())->id, 'note' => 'Weekly top-up',
        'lines' => array_map(fn ($l) => ['productId' => $t['product']->id, 'batchId' => $l[0]->id, 'qtyBase' => $l[1]], $lines),
    ]);

    $res = $send([[$t['batches'][0], 6]])->assertCreated()->assertJsonPath('status', 'sent')->assertJsonPath('qty', 6)
        ->assertJsonPath('from', 'Dhanmondi')->assertJsonPath('to', 'Uttara')->assertJsonPath('lines.0.batchNo', 'B');
    expect($res->json('number'))->toStartWith('TR-DHK1-')
        ->and(stockOf($t['batches'][0]))->toBe(4)->and(stockOf($t['batches'][0], uttara()))->toBe(0); // in transit

    invPost($this, $user, '/transfers/'.$res->json('id').'/receive')->assertOk()->assertJsonPath('status', 'received');
    expect(stockOf($t['batches'][0], uttara()))->toBe(6)->and(avgCost($t['batches'][0], uttara()))->toBe(1.75);
    invPost($this, $user, '/transfers/'.$res->json('id').'/void', ['reason' => 'Late'])->assertJsonValidationErrors('transfer');
    invPost($this, $user, '/transfers/'.$res->json('id').'/receive')->assertJsonValidationErrors('transfer');

    $send([[$t['batches'][0], 5]])->assertJsonValidationErrors('lines.0.qtyBase');
    $send([[$t['batches'][0], 1]], $this->warehouse)->assertJsonValidationErrors('toWarehouseId');
});

it('refuses expired stock and sending from a warehouse the user is not assigned to', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['OLD', day(-1), 5, 1.0]]);
    $other = tabletWithStock([['U', day(100), 5, 1.0]], warehouse: uttara());

    invPost($this, $user, '/transfers', ['fromWarehouseId' => $this->warehouse->id, 'toWarehouseId' => uttara()->id,
        'lines' => [['productId' => $t['product']->id, 'batchId' => $t['batches'][0]->id, 'qtyBase' => 1]]])->assertJsonValidationErrors('lines.0.batchId');
    invPost($this, $user, '/transfers', ['fromWarehouseId' => uttara()->id, 'toWarehouseId' => $this->warehouse->id,
        'lines' => [['productId' => $other['product']->id, 'batchId' => $other['batches'][0]->id, 'qtyBase' => 1]]])->assertJsonValidationErrors('fromWarehouseId');
});

it('voids a transfer in transit back to the source; only the destination can receive', function () {
    $sender = posUser('Inventory');
    $t = tabletWithStock([['B', day(100), 10, 1.0]]);
    $id = invPost($this, $sender, '/transfers', ['fromWarehouseId' => $this->warehouse->id, 'toWarehouseId' => uttara()->id,
        'lines' => [['productId' => $t['product']->id, 'batchId' => $t['batches'][0]->id, 'qtyBase' => 7]]])->assertCreated()->json('id');

    invPost($this, $sender, "/transfers/$id/receive")->assertJsonValidationErrors('transfer'); // sender is not at Uttara
    $receiver = posUser('Inventory', uttara());
    $this->actingAs($receiver)->getJson(INV.'/transfers?status=sent')->assertJsonPath('meta.total', 1);

    invPost($this, $sender, "/transfers/$id/void", ['reason' => 'Wrong branch'])->assertOk()->assertJsonPath('status', 'void')->assertJsonPath('note', 'Wrong branch');
    expect(stockOf($t['batches'][0]))->toBe(10)->and(stockOf($t['batches'][0], uttara()))->toBe(0);
    invPost($this, $receiver, "/transfers/$id/receive")->assertJsonValidationErrors('transfer');
});

// ---------------------------------------------------------------- counts

it('starts a count over the batches on hand in scope', function () {
    $user = posUser('Inventory');
    $sq = Manufacturer::create(['name' => 'Square Pharmaceuticals']);
    $a = tabletWithStock([['A1', day(100), 10, 1.0], ['A2', day(-5), 2, 1.0]]);
    $a['product']->update(['manufacturer_id' => $sq->id]);
    tabletWithStock([['B1', day(100), 5, 1.0]]);
    $start = fn ($type, $value = null) => invPost($this, $user, '/counts', ['warehouseId' => $this->warehouse->id, 'name' => 'Shelf A', 'scopeType' => $type, 'scopeValue' => $value]);

    $start('all')->assertCreated()->assertJsonPath('lines', 3)->assertJsonPath('status', 'counting')->assertJsonPath('scope', 'All products');
    $id = $start('manufacturer', 'square')->assertCreated()->assertJsonPath('lines', 2)->assertJsonPath('scope', 'Manufacturer: square')->json('id');
    expect(invPost($this, $user, '/counts', ['warehouseId' => $this->warehouse->id, 'name' => 'Spot check', 'scopeType' => 'all'])->json('number'))->toStartWith('SC-DHK1-');
    $start('manufacturer', 'Nobody')->assertJsonValidationErrors('scopeValue');
    $start('category')->assertJsonValidationErrors('scopeValue');

    $this->actingAs($user)->getJson(INV."/counts/$id")->assertOk()
        ->assertJsonPath('session.counted', 0)->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.systemQty', 10);
});

it('posts count differences against the stock at posting time and leaves uncounted batches alone', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['A', day(100), 10, 2.0], ['B', day(100), 8, 1.0], ['C', day(100), 5, 1.0]]);
    [$a, $b, $c] = $t['batches'];
    $id = invPost($this, $user, '/counts', ['warehouseId' => $this->warehouse->id, 'name' => 'Full', 'scopeType' => 'all'])->json('id');

    $save = fn ($counts) => $this->actingAs($user)->putJson(INV."/counts/$id/lines", ['counts' => $counts]);
    $save([['batchId' => $a->id, 'countedQty' => 7], ['batchId' => $b->id, 'countedQty' => 8]])->assertOk()
        ->assertJsonPath('counted', 2)->assertJsonPath('varianceQty', -3)->assertJsonPath('varianceValue', -6);
    $save([['batchId' => 999999, 'countedQty' => 1]])->assertJsonValidationErrors('counts.0.batchId');

    // Two pieces of A are sold while counting: the shelf count (7) is compared with 8, not 10.
    app(StockLedger::class)->move(['warehouse_id' => $this->warehouse->id, 'product_id' => $t['product']->id, 'batch_id' => $a->id, 'qty_base' => -2, 'type' => 'sale']);

    invPost($this, $user, "/counts/$id/post")->assertOk()->assertJsonPath('status', 'posted')
        ->assertJsonPath('varianceQty', -1)->assertJsonPath('varianceValue', -2);
    expect(stockOf($a))->toBe(7)->and(stockOf($b))->toBe(8)->and(stockOf($c))->toBe(5)
        ->and(StockMovement::where('type', 'count')->count())->toBe(1);

    $save([['batchId' => $a->id, 'countedQty' => 1]])->assertJsonValidationErrors('count');
    invPost($this, $user, "/counts/$id/post")->assertJsonValidationErrors('count');
    $this->actingAs($user)->getJson(INV."/counts/$id")->assertJsonPath('lines.0.systemQty', 8);
});

it('needs a counted line to post and cancels without touching stock', function () {
    $user = posUser('Inventory');
    $t = tabletWithStock([['A', day(100), 10, 1.0]]);
    $id = invPost($this, $user, '/counts', ['warehouseId' => $this->warehouse->id, 'name' => 'Quick', 'scopeType' => 'all'])->json('id');

    invPost($this, $user, "/counts/$id/post")->assertJsonValidationErrors('count');
    $this->actingAs($user)->putJson(INV."/counts/$id/lines", ['counts' => [['batchId' => $t['batches'][0]->id, 'countedQty' => 3]]])->assertOk();
    $this->actingAs($user)->postJson(INV."/counts/$id/cancel", ['reason' => 'Wrong shelf'])->assertOk()->assertJsonPath('status', 'cancelled');

    expect(stockOf($t['batches'][0]))->toBe(10);
    $this->actingAs($user)->getJson(INV.'/counts?status=cancelled')->assertJsonPath('meta.total', 1);
    $this->actingAs(posUser('Cashier'))->getJson(INV.'/counts')->assertForbidden();
});

it('lists expired batches in product search for stock staff only', function () {
    $t = tabletWithStock([['OLD', day(-1), 5, 1.0], ['NEW', day(100), 5, 1.0]]);
    $show = fn ($user) => $this->actingAs($user)->getJson("/api/pos/v1/catalog/products/{$t['product']->id}?includeExpired=1&warehouse_id={$this->warehouse->id}");

    $show(posUser('Inventory'))->assertJsonCount(2, 'batches');
    $show(posUser('Cashier'))->assertJsonCount(1, 'batches');
});
