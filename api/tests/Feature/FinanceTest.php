<?php

use App\Models\Account;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const FIN = '/api/pos/v1/finance';

function newAccount($test, $user, string $code, float $opening = 0, string $type = 'bank'): int
{
    return $test->actingAs($user)->postJson(FIN.'/accounts', ['code' => $code, 'name' => "Account $code", 'type' => $type, 'openingBalance' => $opening])
        ->assertCreated()->json('id');
}

function balanceOf($test, $user, int $id): float
{
    return collect($test->actingAs($user)->getJson(FIN.'/accounts')->json())->firstWhere('id', $id)['balance'];
}

function moveMoney($test, $user, int $from, int $to, float $amount, array $extra = []): TestResponse
{
    return $test->actingAs($user)->postJson(FIN.'/money-transfers', $extra + ['fromAccountId' => $from, 'toAccountId' => $to, 'amount' => $amount, 'date' => day(0), 'note' => 'Deposit'],
        ['Idempotency-Key' => uniqid('t', true)]);
}

function category(string $kind, string $name = 'Rent'): FinanceCategory
{
    return FinanceCategory::firstOrCreate(['kind' => $kind, 'name' => $name]);
}

function postEntry($test, $user, string $kinds, int $accountId, float $amount, ?FinanceCategory $cat = null, array $extra = []): TestResponse
{
    $cat ??= category($kinds === 'expenses' ? 'expense' : 'income', $kinds === 'expenses' ? 'Rent' : 'Rebates');

    return $test->actingAs($user)->postJson(FIN."/$kinds", $extra + ['date' => day(0), 'categoryId' => $cat->id, 'accountId' => $accountId, 'amount' => $amount, 'note' => 'Monthly'],
        ['Idempotency-Key' => uniqid('e', true)]);
}

// ---------------------------------------------------------------- accounts

it('derives balances from transactions, locks a used opening balance and keeps codes unique', function () {
    $user = posUser('Manager');
    $a = newAccount($this, $user, 'CITY', 1000);
    $b = newAccount($this, $user, 'EBL');
    expect(balanceOf($this, $user, $a))->toBe(1000.0);

    moveMoney($this, $user, $a, $b, 250)->assertCreated();
    expect(balanceOf($this, $user, $a))->toBe(750.0)->and(balanceOf($this, $user, $b))->toBe(250.0);

    $this->actingAs($user)->putJson(FIN."/accounts/$a", ['code' => 'CITY', 'name' => 'City Bank', 'type' => 'bank', 'openingBalance' => 5000])->assertJsonValidationErrors('openingBalance');
    $this->actingAs($user)->putJson(FIN."/accounts/$a", ['code' => 'CITY', 'name' => 'City Bank', 'type' => 'bank', 'accountNo' => '123'])->assertOk()->assertJsonPath('name', 'City Bank');
    $this->actingAs($user)->postJson(FIN.'/accounts', ['code' => 'city', 'name' => 'Dup', 'type' => 'bank'])->assertJsonValidationErrors('code');
});

it('deactivates only empty, non-default accounts that no payment method uses', function () {
    $user = posUser('Manager');
    $cash = Account::where('code', 'CASH')->value('id'); // default, used by the cash method
    $full = newAccount($this, $user, 'FULL', 10);
    $empty = newAccount($this, $user, 'EMPTY');
    $deactivate = fn ($id) => $this->actingAs($user)->postJson(FIN."/accounts/$id/deactivate");

    $deactivate($cash)->assertJsonValidationErrors('account');
    $deactivate($full)->assertJsonValidationErrors('balance');
    $deactivate(Account::where('code', 'BKASH')->value('id'))->assertJsonValidationErrors('account'); // bKash method posts there
    $deactivate($empty)->assertOk();

    $this->actingAs($user)->postJson(FIN."/accounts/$empty/default")->assertJsonValidationErrors('account');
    $this->actingAs($user)->postJson(FIN."/accounts/$full/default")->assertOk();
    expect(Account::where('is_default', true)->pluck('id')->all())->toBe([$full]);
});

// ---------------------------------------------------------------- transfers

it('refuses transfers that overdraw, reuse the account or are dated ahead, and voids by reversing', function () {
    $user = posUser('Manager');
    $a = newAccount($this, $user, 'A', 100);
    $b = newAccount($this, $user, 'B');

    moveMoney($this, $user, $a, $b, 100.01)->assertJsonValidationErrors('amount');
    moveMoney($this, $user, $a, $a, 10)->assertJsonValidationErrors('toAccountId');
    moveMoney($this, $user, $a, $b, 10, ['date' => day(1)])->assertJsonValidationErrors('date');
    $t = moveMoney($this, $user, $a, $b, 60)->assertCreated()->assertJsonPath('status', 'posted');
    expect($t->json('number'))->toStartWith('MT-DHK1-');

    // B spends 30, so the 60 cannot be pulled back any more.
    moveMoney($this, $user, $b, $a, 30)->assertCreated();
    $void = fn ($id) => $this->actingAs($user)->postJson(FIN."/money-transfers/$id/void", ['reason' => 'Wrong account'], ['Idempotency-Key' => uniqid()]);
    $void($t->json('id'))->assertJsonValidationErrors('transfer');

    $small = moveMoney($this, $user, $a, $b, 20)->json('id');
    $void($small)->assertOk()->assertJsonPath('status', 'void')->assertJsonPath('voidReason', 'Wrong account');
    $void($small)->assertJsonValidationErrors('transfer');
    expect(balanceOf($this, $user, $a))->toBe(70.0)->and(balanceOf($this, $user, $b))->toBe(30.0);
});

// ---------------------------------------------------------------- expenses & income

it('posts expenses out of and income into an account, and voids them with a reversal', function () {
    $user = posUser('Manager');
    $acc = newAccount($this, $user, 'OPS', 500);

    postEntry($this, $user, 'expenses', $acc, 501)->assertJsonValidationErrors('amount');
    $exp = postEntry($this, $user, 'expenses', $acc, 200)->assertCreated()->assertJsonPath('category', 'Rent')->assertJsonPath('status', 'posted');
    expect($exp->json('number'))->toStartWith('EXP-DHK1-');
    $inc = postEntry($this, $user, 'incomes', $acc, 50)->assertCreated();
    expect($inc->json('number'))->toStartWith('INC-DHK1-')->and(balanceOf($this, $user, $acc))->toBe(350.0);

    $this->actingAs($user)->postJson(FIN.'/expenses/'.$exp->json('id').'/void', ['reason' => 'Paid twice'])->assertOk()->assertJsonPath('status', 'void');
    $this->actingAs($user)->postJson(FIN.'/expenses/'.$exp->json('id').'/void', ['reason' => 'Again'])->assertJsonValidationErrors('entry');
    $this->actingAs($user)->postJson(FIN.'/incomes/'.$exp->json('id').'/void', ['reason' => 'Wrong kind'])->assertNotFound();
    expect(balanceOf($this, $user, $acc))->toBe(550.0);

    // Income whose money was already spent cannot be voided.
    postEntry($this, $user, 'expenses', $acc, 530)->assertCreated();
    $this->actingAs($user)->postJson(FIN.'/incomes/'.$inc->json('id').'/void', ['reason' => 'Mistake'])->assertJsonValidationErrors('entry');
    $this->actingAs($user)->getJson(FIN.'/expenses')->assertJsonCount(2)->assertJsonPath('1.status', 'void');
});

it('needs an active category and account and refuses future dates', function () {
    $user = posUser('Manager');
    $acc = newAccount($this, $user, 'OPS', 500);
    $old = category('expense', 'Old');
    $old->update(['is_active' => false]);

    postEntry($this, $user, 'expenses', $acc, 10, $old)->assertJsonValidationErrors('categoryId');
    postEntry($this, $user, 'expenses', $acc, 10, category('income', 'Rebates'))->assertJsonValidationErrors('categoryId');
    postEntry($this, $user, 'expenses', $acc, 10, null, ['date' => day(1)])->assertJsonValidationErrors('date');
    Account::whereKey($acc)->update(['is_active' => false]);
    postEntry($this, $user, 'expenses', $acc, 10)->assertJsonValidationErrors('accountId');
});

it('keeps category names unique per kind, nests one level and deletes only unused ones', function () {
    $user = posUser('Manager');
    $post = fn ($path, $body) => $this->actingAs($user)->postJson(FIN.$path, $body);

    $post('/expense-categories', ['name' => 'Utilities'])->assertCreated();
    $post('/expense-categories', ['name' => 'Utilities'])->assertJsonValidationErrors('name');
    $post('/income-categories', ['name' => 'Utilities'])->assertCreated(); // other kind: allowed
    $id = $post('/expense-categories', ['name' => 'Electricity', 'parent' => 'Utilities'])->assertJsonPath('parent', 'Utilities')->json('id');
    $post('/expense-categories', ['name' => 'Meter', 'parent' => 'Electricity'])->assertJsonValidationErrors('parent');

    $acc = newAccount($this, $user, 'OPS', 100);
    postEntry($this, $user, 'expenses', $acc, 10, FinanceCategory::find($id))->assertCreated();
    $this->actingAs($user)->deleteJson(FIN."/expense-categories/$id")->assertJsonValidationErrors('id');
    $this->actingAs($user)->putJson(FIN."/expense-categories/$id", ['isActive' => false])->assertOk()->assertJsonPath('isActive', false)->assertJsonPath('entries', 1);
    $this->actingAs($user)->getJson(FIN.'/expense-categories?all=1')->assertJsonCount(2);
});

it('stores attachments privately and serves them through a short signed link', function () {
    Storage::fake('local');
    $user = posUser('Manager');
    $acc = newAccount($this, $user, 'OPS', 100);
    $id = postEntry($this, $user, 'expenses', $acc, 10)->json('id');

    $this->actingAs($user)->post(FIN."/expenses/$id/attachment", ['file' => UploadedFile::fake()->create('bill.exe', 10)], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    $this->actingAs($user)->post(FIN."/expenses/$id/attachment", ['file' => UploadedFile::fake()->image('bill.png')], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('hasAttachment', true);
    Storage::disk('local')->assertExists(FinanceEntry::find($id)->attachment_path);

    $url = $this->actingAs($user)->getJson(FIN."/expenses/$id/attachment")->assertOk()->json('url');
    $this->get($url)->assertOk();
    $this->get(FIN."/attachments/$id")->assertForbidden(); // no signature
});

// ---------------------------------------------------------------- statement & balance sheet

it('prints a statement with an opening line and a running balance', function () {
    $user = posUser('Manager');
    $a = newAccount($this, $user, 'A', 100);
    $b = newAccount($this, $user, 'B');
    moveMoney($this, $user, $a, $b, 40)->assertCreated();
    postEntry($this, $user, 'expenses', $a, 10)->assertCreated();

    $lines = $this->actingAs($user)->getJson(FIN."/accounts/$a/statement?from=".day(0).'&to='.day(0))->assertOk()->json();
    expect(collect($lines)->map(fn ($l) => [$l['description'], $l['inAmount'], $l['outAmount'], $l['balance']])->all())
        ->toBe([['Opening balance', 0, 0, 100], ['Transfer out', 0, 40, 60], ['Expense', 0, 10, 50]]);
});

it('builds a balance sheet that balances, with month-to-date profit and loss', function () {
    $user = posUser('Manager');
    $t = tabletWithStock([['A', day(200), 100, 1.0]]); // ৳100 of stock
    $acc = newAccount($this, $user, 'OPS', 1000);
    $this->actingAs(posUser('Cashier'))->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['strip']->id, 'qty' => 2]], // 20 pcs, ৳40, cost ৳20
        'payments' => [['method' => 'cash', 'amount' => 40]],
    ], ['Idempotency-Key' => 's1'])->assertCreated();
    postEntry($this, $user, 'expenses', $acc, 100)->assertCreated();
    postEntry($this, $user, 'incomes', $acc, 25)->assertCreated();

    $bs = $this->actingAs($user)->getJson(FIN.'/balance-sheet?as_of='.day(0))->assertOk()->json();
    $sum = fn ($k) => round(collect($bs[$k])->sum('amount'), 2);
    $assets = collect($bs['assets'])->keyBy('label');
    // JSON drops a zero fraction (80.0 -> 80): compare amounts by value.
    expect($assets['Inventory at batch cost']['amount'])->toEqual(80)
        ->and($assets['Cash in hand']['amount'])->toEqual(40)
        ->and($assets['Bank accounts']['amount'])->toEqual(925)
        ->and($sum('assets'))->toEqual(round($sum('liabilities') + $sum('equity'), 2))
        ->and($bs['pnl'])->toMatchArray(['sales' => 40, 'returns' => 0, 'cogs' => 20, 'expenses' => 100, 'income' => 25]);
    $this->actingAs(posUser('Cashier'))->getJson(FIN.'/balance-sheet?as_of='.day(0))->assertForbidden();
});
