<?php

use App\Http\Middleware\Idempotent;
use App\Http\Middleware\PasswordFresh;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: requests from SANCTUM_STATEFUL_DOMAINS get session + CSRF.
        $middleware->statefulApi();
        $middleware->alias(['idempotent' => Idempotent::class, 'password.fresh' => PasswordFresh::class]);
        // An API has no login page: unauthenticated requests get 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
