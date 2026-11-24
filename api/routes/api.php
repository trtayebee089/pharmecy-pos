<?php

use App\Http\Controllers\Pos\AuthController;
use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\CatalogMasterController;
use App\Http\Controllers\Pos\CustomerAccountController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\CustomerGroupController;
use App\Http\Controllers\Pos\DashboardController;
use App\Http\Controllers\Pos\FinanceController;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\InventoryController;
use App\Http\Controllers\Pos\ProductController;
use App\Http\Controllers\Pos\PurchaseController;
use App\Http\Controllers\Pos\PurchaseOrderController;
use App\Http\Controllers\Pos\PurchaseReturnController;
use App\Http\Controllers\Pos\RegisterController;
use App\Http\Controllers\Pos\ReportController;
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
            Route::get('catalog/products/{product}', [CatalogController::class, 'show'])->whereNumber('product');
            Route::get('catalog/products/{product}/form', [ProductController::class, 'form'])->whereNumber('product');
            Route::get('catalog/lookups', [CatalogMasterController::class, 'lookups']);
            Route::get('catalog/{type}', [CatalogMasterController::class, 'index'])->whereIn('type', ['generics', 'manufacturers', 'dosage-forms', 'categories']);
            Route::get('catalog/{type}/{id}', [CatalogMasterController::class, 'show'])->whereIn('type', ['generics', 'manufacturers', 'dosage-forms', 'categories'])->whereNumber('id');
        });
        Route::post('catalog/products', [ProductController::class, 'store'])->middleware('can:products.create');
        Route::post('catalog/products/import', [ProductController::class, 'import'])->middleware(['can:products.create', 'idempotent']);
        Route::put('catalog/products/{product}', [ProductController::class, 'update'])->middleware('can:products.edit')->whereNumber('product');
        Route::post('catalog/products/{product}/deactivate', [ProductController::class, 'deactivate'])->middleware(['can:products.edit', 'idempotent']);
        Route::post('catalog/products/{product}/duplicate', [ProductController::class, 'duplicate'])->middleware(['can:products.create', 'idempotent']);
        Route::middleware('can:catalog.manage')->group(function () {
            $types = ['generics', 'manufacturers', 'dosage-forms', 'categories'];
            Route::post('catalog/{type}', [CatalogMasterController::class, 'store'])->whereIn('type', $types);
            Route::put('catalog/{type}/{id}', [CatalogMasterController::class, 'update'])->whereIn('type', $types)->whereNumber('id');
            Route::delete('catalog/{type}/{id}', [CatalogMasterController::class, 'destroy'])->whereIn('type', $types)->whereNumber('id');
        });

        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
        Route::get('customers/lookup', [CustomerController::class, 'lookup'])->middleware('can:pos.sell');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.manage');
        Route::get('customers/{customer}', [CustomerAccountController::class, 'show'])->middleware('can:customers.view')->whereNumber('customer');
        Route::put('customers/{customer}', [CustomerAccountController::class, 'update'])->middleware('can:customers.manage')->whereNumber('customer');
        Route::post('customers/{customer}/deactivate', [CustomerAccountController::class, 'deactivate'])->middleware(['can:customers.manage', 'idempotent'])->whereNumber('customer');
        Route::middleware('can:payments.collect')->group(function () {
            Route::get('customers/due', [CustomerAccountController::class, 'due']);
            Route::get('customers/{customer}/open-invoices', [CustomerAccountController::class, 'openInvoices'])->whereNumber('customer');
            Route::post('customers/{customer}/payments', [CustomerAccountController::class, 'collect'])->middleware('idempotent')->whereNumber('customer');
            Route::post('customers/{customer}/due-reminders', [CustomerAccountController::class, 'dueReminder'])->middleware('idempotent')->whereNumber('customer');
        });
        Route::get('customer-groups', [CustomerGroupController::class, 'index'])->middleware('can:customers.view');
        Route::get('customer-groups/{group}', [CustomerGroupController::class, 'show'])->middleware('can:customers.view')->whereNumber('group');
        // Groups carry discounts: managed separately from registering customers at the counter.
        Route::middleware('can:customer_groups.manage')->group(function () {
            Route::post('customer-groups', [CustomerGroupController::class, 'store']);
            Route::put('customer-groups/{group}', [CustomerGroupController::class, 'update'])->whereNumber('group');
            Route::delete('customer-groups/{group}', [CustomerGroupController::class, 'destroy'])->whereNumber('group');
        });

        Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view');
        Route::get('sales/returns', [SaleController::class, 'returns'])->middleware('can:returns.view');
        Route::get('sales/held', [HeldSaleController::class, 'index'])->middleware('can:sales.view');
        Route::middleware('can:pos.sell')->group(function () {
            Route::post('sales/held', [HeldSaleController::class, 'store'])->middleware('idempotent');
            Route::post('sales/held/{number}/resume', [HeldSaleController::class, 'resume'])->middleware('idempotent');
            Route::delete('sales/held/{number}', [HeldSaleController::class, 'destroy']);
        });
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

        Route::middleware('can:reorder.manage')->prefix('purchase-orders')->group(function () {
            Route::get('companies', [PurchaseOrderController::class, 'companies']);
            Route::get('report', [PurchaseOrderController::class, 'report']);
            Route::post('forecast', [PurchaseOrderController::class, 'forecast']);
            Route::get('drafts', [PurchaseOrderController::class, 'drafts']);
            Route::get('drafts/{draft}', [PurchaseOrderController::class, 'show'])->whereNumber('draft');
            Route::post('drafts', [PurchaseOrderController::class, 'store'])->middleware('idempotent');
            Route::put('drafts/{draft}', [PurchaseOrderController::class, 'update'])->whereNumber('draft');
            Route::delete('drafts/{draft}', [PurchaseOrderController::class, 'destroy'])->whereNumber('draft');
            Route::post('drafts/{draft}/purchase', [PurchaseOrderController::class, 'convert'])->middleware(['can:purchases.create', 'idempotent'])->whereNumber('draft');
            Route::post('ai-runs', [PurchaseOrderController::class, 'startRun']);
            Route::post('ai-runs/{run}/process', [PurchaseOrderController::class, 'processRun'])->whereNumber('run');
        });

        Route::prefix('reports')->group(function () {
            $perm = ['sales' => 'reports.sales', 'billers' => 'reports.sales', 'custom-products' => 'reports.sales', 'profit' => 'reports.profit',
                'stock' => 'reports.stock', 'expiry' => 'reports.stock', 'purchases' => 'reports.purchases', 'customers' => 'reports.customers',
                'customer' => 'reports.customers', 'suppliers' => 'reports.suppliers', 'payments' => 'reports.payments', 'cashiers' => 'reports.users'];
            foreach ($perm as $report => $permission) {
                Route::get($report, [ReportController::class, 'show'])->defaults('report', $report)->middleware("can:$permission");
            }
            Route::get('monthly-summary', [ReportController::class, 'monthlySummary'])->middleware('can:reports.profit');
            Route::get('options', [ReportController::class, 'options'])->middleware('can:products.view'); // names only (manufacturers, forms, suppliers, custom items)
        });
        Route::get('billers', [ReportController::class, 'billers'])->middleware('can:reports.sales');

        Route::prefix('finance')->group(function () {
            Route::middleware('can:accounts.view')->group(function () {
                Route::get('accounts', [FinanceController::class, 'accounts']);
                Route::post('accounts', [FinanceController::class, 'storeAccount']);
                Route::put('accounts/{account}', [FinanceController::class, 'updateAccount']);
                Route::post('accounts/{account}/activate', [FinanceController::class, 'activate']);
                Route::post('accounts/{account}/deactivate', [FinanceController::class, 'deactivate']);
                Route::post('accounts/{account}/default', [FinanceController::class, 'makeDefault']);
                Route::get('accounts/{account}/statement', [FinanceController::class, 'statement']);
            });
            Route::middleware('can:accounts.transfer')->group(function () {
                Route::get('money-transfers', [FinanceController::class, 'transfers']);
                Route::post('money-transfers', [FinanceController::class, 'storeTransfer'])->middleware('idempotent');
                Route::post('money-transfers/{transfer}/void', [FinanceController::class, 'voidTransfer'])->middleware('idempotent');
            });
            foreach (['expenses' => ['expenses.view', 'expense-categories'], 'incomes' => ['income.view', 'income-categories']] as $kinds => [$permission, $categories]) {
                Route::middleware("can:$permission")->group(function () use ($kinds, $categories) {
                    Route::get($categories, [FinanceController::class, 'categories'])->defaults('kind', $categories);
                    Route::post($categories, [FinanceController::class, 'storeCategory'])->defaults('kind', $categories);
                    Route::get("$categories/{id}", [FinanceController::class, 'showCategory'])->defaults('kind', $categories)->whereNumber('id');
                    Route::put("$categories/{id}", [FinanceController::class, 'updateCategory'])->defaults('kind', $categories)->whereNumber('id');
                    Route::delete("$categories/{id}", [FinanceController::class, 'destroyCategory'])->defaults('kind', $categories)->whereNumber('id');
                    Route::get($kinds, [FinanceController::class, 'entries'])->defaults('kinds', $kinds);
                    Route::post($kinds, [FinanceController::class, 'storeEntry'])->defaults('kinds', $kinds)->middleware('idempotent');
                    Route::post("$kinds/{entry}/void", [FinanceController::class, 'voidEntry'])->defaults('kinds', $kinds);
                    Route::post("$kinds/{entry}/attachment", [FinanceController::class, 'attach'])->defaults('kinds', $kinds);
                    Route::get("$kinds/{entry}/attachment", [FinanceController::class, 'attachmentUrl'])->defaults('kinds', $kinds);
                });
            }
            Route::get('balance-sheet', [FinanceController::class, 'balanceSheet'])->middleware('can:accounts.reports');
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

    // Opened directly in the browser: protected by a 5-minute signature instead of the session.
    Route::get('finance/attachments/{entry}', [FinanceController::class, 'downloadAttachment'])->middleware('signed')->name('finance.attachment');
});
