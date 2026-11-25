<?php

use App\Mail\TemporaryPassword;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\AppSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Mail::fake();
    $this->admin = posUser('Admin');
});

function generalSettings(array $over = []): void
{
    app(AppSettings::class)->saveGeneral($over + AppSettings::GENERAL);
}

function newUser($test, array $over = [], string $key = 'u1')
{
    return $test->actingAs($test->admin)->postJson('/api/pos/v1/admin/users', $over + [
        'name' => 'New Cashier', 'username' => 'newcashier', 'email' => 'newcashier@example.test', 'role' => 'Cashier',
        'warehouses' => [$test->warehouse->name], 'password' => 'Temp-pass-123',
    ], ['Idempotency-Key' => $key]);
}

// ---------------------------------------------------------------- users

it('creates a user with a temporary password, emails it and forces a change', function () {
    $res = newUser($this)->assertCreated()->assertJsonPath('mustChangePassword', true)->assertJsonPath('role', 'Cashier');
    Mail::assertSent(TemporaryPassword::class, fn ($m) => $m->hasTo('newcashier@example.test') && $m->password === 'Temp-pass-123');

    $user = User::find($res->json('id'));
    // Until the password is changed, only the account endpoints work.
    $this->actingAs($user)->getJson('/api/pos/v1/sales')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->actingAs($user)->getJson('/api/pos/v1/me')->assertOk()->assertJsonPath('mustChangePassword', true);
    $this->actingAs($user)->putJson('/api/pos/v1/me/password', ['current_password' => 'Temp-pass-123', 'password' => 'Own-pass-456', 'password_confirmation' => 'Own-pass-456'])->assertOk();
    $this->actingAs($user->fresh())->getJson('/api/pos/v1/sales')->assertOk();
});

it('lets only admins manage users and blocks self-promotion', function () {
    $manager = posUser('Manager');
    $this->actingAs($manager)->postJson('/api/pos/v1/admin/users', ['name' => 'X', 'username' => 'xx1', 'role' => 'Admin', 'warehouses' => [1], 'password' => 'Temp-pass-123'], ['Idempotency-Key' => 'm'])->assertForbidden();
    $this->actingAs($manager)->putJson("/api/pos/v1/admin/users/{$manager->id}", ['name' => 'Me', 'username' => $manager->username, 'role' => 'Admin', 'warehouses' => [1]])->assertForbidden();
    $this->actingAs($manager)->putJson('/api/pos/v1/me/profile', ['name' => 'Renamed', 'role' => 'Admin'])->assertOk()->assertJsonPath('role', 'Manager');
    expect($manager->fresh()->isAdmin())->toBeFalse();
});

it('never leaves the system without an active admin and stops admins locking themselves out', function () {
    $this->actingAs($this->admin)->postJson("/api/pos/v1/admin/users/{$this->admin->id}/disable")->assertJsonValidationErrors('user');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/users/{$this->admin->id}", ['name' => 'A', 'username' => $this->admin->username, 'role' => 'Manager', 'warehouses' => [$this->warehouse->id]])
        ->assertJsonValidationErrors('role');

    $other = posUser('Admin');
    $this->actingAs($other)->postJson("/api/pos/v1/admin/users/{$this->admin->id}/disable")->assertOk()->assertJsonPath('isActive', false);
    $this->actingAs($other)->putJson("/api/pos/v1/admin/users/{$other->id}", ['name' => 'B', 'username' => $other->username, 'role' => 'Manager', 'warehouses' => [$this->warehouse->id]])
        ->assertJsonValidationErrors('role'); // own role
});

it('resets a password to a new temporary one and ends the user sessions', function () {
    $cashier = posUser();
    $this->actingAs($this->admin)->postJson("/api/pos/v1/admin/users/{$cashier->id}/password-reset", ['password' => 'short'])->assertJsonValidationErrors('password');
    $this->actingAs($this->admin)->postJson("/api/pos/v1/admin/users/{$cashier->id}/password-reset", ['password' => 'Reset-pass-789'])->assertOk()->assertJsonPath('mustChangePassword', true);
    Mail::assertSent(TemporaryPassword::class, fn ($m) => ! $m->isNew && $m->password === 'Reset-pass-789');
});

// ---------------------------------------------------------------- roles

it('edits role permissions and discount limit; the Admin role is fixed', function () {
    $cashier = Role::findByName('Cashier', 'web');
    $roles = $this->actingAs($this->admin)->getJson('/api/pos/v1/admin/roles')->assertOk();
    expect(collect($roles->json('roles'))->firstWhere('name', 'Admin')['system'])->toBeTrue()
        ->and(collect($roles->json('groups'))->pluck('module'))->toContain('Online orders');

    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/roles/{$cashier->id}", ['permissions' => ['pos.sell', 'sales.view'], 'maxDiscountPct' => 8])->assertOk();
    expect($cashier->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['pos.sell', 'sales.view'])
        ->and((float) DB::table('role_limits')->where('role_id', $cashier->id)->value('max_line_discount_pct'))->toBe(8.0);

    // The seeder no longer undoes the admin's change.
    $this->seed(DatabaseSeeder::class);
    expect($cashier->fresh()->permissions)->toHaveCount(2);

    $admin = Role::findByName('Admin', 'web');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/roles/{$admin->id}", ['permissions' => [], 'maxDiscountPct' => 0])->assertJsonValidationErrors('role');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/roles/{$cashier->id}", ['permissions' => ['made.up'], 'maxDiscountPct' => 0])->assertJsonValidationErrors('permissions.0');
});

it('creates, renames and deletes roles not in use', function () {
    $id = $this->actingAs($this->admin)->postJson('/api/pos/v1/admin/roles', ['name' => 'Pharmacist', 'description' => 'Counter pharmacist', 'permissions' => ['pos.sell'], 'maxDiscountPct' => 3], ['Idempotency-Key' => 'r'])
        ->assertCreated()->json('id');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/roles/$id/details", ['name' => 'Senior pharmacist', 'description' => 'Can discount'])->assertOk();
    newUser($this, ['role' => 'Senior pharmacist']);
    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/admin/roles/$id")->assertJsonValidationErrors('role');
    $this->actingAs($this->admin)->deleteJson('/api/pos/v1/admin/roles/'.Role::findByName('Inventory', 'web')->id)->assertOk();
});

// ---------------------------------------------------------------- warehouses

it('adds a store with a first counter, protects codes and keeps history', function () {
    $id = $this->actingAs($this->admin)->postJson('/api/pos/v1/admin/warehouses', ['code' => 'MRP1', 'name' => 'Red Pharma · Mirpur', 'type' => 'store'])->assertCreated()->json('id');
    expect(CashRegister::where('warehouse_id', $id)->value('name'))->toBe('Counter 1');
    $this->actingAs($this->admin)->postJson('/api/pos/v1/admin/warehouses', ['code' => 'mrp 2', 'name' => 'X', 'type' => 'store'])->assertJsonValidationErrors('code');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/admin/warehouses/$id", ['phone' => '01700000000'])->assertOk()->assertJsonPath('code', 'MRP1');
    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/admin/warehouses/$id")->assertOk()->assertJsonPath('message', 'Branch deleted.');

    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/admin/warehouses/{$this->warehouse->id}")->assertStatus(422); // last active branch
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/admin/warehouses?all=1')->assertOk();
});

// ---------------------------------------------------------------- settings: rounding and stock switches

it('rounds the bill to the setting and records the difference', function () {
    $t = tabletWithStock([['A1', day(200), 100, 1.0]], 2.37); // ৳2.37 a piece
    generalSettings(['roundTo' => 1]);
    $res = $this->actingAs($this->admin)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 3]], 'payments' => [['method' => 'cash', 'amount' => 7]],
    ], ['Idempotency-Key' => 's'])->assertCreated();

    expect($res->json('grandTotal'))->toEqual(7)->and((float) Sale::first()->rounding)->toBe(-0.11); // 7.11 → 7
});

it('keeps expired stock unsellable unless the switch allows it, and then sells it last', function () {
    $t = tabletWithStock([['OLD', day(-5), 10, 1.0], ['NEW', day(100), 3, 1.0]]);
    $sell = fn ($qty, $key) => $this->actingAs($this->admin)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => $qty]], 'payments' => [['method' => 'cash', 'amount' => $qty * 2]],
    ], ['Idempotency-Key' => $key]);

    $sell(5, 'a')->assertUnprocessable(); // only 3 unexpired
    generalSettings(['blockExpiredSale' => false]);
    $sell(5, 'b')->assertCreated();
    expect(stockOf($t['batches'][1]))->toBe(0)->and(stockOf($t['batches'][0]))->toBe(8); // unexpired used first
});

it('sells into negative stock only when the switch allows it', function () {
    $t = tabletWithStock([['A1', day(200), 4, 1.0]]);
    $sell = fn ($key) => $this->actingAs($this->admin)->postJson('/api/pos/v1/sales', [
        'warehouseId' => $this->warehouse->id, 'customerId' => walkIn()->id, 'orderDiscount' => 0,
        'lines' => [['productId' => $t['product']->id, 'unitId' => $t['piece']->id, 'qty' => 6]], 'payments' => [['method' => 'cash', 'amount' => 12]],
    ], ['Idempotency-Key' => $key]);

    $sell('a')->assertUnprocessable();
    generalSettings(['allowNegativeStock' => true]);
    $sell('b')->assertCreated();
    expect(StockBalance::where('batch_id', $t['batches'][0]->id)->value('qty_base'))->toBe(-2);
});

it('saves general and POS settings; payment methods drive the counter and the PrintNode key is never returned', function () {
    $this->actingAs($this->admin)->putJson('/api/pos/v1/admin/settings/pos', array_merge(AppSettings::GENERAL, ['nearExpiryDays' => 60, 'criticalExpiryDays' => 70]))->assertJsonValidationErrors('criticalExpiryDays');
    $this->actingAs($this->admin)->putJson('/api/pos/v1/admin/settings/pos', array_merge(AppSettings::GENERAL, ['roundTo' => 0.5]))->assertOk()->assertJsonPath('roundTo', 0.5);

    $pos = $this->actingAs($this->admin)->getJson('/api/pos/v1/settings/pos')->assertOk()->json();
    $pos['layout'] = 'classic';
    $pos['paymentMethods'] = collect($pos['paymentMethods'])->map(fn ($m) => $m['code'] === 'nagad' ? ['enabled' => false] + $m : $m)->all();
    $saved = $this->actingAs($this->admin)->putJson('/api/pos/v1/settings/pos', $pos + ['print_node_api_key' => 'secret-key-123'])->assertOk();
    $saved->assertJsonPath('layout', 'classic')->assertJsonPath('printNodeConfigured', true)->assertJsonMissingPath('printNodeKey');
    expect(json_encode($saved->json()))->not->toContain('secret-key-123')
        ->and(PaymentMethod::where('code', 'nagad')->value('is_active'))->toBeFalse();

    $pos['paymentMethods'] = collect($pos['paymentMethods'])->map(fn ($m) => $m['code'] === 'cash' ? ['enabled' => false] + $m : $m)->all();
    $this->actingAs($this->admin)->putJson('/api/pos/v1/settings/pos', $pos)->assertJsonValidationErrors('paymentMethods.0.enabled');
    $this->actingAs(posUser('Manager'))->putJson('/api/pos/v1/settings/pos', $pos)->assertForbidden();
});

it('shows and updates my own profile only', function () {
    $me = posUser();
    $this->actingAs($me)->getJson('/api/pos/v1/me/profile')->assertOk()->assertJsonPath('username', $me->username)->assertJsonPath('role', 'Cashier');
    $this->actingAs($me)->putJson('/api/pos/v1/me/profile', ['name' => 'Cashier One', 'email' => 'c1@example.test', 'phone' => '01700000001'])
        ->assertOk()->assertJsonPath('name', 'Cashier One')->assertJsonPath('phone', '01700000001');
    $this->actingAs($me)->putJson('/api/pos/v1/me/password', ['current_password' => 'wrong', 'password' => 'Another-456', 'password_confirmation' => 'Another-456'])
        ->assertJsonValidationErrors('current');
});
