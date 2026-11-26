<?php

namespace App\Http\Middleware;

use App\Models\Rider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rider app routes: an active rider whose bearer token carries the `rider` ability. */
class EnsureRider
{
    public function handle(Request $request, Closure $next): Response
    {
        $r = $request->user();
        if (! $r instanceof Rider || ! $r->tokenCan('rider') || $r->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Please sign in.'], 401);
        }

        return $next($request);
    }
}
