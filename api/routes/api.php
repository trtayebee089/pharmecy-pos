<?php

use App\Http\Controllers\Pos\AuthController;
use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\DashboardController;
use App\Http\Controllers\Pos\InventoryController;
use App\Http\Controllers\Pos\PurchaseController;
use App\Http\Controllers\Pos\PurchaseReturnController;
use App\Http\Controllers\Pos\RegisterController;
use App\Http\Controllers\Pos\SaleController;
use App\Http\Controllers\Pos\StockDocumentController;
use App\Http\Controllers\Pos\SupplierController;
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
        Route::get('sales/returns', [SaleController::class, 'returns'])->middleware('can:returns.view');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('can:sales.view')->whereNumber('sale');
        Route::post('sales', [SaleController::class, 'store'])->middleware(['can:pos.sell', 'idempotent']);
        Route::post('sales/{sale}/void', [SaleController::class, 'void'])->middleware('can:sales.void');
        Route::post('sales/{sale}/returns', [SaleController::class, 'storeReturn'])->middleware(['can:returns.create', 'idempotent']);

        Route::middleware('can:pos.sell')->prefix('cash-registers')->group(function () {
            Route::get('/', [RegisterController::class, 'registers']);
            Route::get('sessions', [RegisterController::class, 'sessions']);
            Route::post('sessions', [RegisterController::class, 'open']);
            Route::post('sessions/{session}/movements', [RegisterController::class, 'movement'])->middleware('idempotent');
            Route::post('sessions/{session}/close', [RegisterController::class, 'close']);
        });

        Route::get('purchases', [PurchaseController::class, 'index'])->middleware('can:purchases.view');
        Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('can:purchases.view')->whereNumber('purchase');
        Route::post('purchases', [PurchaseController::class, 'store'])->middleware(['can:purchases.create', 'idempotent']);
        Route::post('purchases/{purchase}/payments', [PurchaseController::class, 'pay'])->middleware(['can:payments.pay_supplier', 'idempotent']);
        Route::post('purchases/{purchase}/void', [PurchaseController::class, 'void'])->middleware(['can:purchases.create', 'idempotent']);
        Route::post('purchases/{purchase}/duplicate', [PurchaseController::class, 'duplicate'])->middleware(['can:purchases.create', 'idempotent']);

        Route::prefix('inventory')->group(function () {
            Route::middleware('can:inventory.view')->group(function () {
                Route::get('stock', [InventoryController::class, 'stock']);
                Route::get('stock/summary', [InventoryController::class, 'summary']);
                Route::get('batches', [InventoryController::class, 'batches']);
            });
            Route::middleware('can:inventory.adjust')->group(function () {
                Route::get('adjustments', [StockDocumentController::class, 'adjustments']);
                Route::post('adjustments', [StockDocumentController::class, 'storeAdjustment'])->middleware('idempotent');
                Route::post('adjustments/{adjustment}/void', [StockDocumentController::class, 'voidAdjustment'])->middleware('idempotent');
            });
            Route::middleware('can:inventory.count')->group(function () {
                Route::get('counts', [StockDocumentController::class, 'counts']);
                Route::get('counts/{count}', [StockDocumentController::class, 'showCount'])->whereNumber('count');
                Route::post('counts', [StockDocumentController::class, 'startCount'])->middleware('idempotent');
                Route::put('counts/{count}/lines', [StockDocumentController::class, 'saveCounts']);
                Route::post('counts/{count}/post', [StockDocumentController::class, 'postCount'])->middleware('idempotent');
                Route::post('counts/{count}/cancel', [StockDocumentController::class, 'cancelCount']);
            });
            Route::middleware('can:inventory.transfer')->group(function () {
                Route::get('transfers', [StockDocumentController::class, 'transfers']);
                Route::post('transfers', [StockDocumentController::class, 'sendTransfer'])->middleware('idempotent');
                Route::post('transfers/{transfer}/receive', [StockDocumentController::class, 'receiveTransfer'])->middleware('idempotent');
                Route::post('transfers/{transfer}/void', [StockDocumentController::class, 'voidTransfer'])->middleware('idempotent');
            });
        });

        Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])->middleware('can:purchase_returns.view');
        Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->middleware(['can:purchase_returns.create', 'idempotent']);
        Route::post('purchase-returns/{purchaseReturn}/void', [PurchaseReturnController::class, 'void'])->middleware(['can:purchase_returns.create', 'idempotent']);

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view');
        Route::get('suppliers/lookup', [SupplierController::class, 'lookup'])->middleware('can:purchases.view');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('can:suppliers.view')->whereNumber('supplier');
        Route::get('suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])->middleware('can:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.manage');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('can:suppliers.manage');
        Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'pay'])->middleware(['can:payments.pay_supplier', 'idempotent']);
    });
});
