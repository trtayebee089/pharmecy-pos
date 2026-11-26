<?php

use App\Http\Controllers\Rider\RiderAppController;
use App\Http\Middleware\ForceJson;
use Illuminate\Support\Facades\Route;

/*
 * In-house rider app (Flutter, ../rider-app): bearer tokens (Sanctum, ability `rider`).
 * Envelope {success, message, data, meta} like the customer API.
 */
Route::prefix('rider/v1')->middleware([ForceJson::class, 'throttle:rider-api'])->group(function () {
    Route::post('login', [RiderAppController::class, 'login'])->middleware('throttle:rider-auth');

    Route::middleware(['auth:sanctum', 'rider'])->group(function () {
        Route::get('me', [RiderAppController::class, 'me']);
        Route::post('logout', [RiderAppController::class, 'logout']);
        Route::put('duty', [RiderAppController::class, 'duty']);
        Route::post('locations', [RiderAppController::class, 'locations'])->middleware('throttle:rider-locations');
        Route::get('orders', [RiderAppController::class, 'orders']);
        Route::get('orders/{order}', [RiderAppController::class, 'order'])->whereNumber('order');
        Route::post('orders/{order}/pickup', [RiderAppController::class, 'pickup'])->whereNumber('order');
        Route::post('orders/{order}/deliver', [RiderAppController::class, 'deliver'])->whereNumber('order');
    });
});
