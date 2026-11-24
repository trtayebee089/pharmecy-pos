<?php

use App\Models\AccountTransaction;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Testing\TestResponse;

const CUS = '/api/pos/v1/customers';

function regular(string $name = 'Rahim', string $phone = '01711111111'): Customer
{
    return Customer::create(['code' => 'C-'.$phone, 'name' => $name, 'phone' => $phone, 'phone_normalized' => $phone]);
}

/** A credit sale: $strips strips (৳20 each) for $customer, $paid in cash. Returns the sale. */
function creditSale($test, $user, Customer $customer, array $tablet, int $strips, float $paid): Sale
{
    $id = $test->actingAs($user)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $test->warehouse->id, 'customerId' => $customer->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $tablet['product']->id, 'unitId' => $tablet['strip']->id, 'qty' => $strips]],
        'payments' => $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [],
    ], ['Idempotency-Key' => uniqid('s', true)])->assertCreated()->json('id');

    return Sale::findOrFail($id);
}

function collectDue($test, $user, Customer $c, float $amount, array $alloc, string $method = 'cash', string $ref = ''): TestResponse
{
    return $test->actingAs($user)->postJson(CUS."/{$c->id}/payments", [
        'amount' => $amount, 'method' => $method, 'reference' => $ref,
        'allocations' => array_map(fn ($a) => ['saleId' => $a[0]->id, 'amount' => $a[1]], $alloc),
    ], ['Idempotency-Key' => uniqid('c', true)]);
}

function ledgerBalance(int $customerId): float
{
    return round((float) PartyLedgerEntry::where(['party_type' => 'customer', 'party_id' => $customerId])->sum('debit')
        - (float) PartyLedgerEntry::where(['party_type' => 'customer', 'party_id' => $customerId])->sum('credit'), 2);
}

// ---------------------------------------------------------------- due collection

it('lists customers who owe and their open invoices oldest first', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $first = creditSale($this, $user, $c, $t, 2, 10);   // due 30
    $second = creditSale($this, $user, $c, $t, 1, 0);   // due 20
    creditSale($this, $user, regular('Paid up', '01811111111'), $t, 1, 20);

    $this->actingAs($user)->getJson(CUS.'/due')->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.id', $c->id)->assertJsonPath('0.dueBalance', 50)->assertJsonPath('0.openInvoices', 2)->assertJsonPath('0.oldestDays', 0);
    $this->actingAs($user)->getJson(CUS."/{$c->id}/open-invoices")->assertJsonCount(2)
        ->assertJsonPath('0.saleId', $first->id)->assertJsonPath('0.due', 30)->assertJsonPath('1.saleId', $second->id);
});

it('collects a due payment, allocates it to invoices and posts ledger and account', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $a = creditSale($this, $user, $c, $t, 2, 10); // due 30
    $b = creditSale($this, $user, $c, $t, 1, 0);  // due 20

    $res = collectDue($this, $user, $c, 40, [[$a, 30], [$b, 10]])->assertCreated()->assertJsonPath('dueBalance', 10);
    expect($res->json('receiptNo'))->toStartWith('PAY-DHK1-');

    expect((float) $a->fresh()->due_total)->toBe(0.0)->and($a->fresh()->payment_status)->toBe('paid')
        ->and((float) $b->fresh()->due_total)->toBe(10.0)->and($b->fresh()->payment_status)->toBe('partial')
        ->and(ledgerBalance($c->id))->toBe(10.0);
    $pay = Payment::where('number', $res->json('receiptNo'))->first();
    expect($pay->direction)->toBe('in')
        ->and((float) AccountTransaction::where(['source_type' => 'payment', 'source_id' => $pay->id, 'direction' => 'in'])->sum('amount'))->toBe(40.0);
    $this->actingAs($user)->getJson(CUS.'/due')->assertJsonPath('0.lastPaymentAt', $pay->paid_at->toIso8601String());
});

it('refuses collections above the due, wrong invoices, over-allocation and unallocated money', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $other = regular('Karim', '01911111111');
    $a = creditSale($this, $user, $c, $t, 1, 0);       // due 20
    $foreign = creditSale($this, $user, $other, $t, 1, 0);

    collectDue($this, $user, $c, 25, [[$a, 20]])->assertJsonValidationErrors('amount');
    collectDue($this, $user, $c, 20, [[$foreign, 20]])->assertJsonValidationErrors('allocations.0.saleId');
    collectDue($this, $user, $c, 20, [[$a, 21]])->assertJsonValidationErrors('allocations.0.amount');
    collectDue($this, $user, $c, 10, [[$a, 15]])->assertJsonValidationErrors('allocations');
    collectDue($this, $user, $c, 20, [[$a, 5]])->assertJsonValidationErrors('allocations');
    collectDue($this, $user, $c, 20, [[$a, 20]], 'bkash')->assertJsonValidationErrors('reference');
    collectDue($this, $user, walkIn(), 1, [])->assertJsonValidationErrors('amount');

    expect(ledgerBalance($c->id))->toBe(20.0)->and(Payment::where('note', 'Due collection')->count())->toBe(0);
});

it('lets unallocated money cover a balance that is not on any invoice', function () {
    $user = posUser('Cashier');
    $c = regular();
    PartyLedgerEntry::create(['party_type' => 'customer', 'party_id' => $c->id, 'entry_date' => now()->toDateString(), 'type' => 'opening', 'debit' => 100, 'credit' => 0]);

    collectDue($this, $user, $c, 60, [])->assertCreated()->assertJsonPath('dueBalance', 40);
});

it('counts cash collections in the open register and blocks voiding a sale paid off later', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $register = CashRegister::firstOrCreate(['warehouse_id' => $this->warehouse->id, 'name' => 'Counter 1']);
    $session = $this->actingAs($user)->postJson('/api/pos/v1/cash-registers/sessions', ['register_id' => $register->id, 'opening_float' => 100])->assertCreated()->json('id');
    $sale = creditSale($this, $user, $c, $t, 1, 0);

    collectDue($this, $user, $c, 20, [[$sale, 20]])->assertCreated();
    expect(Payment::where('note', 'Due collection')->value('session_id'))->toBe($session);

    $this->actingAs(posUser('Manager'))->postJson("/api/pos/v1/sales/{$sale->id}/void", ['reason' => 'Wrong customer'])
        ->assertJsonValidationErrors('sale');
});

it('needs the collect permission', function () {
    $this->actingAs(posUser('Inventory'))->getJson(CUS.'/due')->assertForbidden();
});

it('reports that SMS reminders are not available instead of pretending', function () {
    $this->actingAs(posUser('Cashier'))->postJson(CUS.'/'.regular()->id.'/due-reminders', [], ['Idempotency-Key' => 'r'])
        ->assertStatus(503)->assertJsonPath('queued', false);
});

// ---------------------------------------------------------------- profile, edit, deactivate

it('shows a customer profile with purchases, visits and a running ledger', function () {
    $user = posUser('Cashier');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $a = creditSale($this, $user, $c, $t, 2, 10);
    creditSale($this, $user, $c, $t, 1, 20);

    $p = $this->actingAs($user)->getJson(CUS."/{$c->id}")->assertOk()
        ->assertJsonPath('visits', 2)->assertJsonPath('totalSpent', 60)->assertJsonPath('avgBasket', 30)
        ->assertJsonPath('dueBalance', 30)->assertJsonCount(2, 'purchases');
    expect(collect($p->json('ledger'))->pluck('balance')->all())->toBe([40, 30, 50, 30])
        ->and($p->json('ledger.0.ref'))->toBe($a->number);
});

it('edits customers with a unique phone and an existing group; the walk-in is protected', function () {
    $manager = posUser('Manager');
    $c = regular();
    regular('Karim', '01911111111');
    CustomerGroup::create(['name' => 'Senior citizen', 'discount_pct' => 5]);
    $put = fn (array $body, ?Customer $who = null) => $this->actingAs($manager)->putJson(CUS.'/'.($who ?? $c)->id, $body + ['name' => 'Rahim Uddin']);

    $put(['phone' => '+8801911111111'])->assertJsonValidationErrors('phone');
    $put(['phone' => '123'])->assertJsonValidationErrors('phone');
    $put(['phone' => '01712345678', 'group' => 'VIP'])->assertJsonValidationErrors('group');
    $put(['phone' => '+880 1712-345678', 'group' => 'Senior citizen'])->assertOk()
        ->assertJsonPath('phone', '01712345678')->assertJsonPath('group', 'Senior citizen')->assertJsonPath('name', 'Rahim Uddin');
    $put([], walkIn())->assertJsonValidationErrors('name');
    $this->actingAs(posUser('Inventory'))->putJson(CUS."/{$c->id}", ['name' => 'X'])->assertForbidden();
});

it('deactivates customers only when they owe nothing', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([['A', day(200), 500, 1.0]]);
    $c = regular();
    $sale = creditSale($this, $user, $c, $t, 1, 0);
    $deactivate = fn ($who) => $this->actingAs($user)->postJson(CUS."/{$who->id}/deactivate", [], ['Idempotency-Key' => uniqid()]);

    $deactivate($c)->assertJsonValidationErrors('customer');
    $deactivate(walkIn())->assertJsonValidationErrors('customer');
    collectDue($this, $user, $c, 20, [[$sale, 20]])->assertCreated();
    $deactivate($c)->assertOk();
    $this->actingAs($user)->getJson(CUS.'?search=Rahim')->assertJsonPath('meta.total', 0);
});

// ---------------------------------------------------------------- groups

it('manages customer groups; a group with members cannot be deleted', function () {
    $user = posUser('Manager');
    $id = $this->actingAs($user)->postJson('/api/pos/v1/customer-groups', ['name' => 'Senior citizen', 'discountPct' => 5, 'notes' => 'NID over 60'])
        ->assertCreated()->assertJsonPath('discountPct', 5)->assertJsonPath('members', 0)->json('id');
    $this->actingAs($user)->postJson('/api/pos/v1/customer-groups', ['name' => 'Senior citizen', 'discountPct' => 1])->assertJsonValidationErrors('name');
    $this->actingAs($user)->postJson('/api/pos/v1/customer-groups', ['name' => 'Bad', 'discountPct' => 120])->assertJsonValidationErrors('discountPct');
    $this->actingAs($user)->putJson("/api/pos/v1/customer-groups/$id", ['name' => 'Senior citizen', 'discountPct' => 7.5, 'notes' => ''])
        ->assertOk()->assertJsonPath('discountPct', 7.5)->assertJsonPath('notes', '');

    regular()->update(['customer_group_id' => $id]);
    $this->actingAs($user)->getJson('/api/pos/v1/customer-groups?all=1')->assertJsonPath('0.members', 1);
    $this->actingAs($user)->deleteJson("/api/pos/v1/customer-groups/$id")->assertJsonValidationErrors('id');
    $this->actingAs(posUser('Cashier'))->postJson('/api/pos/v1/customer-groups', ['name' => 'X', 'discountPct' => 1])->assertForbidden();
});
