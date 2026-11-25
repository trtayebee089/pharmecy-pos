<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user with a temporary password (set by an admin) may only read their
 * profile, change the password or sign out until the password is changed.
 */
class PasswordFresh
{
    private const ALLOWED = ['api/pos/v1/me', 'api/pos/v1/me/password', 'api/pos/v1/me/profile', 'api/pos/v1/auth/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->must_change_password && ! in_array($request->path(), self::ALLOWED, true)) {
            return response()->json(['message' => 'Change your temporary password before continuing.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
