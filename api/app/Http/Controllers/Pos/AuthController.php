<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA cookie auth for the POS. The session cookie is HttpOnly;
 * no token is ever handed to the browser.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        abort_unless($request->hasSession(), 400, 'Login is only available from the POS web app.');
        $throttleKey = Str::lower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ])->status(429);
        }

        if (! Auth::guard('web')->attempt(['username' => $data['username'], 'password' => $data['password'], 'is_active' => true])) {
            RateLimiter::hit($throttleKey, 60);
            AuditLog::record(null, 'auth.failed', 'user', null, ['username' => $data['username']], $request);
            throw ValidationException::withMessages(['username' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record($user->id, 'auth.login', 'user', $user->id, null, $request);

        return response()->json(Present::me($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(Present::me($request->user()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'Current password is incorrect.']);
        }
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        AuditLog::record($user->id, 'auth.password_changed', 'user', $user->id, null, $request);

        return response()->json(['ok' => true]);
    }
}
