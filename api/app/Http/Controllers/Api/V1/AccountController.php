<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AccountRemovalRequest;
use App\Services\CustomerAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** /api/v1/customer/*: sign-up, sign-in, password reset by email code, profile, sign-out. */
class AccountController extends V1Controller
{
    public function __construct(private CustomerAuth $auth) {}

    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone_number' => $request->input('phone_number', $request->input('phone', $request->input('mobile')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone_number' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'], 'gender' => ['nullable', 'in:male,female,other'], 'address' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()], 'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $c = $this->auth->register(['phone' => $data['phone_number']] + $data);
        $token = $this->auth->token($c, $data['device_name'] ?? 'web');

        return $this->ok(['token' => $token, 'user' => $this->customer($c)], 'Account created.', 201, ['token' => $token, 'user' => $this->customer($c)]);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['identifier' => $request->input('identifier', $request->input('phone_number', $request->input('phone', $request->input('email'))))]);
        $data = $request->validate(['identifier' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'max:200'], 'device_name' => ['nullable', 'string', 'max:60']]);
        $this->throttle('login', $request->ip(), mb_strtolower($data['identifier']), 5);
        $c = $this->auth->login($data['identifier'], $data['password']);
        RateLimiter::clear('login|'.mb_strtolower($data['identifier']));
        $token = $this->auth->token($c, $data['device_name'] ?? 'web');

        return $this->ok(['token' => $token, 'user' => $this->customer($c)], 'Signed in.', 200, ['token' => $token, 'user' => $this->customer($c)]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $id = (string) $request->input('identifier', $request->input('mobile', $request->input('phone', $request->input('email', ''))));
        if (trim($id) === '') {
            throw ValidationException::withMessages(['mobile' => 'Enter your phone number or email.']);
        }
        $this->throttle('reset', $request->ip(), mb_strtolower($id), 3);
        $this->auth->sendResetCode($id);

        // Same answer whether or not the account exists.
        return $this->ok(null, 'If the account has an email address, a 6-digit code has been sent to it.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge(['identifier' => $request->input('identifier', $request->input('mobile', $request->input('phone', $request->input('email'))))]);
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'], 'otp' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $this->throttle('reset-check', $request->ip(), mb_strtolower($data['identifier']), 10);
        $this->auth->resetPassword($data['identifier'], $data['otp'], $data['password']);

        return $this->ok(null, 'Password changed. Sign in with the new password.');
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->ok($this->customer($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'], 'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other'], 'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->customer($this->auth->updateProfile($request->user(), $data)), 'Profile saved.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
        $this->auth->changePassword($request->user(), $data['current_password'], $data['password']);

        return $this->ok(null, 'Password changed.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Signed out.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->ok(null, 'Signed out on every device.');
    }

    /** Play Store requirement: the customer asks for the account to be deleted; staff handle it. */
    public function requestRemoval(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $req = AccountRemovalRequest::firstOrCreate(['customer_id' => $request->user()->id, 'status' => 'pending'], ['reason' => $data['reason'] ?? null]);

        return $this->ok(['id' => $req->id, 'status' => $req->status], 'Your request was received. The pharmacy will confirm by phone or email.', 201);
    }

    private function throttle(string $name, string $ip, string $identifier, int $perIdentifier): void
    {
        foreach (["$name|ip|$ip" => 10, "$name|$identifier" => $perIdentifier] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['identifier' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'])->status(429);
            }
            RateLimiter::hit($key, 60);
        }
    }
}
