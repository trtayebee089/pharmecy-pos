<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Customer routes: a signed-in, active customer whose bearer token carries the `customer` ability. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $c = $request->user();
        if (! $c instanceof Customer || ! $c->tokenCan('customer') || ! $c->is_active || $c->is_walk_in) {
            return response()->json(['success' => false, 'message' => 'Please sign in.'], 401);
        }

        return $next($request);
    }
}
