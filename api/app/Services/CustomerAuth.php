<?php

namespace App\Services;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Website/app sign-in (owner decision 26 Nov 2026: password; reset code by email).
 *
 * - Register: phone required (BD mobile), email optional. A phone that already has
 *   a customer record (counter sale, guest order, earlier sign-up) cannot be
 *   claimed by registering: that would hand someone else's points and orders to
 *   whoever types the number. Such customers use "forgot password" (needs an email
 *   on file) or ask the pharmacy.
 * - Login: phone or email + password. Tokens are Sanctum personal access tokens
 *   with the `customer` ability, valid for 30 days.
 * - Reset code: 6 digits by email, hashed, 15 minutes, 60 s between sends, 5 tries.
 *   Tries are counted outside the reset transaction so they cannot be rolled back.
 *   Responses never reveal whether a phone or email is registered.
 */
class CustomerAuth
{
    public const TOKEN_DAYS = 30;

    public const CODE_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function register(array $data): Customer
    {
        $phone = $this->phone($data['phone'], 'phone_number');
        $email = ($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        if (Customer::where('phone_normalized', $phone)->exists()) {
            throw ValidationException::withMessages(['phone_number' => 'An account with this phone already exists. Sign in, or use "Forgot password".']);
        }
        if ($email && Customer::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already used by another account.']);
        }

        return DB::transaction(function () use ($data, $phone, $email) {
            $c = Customer::create([
                'code' => 'TMP-'.uniqid(), 'name' => trim($data['name']), 'phone' => $phone, 'phone_normalized' => $phone,
                'email' => $email, 'gender' => ($data['gender'] ?? null) ?: null, 'address' => ($data['address'] ?? null) ?: null,
                'password' => $data['password'],
            ]);
            $c->update(['code' => 'C-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)]);
            AuditLog::record(null, 'customer.registered', 'customer', $c->id, ['channel' => $data['device_name'] ?? 'web']);

            return $c;
        });
    }

    public function login(string $identifier, string $password): Customer
    {
        $c = $this->find($identifier);
        if (! $c || ! $c->password || ! Hash::check($password, $c->password)) {
            throw ValidationException::withMessages(['identifier' => 'The phone/email or password is incorrect.']);
        }
        if (! $c->is_active) {
            throw ValidationException::withMessages(['identifier' => 'This account is closed. Please contact the pharmacy.']);
        }
        $c->update(['last_login_at' => now()]);

        return $c;
    }

    /** A token for the website or app (`customer` ability only). */
    public function token(Customer $c, string $device): string
    {
        return $c->createToken(mb_substr($device ?: 'web', 0, 60), ['customer'], now()->addDays(self::TOKEN_DAYS))->plainTextToken;
    }

    /** Sends a reset code when the account exists and has an email. Silent otherwise. */
    public function sendResetCode(string $identifier): void
    {
        $c = $this->find($identifier);
        if (! $c || ! $c->email || ! $c->is_active) {
            return;
        }
        $last = CustomerPasswordReset::where('customer_id', $c->id)->latest('id')->first();
        if ($last && $last->created_at->gt(now()->subSeconds(60))) {
            return; // cooldown: the previous code is still on its way
        }
        $code = (string) random_int(100000, 999999);
        CustomerPasswordReset::where('customer_id', $c->id)->whereNull('used_at')->update(['used_at' => now()]); // one live code
        CustomerPasswordReset::create(['customer_id' => $c->id, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(self::CODE_MINUTES)]);
        Mail::raw(
            'Your '.app(Integrations::class)->brand()." password reset code is {$code}. It is valid for ".self::CODE_MINUTES." minutes.\nIf you did not ask for it, ignore this email.",
            fn ($m) => $m->to($c->email, $c->name)->subject(app(Integrations::class)->brand().' password reset code'),
        );
        Log::info('Customer password reset code sent', ['customer' => $c->id]);
    }

    public function resetPassword(string $identifier, string $code, string $password): Customer
    {
        $fail = fn () => throw ValidationException::withMessages(['otp' => 'The code is wrong or has expired. Request a new one.']);
        $c = $this->find($identifier);
        $reset = $c ? CustomerPasswordReset::where('customer_id', $c->id)->whereNull('used_at')->latest('id')->first() : null;
        if (! $reset || $reset->expires_at->isPast() || $reset->attempts >= self::MAX_ATTEMPTS) {
            $fail();
        }
        // Count the try before checking, outside any transaction.
        CustomerPasswordReset::whereKey($reset->id)->increment('attempts');
        if (! Hash::check($code, $reset->code_hash)) {
            $fail();
        }

        return DB::transaction(function () use ($c, $reset, $password) {
            $reset->update(['used_at' => now()]);
            $c->update(['password' => $password]);
            $c->tokens()->delete(); // sign out everywhere
            AuditLog::record(null, 'customer.password_reset', 'customer', $c->id, null);

            return $c;
        });
    }

    public function changePassword(Customer $c, string $current, string $new): void
    {
        if (! $c->password || ! Hash::check($current, $c->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $c->update(['password' => $new]);
        $c->tokens()->where('id', '!=', $c->currentAccessToken()?->id)->delete(); // other devices sign out
    }

    public function updateProfile(Customer $c, array $data): Customer
    {
        $email = array_key_exists('email', $data) ? (($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null) : $c->email;
        if ($email && Customer::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $c->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already used by another account.']);
        }
        // The phone is the account's identity: it is not changed from the website or app.
        $c->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'gender' => $data['gender'] ?? null, 'address' => $data['address'] ?? null,
        ], fn ($v) => $v !== null) + ['email' => $email]);

        return $c;
    }

    public function find(string $identifier): ?Customer
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return Customer::whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->where('is_walk_in', false)->first();
        }
        $phone = CustomerController::normalizePhone($identifier);

        return $phone ? Customer::where('phone_normalized', $phone)->where('is_walk_in', false)->first() : null;
    }

    private function phone(string $raw, string $field): string
    {
        $phone = CustomerController::normalizePhone($raw);
        if (! $phone) {
            throw ValidationException::withMessages([$field => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }

        return $phone;
    }
}
