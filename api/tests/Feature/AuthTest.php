<?php

use App\Models\User;

// Login goes through Sanctum's stateful (session) pipeline only for the SPA origin.
beforeEach(fn () => $this->withHeaders(['Origin' => 'http://localhost:3100', 'Referer' => 'http://localhost:3100/']));

it('logs in with username and returns the me payload', function () {
    $user = posUser('Cashier');

    $this->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('username', $user->username)
        ->assertJsonPath('role', 'Cashier')
        ->assertJsonPath('warehouses.0.id', $this->warehouse->id)
        ->assertJsonFragment(['pos.sell']);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password without revealing which field was wrong', function () {
    $user = posUser();

    $this->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username' => 'These credentials do not match our records.']);
    $this->assertGuest();
});

it('rejects inactive users', function () {
    $user = posUser();
    $user->update(['is_active' => false]);

    $this->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'password'])->assertUnprocessable();
});

it('locks out after five failed attempts', function () {
    $user = posUser();
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'bad']);
    }

    $this->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'password'])->assertStatus(429);
});

it('returns 401 for guests', function () {
    $this->getJson('/api/pos/v1/me')->assertUnauthorized();
    $this->postJson('/api/pos/v1/sales', [])->assertUnauthorized();
});

it('gives Admin the wildcard permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)->getJson('/api/pos/v1/me')->assertOk()->assertJsonPath('permissions', ['*']);
});

it('denies routes the role has no permission for', function () {
    $cashier = posUser('Cashier');

    $this->actingAs($cashier)->postJson('/api/pos/v1/purchases', [], ['Idempotency-Key' => 'k1'])->assertForbidden();
});

it('changes the password only with the current one', function () {
    $user = posUser();

    $this->actingAs($user)->putJson('/api/pos/v1/me/password', ['currentPassword' => 'wrong', 'password' => 'n3w-Passw0rd', 'password_confirmation' => 'n3w-Passw0rd'])
        ->assertJsonValidationErrors('currentPassword');
    $this->actingAs($user)->putJson('/api/pos/v1/me/password', ['currentPassword' => 'password', 'password' => 'n3w-Passw0rd', 'password_confirmation' => 'n3w-Passw0rd'])
        ->assertOk();
});

it('refuses login from a non-SPA origin (no session)', function () {
    $user = posUser();

    $this->withHeaders(['Origin' => 'https://evil.example', 'Referer' => 'https://evil.example/'])
        ->postJson('/api/pos/v1/auth/login', ['username' => $user->username, 'password' => 'password'])
        ->assertStatus(400);
    $this->assertGuest();
});
