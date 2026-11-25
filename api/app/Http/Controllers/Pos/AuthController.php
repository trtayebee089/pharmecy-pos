<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        // The account page sends current_password; older clients send currentPassword.
        if ($request->has('current_password') && ! $request->has('currentPassword')) {
            $request->merge(['currentPassword' => $request->input('current_password')]);
        }
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'Current password is incorrect.', 'current' => 'The current password is incorrect.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the current one.']);
        }
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        // Sign out every other session of this user.
        DB::table('sessions')->where('user_id', $user->id)->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))->delete();
        AuditLog::record($user->id, 'auth.password_changed', 'user', $user->id, null, $request);

        return response()->json(['ok' => true]);
    }

    /** My account: profile and recent sign-ins. */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $me = Present::me($user);

        return response()->json([
            'name' => $user->name, 'username' => $user->username,
            'email' => str_ends_with((string) $user->email, '@users.invalid') ? '' : (string) $user->email,
            'phone' => (string) $user->phone, 'role' => $me['role'], 'warehouses' => array_column($me['warehouses'], 'name'),
            'signIns' => DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'auth.login')->orderByDesc('id')->limit(10)->get()
                ->map(fn ($l) => ['at' => Carbon::parse($l->created_at)->toIso8601String(), 'device' => mb_substr((string) $l->user_agent, 0, 80), 'ip' => (string) $l->ip, 'ok' => true])->all(),
        ]);
    }

    /** Self-service may change only name, email and phone (never role, branches or status: KI-010). */
    public function saveProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);
        $user->update(['name' => trim($d['name']), 'email' => ($d['email'] ?? null) ?: $user->email, 'phone' => $d['phone'] ?? $user->phone]);
        AuditLog::record($user->id, 'auth.profile_updated', 'user', $user->id, null, $request);

        return $this->profile($request);
    }
}
