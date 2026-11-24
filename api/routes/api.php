<?php

use App\Http\Controllers\Pos\AuthController;
use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\DashboardController;
use App\Http\Controllers\Pos\PurchaseController;
use App\Http\Controllers\Pos\SaleController;
use Illuminate\Support\Facades\Route;

/*
 * Nuxt POS channel: Sanctum SPA cookie auth (stateful), every route checks a
 * permission. Customer website/app channel (/api/v1) comes later; the legacy
 * /api routes stay frozen in the old system.
 */
Route::prefix('pos/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me/password', [AuthController::class, 'changePassword']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->middleware('can:dashboard.view');

        Route::middleware('can:products.view')->group(function () {
            Route::get('catalog/products/search', [CatalogController::class, 'search']);
            Route::get('catalog/products', [CatalogController::class, 'index']);
            Route::get('catalog/products/{product}', [CatalogController::class, 'show']);
        });

        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
        Route::get('customers/lookup', [CustomerController::class, 'lookup'])->middleware('can:pos.sell');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.manage');

        Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('can:sales.view');
        Route::post('sales', [SaleController::class, 'store'])->middleware(['can:pos.sell', 'idempotent']);

        Route::post('purchases', [PurchaseController::class, 'store'])->middleware(['can:purchases.create', 'idempotent']);
    });
});
