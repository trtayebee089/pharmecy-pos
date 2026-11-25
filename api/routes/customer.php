<?php

use App\Http\Controllers\Api\CourierWebhookController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\LegacyAppController as Legacy;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Middleware\ForceJson;
use Illuminate\Support\Facades\Route;

/*
 * Customer channel for the website and the Android app: stateless bearer tokens
 * (Sanctum, ability `customer`), never the staff session. Envelope
 * {success, message, data, meta}. Breaking changes go to /api/v2.
 */
Route::prefix('v1')->middleware([ForceJson::class, 'throttle:customer-api'])->group(function () {
    // Catalogue (public)
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('categories/{slug}', [CatalogController::class, 'category']);
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/featured', [CatalogController::class, 'featured']);
    Route::get('products/best-selling', [CatalogController::class, 'bestSelling']);
    Route::get('products/suggestions', [CatalogController::class, 'suggestions']);
    Route::get('products/{slug}', [CatalogController::class, 'product']);
    Route::get('shipping-zones', [CatalogController::class, 'zones']);
    Route::get('site-config', [SiteController::class, 'config']);

    // Account
    Route::prefix('customer')->group(function () {
        Route::middleware('throttle:customer-auth')->group(function () {
            Route::post('register', [AccountController::class, 'register']);
            Route::post('login', [AccountController::class, 'login']);
            Route::post('forgot-password', [AccountController::class, 'forgotPassword']);
            Route::post('reset-password', [AccountController::class, 'resetPassword']);
        });
        Route::middleware(['auth:sanctum', 'customer'])->group(function () {
            Route::get('profile', [AccountController::class, 'profile']);
            Route::put('profile', [AccountController::class, 'updateProfile']);
            Route::put('password', [AccountController::class, 'changePassword']);
            Route::post('logout', [AccountController::class, 'logout']);
            Route::post('logout-all', [AccountController::class, 'logoutAll']);
            Route::post('account-removal-request', [AccountController::class, 'requestRemoval']);

            // Rewards and membership
            Route::get('rewards', [LoyaltyController::class, 'rewards']);
            Route::get('rewards/transactions', [LoyaltyController::class, 'transactions']);
            Route::post('rewards/quote', [LoyaltyController::class, 'quote']);
            Route::get('membership', [LoyaltyController::class, 'current']);
            Route::get('membership/progress', [LoyaltyController::class, 'current']);
            Route::get('membership/history', [LoyaltyController::class, 'history']);
            Route::post('membership/renew', [LoyaltyController::class, 'renew']);
        });
    });

    // Orders: checkout works signed in or as a guest; history is the owner's only.
    Route::post('checkout', [OrderController::class, 'checkout'])->middleware('throttle:checkout');
    Route::get('tracking/{code}', [OrderController::class, 'track'])->middleware('throttle:order-tracking');

    // Online payment (SSLCommerz, bKash): start/retry and switch to COD by tracking code; gateway returns.
    Route::post('payments/start', [PaymentController::class, 'start'])->middleware('throttle:checkout');
    Route::post('payments/cod', [PaymentController::class, 'cod'])->middleware('throttle:checkout');
    Route::post('payments/sslcommerz/ipn', [PaymentController::class, 'sslcommerzIpn']);
    Route::match(['get', 'post'], 'payments/sslcommerz/{outcome}', [PaymentController::class, 'sslcommerz'])->whereIn('outcome', ['success', 'fail', 'cancel']);
    Route::get('payments/bkash/callback', [PaymentController::class, 'bkash']);
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{number}', [OrderController::class, 'show']);
        Route::post('orders/{number}/reorder', [OrderController::class, 'reorder']);
    });
});

/*
 * Legacy paths of the installed Android app (1.x), same services and rules as
 * /v1 with the old response shapes (LegacyAppController). Remove when old
 * installs have aged out.
 */
Route::middleware([ForceJson::class, 'throttle:customer-api'])->group(function () {
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('categories/{slug}', [Legacy::class, 'category']);
    Route::get('products/featured', [CatalogController::class, 'featured']);
    Route::get('products/best-selling', [CatalogController::class, 'bestSelling']);
    Route::get('products/suggestions', [Legacy::class, 'suggestions']);
    Route::get('products/{slug}', [Legacy::class, 'product']);
    Route::get('search/{q}', [Legacy::class, 'search']);
    Route::get('shipping-zones', [CatalogController::class, 'zones']);
    Route::post('checkout', [Legacy::class, 'checkout'])->middleware('throttle:checkout');

    Route::prefix('customer')->group(function () {
        Route::middleware('throttle:customer-auth')->group(function () {
            Route::post('login', [Legacy::class, 'login']);
            Route::post('register', [Legacy::class, 'register']);
            Route::post('forget-password', [AccountController::class, 'forgotPassword']);
            Route::post('verify-otp', [AccountController::class, 'resetPassword']);
        });
        Route::middleware(['auth:sanctum', 'customer'])->group(function () {
            Route::get('profile', [Legacy::class, 'profile']);
            Route::post('profile/update', [Legacy::class, 'updateProfile']);
            Route::post('profile/update-password', [Legacy::class, 'updatePassword']);
            Route::post('logout', [AccountController::class, 'logout']);
            Route::get('orders', [Legacy::class, 'orders']);
            Route::get('orders/{id}', [Legacy::class, 'order'])->whereNumber('id');
        });
    });
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::post('orders/{id}/reorder', [Legacy::class, 'reorder'])->whereNumber('id');
        Route::get('reward/profile', [LoyaltyController::class, 'rewards']);
        Route::get('reward/transactions', [LoyaltyController::class, 'transactions']);
        Route::post('reward/redeem', [LoyaltyController::class, 'quote']);
    });
});

// Product catalogue feeds for Facebook / Google Merchant Center (Administration › Integrations › Tracking & feed).
Route::middleware('throttle:feeds')->group(function () {
    Route::get('feeds/products.xml', [FeedController::class, 'xml']);
    Route::get('feeds/products.csv', [FeedController::class, 'csv']);
});

// Parcel status from Steadfast / Pathao (each checks its own secret).
Route::middleware('throttle:feeds')->group(function () {
    Route::post('webhooks/steadfast', [CourierWebhookController::class, 'steadfast']);
    Route::post('webhooks/pathao', [CourierWebhookController::class, 'pathao']);
});
