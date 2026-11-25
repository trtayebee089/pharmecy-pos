<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** POS routes are for staff only: a customer's website/app token never reaches them. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json(['message' => 'Staff sign-in required.'], 403);
        }

        return $next($request);
    }
}
