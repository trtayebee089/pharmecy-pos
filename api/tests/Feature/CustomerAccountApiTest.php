<?php

use App\Models\Customer;
use App\Models\CustomerPasswordReset;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

// ---------------------------------------------------------------- accounts

it('registers a customer with a bearer token and signs in by phone or email', function () {
    $res = signUp($this)->assertCreated()->assertJsonPath('success', true)
        ->assertJsonPath('data.user.phone_number', '01812345678')->assertJsonPath('user.name', 'Nusrat Jahan');
    expect($res->json('data.token'))->toBe($res->json('token'))->and(Customer::where('phone_normalized', '01812345678')->value('password'))->not->toBe('secret123');

    $this->getJson(V1.'/customer/profile', bearer($res->json('token')))->assertOk()->assertJsonPath('data.email', 'nusrat@example.test');

    $this->postJson(V1.'/customer/login', ['identifier' => '+8801812345678', 'password' => 'secret123'])->assertOk()->assertJsonPath('data.user.id', $res->json('data.user.id'));
    $this->postJson(V1.'/customer/login', ['identifier' => 'NUSRAT@example.test', 'password' => 'secret123'])->assertOk();
    $this->postJson(V1.'/customer/login', ['phone_number' => '01812345678', 'password' => 'secret123'])->assertOk(); // app field name
    $this->postJson(V1.'/customer/login', ['identifier' => '01812345678', 'password' => 'wrong'])->assertJsonValidationErrors('identifier');
});

it('refuses to hand an existing phone to whoever registers it, and weak passwords', function () {
    Customer::create(['code' => 'C-0500', 'name' => 'Counter customer', 'phone_normalized' => '01812345678', 'phone' => '01812345678']);

    signUp($this)->assertJsonValidationErrors('phone_number');
    signUp($this, ['phone_number' => '01912345678', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertJsonValidationErrors('password');
    signUp($this, ['phone_number' => '123'])->assertJsonValidationErrors('phone_number');
    signUp($this, ['phone_number' => '01912345678'])->assertCreated();
    signUp($this, ['phone_number' => '01712345678'])->assertJsonValidationErrors('email'); // same email
});

it('throttles repeated failed sign-ins for one account', function () {
    signUp($this)->assertCreated();
    foreach (range(1, 5) as $i) {
        $this->postJson(V1.'/customer/login', ['identifier' => '01812345678', 'password' => 'nope'.$i])->assertStatus(422);
    }
    $this->postJson(V1.'/customer/login', ['identifier' => '01812345678', 'password' => 'secret123'])->assertStatus(429);
});

it('resets a password with an emailed code, counting wrong tries that cannot be undone', function () {
    Mail::fake();
    signUp($this)->assertCreated();
    $c = Customer::where('phone_normalized', '01812345678')->first();

    // Unknown accounts get the same answer and no mail.
    $this->postJson(V1.'/customer/forgot-password', ['mobile' => '01999999999'])->assertOk();
    expect(CustomerPasswordReset::count())->toBe(0);

    $this->postJson(V1.'/customer/forgot-password', ['mobile' => '01812345678'])->assertOk();
    $reset = CustomerPasswordReset::where('customer_id', $c->id)->firstOrFail();
    $reset->update(['code_hash' => Hash::make('123456')]); // the emailed code is random: pin it for the test
    $this->postJson(V1.'/customer/forgot-password', ['mobile' => '01812345678'])->assertOk(); // cooldown: no second code
    expect(CustomerPasswordReset::count())->toBe(1);

    $reset2 = fn ($otp) => $this->postJson(V1.'/customer/reset-password', ['mobile' => '01812345678', 'otp' => $otp, 'password' => 'newpass99', 'password_confirmation' => 'newpass99']);
    $reset2('000000')->assertJsonValidationErrors('otp');
    expect($reset->fresh()->attempts)->toBe(1);
    $reset2('123456')->assertOk();
    $this->postJson(V1.'/customer/login', ['identifier' => '01812345678', 'password' => 'newpass99'])->assertOk();
    $reset2('123456')->assertJsonValidationErrors('otp'); // used once
});

it('locks a reset code after five wrong tries', function () {
    signUp($this)->assertCreated();
    $c = Customer::where('phone_normalized', '01812345678')->first();
    CustomerPasswordReset::create(['customer_id' => $c->id, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(15)]);
    $try = fn ($otp) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.random_int(1, 250)])
        ->postJson(V1.'/customer/reset-password', ['mobile' => '01812345678', 'otp' => $otp, 'password' => 'newpass99', 'password_confirmation' => 'newpass99']);

    foreach (range(1, 5) as $i) {
        $try('00000'.$i)->assertJsonValidationErrors('otp');
    }
    $try('123456')->assertJsonValidationErrors('otp');
});

it('edits the profile (not the phone), changes the password with the current one, and signs out', function () {
    $token = signUp($this)->json('token');

    $this->putJson(V1.'/customer/profile', ['name' => 'Nusrat J.', 'address' => 'Dhanmondi 27', 'phone_number' => '01700000000'], bearer($token))
        ->assertOk()->assertJsonPath('data.name', 'Nusrat J.')->assertJsonPath('data.phone', '01812345678');
    $this->putJson(V1.'/customer/password', ['current_password' => 'nope', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'], bearer($token))
        ->assertJsonValidationErrors('current_password');
    $this->putJson(V1.'/customer/password', ['current_password' => 'secret123', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'], bearer($token))->assertOk();
    $this->postJson(V1.'/customer/account-removal-request', ['reason' => 'Moving abroad'], bearer($token))->assertCreated()->assertJsonPath('data.status', 'pending');

    $this->postJson(V1.'/customer/logout', [], bearer($token))->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson(V1.'/customer/profile', bearer($token))->assertUnauthorized();
});

it('keeps customer tokens out of the POS and staff sessions out of customer routes', function () {
    $token = signUp($this)->json('token');
    $this->getJson('/api/pos/v1/me', bearer($token))->assertForbidden();
    $this->getJson('/api/pos/v1/sales', bearer($token))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->actingAs(posUser('Manager'))->getJson(V1.'/customer/profile')->assertUnauthorized();
});

// ---------------------------------------------------------------- catalogue

it('lists the catalogue with piece prices and the online branch stock', function () {
    $t = tabletWithStock([['A', day(200), 35, 1.0], ['OLD', day(-1), 50, 1.0]]); // only 35 sellable
    $t['product']->update(['slug' => 'testamol-500']);
    $none = tabletWithStock([]);
    $none['product']->update(['name' => 'Zeromol']);
    ProductPrice::whereIn('product_unit_id', [$none['piece']->id])->delete(); // no piece price: not sold online

    $this->getJson(V1.'/products?per_page=10')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'testamol-500')->assertJsonPath('data.0.price', 2.0)->assertJsonPath('data.0.stock', 35)
        ->assertJsonPath('data.0.piece_qty', 10)->assertJsonPath('data.0.stripe_qty', 10);
    $this->getJson(V1.'/products/testamol-500')->assertOk()->assertJsonPath('data.name', 'Testamol')->assertJsonPath('data.saleCount', 0);
    $this->getJson(V1.'/products/'.$t['product']->id)->assertOk(); // id works where a slug is missing
    $this->getJson(V1.'/products?search=testa')->assertJsonPath('meta.total', 1);
    $this->getJson(V1.'/products?search=zzz')->assertJsonPath('meta.total', 0);
    $this->getJson(V1.'/products/suggestions?query=tes')->assertJsonPath('data.0.slug', 'testamol-500');
    $this->getJson(V1.'/products/nope')->assertNotFound();
});
