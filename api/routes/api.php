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
use App\Http\Controllers\Pos\HrController;
use App\Http\Controllers\Pos\InventoryController;
use App\Http\Controllers\Pos\LoyaltyController;
use App\Http\Controllers\Pos\OnlineOrderController;
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

        // HR & payroll. Registered before the finance group so finance/accounts/payout is not taken for an account id.
        Route::get('finance/accounts/payout', [HrController::class, 'payoutAccounts'])->middleware('can:hr.payroll');
        Route::prefix('hr')->group(function () {
            Route::middleware('can:hr.view')->group(function () {
                Route::get('employees', [HrController::class, 'employees']);
                Route::get('employees/{employee}', [HrController::class, 'employee'])->whereNumber('employee');
                Route::get('departments', [HrController::class, 'departments']);
                Route::get('designations', [HrController::class, 'designations']);
                Route::get('holidays', [HrController::class, 'holidays']);
                Route::get('settings', [HrController::class, 'settings']);
            });
            Route::middleware('can:hr.manage')->group(function () {
                Route::post('employees', [HrController::class, 'saveEmployee']);
                Route::put('employees/{employee}', [HrController::class, 'saveEmployee']);
                Route::post('departments', [HrController::class, 'saveDepartment']);
                Route::put('departments/{department}', [HrController::class, 'saveDepartment']);
                Route::delete('departments/{department}', [HrController::class, 'deleteDepartment']);
                Route::post('designations', [HrController::class, 'saveDesignation']);
                Route::put('designations/{designation}', [HrController::class, 'saveDesignation']);
                Route::delete('designations/{designation}', [HrController::class, 'deleteDesignation']);
                Route::post('holidays', [HrController::class, 'saveHoliday']);
                Route::put('holidays/{holiday}', [HrController::class, 'saveHoliday']);
                Route::delete('holidays/{holiday}', [HrController::class, 'deleteHoliday']);
            });
            Route::middleware('can:hr.attendance')->group(function () {
                Route::get('attendance', [HrController::class, 'attendanceDay']);
                Route::get('attendance/month', [HrController::class, 'attendanceMonth']);
                Route::post('attendance', [HrController::class, 'saveAttendance'])->middleware('idempotent');
            });
            Route::middleware('can:hr.payroll')->group(function () {
                Route::put('settings', [HrController::class, 'saveSettings']);
                Route::get('payroll-runs', [HrController::class, 'runs']);
                Route::get('payroll-runs/{run}', [HrController::class, 'showRun'])->whereNumber('run');
                Route::post('payroll-runs', [HrController::class, 'createRun'])->middleware('idempotent');
                Route::post('payroll-runs/{run}/approve', [HrController::class, 'approveRun']);
                Route::post('payroll-runs/{run}/pay', [HrController::class, 'payRun'])->middleware('idempotent');
                Route::delete('payroll-runs/{run}', [HrController::class, 'deleteRun']);
                Route::put('payroll-runs/{run}/payslips/{payslip}', [HrController::class, 'updatePayslip']);
                Route::get('salary-advances', [HrController::class, 'advances']);
                Route::post('salary-advances', [HrController::class, 'giveAdvance'])->middleware('idempotent');
                Route::post('salary-advances/{advance}/recoveries', [HrController::class, 'recoverAdvance'])->middleware('idempotent');
                Route::post('salary-advances/{advance}/void', [HrController::class, 'voidAdvance']);
            });
        });

        // Loyalty: wallets, campaigns, membership plans and members; reward point settings.
        Route::prefix('loyalty')->group(function () {
            Route::middleware('can:loyalty.view')->group(function () {
                Route::get('wallets', [LoyaltyController::class, 'wallets']);
                Route::get('wallets/{customer}/ledger', [LoyaltyController::class, 'ledger']);
                Route::get('campaigns', [LoyaltyController::class, 'campaigns']);
                Route::get('campaigns/{campaign}', [LoyaltyController::class, 'campaign'])->whereNumber('campaign');
                Route::get('membership-plans', [LoyaltyController::class, 'plans']);
                Route::get('members', [LoyaltyController::class, 'members']);
            });
            Route::middleware('can:loyalty.manage')->group(function () {
                Route::post('wallets/{customer}/adjustments', [LoyaltyController::class, 'adjust'])->middleware('idempotent');
                Route::post('campaigns', [LoyaltyController::class, 'saveCampaign']);
                Route::put('campaigns/{campaign}', [LoyaltyController::class, 'saveCampaign']);
                Route::delete('campaigns/{campaign}', [LoyaltyController::class, 'deleteCampaign']);
                Route::put('membership-plans/{plan}', [LoyaltyController::class, 'savePlan']);
                Route::delete('membership-plans/{plan}', [LoyaltyController::class, 'deletePlan']);
                Route::post('customers/{customer}/membership', [LoyaltyController::class, 'assign']);
                Route::post('members/{member}/renew', [LoyaltyController::class, 'renew'])->middleware('idempotent');
                Route::post('members/{member}/plan', [LoyaltyController::class, 'changePlan']);
                Route::post('members/{member}/cancel', [LoyaltyController::class, 'cancel']);
            });
        });
        // Online orders (phone orders now, website/app later) and delivery.
        Route::middleware('can:orders.view')->group(function () {
            Route::get('orders', [OnlineOrderController::class, 'index']);
            Route::get('orders/settings', [OnlineOrderController::class, 'settings']);
            Route::get('orders/{order}', [OnlineOrderController::class, 'show'])->whereNumber('order');
            Route::get('delivery/riders', [OnlineOrderController::class, 'riders']);
            Route::get('delivery/riders/{rider}', [OnlineOrderController::class, 'riderShow'])->whereNumber('rider');
            Route::get('delivery/riders/{rider}/cash', [OnlineOrderController::class, 'riderCash']);
            Route::get('delivery/zones', [OnlineOrderController::class, 'zones']);
            Route::get('delivery/zones/{zone}', [OnlineOrderController::class, 'zoneShow'])->whereNumber('zone');
        });
        Route::middleware('can:orders.fulfil')->group(function () {
            Route::post('orders', [OnlineOrderController::class, 'store'])->middleware('idempotent');
            Route::post('orders/{order}/status', [OnlineOrderController::class, 'status'])->middleware('idempotent');
            Route::post('orders/{order}/rider', [OnlineOrderController::class, 'rider'])->middleware('idempotent');
        });
        Route::middleware('can:riders.manage')->group(function () {
            Route::post('delivery/riders', [OnlineOrderController::class, 'saveRider']);
            Route::put('delivery/riders/{rider}', [OnlineOrderController::class, 'saveRider']);
            Route::delete('delivery/riders/{rider}', [OnlineOrderController::class, 'deleteRider']);
            Route::post('delivery/riders/{rider}/handover', [OnlineOrderController::class, 'handover'])->middleware('idempotent');
        });
        Route::middleware('can:zones.manage')->group(function () {
            Route::post('delivery/zones', [OnlineOrderController::class, 'saveZone']);
            Route::put('delivery/zones/{zone}', [OnlineOrderController::class, 'saveZone']);
            Route::delete('delivery/zones/{zone}', [OnlineOrderController::class, 'deleteZone']);
            Route::put('orders/settings', [OnlineOrderController::class, 'saveSettings']);
        });

        // The POS reads the reward rules to offer points at checkout; only settings managers change them.
        Route::get('settings/reward-points', [LoyaltyController::class, 'settings']);
        Route::put('settings/reward-points', [LoyaltyController::class, 'saveSettings'])->middleware('can:settings.manage');

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
