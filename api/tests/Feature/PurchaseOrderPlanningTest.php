<?php

use App\Models\Manufacturer;
use App\Models\ProductPrice;
use App\Models\Purchase;
use App\Models\PurchaseOrderDraft;
use Illuminate\Testing\TestResponse;

const PO = '/api/pos/v1/purchase-orders';

/** Tablet (piece / strip 10 / box 100) by $maker with $stock sellable pieces at ৳1.00. */
function makerTablet(Manufacturer $maker, int $stock = 1000): array
{
    $t = tabletWithStock($stock > 0 ? [['B'.uniqid(), day(300), $stock, 1.0]] : []);
    $t['product']->update(['manufacturer_id' => $maker->id]);
    // Sales are placed in the past (travelTo): prices must already be effective then.
    ProductPrice::whereIn('product_unit_id', [$t['piece']->id, $t['strip']->id, $t['box']->id])->update(['effective_from' => now()->subYear()]);

    return $t;
}

/** Sells $pieces of the tablet $daysAgo days ago (walk-in, paid). */
function soldAgo($test, array $t, int $pieces, int $daysAgo): void
{
    $test->travelTo(now()->subDays($daysAgo)->setTime(12, 0));
    $test->actingAs(posUser('Cashier'))->postJson('/api/pos/v1/sales', [
        'warehouseId' => $test->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => $pieces]],
        'payments' => [['method' => 'cash', 'amount' => $pieces * 2]],
    ], ['Idempotency-Key' => uniqid('s', true)])->assertCreated();
    $test->travelBack();
}

function draftRows(array $t, int $orderQty, array $extra = []): array
{
    return [array_merge(['productId' => $t['product']->id, 'orderQty' => $orderQty, 'soldQty' => 10, 'inStock' => 5, 'unitCost' => 1.25], $extra)];
}

function saveDraftAs($test, $user, Manufacturer $maker, string $date, array $rows, string $source = 'manual'): TestResponse
{
    return $test->actingAs($user)->postJson(PO.'/drafts', ['company_id' => $maker->id, 'date' => $date, 'source' => $source, 'rows' => $rows],
        ['Idempotency-Key' => uniqid('d', true)]);
}

it('reports sales net of returns, sellable stock, the unit ladder and last cost per company', function () {
    $user = posUser('Inventory');
    $sq = Manufacturer::create(['name' => 'Square']);
    $t = makerTablet($sq, 500);
    makerTablet(Manufacturer::create(['name' => 'Other']));
    soldAgo($this, $t, 30, 2);
    soldAgo($this, $t, 50, 40); // outside the period

    $this->actingAs($user)->getJson(PO.'/companies')->assertJsonCount(2)->assertJsonPath('1.name', 'Square')->assertJsonPath('1.products', 1);
    $this->actingAs($user)->getJson(PO."/report?company_id={$sq->id}&from=".day(-7).'&to='.day(0))->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.soldQty', 30)->assertJsonPath('0.inStock', 420)
        ->assertJsonPath('0.piecesPerStrip', 10)->assertJsonPath('0.stripsPerBox', 10)->assertJsonPath('0.unitCost', 1);
});

it('forecasts from the last 28 days with a trend and recommends 21 days of cover', function () {
    $user = posUser('Inventory');
    $sq = Manufacturer::create(['name' => 'Square']);
    $steady = makerTablet($sq, 100);
    foreach (range(1, 28) as $d) {
        soldAgo($this, $steady, 2, $d - 1); // 2 pcs every day; 44 left covers 22 days > 21
    }
    $idle = makerTablet($sq, 100);

    $f = $this->actingAs($user)->postJson(PO.'/forecast', ['product_ids' => [$steady['product']->id, $idle['product']->id]])->assertOk()->json();
    expect($f[$steady['product']->id])->toMatchArray(['forecast7' => 14, 'recommended' => 0, 'confidence' => 'high'])
        ->and($f[$idle['product']->id])->toMatchArray(['forecast7' => 0, 'recommended' => 0, 'confidence' => 'low'])
        ->and($f[$idle['product']->id]['reason'])->toContain('No sales');

    // Selling the shelf down makes the recommendation appear: 2/day × 21 = 42, 4 left → 38.
    soldAgo($this, $steady, 40, 0);
    $f = $this->actingAs($user)->postJson(PO.'/forecast', ['product_ids' => [$steady['product']->id]])->json();
    expect($f[$steady['product']->id]['recommended'])->toBeGreaterThan(38) // the 40-piece day also raises the trend
        ->and($f[$steady['product']->id]['reason'])->toContain('ordering tops up to 21 days');
});

it('saves one draft per company and date, updates it and drops empty lines', function () {
    $user = posUser('Inventory');
    $sq = Manufacturer::create(['name' => 'Square']);
    $a = makerTablet($sq);
    $b = makerTablet($sq);
    $other = makerTablet(Manufacturer::create(['name' => 'Other']));

    $first = saveDraftAs($this, $user, $sq, day(0), [...draftRows($a, 200), ...draftRows($b, 0)])->assertCreated()
        ->assertJsonPath('lines', 1)->assertJsonPath('orderQty', 200)->assertJsonPath('estValue', 250)
        ->assertJsonPath('rows.0.boxQty', 2)->assertJsonPath('rows.0.stripQty', 20)->assertJsonPath('createdBy', $user->name);
    expect($first->json('number'))->toStartWith('POD-DHK1-');

    saveDraftAs($this, $user, $sq, day(0), draftRows($b, 30))->assertCreated()->assertJsonPath('id', $first->json('id'))->assertJsonPath('rows.0.productId', $b['product']->id);
    saveDraftAs($this, $user, $sq, day(1), draftRows($a, 10))->assertCreated();
    saveDraftAs($this, $user, $sq, day(0), draftRows($other, 10))->assertJsonValidationErrors('rows.0.productId');
    saveDraftAs($this, $user, $sq, day(0), draftRows($a, 0))->assertJsonValidationErrors('rows');

    $id = $first->json('id');
    $this->actingAs($user)->putJson(PO."/drafts/$id", ['rows' => draftRows($a, 100)])->assertOk()->assertJsonPath('orderQty', 100);
    $this->actingAs($user)->getJson(PO.'/drafts?companyId='.$sq->id.'&from='.day(0).'&to='.day(0))->assertJsonCount(1);
    $this->actingAs($user)->deleteJson(PO."/drafts/$id")->assertOk();
    expect(PurchaseOrderDraft::count())->toBe(1);
});

it('converts a draft into an ordered purchase in the largest unit that fits, once', function () {
    $user = posUser('Inventory');
    $sq = Manufacturer::create(['name' => 'Square']);
    $a = makerTablet($sq);
    $b = makerTablet($sq);
    $id = saveDraftAs($this, $user, $sq, day(0), [...draftRows($a, 200), ...draftRows($b, 35, ['unitCost' => 2])])->json('id');

    $res = $this->actingAs($user)->postJson(PO."/drafts/$id/purchase", ['supplier_id' => supplier()->id], ['Idempotency-Key' => 'c1'])->assertCreated();
    $purchase = Purchase::findOrFail($res->json('purchaseId'));
    expect($purchase->status)->toBe('ordered')->and($res->json('number'))->toStartWith('PO-DHK1-')
        ->and($purchase->items()->orderBy('id')->get()->map(fn ($i) => [$i->unit->unit_code, (float) $i->qty, (float) $i->unit_cost])->all())
        ->toBe([['box', 2.0, 125.0], ['piece', 35.0, 2.0]])
        ->and(stockOf($a['batches'][0]))->toBe(1000); // nothing received

    $this->actingAs($user)->getJson(PO."/drafts/$id")->assertJsonPath('status', 'converted')->assertJsonPath('purchaseNumber', $purchase->number);
    $this->actingAs($user)->postJson(PO."/drafts/$id/purchase", ['supplier_id' => supplier()->id], ['Idempotency-Key' => 'c2'])->assertJsonValidationErrors('draft');
    $this->actingAs($user)->deleteJson(PO."/drafts/$id")->assertJsonValidationErrors('draft');
});

it('drafts every company in batches where stock will not last 21 days', function () {
    $user = posUser('Inventory');
    $makers = collect(['A', 'B', 'C', 'D'])->map(fn ($n) => Manufacturer::create(['name' => "Maker $n"]));
    $short = makerTablet($makers[0], 30);   // sells 3/day: needs an order
    makerTablet($makers[1], 1000);          // no sales: nothing to order
    $alsoShort = makerTablet($makers[3], 10); // sells 1/day: also needs an order
    foreach (range(0, 6) as $d) {
        soldAgo($this, $short, 3, $d);
        soldAgo($this, $alsoShort, 1, $d);
    }

    $run = $this->actingAs($user)->postJson(PO.'/ai-runs')->assertCreated()->assertJsonPath('total', 3)->json(); // Maker C has no products
    $run = $this->actingAs($user)->postJson(PO."/ai-runs/{$run['id']}/process")->assertOk()->json();
    expect($run['status'])->toBe('done')->and($run['processed'])->toBe(3)->and($run['created'])->toBe(2);

    $drafts = $this->actingAs($user)->getJson(PO.'/drafts')->json();
    $byCompany = collect($drafts)->keyBy('company');
    expect($drafts)->toHaveCount(2)->and($byCompany->keys()->sort()->values()->all())->toBe(['Maker A', 'Maker D'])
        ->and($byCompany['Maker A']['source'])->toBe('ai')->and($byCompany['Maker A']['createdBy'])->toBe('Automatic draft');
    $drafts = [$byCompany['Maker A']];
    $detail = $this->actingAs($user)->getJson(PO.'/drafts/'.$drafts[0]['id'])->json();
    expect($detail['rows'][0]['orderQty'] % 100)->toBe(0)->and($detail['rows'][0]['forecast']['confidence'])->toBe('medium'); // sold on 7 of 28 days
});

it('keeps purchase planning to staff who manage reorders', function () {
    $this->actingAs(posUser('Cashier'))->getJson(PO.'/companies')->assertForbidden();
});
