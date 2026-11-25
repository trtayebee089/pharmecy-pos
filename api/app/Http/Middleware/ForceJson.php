<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer routes always answer in JSON. The installed Android app leaves out
 * `Accept: application/json` on many calls; without this a validation error
 * would be a redirect and a server error an HTML page, which the app cannot read.
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
