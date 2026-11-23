# Red Pharma POS: Navigation Inventory (master)

## 1. Header

| Item | Value |
|---|---|
| Scope | Every entry in the legacy backend sidebar (18 groups), plus hidden/commented menus, routes not linked from the sidebar, and header/top-bar entry points |
| Source | `D:\01 REDPHARMA\00 CODEBASE\backend - ocr pos\` (Laravel 10 + Blade), read-only. Nothing was modified or executed (`artisan route:list` was not run) |
| Inputs | `docs/workpapers/wp1-navigation-core.md` (WP1: Dashboard, Product, Purchase, Sale, POS, Online Orders, Transfer, Return) and `docs/workpapers/wp2-navigation-admin.md` (WP2: Customer Ecosystem, Expense, Income, Accounting, HRM, People, Reports, Settings, Manufacturing, RBAC). Both cite `file:line` |
| Method | Consolidation of WP1/WP2 (no new claims), plus a fresh line-by-line re-read of `resources/views/backend/layout/sidebar.blade.php` (737 lines) for the verification in §6. A few list-page column headers and form fields not captured in the workpapers were read directly from the views (marked **[src]**) |
| Status | Static code inventory. **Live UI verification pending** (see caveat) |

### 1.1 Critical caveat: local snapshot vs production

- The local source snapshot is **not identical to production**. Example: local `routes/web.php:607-649` mis-nests the POS under `Route::prefix('report')`, so locally POS lives at `/report/pos` and several POS/Sale AJAX calls resolve to missing `ReportController` methods (WP1 R1-R3, X2/X3, §5.2). But on the live site `https://pos.redpharmabd.com/pos` redirects to login (route exists) and `/report/pos` returns 404, so production routes differ.
- Therefore every "broken because of the `/report` prefix" finding below is a **local-source finding**. Re-check it against production before you act on it.
- The live POS was **NOT inspected logged-in** (no credentials). Screens, column sets and runtime behaviour on production are unverified.
- Items marked **UNVERIFIED** in the workpapers stay UNVERIFIED here.

### 1.2 Legend

**Recommendation values** (WP wording kept after the dash):

| Value | Meaning |
|---|---|
| **Migrate** | Carry the feature into the new system largely as-is functionally, with normal hardening (server-side permissions, validation) |
| **Redesign** | Keep the business need, rebuild logic/data model/UI |
| **Preserve-as-is** | Behaviour is sound; port without functional change |
| **Retire-candidate (needs business confirmation)** | Looks unused or irrelevant for a pharmacy; confirm with the business before dropping |
| **Fix-before-migrate** | Contains a defect (often security) that must be fixed or removed now, in the legacy system, independent of migration |

**Abbreviations:** SB = `resources/views/backend/layout/sidebar.blade.php`; W = `routes/web.php`; MAIN = `resources/views/backend/layout/main.blade.php`; V/ = `resources/views/backend/`; C/ = `app/Http/Controllers/`; PC = ProductController (Product section) or PurchaseController (Purchase section); SC = SaleController; RC = ReportController (Reports) or ReturnController (Sale return); RPC = ReturnPurchaseController; TC = TransferController; pos = `V/sale/pos.blade.php`. "—" = not recorded in the workpapers / not applicable.

**Item attribute rows (17):** 1 Menu · 2 Submenus · 3 Route (URL / name / Controller@method) · 4 Purpose · 5 Main functionality · 6 Actions · 7 Forms & fields · 8 Table columns · 9 Search/filter · 10 Validation · 11 Endpoints (AJAX) · 12 DB tables · 13 Permissions · 14 Business rules · 15 Known bugs / design problems · 16 Dependencies · 17 Recommendation.

### 1.3 Cross-cutting facts (apply to every item; not repeated per item)

- **Gating mechanism.** Every sidebar gate is `$role_has_permissions_list->where('name', X)->first()`. The list is shared by middleware `Common` (`app/Http/Middleware/Common.php:88-91`, cached per role for 365 days). No `@can` is used.
- **Route middleware.** All app routes are in `['common','auth','active']` (W:140) with **no route-level authorization** (WP1 R7). Most controllers check permission only on `index`, so writes are open to any active user (WP2 finding 7).
- **Global cache bug.** `user_role` and `role_has_permissions` are cached under global keys (`Common.php:75-86`). The `$role` used for the Role Permission link (SB:658) is whoever warmed the cache first. `setPermission` does not clear `role_has_permissions_list{role_id}` (WP2 finding 8).
- **HTML comments do not stop Blade.** `<!-- @if ... route() ... -->` still executes server-side and ships in page source (SB:104-113, 134-140, 178-188, 596-609, 660-734). If `general_settings.modules` contains `woocommerce` or `ecommerce`, every backend page 500s (WP2 §10).
- **Duplicate route blocks.** Sales/purchase/transfer/return/adjustment blocks are registered twice (W:394-605 and W:650-817). The later copy wins (WP1 R4, §3.0).
- **Client-trusted permission list.** Many DataTables build row-action buttons from a browser-posted `all_permission` (WP1 X5).
- **Unauthenticated critical endpoints.** `GET /update-coupon` (truncates all tables and deletes `?data=` dir), `/migrate`, `/cc`, auto-update, developer-section (W:72-123). Scheduler `reset:db` runs every minute (`app/Console/Kernel.php:39-41`). See §4.

---

## 2. Sidebar tree (all 18 groups)

Status: **live** = rendered when the gate passes; **commented** = inside `<!-- -->` (hidden in browser, Blade still evaluated).

| # | Group (SB lines) | Group gate | Child (SB line) → route | Child gate | Status | § |
|---|---|---|---|---|---|---|
| 1 | Dashboard (2) | none | Dashboard (2) → `url('/dashboard')` | none | live | 3.1 |
| 2 | Customer Ecosystem (3-10) | **none** (incl. role 5) | Membership Dashboard (5) → `membership.admin.dashboard` | none | live | 3.2.1 |
| | | | Membership Plans (5) → `membership.admin.plans` | none | live | 3.2.2 |
| | | | Customers (6) → `membership.admin.customers` | none | live | 3.2.3 |
| | | | Membership History (6) → `membership.admin.history` | none | live | 3.2.4 |
| | | | Renewals (7) → `membership.admin.renewals` | none | live | 3.2.5 |
| | | | Reports (7) → `membership.admin.reports` | none | live | 3.2.6 |
| | | | Reward Management (8) → `rewards.admin.dashboard` | none | live | 3.2.7 |
| 3 | Product (23-50) | category ∨ products-index ∨ print_barcode ∨ stock_count ∨ adjustment | Category (27) → `category.index` | category | live | 3.3.1 |
| | | | Product List (30) → `products.index` | products-index | live | 3.3.2 |
| | | | Add Product (35) → `products.create` | products-index ∧ products-add | live | 3.3.3 |
| | | | Print Barcode (39) → `product.printBarcode` | print_barcode | live | 3.3.4 |
| | | | Adjustment List (42) → `qty_adjustment.index` | adjustment | live | 3.3.5 |
| | | | Add Adjustment (43) → `qty_adjustment.create` | adjustment | live | 3.3.6 |
| | | | Stock Count (46) → `stock-count.index` | stock_count | live | 3.3.7 |
| 4 | Purchase (54-74) | purchases-index | Purchase List (57) → `purchases.index` | — | live | 3.4.1 |
| | | | Add Purchase (63, role 6) → `purchases.new.create` | purchases-add | live | 3.4.2 |
| | | | Add Purchase (65, mobile `d-md-none`) → `purchases.new.create` | purchases-add, role≠6 | live | 3.4.2 |
| | | | Add Purchase (66, desktop `d-md-block`) → `purchases.create` | purchases-add, role≠6 | live | 3.4.2 |
| | | | (68, 69) old Add Purchase variants | — | commented | 3.4.2 |
| | | | Import Purchase By CSV (70) → `url('purchases/purchase_by_csv')` | purchases-add | live | 3.4.3 |
| 5 | Sale (89-116) | sales-index ∨ packing_slip_challan ∨ gift_card ∨ coupon ∨ delivery | Sale List (93) → `sales.index` | sales-index | live | 3.5.1 |
| | | | POS (96) → `sale.pos` | sales-add | live | 3.5.2 |
| | | | Add Sale (97) → `sales.create` | sales-add | live | 3.5.3 |
| | | | Import Sale By CSV (98) → `url('sales/sale_by_csv')` | sales-add | live | 3.5.4 |
| | | | Packing Slip List (101) → `packingSlip.index` | packing_slip_challan | live | 3.5.5 |
| | | | Challan List (102) → `challan.index` | packing_slip_challan | live | 3.5.6 |
| | | | Delivery List (105) → `delivery.index` | delivery | commented | 3.6.2 / 4.1 |
| | | | Gift Card List (108) → `gift_cards.index` | gift_card | commented | 4.1 |
| | | | Coupon List (111) → `coupons.index` | coupon | commented | 4.1 |
| | | | Courier List (113) → `couriers.index` | none | commented | 4.1 |
| 6 | Online Orders (121-132) | sales-index ∨ delivery ∨ online-orders | Orders List (125) → `online-sales.index` | sales-index ∨ online-orders | live | 3.6.1 |
| | | | Delivery List (128) → `delivery.index` | delivery | live | 3.6.2 |
| 7 | Manufacturing (133-141) | `general_settings.modules` contains `manufacturing` | Production List (137) → `productions.index`; Add Production (138) → `productions.create` | — | commented (whole group) | 3.7 |
| 8 | Expense (146-159) | expenses-index | Expense Category (149) → `expense_categories.index` | — | live | 3.8.1 |
| | | | Expense List (150) → `expenses.index` | — | live | 3.8.2 |
| | | | Add Expense (155) → `#add-expense` modal | expenses-add | live | 3.8.3 |
| 9 | Income (160-173) | incomes-index | Income Category (163) → `income_categories.index` | — | live | 3.9.1 |
| | | | Income List (164) → `incomes.index` | — | live | 3.9.2 |
| | | | Add Income (169) → `#add-income` modal | incomes-add | live | 3.9.3 |
| 10 | Quotation (177-189) | quotes-index | Quotation List (180) → `quotations.index`; Add Quotation (185) → `quotations.create` [quotes-add] | — | commented (whole group) | 3.10 |
| 11 | Transfer (193-206) | transfers-index | Transfer List (196) → `transfers.index` | — | live | 3.11.1 |
| | | | Add Transfer (201) → `transfers.create` | transfers-add | live | 3.11.2 |
| | | | Import Transfer By CSV (202) → `url('transfers/transfer_by_csv')` | transfers-add | live | 3.11.3 |
| 12 | Return (213-224) | returns-index ∨ purchase-return-index | Sale (217) → `return-sale.index` | returns-index | live | 3.12.1 |
| | | | Purchase (220) → `return-purchase.index` | purchase-return-index | live | 3.12.2 |
| 13 | Accounting (235-253) | account-index ∨ balance-sheet ∨ account-statement ∨ money-transfer | Account List (239) → `accounts.index` | account-index | live | 3.13.1 |
| | | | Add Account (240) → `#add-account` modal | account-index | live | 3.13.2 |
| | | | Money Transfer (243) → `money-transfers.index` | money-transfer | live | 3.13.3 |
| | | | Balance Sheet (246) → `accounts.balancesheet` | balance-sheet | live | 3.13.4 |
| | | | Account Statement (249) → `#account-statement` modal | account-statement | live | 3.13.5 |
| 14 | HRM (272-301) | department ∨ employees-index ∨ attendance ∨ payroll ∨ monthly-payroll ∨ advances-index ∨ holiday ∨ payroll-report | Department (276) → `departments.index` | department | live | 3.14.1 |
| | | | Employee (279) → `employees.index` | employees-index | live | 3.14.2 |
| | | | Attendance (282) → `attendance.index` | attendance | live | 3.14.3 |
| | | | Payroll (285) → `payroll.index` | payroll | live | 3.14.4 |
| | | | Monthly Payroll (288) → `monthly-payroll.index` | monthly-payroll | live | 3.14.5 |
| | | | Advances Payment (291) → `advances-payments.index` | advances-index | live | 3.14.6 |
| | | | Payroll Report (294) → `report.payroll` | payroll-report | live | 3.14.7 |
| | | | Holiday (297) → `holidays.index` | holiday | live | 3.14.8 |
| 15 | People (315-364) | users-index ∨ customers-index ∨ billers-index ∨ suppliers-index | User List (320) → `user.index` | users-index | live | 3.15.1 |
| | | | Add User (325) → `user.create` | users-index ∧ users-add | live | 3.15.2 |
| | | | Customer List (330) → `customer.index` | customers-index | live | 3.15.3 |
| | | | Add Customer (335) → `customer.create` | customers-index ∧ customers-add | live | 3.15.4 |
| | | | Biller List (340) → `biller.index` | billers-index | live | 3.15.5 |
| | | | Add Biller (345) → `biller.create` | billers-index ∧ billers-add | live | 3.15.6 |
| | | | Supplier List (350) → `supplier.index` | suppliers-index | live | 3.15.7 |
| | | | Add Supplier (355) → `supplier.create` | suppliers-index ∧ suppliers-add | live | 3.15.8 |
| | | | Riders List (360) → `riders.index` | **sales-index** | live | 3.15.9 |
| 16 | Reports (415-595) | any of 23 report perms (SB:415) | Summary Report (423, POST form `report.profitLoss`) | profit-loss | live | 3.16.1 |
| | | | Daily Profit & Cost (432, GET form `report.dailyCostAndProfit`) | **none** | live | 3.16.2 |
| | | | Best Seller (438) → `url('report/best_seller')` | best-seller | live | 3.16.3 |
| | | | Product Report (447, GET form `report.product`) | product-report | live | 3.16.4 |
| | | | Daily Sale (453) → `url('report/daily_sale/Y/m')` | daily-sale | live | 3.16.5 |
| | | | Monthly Sale (458) → `url('report/monthly_sale/Y')` | monthly-sale | live | 3.16.6 |
| | | | Daily Purchase (463) → `url('report/daily_purchase/Y/m')` | daily-purchase | live | 3.16.7 |
| | | | Monthly Purchase (468) → `url('report/monthly_purchase/Y')` | monthly-purchase | live | 3.16.8 |
| | | | Sale Report (477, POST form `report.sale`) | sale-report | live | 3.16.9 |
| | | | Challan Report (481) → `report.challan` | **none** | live | 3.16.10 |
| | | | Sale Report Chart (489, POST form `report.saleChart`) | sale-report-chart | live | 3.16.11 |
| | | | Payment Report (498, POST form `report.paymentByDate`) | payment-report | live | 3.16.12 |
| | | | Purchase Report (508, POST form `report.purchase`) | purchase-report | live | 3.16.13 |
| | | | Customer Report (514, modal link) | customer-report | live | 3.16.14 |
| | | | Customer Group Report (519, modal link) | customer-report | live | 3.16.15 |
| | | | Customer Due Report (527, POST form `report.customerDueByDate`) | due-report | live | 3.16.16 |
| | | | Supplier Report (533, modal link) | supplier-report | live | 3.16.17 |
| | | | Supplier Due Report (541, POST form `report.supplierDueByDate`) | supplier-due-report | live | 3.16.18 |
| | | | Warehouse Report (547, modal link) | warehouse-report | live | 3.16.19 |
| | | | Warehouse Stock Chart (552) → `report.warehouseStock` | warehouse-stock-report | live | 3.16.20 |
| | | | Product Expiry Report (557) → `report.productExpiry` | product-expiry-report | live | 3.16.21 |
| | | | Product Quantity Alert (562) → `report.qtyAlert` | product-qty-alert | live | 3.16.22 |
| | | | Product Order Report (567) → `report.OrderqtyAlert` | product-qty-alert | live | 3.16.23 |
| | | | Custom Product Sales Report (572) → `report.customProduct` | **sales-index** | live | 3.16.24 |
| | | | Adjustment Report (576) → `report.adjustment` | **none** | live | 3.16.25 |
| | | | Daily Sale Objective Report (580) → `report.dailySaleObjective` | dso-report | live | 3.16.26 |
| | | | User Report (585, modal link) | user-report | live | 3.16.27 |
| | | | Biller Report (590, modal link) | biller-report | live | 3.16.28 |
| 17 | Addons / WooCommerce / eCommerce (596-610) | role_id≠5 + SaaS/module flags | Addons (598) → `url('addon-list')`; WooCommerce (601) → `woocommerce.index`; eCommerce (604-608) `@include('ecommerce::…')` | — | commented (whole group) | 3.17 |
| 18 | Settings (611-736) | **none** (always shown) | Role Permission (659) → `role.index` | `$role->id <= 2` (global cache) | live | 3.18.1 |
| | | | Warehouse (682) → `warehouse.index` | warehouse | live | 3.18.2 |
| | | | Customer Group (686) → `customer_group.index` | customer_group | live | 3.18.3 |
| | | | Brand (689) → `brand.index` | brand | live | 3.18.4 |
| | | | Unit (692) → `unit.index` | unit | live | 3.18.5 |
| | | | User Profile (700) → `user.profile` (Auth::id()) | none | live | 3.18.6 |
| | | | General Setting (708) → `setting.general` | general_setting | live | 3.18.7 |
| | | | Reward Point Setting (714) → `setting.rewardPoint` | reward_point_setting | live | 3.18.8 |
| | | | Payment Gateways (720) → `setting.gateway` | payment_gateway_setting | live | 3.18.9 |
| | | | POS Settings (723) → `setting.pos` | pos_setting | live | 3.18.10 |
| | | | HRM Setting (726) → `setting.hrm` | hrm_setting | live | 3.18.11 |
| | | | Barcode Settings (729) → `barcodes.index` | barcode_setting | live | 3.18.12 |
| | | | Shipping Setting (732) → `shipping-zones.index` | shipping_setting | live | 3.18.13 |
| | | | SMS Template (660), Custom Field List (662), Discount Plan (666), Discount (669), All Notification (673), Send Notification (678), Tables (684), Currency (695), Tax (698), Create SMS (702), Backup Database (705), Mail Setting (711), SMS Setting (717), Languages (734) | various | commented (14) | 3.18.H |

**Totals (from SB re-read):** 18 groups. **100 live logical menu items** (102 live `<li>` link lines, since Add Purchase renders as 3 role/viewport variants). **27 commented-out `<li>` entries** (Purchase 2 duplicates, Sale 4, Manufacturing 2, Quotation 2, Addons/Woo/eCommerce 3, Settings 14), which make 25 distinct hidden menu items once the 2 Add Purchase duplicates are removed. 3 groups are entirely commented (Manufacturing, Quotation, Addons/Woo/eCommerce).

**Sidebar-level defects** (WP1 §0, WP2 §1):
- Duplicate DOM ids: `purchase-create-menu` ×3 (63, 65, 66), `customer-report-menu` ×2 (513, 518), `profit-loss-report-menu` ×2 (419, 428).
- Sale group renders **empty** when the role has only gift_card/coupon/delivery, because those children are commented out.
- Riders List and Custom Product Sales Report are gated by `sales-index`. Daily Profit & Cost, Challan Report and Adjustment Report have **no gate**.
- No sidebar entries exist for Variant, Labels, Gift Card, Coupon, Courier, Quotation, Cash Register or Product History.

---

## 3. Per-item inventory

Each item below has the 17 attribute rows. Citations come from WP1/WP2 unless marked **[src]** (read directly for this document).

### 3.1 Dashboard (SB:2) — WP1 §1

| Attr | Detail |
|---|---|
| 1 Menu | Dashboard |
| 2 Submenus | none |
| 3 Route | `GET /dashboard` → `HomeController@dashboard` (W:203, unnamed). `GET /` → `HomeController@index`, which redirects to dashboard (HomeController.php:42-46). `GET /home` → `@home` (W:135) |
| 4 Purpose | Landing KPIs for the business |
| 5 Main functionality | Revenue/profit cards, cash-flow chart, monthly summary, yearly report, recent-transaction tabs, best sellers. The customer role (5) gets `backend.customer_index` instead (HomeController.php:88-102). The restaurant kitchen branch is dead (104-111) |
| 6 Actions | Date-range + warehouse filter; tab switching |
| 7 Forms & fields | Filter: start, end, warehouse_id |
| 8 Table columns | Recent Transaction tabs: sale/purchase/quotation/payment (index.blade.php:176-275); best-seller lists (276-340) |
| 9 Search/filter | `dashboard-filter/{start}/{end}/{warehouse_id}` (W:212; HomeController.php:365) |
| 10 Validation | none |
| 11 Endpoints | `/yearly-best-selling-price`, `/yearly-best-selling-qty`, `/monthly-best-selling-qty`, `/recent-sale`, `/recent-purchase`, `/recent-quotation`, `/recent-payment` (W:204-210); `setting/general_setting/change-theme/{c}`; dead `$.ajax(url('/'))` (index.blade.php:515-528) |
| 12 DB tables | sales, product_sales, returns, return_purchases, expenses, incomes, purchases, payments, quotations, products, customers |
| 13 Permissions | The menu item is ungated. Widgets are gated by `revenue_profit_summary`, `cash_flow`, `monthly_summary` and `yearly_report`; some tabs require role_id==1 (184, 274). The AJAX widget endpoints have **no** check (HomeController.php:306-318) |
| 14 Business rules | MTD revenue = Σ(grand_total − shipping) − returns + income. Profit = revenue + purchase_return − avg COGS − expense (HomeController.php:126-161). When `staff_access=='own'` and role>2, data is filtered to the user's own rows (118, 308, 370) |
| 15 Bugs | Toggles MySQL strict mode off and reconnects mid-request (86-88, 234-235, 371-372). The AJAX endpoints leak data that the hidden cards are meant to hide. The Recent Quotation tab remains although quotations are hidden. Dead `url('/')` AJAX call. A second, unauthenticated route is named `dashboard` (W:103, R8). `Common` runs alert/DSO counts on every request (Common.php:68-74) |
| 16 Dependencies | Common middleware (alert counts), general_settings.staff_access, all transactional modules |
| 17 Recommendation | **Redesign**. WP note: keep the sales/profit/expiry/low-stock KPIs, compute them server-side with permission checks, and drop the quotation and restaurant branches |

### 3.2 Customer Ecosystem (SB:3-10) — WP2 §2

**Applies to all 7 items**
- **No authorization** in `C/MembershipManagementController.php` (17 lines) or `C/RewardManagementController.php` (32 lines). Policies are registered (AuthServiceProvider.php:18-20) but never called.
- The group is shown to every role, including customers (role 5).
- Each area is a single Blade view that switches on `$section` (`V/membership/index.blade.php`, `V/rewards/index.blade.php`). Pages are full-page forms with no AJAX.
- Membership routes: prefix `customer-ecosystem/membership`, names `membership.admin.*` (W:1183-1187). Rewards routes: prefix `customer-ecosystem/rewards`, names `rewards.admin.*` (W:1188-1192).

#### 3.2.1 Membership Dashboard (SB:5)

| Attr | Detail |
|---|---|
| 1 Menu | Membership Dashboard |
| 2 Submenus | none |
| 3 Route | GET `customer-ecosystem/membership/` · `membership.admin.dashboard` · `MembershipManagementController@dashboard` :7 |
| 4 Purpose | Loyalty programme overview |
| 5 Main functionality | 4 KPIs (Active, Expired, Renewals due, Revenue), a tier doughnut chart, and the top 25 customers by lifetime spend |
| 6 Actions | view only |
| 7 Forms & fields | — |
| 8 Table columns | Customer, Phone, Lifetime |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | none (full page) |
| 12 DB tables | customer_membership_profiles, customer_memberships, membership_plans, membership_histories, membership_renewals, membership_payments, sales (MembershipReportingService.php:16-53) |
| 13 Permissions | none |
| 14 Business rules | Spend = calendar-year `sales.grand_total` with sale_status=1 and payment_status=4, minus returns (MembershipEligibilityService.php:15-27). Tier = the highest active automatic plan with threshold ≤ spend. Premium "Red" is kept while a paid, unexpired membership_payments row exists. Default tier: Silver |
| 15 Bugs | The "Expired" KPI is always 0 (status never set to `expired`). Chart.js comes from an unpinned CDN |
| 16 Dependencies | `Sale::saved` / `Returns::saved` dispatch `MembershipReviewRequested`; the listener runs synchronously (QUEUE_CONNECTION=sync) inside the POS request (Sale.php:12-16). Scheduler jobs (Kernel.php:42-48) |
| 17 Recommendation | **Redesign**. WP note: valuable for a pharmacy loyalty programme. Add permissions, make the listeners async or failure-tolerant, and use rolling 12-month qualification |

#### 3.2.2 Membership Plans (SB:5)

| Attr | Detail |
|---|---|
| 1 Menu | Membership Plans |
| 2 Submenus | none |
| 3 Route | GET `plans` · `membership.admin.plans` · :8. POST `plans` (`.plans.store`) and PUT `plans/{plan}` (`.plans.update`) → `savePlan` :13. DELETE `plans/{plan}` (`.plans.destroy`) → `deletePlan` :14 |
| 4 Purpose | Define membership tiers and their benefits |
| 5 Main functionality | Accordion edit list plus a collapsed create form |
| 6 Actions | Create, edit, soft delete. Delete is blocked when the plan is the default or has ever been assigned (MembershipPlanService.php:39-47) |
| 7 Forms & fields | name, slug, type (automatic/premium), minimum_annual_purchase, annual_fee, duration_months, sort_order, color, is_active, benefit_values[] |
| 8 Table columns | — (accordion layout) |
| 9 Search/filter | — |
| 10 Validation | name required, ≤100. slug required, alpha_dash, **no unique rule while the DB index is unique, so a duplicate slug returns 500**. Fees ≥0. duration ≥1 |
| 11 Endpoints | none |
| 12 DB tables | membership_plans, membership_plan_benefit, membership_benefits |
| 13 Permissions | none |
| 14 Business rules | Seeded tiers: Silver, Gold, Platinum, Red. Slug `red` is hard-coded (MembershipEngine.php:114) |
| 15 Bugs | Editing silently disables benefits: booleans render as `text_value=''`, and a numeric 0 is saved as `enabled=false` (V/membership/index.blade.php:9; MembershipDeliveryService.php:11-12). The create form hard-codes sort_order=50. Validation errors are never shown. **Deactivating the default plan makes `firstOrFail` throw, so every POS sale fails** (MembershipEngine.php:48) |
| 16 Dependencies | POS sale save (listener); Reward engine (earn rates come from plan benefits) |
| 17 Recommendation | **Redesign** |

#### 3.2.3 Customers (membership) (SB:6)

| Attr | Detail |
|---|---|
| 1 Menu | Customers |
| 2 Submenus | none |
| 3 Route | GET `customers` · `membership.admin.customers` · :9. POST `customers/review` · `.customers.review` → `bulkReview` :15 |
| 4 Purpose | View customer tiers and force a re-evaluation |
| 5 Main functionality | Searchable list. "Review selected" runs `MembershipEngine::review` for each selected customer |
| 6 Actions | search, select, bulk review |
| 7 Forms & fields | q, status, customers[] |
| 8 Table columns | ☐, Customer, Tier, Current-year spend, Lifetime spend, Status (25 per page) |
| 9 Search/filter | q, status |
| 10 Validation | customers[] required, max 100, each must exist |
| 11 Endpoints | none |
| 12 DB tables | all membership tables |
| 13 Permissions | none |
| 14 Business rules | as 3.2.1 |
| 15 Bugs | Jan-1 downgrade: the annual reset at 00:15 followed by the review at 01:15 drops every automatic tier to Silver |
| 16 Dependencies | customers, sales, returns |
| 17 Recommendation | **Redesign** |

#### 3.2.4 Membership History (SB:6)

| Attr | Detail |
|---|---|
| 1 Menu | Membership History |
| 2 Submenus | none |
| 3 Route | GET `history` · `membership.admin.history` · :10 |
| 4 Purpose | Audit trail of tier changes |
| 5 Main functionality | Read-only list |
| 6 Actions | view |
| 7 Forms & fields | — |
| 8 Table columns | Date, Customer, Event, From, To, Reason |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | none |
| 12 DB tables | membership_histories |
| 13 Permissions | none |
| 14 Business rules | Rows are written by MembershipEngine |
| 15 Bugs | — |
| 16 Dependencies | MembershipEngine |
| 17 Recommendation | **Migrate** |

#### 3.2.5 Renewals (SB:7)

| Attr | Detail |
|---|---|
| 1 Menu | Renewals |
| 2 Submenus | none |
| 3 Route | GET `renewals` · `membership.admin.renewals` · :11 |
| 4 Purpose | Premium membership renewal requests |
| 5 Main functionality | Read-only list. Approve/confirm exists **only** in the API (`routes/api.php:91`) |
| 6 Actions | view |
| 7 Forms & fields | — |
| 8 Table columns | Requested, **raw Customer ID**, Period, Fee, Status |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | API only |
| 12 DB tables | membership_renewals |
| 13 Permissions | none |
| 14 Business rules | `renewal-reminders` is scheduled hourly but has no listener |
| 15 Bugs | Early renewal shortens a Red membership (MembershipRenewalService.php:50-79). The raw customer id is shown instead of a name |
| 16 Dependencies | API consumers, membership_payments |
| 17 Recommendation | **Redesign** |

#### 3.2.6 Reports (membership) (SB:7)

| Attr | Detail |
|---|---|
| 1 Menu | Reports |
| 2 Submenus | none |
| 3 Route | GET `reports` · `membership.admin.reports` · :12 |
| 4 Purpose | Membership reporting |
| 5 Main functionality | **Identical to the dashboard** |
| 6 Actions | view |
| 7 Forms & fields | from/to (ignored) |
| 8 Table columns | as 3.2.1 |
| 9 Search/filter | from/to, ignored by the controller |
| 10 Validation | — |
| 11 Endpoints | Export exists only in the API (`api.php:89`) |
| 12 DB tables | as 3.2.1 |
| 13 Permissions | none |
| 14 Business rules | — |
| 15 Bugs | Filters are ignored; no export |
| 16 Dependencies | MembershipReportingService |
| 17 Recommendation | **Redesign**: merge into the dashboard with working filters and export |

#### 3.2.7 Reward Management (SB:8)

| Attr | Detail |
|---|---|
| 1 Menu | Reward Management |
| 2 Submenus | Reached from links inside the page, not the sidebar: Settings, Wallets, Wallet detail/Adjust, Transactions, Campaigns, Expiry, Reports/Export |
| 3 Route | GET `customer-ecosystem/rewards/` · `rewards.admin.dashboard` :19. `settings` GET :20 / POST `saveSettings` :21. `wallets` :22. `wallets/{customer}` :23. POST `wallets/{customer}/adjust` :24. `transactions` :25. `campaigns` GET :26 / POST :27. `expiry` :28. `reports` :29. `reports/export` :30 |
| 4 Purpose | Points programme administration |
| 5 Main functionality | 6 KPIs and the top 20 wallets; settings; wallet adjust (credit or debit with a reason); transaction ledger; campaigns (create only); FIFO expiry lots; CSV export |
| 6 Actions | filter, export CSV, save settings, adjust a wallet, create a campaign |
| 7 Forms & fields | **Settings:** minimum_redemption_points, maximum_redemption_percentage, expiry_mode, expiry_months, pending_days. **Adjust:** direction, points, reason. **Campaign:** name, code, dates, rule_type, value, minimum_order_amount |
| 8 Table columns | **Dashboard:** Customer, Available, Earned, Redeemed. **Wallets:** Customer, Available, Pending, Lifetime, Open. **Transactions:** Date, Customer, Type, Points, Balance, Order. **Expiry:** Customer, Remaining, Available, Expires, Status. **Export:** Date, Customer, Phone, Type, Points, Balance, Status, Sale |
| 9 Search/filter | from/to (not validated on the dashboard), q, type (no UI control) |
| 10 Validation | Settings: all required; min ≥0, max % 0-100, months 1-120, pending days 0-365. Adjust: points >0, reason 5-1000 chars. Campaign: code alpha_dash unique, value >0. Export: to ≥ from |
| 11 Endpoints | none (full pages). Campaign edit/deactivate exist only in the API (`api.php:103-104`) |
| 12 DB tables | reward_wallets, reward_transactions, reward_expiry_batches, reward_settings, reward_adjustments, reward_campaigns, reward_campaign_rules |
| 13 Permissions | none |
| 14 Business rules | Earn = (paid grand_total − returns) / reward_spend × reward_points. Seeded rates: Silver 0, Gold 1 per 100 BDT, Platinum 1.5, Red 2. Campaigns add multipliers or bonuses. Points go into pending lots first, then FIFO expiry lots |
| 15 Bugs | **Four parallel ledgers**: L1 `customers.points`, L2 `reward_point_tiers`, L3 `customer_membership_profiles.reward_balance`, L4 `reward_wallets`. A POS sale earns in L1, L3 and L4 but redeems only from L1. The idempotency key is a hash of the desired total, so 10→5→10 never re-credits (RewardEngine.php:44,125). A reversal ignores pending lots (154-157). A double-submitted adjust credits twice. A NULL minimum_order_amount returns 500 in strict mode |
| 16 Dependencies | POS (L1 earn and redeem, SC:1301-1306, 1586, 1641); Settings → Reward Point Setting (L1); membership plan benefits |
| 17 Recommendation | **Redesign**. WP note: consolidate on L4 and retire L1-L3 and the legacy Reward Point Setting |

### 3.3 Product group (SB:23-50) — WP1 §2

#### 3.3.1 Category (SB:27)

| Attr | Detail |
|---|---|
| 1 Menu | Category |
| 2 Submenus | none |
| 3 Route | GET `/category` · `category.index` · `CategoryController@index`. Resource plus `category/import` (`category.import`), `category/deletebyselection` and `category/category-data` (W:296-303) |
| 4 Purpose | Product category master |
| 5 Main functionality | One page holds the list, the add form, an edit modal (`category.update` with dummy id, PUT, :42) and an import modal (:128) |
| 6 Actions | add, edit, delete, bulk delete, CSV import |
| 7 Forms & fields | name\*, image, parent_id, is_sync_disable, featured, icon, page_title, short_description (the last two are ecommerce-only) |
| 8 Table columns | checkbox, Category, Parent Category, Number of Product, Stock Quantity, Stock Worth (Price/Cost), Action |
| 9 Search/filter | name LIKE; ordering by id/name/parent_id/is_active |
| 10 Validation | name max:255 and unique among active; image mimes; icon mimetypes. `required` is not enforced server-side (:134, :237) |
| 11 Endpoints | POST `category/category-data` (:206); GET `category/{id}/edit` returns JSON (:186); POST `category/deletebyselection` (:307) |
| 12 DB tables | categories, products |
| 13 Permissions | `category`, in the sidebar and on index only (CategoryController:27) |
| 14 Business rules | Deleting a category deactivates every product in it (:397-401, :377-381) |
| 15 Bugs | Cascade deactivation of products. Writes are unchecked. `DB::table()->update($request->except())` bypasses $fillable (:250, :320). Update is blocked unless `env('USER_VERIFIED')` (:234; behaviour with a cached config is UNVERIFIED). icon is not fillable. Import's parent `firstOrNew` is never saved (:355). N+1 of about 5 queries per row. create/show are routed but not implemented |
| 16 Dependencies | Products; the POS category filter (dead UI) |
| 17 Recommendation | **Migrate**. WP note: remove the cascade delete |

#### 3.3.2 Product List (SB:30)

| Attr | Detail |
|---|---|
| 1 Menu | Product List |
| 2 Submenus | none |
| 3 Route | GET `/products` · `products.index` · `ProductController@index` (125-155) |
| 4 Purpose | Medicine/product master list |
| 5 Main functionality | Yajra DataTable; view modal with variants and warehouse batches; import, bulk update, bulk download |
| 6 Actions | **Row:** View, Edit (`products-edit`), History (`product_history`), Barcode (`print_barcode`), Delete (`products-delete`) (PC:362-399). **Top (products-add):** Add, Import, Bulk Update, Download bulk. **DataTable:** excel, print, bulk delete, colvis |
| 7 Forms & fields | Import modal (file; sample file via `sample.download`, W:180-194); Bulk Update modal (file) |
| 8 Table columns | checkbox, name, code, brand, category, qty (global `products.qty` + unit), price, cost, expiry date, custom fields, options (index:79-88) |
| 9 Search/filter | Search on name, code, category name and brand name; category filter `#pr_category`. `warehouse_id` is sent but ignored |
| 10 Validation | none on import or bulk update |
| 11 Endpoints | POST `products/product-data` (W:237, PC:302-360); `products/getdata/{id}/{variant}`; `products/variant-data/{id}`; `products/product_warehouse/{id}`; POST `importproduct` (1276); POST `bulkUpdateproduct` (1602); GET `download-bulk` (1681); POST `products/deletebyselection` (1876) |
| 12 DB tables | products, product_warehouse, product_batches, product_variants, brands, categories, units |
| 13 Permissions | `products-index`, on index only (PC:129). Row buttons are built from the **client-posted** `all_permission` (index:495, PC:356). destroy and deleteBySelection are unchecked |
| 14 Business rules | Delete is a soft deactivate plus image delete, with no stock or transaction check. Import matches on **name**, resets qty to 0, adds a new warehouse-1 row every time and forces expire_date to 2999-12-31. Bulk update changes box/strip/piece qty and cost by code (inside a transaction) |
| 15 Bugs | `data['product']` is never returned, so the View modal and bulk delete are broken (UNVERIFIED at runtime). Import drops box/stripe/piece (not fillable; `striqty` typo), has no transaction, and sets 8000M limits. Download exports cost and profit to any user, has an N+1, a hard-coded `whereDate('updated_at','!=','2025-07-08')`, reads non-existent `quantity`/`expiry_date` properties, and serves CSV as xlsx. The three stock stores are never reconciled |
| 16 Dependencies | POS, purchases, adjustments, barcode printing |
| 17 Recommendation | **Redesign** |

#### 3.3.3 Add Product (SB:35)

| Attr | Detail |
|---|---|
| 1 Menu | Add Product |
| 2 Submenus | none. Edit is hidden and reached from the row action |
| 3 Route | GET `products/create` · `products.create` · PC@create (400); POST `products` → PC@store (419). **Edit:** GET `products/{id}/edit` → PC@edit (973); POST `products/update` → PC@updateProduct (1004) (W:245). The resource PUT route points at a missing `update()` |
| 4 Purpose | Create or edit the medicine master |
| 5 Main functionality | AJAX/Dropzone form; entering initial stock auto-creates a purchase |
| 6 Actions | generate code, add brand or category inline, save |
| 7 Forms & fields | warehouse_select\*, type (hidden, standard), name\*, code\* (Generate → `products/gencode`), barcode_symbology\*, file, brand_id, category_id\*, unit_id\*, sale_unit_id, purchase_unit_id, cost\*, price\*, wholesale_price, expire_date, alert_quantity, stock[]\*, box_qty, stripe_qty, piece_qty, custom fields, product_details. The tax field is commented out (:1513-1521) |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | Only code and name: max:255, unique among active (421-434, 1007-1021). cost, price, category and unit are not required server-side |
| 11 Endpoints | `products/gencode`, `products/saleunit/{id}`, `products/lims_product_search`, inline `brand.store` and `category.store` |
| 12 DB tables | products, product_warehouse, purchases, product_purchases, payments |
| 13 Permissions | The sidebar requires products-index ∧ products-add. create checks products-add (403). **store and updateProduct are unchecked** |
| 14 Business rules | The name is HTML-escaped before saving. The image is forced to `zummXD2dvAtI.png` and featured=1. Initial stock triggers `autoPurchase` (472-551), which creates a purchase (`pr-`), product_purchases, a **Cash payment with account_id 0** and product_warehouse rows, but **no product_batches row** |
| 15 Bugs | **Edit wipes the expiry date**: the input is empty (edit.blade.php:240), and PC:1031 saves it. Edit overwrites warehouse stock and can zero `products.qty` (1047-1055), with no audit. box/strip/piece are dropped by $fillable (Product.php:16-75). `Role::firstOrCreate` (402, 975). Name uniqueness blocks the same brand in different strengths. No generic, strength, manufacturer or rack fields exist |
| 16 Dependencies | Units, brands, categories, warehouses, purchase and payment tables |
| 17 Recommendation | **Redesign**. WP note: use a FormRequest, a proper medicine master (generic, strength, form, manufacturer, rack, pack size), and allow stock only through purchases or adjustments |

#### 3.3.4 Print Barcode (SB:39)

| Attr | Detail |
|---|---|
| 1 Menu | Print Barcode |
| 2 Submenus | none |
| 3 Route | GET `/products/print_barcode` · `product.printBarcode` · PC@printBarcode (1162-1180). Preview: GET `/labels/print` · `print.label` · LabelsController@printLabel |
| 4 Purpose | Shelf and product label printing |
| 5 Main functionality | Add products, choose label options and a template, then preview and print |
| 6 Actions | add product, preview, print |
| 7 Forms & fields | Per label: name, price, promo price, business name, brand, sizes. barcode_setting select |
| 8 Table columns | product rows (name, code, qty) |
| 9 Search/filter | product search |
| 10 Validation | none |
| 11 Endpoints | `products/lims_product_search` (:221) → limsProductSearch (1197-1245). `/labels/print` receives the whole form in the query string (:268-271) |
| 12 DB tables | products, barcodes |
| 13 Permissions | `print_barcode` in the sidebar only; **no server-side check** |
| 14 Business rules | `?data=code (name)`, sent by the product list row action, preloads rows |
| 15 Bugs | `$default` is not passed to the view (1176). The label prints whatever name and price the **client sends** (LabelsController:54-62). limsProductSearch errors when no product is found (1244). `print_barcode_backup` and `print_barcode_rongta` are unused copies |
| 16 Dependencies | Barcode Settings (3.18.12); Labels (hidden, §4) |
| 17 Recommendation | **Redesign**. WP note: preserve the function, rebuild the code |

#### 3.3.5 Adjustment List (SB:42)

| Attr | Detail |
|---|---|
| 1 Menu | Adjustment List |
| 2 Submenus | none |
| 3 Route | GET `/qty_adjustment` · `qty_adjustment.index` · AdjustmentController@index. Routes at W:566-572, duplicated at W:781-786 |
| 4 Purpose | Stock adjustment history |
| 5 Main functionality | Full, unpaginated list with edit and delete |
| 6 Actions | Edit, Delete, bulk delete |
| 7 Forms & fields | — |
| 8 Table columns | checkbox, Date, Reference, Warehouse, Products, Note, Action (V/adjustment/index:18-24) |
| 9 Search/filter | none. The `staff_access=own` filter is commented out (:25-27) |
| 10 Validation | none |
| 11 Endpoints | POST `qty_adjustment/deletebyselection` (:183) |
| 12 DB tables | adjustments, product_adjustments, products, product_warehouse, product_variants |
| 13 Permissions | `adjustment`, on index only (AdjustmentController:24) |
| 14 Business rules | Delete reverses the stock lines |
| 15 Bugs | Unpaginated. destroy and bulk delete are unchecked hard deletes with no transaction |
| 16 Dependencies | Adjustment Report (3.16.25), Stock Count |
| 17 Recommendation | **Redesign**. WP note: replace with a stock ledger |

#### 3.3.6 Add Adjustment (SB:43)

| Attr | Detail |
|---|---|
| 1 Menu | Add Adjustment |
| 2 Submenus | none (edit is hidden) |
| 3 Route | GET `qty_adjustment/create` · `qty_adjustment.create`; POST → store (178); edit/update (260) |
| 4 Purpose | Manual stock correction |
| 5 Main functionality | Product picker with + or − per line |
| 6 Actions | add rows, save |
| 7 Forms & fields | warehouse_id\*, document (file). Per row: unit_cost[] (hidden), qty[], action[] (+/−), product_code[], product_id[]. Also total_qty, item, note, and a hidden stock_count_id when opened from Stock Count |
| 8 Table columns | line grid |
| 9 Search/filter | product search |
| 10 Validation | **none** (store 178, update 260) |
| 11 Endpoints | GET `/products/get-search-library` (:133); `qty_adjustment/lims_product_search` (`product_adjustment.search`, :264); `qty_adjustment/getproduct/{id}` |
| 12 DB tables | adjustments, product_adjustments, products, product_warehouse, product_variants |
| 13 Permissions | `adjustment` in the sidebar; store and update are unchecked |
| 14 Business rules | Updates products.qty and the `product_warehouse.first()` row for product + warehouse (product_batch_id is ignored). Update reverses the old lines, then applies the new ones |
| 15 Bugs | No transaction: a null row is fatal (232/239) after stock has partly changed. The cost join uses `product_purchases.product_id = purchases.id`, the wrong key (69, 91, 148, 157). The client unit_cost is trusted (200). user_id is not fillable. The document keeps its original filename with no type check (191-192; whether it is executable is UNVERIFIED). Batch qty is untouched |
| 16 Dependencies | Stock Count (`stock-count/{id}/qty_adjustment`) |
| 17 Recommendation | **Redesign** |

#### 3.3.7 Stock Count (SB:46)

| Attr | Detail |
|---|---|
| 1 Menu | Stock Count |
| 2 Submenus | none |
| 3 Route | GET `/stock-count` · `stock-count.index` · StockCountController. POST `stock-count/finalize`; GET `stock-count/stockdif/{id}`; GET `stock-count/{id}/qty_adjustment` (W:1087-1094) |
| 4 Purpose | Compare a physical count with system stock |
| 5 Main functionality | Generate an expected-qty CSV, upload the counted CSV, show the differences, pre-fill an adjustment |
| 6 Actions | create a count, finalize, view the difference, create an adjustment |
| 7 Forms & fields | **Create:** warehouse_id\*, type (full/partial), category_id[], brand_id[]. **Finalize:** final_file\* (CSV), stock_count_id, note |
| 8 Table columns | Date, Reference, Warehouse, Category, Brand, Type, Initial File, Final File, Action |
| 9 Search/filter | — |
| 10 Validation | The finalize extension check uses only the client filename |
| 11 Endpoints | `stockdif/{id}`, `{id}/qty_adjustment` |
| 12 DB tables | stock_counts, product_warehouse, products. Files are written to `public/stock_count/` |
| 13 Permissions | `stock_count`, on index only (:21) |
| 14 Business rules | Expected qty = a snapshot of product_warehouse.qty taken at creation |
| 15 Bugs | The CSV is built by string concatenation and `explode`, so commas in product names shift columns (65-75). Public files with predictable names leak stock levels. The snapshot goes stale. Batch rows produce duplicate codes. A code LIKE fallback can pick the wrong product (113). An unknown code is fatal (162). destroy is empty |
| 16 Dependencies | Add Adjustment |
| 17 Recommendation | **Redesign** |

### 3.4 Purchase group (SB:54-74) — WP1 §3

#### 3.4.1 Purchase List (SB:57)

| Attr | Detail |
|---|---|
| 1 Menu | Purchase List |
| 2 Submenus | none. Hidden row actions: Edit, Duplicate, payments (§4) |
| 3 Route | GET `/purchases` · `purchases.index` · PurchaseController@index (PC:41) |
| 4 Purpose | List purchases/POs with totals, payments and returns |
| 5 Main functionality | Server-side DataTable with a totals footer, view modal, payment modals and bulk delete |
| 6 Actions | **Row:** View; Duplicate (`purchases-add`); Edit (`purchases-edit`); View Payment (`purchase-payment-index`); Add Payment (`purchase-payment-add`); Delete (`purchases-delete`) (PC:878-912). **Header:** Add (role 6 → `purchases.new.create`, other roles → `purchases.create`) and Import |
| 7 Forms & fields | **Add Payment modal:** paying_amount, amount, paid_by_id (1 Cash / 3 Credit Card / 4 Cheque), cheque_no, account_id, payment_note, purchase_id. The Edit Payment modal has the same fields |
| 8 Table columns | checkbox, Date, Reference, Supplier, Purchase Status, Grand Total, Returned Amount, Paid, Due, Payment Status, custom fields, Action (index.blade:77-92) |
| 9 Search/filter | Date range (default last 1 year, PC:61-68), warehouse, purchase status (0 All / 1 Recieved / 2 Partial / 3 Pending / 4 Ordered), payment status (0 / 1 Due / 2 Paid). Sortable by created_at, reference_no, grand_total, paid_amount |
| 10 Validation | none on payments or bulk delete |
| 11 Endpoints | POST `purchases/purchase-data` (PC:705-956); GET `purchases/product_purchase/{id}` (PC:958); POST `purchases/add_payment` (1541); GET `purchases/getpayment/{id}` (1597); POST `purchases/updatepayment` (1652); POST `purchases/deletepayment` (1727); POST `purchases/deletebyselection` (1757) |
| 12 DB tables | purchases, product_purchases, payments, payment_with_cheque, payment_with_credit_card, accounts, suppliers, warehouses, users, currencies, return_purchases, purchase_product_return, custom_fields, pos_setting |
| 13 Permissions | `purchases-index` (PC:45). destroy checks `purchases-delete` (PC:1829). deleteBySelection, all payment endpoints, store, update, import and purchaseData have **no check**. Buttons come from the client-posted `all_permission` (PC:887-906) |
| 14 Business rules | Due = grand_total − returned − paid (PC:873). On payment, paid_amount += amount; payment_status = 1 when the balance ≠ 0, else 2 (PC:1545-1551). The payment reference is `ppr-…`. Role 6 sees only its own rows (PC:729-731) |
| 15 Bugs | Overpayment is allowed. Returns are ignored in payment_status, so a partly returned PO stays Due forever. The Credit Card path makes a **real Stripe USD charge** for supplier payments (1576-1590). `Payment::latest()->first()` race (1573). No transaction. returned_amount is an N+1 (870). The detail JSON is hand-built (933) |
| 16 Dependencies | Suppliers, Accounts, Purchase Return, Supplier Due report |
| 17 Recommendation | **Redesign**. WP note: server-side totals, a payment ledger with due that includes returns, remove Stripe |

#### 3.4.2 Add Purchase (SB:63 / 65 / 66; commented 68-69)

| Attr | Detail |
|---|---|
| 1 Menu | Add Purchase. It renders as 3 variants: role 6 → create-new (63); other roles on mobile → create-new (65); other roles on desktop → create (66) |
| 2 Submenus | none. Edit and Duplicate are hidden (§4) |
| 3 Route | **Desktop:** GET `/purchases/create` · `purchases.create` · PC@create (105). **Mobile / role 6:** GET `/purchases-new/create` · `purchases.new.create` · PC@createNew (132) (W:543/760). Both submit POST `purchases` → PC@store (243-486) |
| 4 Purpose | Create a purchase or PO (desktop) or a pending order request (mobile, supplier portal) |
| 5 Main functionality | **create:** full form; an admin can receive stock directly. **create-new:** always a Pending PO for warehouse 1 with no stock effect; the cart is kept in `localStorage.purchase_products` (create-new.blade:506-855) |
| 6 Actions | search product, edit line, save |
| 7 Forms & fields | **create:** Date, Reference (auto), Bill No. For role 1 only: warehouse and supplier selects; other roles get hidden `supplier_id = role_id` (**bug**, create.blade:72) and warehouse_id=1. Status (role 1: Recieved/Partial/Pending/Ordered; others: Pending/Ordered), document, currency, exchange rate, order tax, discount, shipping, note. **Lines:** qty, recieved, batch_no, expired_date (required if is_batch), net unit cost, discount, tax, subtotal. **create-new:** Reference only; supplier = user.supplier_id, warehouse 1, status 3; shipping and tax fixed at 0 |
| 8 Table columns | Line grid: product, qty, recieved, batch, expiry, cost, discount, tax, subtotal |
| 9 Search/filter | Autocomplete `GET search-product?term=` → getProductList (PC:170). Selection loads `purchases/lims_product_search` (PC:1115) or `purchases-new/lims_product_search/search` (W:545) |
| 10 Validation | **none** server-side. Totals are trusted from the client and mass-assigned (`Purchase::create($request->except('document'))`) |
| 11 Endpoints | `search-product`, `purchases/lims_product_search`, `purchases-new/lims_product_search/search` |
| 12 DB tables | purchases, product_purchases, product_batches, product_warehouse, products, product_variants, custom_fields |
| 13 Permissions | `purchases-add` on both create pages (PC:108, 138). **store is unchecked** |
| 14 Business rules | Runs in a transaction. Stock increases by `recieved` × unit conversion (PC:342-348), so Pending/Ordered POs post 0. A batch is matched on product_id + batch_no (warehouse-agnostic): its qty is added and **expired_date overwritten**, or a new batch is created (352-371). products.cost is never updated. No Payment row is created |
| 15 Bugs | A non-batch purchase can add qty to an arbitrary batch row and set its product_batch_id to null (417-429). create-new loads the full product list twice (156-157) and passes warehouses as `$lims_supplier_list`. Errors redirect to `/purchases/create` even from create-new. Duplicate DOM id `purchase-create-menu` |
| 16 Dependencies | Suppliers (role 6 = supplier-linked user, UNVERIFIED), AI draft → PO (3.16.23), Edit-to-receive workflow |
| 17 Recommendation | **Redesign**. WP note: merge into one PO workflow (draft → ordered → receive, partial GRN), mobile-friendly, with role-1-only receiving enforced server-side |

#### 3.4.3 Import Purchase By CSV (SB:70)

| Attr | Detail |
|---|---|
| 1 Menu | Import Purchase By CSV |
| 2 Submenus | none |
| 3 Route | GET `purchases/purchase_by_csv` → PC@purchaseByCsv (488); POST `importpurchase` · `purchase.import` → importPurchase (502) |
| 4 Purpose | Bulk-create a purchase from a CSV |
| 5 Main functionality | Upload a CSV plus header fields; lines and totals are computed server-side |
| 6 Actions | upload, submit |
| 7 Forms & fields | warehouse (required), supplier, status (1 Recieved / 3 Pending / 4 Ordered), document, CSV file, order tax rate, order discount, shipping, note. Sample: `public/sample_file/sample_purchase_products.csv` |
| 8 Table columns | CSV: product_code, quantity, purchase_unit_code, cost, discount_per_unit, tax_name (+ optional IMEI) |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | POST `importpurchase` |
| 12 DB tables | purchases, product_purchases, products, product_warehouse |
| 13 Permissions | `purchases-add` on the page; **import is unchecked** |
| 14 Business rules | Runs in a transaction. Products are found by active code. Status 1 updates products.qty and the first product_warehouse row. tax_method 1 = exclusive, 2 = inclusive |
| 15 Bugs | **No batch or expiry support.** A null tax_method throws. `Purchase::latest()` race (599). qty is cast to int. Partial status is not offered |
| 16 Dependencies | Products, units, taxes |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: migrate with batch/expiry columns, or retire |

### 3.5 Sale group (SB:89-116) — WP1 §4-5

#### 3.5.1 Sale List (SB:93)

| Attr | Detail |
|---|---|
| 1 Menu | Sale List |
| 2 Submenus | none. Hidden: Edit Sale, draft→POS, invoice, payments (§4) |
| 3 Route | GET `/sales` · `sales.index` · SaleController@index (SC:92). Data: POST `/sales/sale-data` → saleData (SC:162) |
| 4 Purpose | Back-office list of POS sales |
| 5 Main functionality | Server-side DataTable limited to `sale_type='pos'` (index.blade:1085), with payments, returns, packing slip, delivery, SMS and WhatsApp |
| 6 Actions | Generate Invoice, View, Edit (drafts go to POS via `sales/{id}/create`), Create Packing Slip (if `is_packing_slip` and status 2/5), View/Add Payment, Add Return, Send SMS, WhatsApp, Add Delivery, Delete; bulk delete; Download Excel |
| 7 Forms & fields | **Modals:** sale-details, packing-slip (→ `packingSlip.store`), view-payment, add/edit-payment (Cash, Gift Card, Credit Card, Cheque, Paypal, Deposit, Points; account), add-delivery (status, courier, file), send-sms (template) |
| 8 Table columns | checkbox, date (Asia/Dhaka), reference, Billed By, customer (+phone), products, total qty, grand total, paid, due, payment status, custom fields, options |
| 9 Search/filter | Date range (default 1 year), warehouse, sale_status (0/1/2/4; no 3 Draft or 5), payment_status, payment_method. Search: numeric = `reference_no LIKE %x`, anything else is parsed as a date, so name/phone search does not work (290-296) |
| 10 Validation | none on payments or delete |
| 11 Endpoints | `sales/get-sold-items/{id}`, `sales/getpayment/{id}`, `sales/product_sale/{id}` (**404 locally**, X3a), `delivery/create/{id}`, `sales/deletebyselection`, `sales/export-all` (**no route → 500**, X4) |
| 12 DB tables | sales, product_sales, payments, payment_with_{gift_card,cheque,credit_card,paypal}, customers, deliveries, returns, product_returns, units, custom_fields, sms_templates, gift_cards, accounts, couriers |
| 13 Permissions | `sales-index` or role ≤2 (SC:95). saleData, update, destroy, deleteBySelection, payments, invoice, SMS/mail/WhatsApp are **unchecked**. Buttons come from client `all_permission`. Bulk delete is also gated on `env('USER_VERIFIED')` |
| 14 Business rules | sale_status: 1 Completed, 2 Pending, 3 Draft, 4 Returned, 5 Processing. payment_status: 1 Pending, 2 Due, 3 Partial, 4 Paid. addPayment sets only 2 or 4. Points payment deducts ceil(amount/per_point_amount) |
| 15 Bugs | Delete **decrements** batch stock instead of restoring it (4629, 4775). Returns are double-restored. Stock is restored only for status 1, so Processing sales lose stock. Earned points are not reversed. Coupon `used -= 1` without a null check. No transaction. deletePayment flashes `not_permitted` on success |
| 16 Dependencies | POS, Packing Slip, Delivery, Sale Return, Accounts, gift cards |
| 17 Recommendation | **Redesign**. WP note: fix X2-X5 (local) and add server-side authorization; one transactional stock-reversal service |

#### 3.5.2 POS (SB:96) — WP1 §5

| Attr | Detail |
|---|---|
| 1 Menu | POS (also a header button, MAIN:303) |
| 2 Submenus | none. Panels: header, top fields, search row, cart, totals, action bar, 14 modals |
| 3 Route | **Local source:** `GET pos/{id?}` · `sale.pos` and `pos` · `pos.index` → SaleController@posSale, inside `prefix('report')`, so the URL is `/report/pos` (W:617-618). **Production:** `/pos` exists (redirects to login) and `/report/pos` is 404, so the routing differs (§1.1). Submit: POST `/sales` → SC@store (1152-1686) |
| 4 Purpose | Counter sale for the pharmacy |
| 5 Main functionality | Product search (Select2, camera vision, barcode); cart with unit/batch; discount; cash payment; draft/hold; recent transactions; invoice printing; cash-register details |
| 6 Actions | add product, edit line (role ≤2 only), order discount, draft, cancel, Cash pay, print last receipt, recent transactions, today sale/profit, add customer. Coupon, tax and shipping buttons are commented out (739-747). Only **Cash** is reachable (754-771) |
| 7 Forms & fields | Date (editable, so back-dating is possible), warehouse, biller, customer (+add), reference no, currency/exchange rate, custom fields. Cart: product, batch, price, qty, subtotal. Payment: paying/paid amount, account. Custom product (product_id 0) |
| 8 Table columns | Cart: Product (name, code, in-stock, batch), Brand, Price, Qty, Subtotal. Totals: Items, Total, Discount |
| 9 Search/filter | Select2 `/products/get-search-library` (name or code LIKE, active, price>0, limit 20; **no generic-name search**; global qty). Vision search. Category/brand grid is dead UI |
| 10 Validation | Server: only `reference_no` unique when supplied, plus a document extension whitelist (SC:1157-1163). Every money value is client-trusted. Vision: image jpeg/png/webp ≤4MB ≤4096px |
| 11 Endpoints | Working: `/products/get-search-library`, `/sales/recent-sale`, `/sales/recent-draft`, `sales/getcustomergroup/{id}` (relative), `sales/getproduct/{wh}` (relative), `/sales/lims_product_search`, `/sales/check-discount`, `/sales/gen_invoice/{id}`, `sales.printLastReciept`, `cashRegister.close`, `/cash-register/showDetails/{wh}`, `/cash-register/check-availability/{id}`, POST `pos/product/vision-search` (`throttle:20,1`, W:142). **Broken locally** (§5.2 of WP1): today-sale, today-profit, get-sale, product_sale, sales/{id}/edit, sales/{id}/create, get_gift_card, check-batch-availability, notifications/mark-as-read, check-status, getproduct/{cat}/{brand}, absolute getcustomergroup |
| 12 DB tables | sales, product_sales, custom_product_sales, products, product_variants, product_warehouse, product_batches, coupons, customers (points/expense), payments, payment_with_*, gift_cards, cash_registers |
| 13 Permissions | posSale checks `sales-add` (SC:2434-2435). **store has none** (SC:1152). Vision checks `sales-add` (Ctl:44). Delete sale, invoice and register close have no check and no ownership check |
| 14 Business rules | Stock is deducted only when status=1. Drafts (status 3) do not move stock but still consume coupons and earn points. One arbitrary batch per product (**no FEFO**, expired batches not blocked, SC:2236-2266). `without_stock` setting gates the client check only. Cash register is optional (modal commented). Customer group % is applied as a markup |
| 15 Bugs | No transaction or row locks (SC:1155-1156). Totals double-count line discount and tax (pos:4829-4833 vs 4944-4952). Customer-group price compounding (4646). Draft→sale creates a duplicate and leaves the draft. "Qty: N" label stored and printed as IMEI. XSS in Select2, recent list and `{!! !!}` invoices. Reference `Ymd-his` not unique. 500 after commit when SMS is configured (SC:1667). Negative stock parsed as positive. Hard-coded `whereDate != 2025-07-08` (SC:2240). Camera barcode button likely does nothing (UNVERIFIED). Vision auto-retry may exhaust the throttle (PLAUSIBLE) |
| 16 Dependencies | Products/batches, customers/groups, membership listener (`Sale::saved`, can fail the sale), rewards L1/L3/L4, cash register, POS settings, OpenAI vision (`OPENAI_API_KEY`, `OPENAI_VISION_MODEL`), SMS/mail |
| 17 Recommendation | **Redesign**. WP note: keep the data model; transactional store with `sales-add`, FormRequest, server-side price recompute, `lockForUpdate`, FEFO with expiry block, unique references, idempotency key; **preserve the camera/vision pipeline** (add generic/manufacturer matching and retry back-off) |

#### 3.5.3 Add Sale (SB:97)

| Attr | Detail |
|---|---|
| 1 Menu | Add Sale (also in the header quick-add, MAIN:234) |
| 2 Submenus | none |
| 3 Route | GET `/sales/create` · `sales.create` · SC@create (1114) → `V/sale/create.blade.php`. POST `/sales` → store (shared with POS) |
| 4 Purpose | Back-office sale or invoice entry |
| 5 Main functionality | Full sale form with payment block |
| 6 Actions | add customer, add products, save |
| 7 Forms & fields | Date, reference_no, customer (+modal → `customer.store`), warehouse, biller, product table (qty, batch, discount, tax, unit), order tax, order discount, coupon, shipping, sale_status, payment_status, payment (paid_by, amount, gift card, cheque, account), document (whitelist), sale_note, staff_note, custom fields, currency/rate; cash-register modal (`cashRegister.store`) |
| 8 Table columns | line grid |
| 9 Search/filter | product search |
| 10 Validation | reference_no `max:191\|required\|unique:sales` only if sent (1157-1162); document extension whitelist (1220-1228) |
| 11 Endpoints | `getcustomergroup/`, `getproduct/` (**404 locally**), `lims_product_search`, `get_gift_card` (**500 locally**, X3c); `cash-register/check-availability` (create.blade:982); `check-batch-availability` (963) |
| 12 DB tables | as POS store |
| 13 Permissions | `sales-add` (1117); store unchecked |
| 14 Business rules | Stock deducted only for status 1 (1414, 1469, 1511). Points earned when grand_total ≥ minimum. Email for status 1 only. SMS sent |
| 15 Bugs | Product loading, search, group pricing and gift-card lookup broken locally (X3c). `create_sale.blade.php` (4851 lines) is orphaned |
| 16 Dependencies | POS store logic, cash register |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: likely unused because POS is the working entry point; fix the URLs or retire in favour of POS plus a back-office invoice form |

#### 3.5.4 Import Sale By CSV (SB:98)

| Attr | Detail |
|---|---|
| 1 Menu | Import Sale By CSV |
| 2 Submenus | none |
| 3 Route | GET `/sales/sale_by_csv` → SC@saleByCsv (3096) → `V/sale/import.blade.php`. The form posts to `route('sale.import')` = `/report/importsale` → ReportController@importSale (**missing**) (W:616). The working `SC@importSale` (3111) is unreachable |
| 4 Purpose | Bulk sale import |
| 5 Main functionality | Upload a CSV |
| 6 Actions | upload |
| 7 Forms & fields | CSV file plus header fields; sample `../sample_file/sample_sale_products.csv` |
| 8 Table columns | — (CSV layout per sample) |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | POST `/report/importsale` (broken locally) |
| 12 DB tables | sales, product_sales (if it worked) |
| 13 Permissions | `sales-add` on the page |
| 14 Business rules | — |
| 15 Bugs | **Submit returns 500 locally** (X3b). `online-sales/sale_by_csv` reuses the same broken view |
| 16 Dependencies | Route block W:607-649 |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: cannot currently work; re-route only if the business needs it |

#### 3.5.5 Packing Slip List (SB:101)

| Attr | Detail |
|---|---|
| 1 Menu | Packing Slip List |
| 2 Submenus | none |
| 3 Route | GET `/packing-slips` · `packingSlip.index` · PackingSlipController@index (:19) |
| 4 Purpose | Pick/pack documents for orders before courier dispatch |
| 5 Main functionality | List slips; create (from the Sale List modal); print; delete; "Create Challan" form |
| 6 Actions | invoice/print, delete, select slips → Create Challan (`challan.create`, index:11) |
| 7 Forms & fields | Create (from Sale List): sale lines to pack |
| 8 Table columns | reference, sale, amount, status (Pending, In Transit, Delivered, Cancelled) (:100-105) |
| 9 Search/filter | ordered by `CAST(col AS SIGNED)` |
| 10 Validation | none |
| 11 Endpoints | POST `packing-slips/packing-slip-data` (:24), POST `packing-slips/store` (:125), GET `packing-slips/invoice/{id}`, POST `packing-slips/delete/{id}` (:291) |
| 12 DB tables | packing_slips, packing_slip_products, product_sales (is_packing), products, product_warehouse, product_variants, deliveries, sales |
| 13 Permissions | sidebar `packing_slip_challan`; **controller has no checks at all** |
| 14 Business rules | store: reference = latest+1; deducts stock (incl. combos, 173-236) but **not product_batches**; creates/extends delivery (status 1); sale_status=5. delete: restores stock (no batch), sale_status=2, deletes the whole delivery |
| 15 Bugs | Batch stock drifts; race-prone reference; delete ignores In Transit/Delivered state; no transaction; `catch(Exception)` without import (277) |
| 16 Dependencies | Sale List, Challan, Delivery |
| 17 Recommendation | **Redesign**. WP note: preserve the concept; single stock ledger, batch-aware movements, status guards |

#### 3.5.6 Challan List (SB:102)

| Attr | Detail |
|---|---|
| 1 Menu | Challan List |
| 2 Submenus | none |
| 3 Route | GET `/challans` · `challan.index` · ChallanController@index (:22) |
| 4 Purpose | Courier dispatch and COD reconciliation |
| 5 Main functionality | Create a challan from Pending slips, print, money receipt, finalize with collected amounts |
| 6 Actions | create, save, print, money receipt, finalize |
| 7 Forms & fields | Create: packing_slip_id CSV, courier. Finalize: per-slip cash, cheque, online amounts |
| 8 Table columns | — (not detailed in WP) |
| 9 Search/filter | courier, status (Active/Close) |
| 10 Validation | none |
| 11 Endpoints | POST `challans/challan-data`, POST `challans/create` (:248-271), POST `challans/store` (:274), GET `challans/invoice/{id}`, GET `challans/money-reciept/{id}`, GET `challans/finalize/{id}`, POST `challans/update/{id}` (:318) |
| 12 DB tables | challans (CSV columns `packing_slip_list`, `amount_list`), packing_slips, deliveries, product_sales, payments, sales, accounts, cash_registers |
| 13 Permissions | sidebar `packing_slip_challan`; controller has none |
| 14 Business rules | Slips → In Transit, delivery → 2. Finalize: all-zero = Failed, else Delivered; creates a payment per method (default account, open register), increments paid_amount; sale_status=1 when all delivered; payment_status=4 only when due is exactly 0 |
| 15 Bugs | **Not idempotent** (no `status=='Close'` check; re-post duplicates payments); failed slips keep stock deducted and sale at Processing; crash without a default account; CSV columns; `catch(Exception)` without import (438) |
| 16 Dependencies | Packing Slip, Couriers, Accounts, Challan Report |
| 17 Recommendation | **Redesign**. WP note: preserve the COD reconciliation flow; normalised tables, idempotent finalize, permissions |

### 3.6 Online Orders group (SB:121-132) — WP1 §4.7-4.8

#### 3.6.1 Orders List (SB:125)

| Attr | Detail |
|---|---|
| 1 Menu | Orders List |
| 2 Submenus | none. Hidden: show page, assign rider, update status (§4) |
| 3 Route | GET `/online-sales` · `online-sales.index` · OnlineOrderController@index (:96). Data: POST `online-sales/sale-data` (:171) |
| 4 Purpose | Website/app orders fulfilment |
| 5 Main functionality | List website orders (`sale_type='website'`); show page with status dropdown, rider assignment, invoice; Excel export |
| 6 Actions | View, **Delete (always rendered)**, Generate Invoice; header Add Sale (**500**, missing `create`), Import Sale (broken), Download Excel (works); show: change status, assign rider |
| 7 Forms & fields | Status dropdown `#order_status`; Assign Rider modal (order_id, rider_id); "Add Tracking Update" modal with `action=""` (dead) |
| 8 Table columns | checkbox, date, reference, Rider, customer, products, qty, grand total, paid, due, status (tracking: pending, processing, in_transit, delivered) (:455-520) |
| 9 Search/filter | date, warehouse, "Delivery status" (actually filters sale_status), sale_status, payment_status, payment_method; search reference, customer, phone, biller, rider, IMEI |
| 10 Validation | assignRider validates order_id and rider_id; updateStatus `required\|string` (any value) |
| 11 Endpoints | `online-sales/export-all` (:545), `admin.online-sale.update-status` (:328), `admin.online-sale.assign-rider` (:253), `online-sales/{ref}` show (:524), duplicates `online-orders/show/{ref}`, `online-orders/assign-rider` (W:1176-1177) |
| 12 DB tables | sales, product_sales, order_trackings, tracking histories (table name UNVERIFIED), riders, products, product_warehouse, product_variants |
| 13 Permissions | sales-index ∨ online-orders on index (:100); everything else unchecked |
| 14 Business rules | `in_transit` deducts stock; `delivered` sets sale_status=1, payment_status=4, paid_amount=grand_total **without a payments row**; assignRider sets status `assigned` (unknown to the list badge) |
| 15 Bugs | in_transit deducts stock on **every** selection (no unit conversion, batches or combos); going straight to delivered skips deduction; payment badge shows Paid for any non-zero payment_status (:490); show fatals without tracking row (:41); ~2000 lines cloned from SaleController with 10 routed but missing methods; delete copies batch `-=` bug |
| 16 Dependencies | Riders (3.15.9), Delivery, website/app API consumers |
| 17 Recommendation | **Redesign**. WP note: explicit order state machine, one stock-reservation point, real payments on delivery, permissions, drop the cloned controller |

#### 3.6.2 Delivery List (SB:128; also commented at SB:105)

| Attr | Detail |
|---|---|
| 1 Menu | Delivery List |
| 2 Submenus | none |
| 3 Route | GET `/delivery` · `delivery.index` · DeliveryController@index (:28) |
| 4 Purpose | Track deliveries |
| 5 Main functionality | List deliveries; create from Sale List / Online list "Add Delivery"; edit; mail challan |
| 6 Actions | edit, delete, bulk delete, send mail |
| 7 Forms & fields | reference_no, status (1 Packing / 2 Delivering / 3 Delivered), courier, address, file |
| 8 Table columns | reference, sale reference, packing-slip references, customer, courier, address, products, status |
| 9 Search/filter | — |
| 10 Validation | none on store; **file has no extension/MIME validation** |
| 11 Endpoints | GET `delivery/delivery_list_data` (:43), `product_delivery/{id}`, `create/{id}`, `store` (:197), `sendmail`, `{id}/edit`, `update`, `deletebyselection`, `delete/{id}` |
| 12 DB tables | deliveries, sales, couriers; files in `public/documents/delivery/` |
| 13 Permissions | `delivery` on index and data; store/update/delete unchecked |
| 14 Business rules | Emails the customer when status ≠ 1 |
| 15 Bugs | `firstOrNew` by client reference_no can overwrite another delivery; potential web-shell upload (UNVERIFIED execution); `delivery/index_new.blade.php` dead |
| 16 Dependencies | Sale List, Online Orders, Packing Slip, Couriers |
| 17 Recommendation | **Fix-before-migrate** (validate uploads now). WP note: then merge into the order/packing redesign |

### 3.7 Manufacturing (SB:133-141, whole group commented) — WP2 §10

| Attr | Detail |
|---|---|
| 1 Menu | Manufacturing (hidden) |
| 2 Submenus | Production List (SB:137) → `productions.index`; Add Production (SB:138) → `productions.create` |
| 3 Route | `Modules/Manufacturing/Routes/web.php:35-48`: `productions` resource, `productions/production-data`, `productions/product_production/{id}`, `manufacturing` stub; API stub `Routes/api.php:16` |
| 4 Purpose | "Production" of combo products |
| 5 Main functionality | Deducts component stock and adds combo stock (ProductionController.php:208-349); destroy reverses |
| 6 Actions | create, delete (show/edit views missing → 500; update empty) |
| 7 Forms & fields | — |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | `productions/production-data`, `productions/product_production/{id}` |
| 12 DB tables | productions, product_productions |
| 13 Permissions | addon flag on index only |
| 14 Business rules | When enabled, combo sales stop deducting component stock (SC:1415) |
| 15 Bugs | no transactions; missing views; SaaS branch fatal |
| 16 Dependencies | `general_settings.modules`, `modules_statuses.json` |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: disable unless pharmacy kitting is used |

### 3.8 Expense group (SB:146-159) — WP2 §3

#### 3.8.1 Expense Category (SB:149)

| Attr | Detail |
|---|---|
| 1 Menu | Expense Category |
| 2 Submenus | none |
| 3 Route | GET `/expense_categories` · `expense_categories.index` (W:982) · ExpenseCategoryController@index :13 |
| 4 Purpose | Expense category master |
| 5 Main functionality | List with add/edit modals, code generator, CSV import |
| 6 Actions | add, edit, delete (soft), import, bulk delete, PDF/CSV/Print |
| 7 Forms & fields | code (Generate → `expense_categories/gencode`, 8-digit), name |
| 8 Table columns | Code, Name |
| 9 Search/filter | — |
| 10 Validation | code unique among active (ExpenseCategoryController.php:32-39, 59-66) |
| 11 Endpoints | `expense_categories/{gencode,import,deletebyselection,all}` (W:975-988) |
| 12 DB tables | expense_categories |
| 13 Permissions | **none in controller** (sidebar `expenses-index`) |
| 14 Business rules | soft delete via is_active |
| 15 Bugs | no permission checks |
| 16 Dependencies | Expense List |
| 17 Recommendation | **Migrate** — into a typed expense/other-income journal |

#### 3.8.2 Expense List (SB:150)

| Attr | Detail |
|---|---|
| 1 Menu | Expense List |
| 2 Submenus | none |
| 3 Route | GET `/expenses` · `expenses.index` (W:991) · ExpenseController@index :20 |
| 4 Purpose | Record operating expenses |
| 5 Main functionality | Server-side DataTable with footer total; edit/delete |
| 6 Actions | Edit, Delete (hard), bulk delete, PDF/Excel/CSV/Print |
| 7 Forms & fields | Edit modal as 3.8.3 |
| 8 Table columns | Date, Reference, Warehouse, Category, Amount, Note |
| 9 Search/filter | date range (default 1 year), warehouse; search branch drops date/warehouse filters |
| 10 Validation | none server-side |
| 11 Endpoints | POST `expenses/expense-data` (`expenses.data`, :52), `expenses/deletebyselection` |
| 12 DB tables | expenses, accounts, cash_registers |
| 13 Permissions | `expenses-index` :23; writes unchecked |
| 14 Business rules | Reference `er-YmdHis` (12-hour, collisions possible); tagged with cash_register_id when open; debit in Balance Sheet (AccountsController.php:115) and deduction in cash register; `accounts.total_balance` never updated |
| 15 Bugs | `update($request->all())` mass assignment (user_id, reference_no, cash_register_id); `Role::firstOrCreate` in edit (:203); hard delete |
| 16 Dependencies | Accounts, Cash register, Summary report |
| 17 Recommendation | **Redesign**. WP note: one Expense/Other-income journal posting to a real ledger, validation, void instead of delete |

#### 3.8.3 Add Expense (SB:155, modal)

| Attr | Detail |
|---|---|
| 1 Menu | Add Expense (also header quick-add, MAIN:240) |
| 2 Submenus | none |
| 3 Route | `#add-expense` opens `#expense-modal` (MAIN:553-623, JS :1236); POST `/expenses` → ExpenseController@store :176 |
| 4 Purpose | Quick expense entry |
| 5 Main functionality | Modal form on any page |
| 6 Actions | save |
| 7 Forms & fields | created_at, expense_category_id\*, warehouse_id\* (locked to user's warehouse when role >2), amount\*, account_id (default preselected), note |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | HTML `required` only |
| 11 Endpoints | POST `/expenses` |
| 12 DB tables | expenses |
| 13 Permissions | sidebar `expenses-add`; store **unchecked** |
| 14 Business rules | as 3.8.2 |
| 15 Bugs | no server validation or permission |
| 16 Dependencies | MAIN layout modal |
| 17 Recommendation | **Redesign** (with 3.8.2) |

### 3.9 Income group (SB:160-173) — WP2 §3

IncomeController is a copy-paste of ExpenseController. Every Expense finding applies here too.

#### 3.9.1 Income Category (SB:163)

| Attr | Detail |
|---|---|
| 1 Menu | Income Category |
| 2 Submenus | none |
| 3 Route | GET `/income_categories` (W:1003) · IncomeCategoryController@index :14 |
| 4 Purpose | Income category master |
| 5 Main functionality | as 3.8.1 |
| 6 Actions | add, edit, delete, import, bulk delete |
| 7 Forms & fields | code, name |
| 8 Table columns | Code, Name |
| 9 Search/filter | — |
| 10 Validation | code unique among active |
| 11 Endpoints | `income_categories/{gencode,import,deletebyselection,all}` (W:996-1009). **import, deleteBySelection and incomeCategoriesAll do not exist on the controller, so they return 500** |
| 12 DB tables | income_categories |
| 13 Permissions | none in controller (sidebar `incomes-index`) |
| 14 Business rules | soft delete |
| 15 Bugs | 3 routes point at missing methods |
| 16 Dependencies | Income List |
| 17 Recommendation | **Migrate** (merged journal) |

#### 3.9.2 Income List (SB:164)

| Attr | Detail |
|---|---|
| 1 Menu | Income List |
| 2 Submenus | none |
| 3 Route | GET `/incomes` (W:1012) · IncomeController@index :20 |
| 4 Purpose | Record other income |
| 5 Main functionality | as 3.8.2 |
| 6 Actions | Edit, Delete (hard), bulk delete, exports |
| 7 Forms & fields | as 3.9.3 |
| 8 Table columns | Date, Reference, Warehouse, Category, Amount, Note |
| 9 Search/filter | date range, warehouse |
| 10 Validation | none server-side |
| 11 Endpoints | POST `incomes/income-data`, `incomes/deletebyselection` |
| 12 DB tables | incomes |
| 13 Permissions | `incomes-index` :23; writes unchecked |
| 14 Business rules | Reference `ir-YmdHis`. **Income is excluded from the Balance Sheet** and shown as a debit in the Account Statement (AccountsController.php:177; V/account/account_statement:30). The cash register ignores income |
| 15 Bugs | as 3.8.2, plus the wrong ledger treatment |
| 16 Dependencies | Accounts, Summary report, Dashboard revenue |
| 17 Recommendation | **Redesign** |

#### 3.9.3 Add Income (SB:169, modal)

| Attr | Detail |
|---|---|
| 1 Menu | Add Income |
| 2 Submenus | none |
| 3 Route | `#add-income` opens `#income-modal` (MAIN:626-696, JS :1241); POST `/incomes` → @store :176 |
| 4 Purpose | Quick income entry |
| 5 Main functionality | modal |
| 6 Actions | save |
| 7 Forms & fields | created_at, income_category_id\*, warehouse_id\*, amount\*, account_id, note |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | HTML only |
| 11 Endpoints | POST `/incomes` |
| 12 DB tables | incomes |
| 13 Permissions | sidebar `incomes-add`; store unchecked |
| 14 Business rules | as 3.9.2 |
| 15 Bugs | as 3.8.3 |
| 16 Dependencies | MAIN modal |
| 17 Recommendation | **Redesign** |

### 3.10 Quotation (SB:177-189, whole group commented) — WP1 §4.9, WP2 §12

| Attr | Detail |
|---|---|
| 1 Menu | Quotation (hidden). Still reachable from the header "+" Add Quotation (MAIN:246, `quotes-add`) and the dashboard Recent Quotation tab |
| 2 Submenus | Quotation List (SB:180) → `quotations.index`; Add Quotation (SB:185) → `quotations.create` |
| 3 Route | `quotations` resource + `quotation-data`, `product_quotation/{id}`, `lims_product_search`, `getcustomergroup/{id}`, `getproduct/{id}`, `{id}/create_sale`, `{id}/create_purchase`, `sendmail`, `deletebyselection` (W:507-522, dup W:728-741) |
| 4 Purpose | Customer quotations convertible to sale or purchase |
| 5 Main functionality | CRUD, convert to sale (posts `sales.store`) or purchase (posts `purchases.store`) |
| 6 Actions | create, edit, delete, convert, email |
| 7 Forms & fields | — (not detailed) |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | as route list |
| 12 DB tables | quotations (+ lines) |
| 13 Permissions | `quotes-index` (:42), `quotes-add` (:363), `quotes-edit` (:891); createSale, createPurchase, store, update, destroy, deleteBySelection unchecked |
| 14 Business rules | Converted quote is not closed (SC never updates quotations; UNVERIFIED whether an id is sent) |
| 15 Bugs | gift-card lookup on create_sale 500s locally (`quotation/create_sale.blade.php:804`) |
| 16 Dependencies | Sale store, Purchase store |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: likely unused; remove the routes or put them behind a feature flag |

### 3.11 Transfer group (SB:193-206) — WP1 §3.6-3.8

#### 3.11.1 Transfer List (SB:196)

| Attr | Detail |
|---|---|
| 1 Menu | Transfer List |
| 2 Submenus | none |
| 3 Route | GET `/transfers` · `transfers.index` · TC@index (TC:29) |
| 4 Purpose | Inter-branch stock movements |
| 5 Main functionality | DataTable, view modal, bulk delete |
| 6 Actions | View, Edit (`transfers-edit`), Delete (`transfers-delete`) from client `all_permission` (TC:239, 244); bulk delete |
| 7 Forms & fields | — |
| 8 Table columns | Date, Reference, From WH, To WH, Product Cost, Product Tax, Grand Total, Status (1 Completed / 2 Pending / 3 Sent), Email Sent |
| 9 Search/filter | date range (default 1 year), from-warehouse, to-warehouse |
| 10 Validation | — |
| 11 Endpoints | POST `transfers/transfer-data` (TC:104-266), GET `transfers/product_transfer/{id}` (TC:904), POST `transfers/deletebyselection` (TC:1393) |
| 12 DB tables | transfers, product_transfer, warehouses |
| 13 Permissions | `transfers-index`; bulk delete unchecked |
| 14 Business rules | `staff_access=warehouse` scoping |
| 15 Bugs | Ungrouped `where/orWhere` escapes date and warehouse filters (TC:118, 144, 186-191), leaking other warehouses; missing `all_permission` → TypeError; JSON by concatenation |
| 16 Dependencies | Warehouses |
| 17 Recommendation | **Redesign** |

#### 3.11.2 Add Transfer (SB:201)

| Attr | Detail |
|---|---|
| 1 Menu | Add Transfer (also header quick-add MAIN:252) |
| 2 Submenus | none (Edit hidden) |
| 3 Route | GET `transfers/create` · `transfers.create` · TC@create (268); POST `transfers` → TC@store (699). Edit: GET `transfers/{id}/edit` (1116) → PUT → TC@update (1129). DELETE → TC@destroy (1443) |
| 4 Purpose | Move stock between branches |
| 5 Main functionality | Product picker per source warehouse, batch-aware lines, email to both warehouses |
| 6 Actions | add lines, save, edit, delete |
| 7 Forms & fields | created_at, from_warehouse_id\*, to_warehouse_id\*, status (1/2/3), lines (name, code, batch no, qty, net unit cost, tax, subtotal; hidden product_id, product_code, product_batch_id, purchase_unit, imei), shipping_cost, document, note |
| 8 Table columns | line grid |
| 9 Search/filter | product search |
| 10 Validation | client-side only (from ≠ to, create.blade:711) |
| 11 Endpoints | GET `transfers/getproduct/{warehouse}` (TC:279, toggles strict mode), `transfers/lims_product_search`, `/check-batch-availability/{p}/{batch}/{wh}` (W:260) |
| 12 DB tables | transfers, product_transfer, product_warehouse, product_variants, units, warehouses, mail_settings |
| 13 Permissions | create `transfers-add`, edit `transfers-edit`; store/update/destroy unchecked |
| 14 Business rules | Status ≠2 deducts source; status 1 adds to destination; status 3 (Sent) deducts and adds nowhere until edited to Completed; status 2 no effect; products.qty and product_batches unchanged |
| 15 Bugs | No transaction; no server stock check; mail failure `return $e;` after stock moved (TC:896); destroy divides a model object (TypeError for `/` units, TC:1404, 1452); update matches lines on product_id only; original filenames for documents |
| 16 Dependencies | Warehouses, mail settings, batches |
| 17 Recommendation | **Redesign**. WP note: transaction, server stock checks, explicit Send/Receive steps |

#### 3.11.3 Import Transfer By CSV (SB:202)

| Attr | Detail |
|---|---|
| 1 Menu | Import Transfer By CSV |
| 2 Submenus | none |
| 3 Route | GET `transfers/transfer_by_csv` (TC:972); POST `importtransfer` (TC:983) |
| 4 Purpose | Bulk transfer |
| 5 Main functionality | Upload a CSV |
| 6 Actions | upload |
| 7 Forms & fields | from/to warehouse, status (1/2/3), CSV, shipping, note |
| 8 Table columns | CSV: product_code, quantity, product_unit, product_cost, tax_name |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | POST `importtransfer` |
| 12 DB tables | transfers, product_transfer, product_warehouse |
| 13 Permissions | `transfers-add` on the page; import unchecked |
| 14 Business rules | status 1 moves between first rows; status 3 deducts only |
| 15 Bugs | **No batch support**; no transaction; no active check |
| 16 Dependencies | — |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: batch-aware CSV only if kept |

### 3.12 Return group (SB:213-224) — WP1 §3.9-3.10

#### 3.12.1 Sale Return (SB:217)

| Attr | Detail |
|---|---|
| 1 Menu | Return → Sale |
| 2 Submenus | none. Add via modal `#add-sale-return` (MAIN:700-724; header quick-add MAIN:258) |
| 3 Route | GET `/return-sale` · `return-sale.index` · ReturnController@index (RC:40). Create GET `return-sale/create?reference_no=` (RC:292); POST store (RC:686-958); edit (1050)/update (1066); DELETE destroy (1462) |
| 4 Purpose | Customer returns against a completed sale |
| 5 Main functionality | List; create from a sale reference with pre-filled lines; edit; delete; email |
| 6 Actions | add, edit, delete, bulk delete, send mail |
| 7 Forms & fields | Per line: is_return, qty (max sold − returned), batch id, imei, unit, net price, discount, tax; order tax/discount, return_note, staff_note, document, cash_in_hand modal, hidden change_sale_status |
| 8 Table columns | Date, Reference, Sale Reference, Warehouse, Biller, Customer, Grand Total |
| 9 Search/filter | date range, warehouse; search `orwhere` bypasses filters (RC:132-225) |
| 10 Validation | **none** server-side (client max only) |
| 11 Endpoints | POST `return-sale/return-data` (RC:71-290), `getcustomergroup`, `getproduct`, `lims_product_search`, `product_return/{id}`, `sendmail` (RC:960); bulk JS posts **`returns/deletebyselection`** (wrong URL → 404) |
| 12 DB tables | returns, product_returns, product_sales (return_qty), sales, products, product_variants, product_batches, product_warehouse, accounts, cash_registers, customers, mail_settings |
| 13 Permissions | index `returns-index`, create `returns-add`, edit `returns-edit`; store/update/destroy unchecked |
| 14 Business rules | Restores stock to the sale's warehouse; sale must be status 1; change_sale_status sets sale to 4; **no refund payment** and no change to paid_amount |
| 15 Bugs | Batch return with missing row creates a row without batch id; return_qty matched by product only; update ignores return_qty; delete deducts unconverted batch qty (RC:1526, 1434) and forces sale back to status 1; no transaction; bulk delete 404 |
| 16 Dependencies | Sale, cash register, membership listener (`Returns::saved`) |
| 17 Recommendation | **Redesign**. WP note: line-level link to product_sale id, refund payment record, fix bulk URL and return_qty on edit |

#### 3.12.2 Purchase Return (SB:220)

| Attr | Detail |
|---|---|
| 1 Menu | Return → Purchase |
| 2 Submenus | none. Add via modal `#add-purchase-return` (MAIN:727-750; header MAIN:264) |
| 3 Route | GET `/return-purchase` · `return-purchase.index` · RPC@index (RPC:34); create (258); store (416-611); edit (701)/update (717); destroy (990); bulk (940) |
| 4 Purpose | Return goods to a supplier |
| 5 Main functionality | List; create from a purchase reference; edit; delete; mail |
| 6 Actions | add, edit, delete, bulk delete, mail |
| 7 Forms & fields | Per line: is_return, qty (**max = ordered qty**), batch, unit, cost, discount, tax; account_id, return_note, staff_note, document, order tax |
| 8 Table columns | Date, Reference, Purchase Reference, Warehouse, Supplier, Grand Total |
| 9 Search/filter | date range, warehouse; search bypass |
| 10 Validation | none |
| 11 Endpoints | POST `return-purchase/return-data` (RPC:65), `getproduct/{wh}` (275), `lims_product_search` (358), `product_return/{id}` (613), `sendmail` (648). Dead routes: `add-payment`, `getpayment/{id}`, `mark-paid` (W:806-808, methods missing) |
| 12 DB tables | return_purchases, purchase_product_return, product_purchases (return_qty), products, product_variants, product_batches, product_warehouse, suppliers |
| 13 Permissions | **Gate mismatch**: menu `purchase-return-index`, controller checks `returns-index` (RPC:37); row actions use `returns-edit/delete` (224, 229); create `purchase-return-add`; store/update/destroy unchecked |
| 14 Business rules | Deducts stock from the purchase's warehouse; any purchase status accepted (incl. Pending with 0 received); no supplier refund/payment record |
| 15 Bugs | Combo child variant qty **increased** (RPC:545); return_qty never decremented on update/delete; batch revert unconverted (971, 1019); null-row fatal; raw `Mail::send` |
| 16 Dependencies | Purchases, Supplier Due report |
| 17 Recommendation | **Redesign**. WP note: fix gates, cap at received − returned, revert return_qty, supplier credit record |

### 3.13 Accounting group (SB:235-253) — WP2 §4

#### 3.13.1 Account List (SB:239)

| Attr | Detail |
|---|---|
| 1 Menu | Account List |
| 2 Submenus | none |
| 3 Route | GET `/accounts` · `accounts.index` (W:1052) · AccountsController@index :24 |
| 4 Purpose | Cash and bank account master |
| 5 Main functionality | List with edit modal, default toggle, delete |
| 6 Actions | Edit, Delete (soft; blocked for the default account; gated on `env('USER_VERIFIED')`, :214), make default, PDF/Excel/CSV/Print |
| 7 Forms & fields | account_no\*, name\*, initial_balance, note |
| 8 Table columns | Account No, Name, Initial Balance, Default toggle, Note |
| 9 Search/filter | — |
| 10 Validation | account_no unique among active (:42-49) |
| 11 Endpoints | JS calls `accounts/make-default/{id}` (V/account/index:143) but the route is `/make-default/{id}` (W:1046), so it **404s**. Also `accounts/all` (W:1049) |
| 12 DB tables | accounts |
| 13 Permissions | `account-index` :27. `makeDefault` is an unchecked GET (CSRF-able) |
| 14 Business rules | The first account becomes default. Update **resets** total_balance (:82). total_balance is otherwise never maintained |
| 15 Bugs | Default toggle broken; stale total_balance |
| 16 Dependencies | Payments, expenses, payroll, POS default account (`is_default` lookup) |
| 17 Recommendation | **Redesign**. WP note: keep only the account master; move to a double-entry or signed ledger |

#### 3.13.2 Add Account (SB:240, modal)

| Attr | Detail |
|---|---|
| 1 Menu | Add Account |
| 2 Submenus | none |
| 3 Route | `#add-account` modal (MAIN:754-788); POST `/accounts` → @store :40 |
| 4 Purpose | Create an account |
| 5 Main functionality | modal |
| 6 Actions | save |
| 7 Forms & fields | account_no\*, name\*, initial_balance, note |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | account_no unique among active |
| 11 Endpoints | POST `/accounts` |
| 12 DB tables | accounts |
| 13 Permissions | sidebar `account-index`; store **unchecked** |
| 14 Business rules | total_balance = initial_balance |
| 15 Bugs | no permission check |
| 16 Dependencies | MAIN modal |
| 17 Recommendation | **Migrate** (as part of the ledger redesign) |

#### 3.13.3 Money Transfer (SB:243)

| Attr | Detail |
|---|---|
| 1 Menu | Money Transfer |
| 2 Submenus | none |
| 3 Route | GET `/money-transfers` · `money-transfers.index` (W:1055) · MoneyTransferController@index :14 |
| 4 Purpose | Move funds between accounts |
| 5 Main functionality | List with create/edit modals |
| 6 Actions | create, edit, delete (hard) |
| 7 Forms & fields | from account, to account, amount, date |
| 8 Table columns | Date, Ref (`mtr-…`), From, To, Amount |
| 9 Search/filter | — |
| 10 Validation | **none**: from = to, negative amounts and inactive accounts are all accepted |
| 11 Endpoints | resource store/update/destroy |
| 12 DB tables | money_transfers, accounts |
| 13 Permissions | `money-transfer` :17 on index; store/update/destroy unchecked |
| 14 Business rules | Counts as transfer in/out in the Balance Sheet |
| 15 Bugs | update finds the record by body `id`; hard delete |
| 16 Dependencies | Balance Sheet, Account Statement |
| 17 Recommendation | **Redesign** |

#### 3.13.4 Balance Sheet (SB:246)

| Attr | Detail |
|---|---|
| 1 Menu | Balance Sheet |
| 2 Submenus | none |
| 3 Route | GET `/balancesheet` · `accounts.balancesheet` (W:1047) · @balanceSheet :103 |
| 4 Purpose | Per-account balance |
| 5 Main functionality | Computed lifetime credit/debit per account |
| 6 Actions | view, export |
| 7 Forms & fields | — |
| 8 Table columns | Name, Account No, Credit, Debit, Balance, footer Total **[src]** (V/account/balance_sheet:12-16) |
| 9 Search/filter | none (no date filter) |
| 10 Validation | — |
| 11 Endpoints | — |
| 12 DB tables | accounts, payments, return_purchases, returns, expenses, payrolls, money_transfers |
| 13 Permissions | `balance-sheet` :106 |
| 14 Business rules | Credit = sale payments + purchase returns + transfers in + initial balance. Debit = purchase payments + sale returns + expenses + payrolls + transfers out (:110-121) |
| 15 Bugs | Income excluded; no date filter; 8 queries per account; cancelled sales not excluded; customer deposits never booked |
| 16 Dependencies | all payment-producing modules |
| 17 Recommendation | **Redesign** |

#### 3.13.5 Account Statement (SB:249, modal)

| Attr | Detail |
|---|---|
| 1 Menu | Account Statement |
| 2 Submenus | none |
| 3 Route | `#account-statement` modal (MAIN:791-835); POST `/account-statement` · `accounts.statement` (W:1048) · @accountStatement :132 |
| 4 Purpose | Transaction statement per account |
| 5 Main functionality | List of debits/credits with a running balance |
| 6 Actions | generate |
| 7 Forms & fields | account_id, type (0 all / 1 debit / 2 credit), date range |
| 8 Table columns | — (statement rows; debit/credit decided in the view) |
| 9 Search/filter | modal fields |
| 10 Validation | none |
| 11 Endpoints | POST `/account-statement` |
| 12 DB tables | payments, incomes, expenses, money_transfers, sales, purchases |
| 13 Permissions | sidebar `account-statement`; controller **none** |
| 14 Business rules | Debit vs credit by reference-prefix sniffing (`spr`, `prr`, `mtr`) |
| 15 Bugs | **type=2 crashes** (`$income_list` undefined, :202); income shown as debit; running balance from 0 over newest-first list; N+1 `Sale::find`/`Purchase::find` per row |
| 16 Dependencies | Accounts |
| 17 Recommendation | **Redesign** |

### 3.14 HRM group (SB:272-301) — WP2 §5

#### 3.14.1 Department (SB:276)

| Attr | Detail |
|---|---|
| 1 Menu | Department |
| 2 Submenus | none |
| 3 Route | resource `departments` · `departments.index` + `departments/deletebyselection` (W:1059-1060) · DepartmentController |
| 4 Purpose | Department master |
| 5 Main functionality | List with add/edit |
| 6 Actions | add, edit, delete (soft), bulk delete |
| 7 Forms & fields | name |
| 8 Table columns | checkbox, Department, Action **[src]** (V/department/index:19-23) |
| 9 Search/filter | — |
| 10 Validation | name unique among active |
| 11 Endpoints | `departments/deletebyselection` |
| 12 DB tables | departments |
| 13 Permissions | index `department` :17; store/update/destroy/bulk unchecked. `department-add/edit/delete` are never checked |
| 14 Business rules | — |
| 15 Bugs | writes unchecked |
| 16 Dependencies | Employee |
| 17 Recommendation | **Migrate** |

#### 3.14.2 Employee (SB:279)

| Attr | Detail |
|---|---|
| 1 Menu | Employee |
| 2 Submenus | none |
| 3 Route | resource `employees` · `employees.index` + deletebyselection (W:1063-1064) · EmployeeController |
| 4 Purpose | Staff master (optionally linked to a login user) |
| 5 Main functionality | List; create with optional user account |
| 6 Actions | add, edit, delete, bulk delete |
| 7 Forms & fields | employee_name\*, image, department_id\*, email\*, phone_number\*, address, city, country, staff_id, salary\*; optional user: name, password, role_id, warehouse_id, biller_id |
| 8 Table columns | checkbox, Image, Name, Email, Phone Number, Department, Address, Staff Id, Salary, Action **[src]** (V/employee/index:28-39) |
| 9 Search/filter | — |
| 10 Validation | — (not detailed in WP) |
| 11 Endpoints | `employees/deletebyselection` |
| 12 DB tables | employees, users |
| 13 Permissions | `employees-index/add/edit/delete` (:24, 42, 58, 125, 182, 207) |
| 14 Business rules | Salary drives payroll |
| 15 Bugs | **salary not in `Employee::$fillable`**, never saved (live rows UNVERIFIED); `User::create($request->except('image'))` lets the form choose role_id (**escalation**, :82); `User::latest()->first()` race |
| 16 Dependencies | Payroll, Attendance, Users |
| 17 Recommendation | **Fix-before-migrate** (role escalation). WP note: then migrate |

#### 3.14.3 Attendance (SB:282)

| Attr | Detail |
|---|---|
| 1 Menu | Attendance |
| 2 Submenus | none |
| 3 Route | resource `attendance` · `attendance.index`, `attendance/delete/{date}/{employee_id}`, deletebyselection, `attendance/importDeviceCsv` (W:1081-1084) · AttendanceController |
| 4 Purpose | Daily check-in/out |
| 5 Main functionality | Manual multi-employee entry; biometric device CSV import |
| 6 Actions | add, import CSV, delete (hard), bulk delete. No edit (`update` stub, :211) |
| 7 Forms & fields | employees (multi), date, check-in, check-out, note; device CSV |
| 8 Table columns | checkbox, Date, Employee, CheckIn - CheckOut, Status, Created By, Action **[src]** (V/attendance/index:62-70) |
| 9 Search/filter | grouped by date and employee |
| 10 Validation | — |
| 11 Endpoints | `attendance/importDeviceCsv`, `attendance/delete/{date}/{emp}`, deletebyselection |
| 12 DB tables | attendances, employees, hrm_settings |
| 13 Permissions | `attendance` :20, `attendance-add` :73/:123, `attendance-delete` :219/:233; `attendance-edit` never checked |
| 14 Business rules | status int: 1 on time, 0 late vs `hrm_settings.checkin`; CSV matched on staff_id + datetime, upserted |
| 15 Bugs | no edit |
| 16 Dependencies | HRM Setting, Monthly Payroll |
| 17 Recommendation | **Migrate** — WP: keep device import only if a biometric device is used |

#### 3.14.4 Payroll (SB:285)

| Attr | Detail |
|---|---|
| 1 Menu | Payroll |
| 2 Submenus | none |
| 3 Route | resource `payroll` · `payroll.index` + deletebyselection (W:1067-1068) · PayrollController |
| 4 Purpose | Manual salary payments |
| 5 Main functionality | List and create/edit payroll records; email the employee |
| 6 Actions | add, edit, delete (hard), bulk delete |
| 7 Forms & fields | created_at, employee_id\*, account_id, addition, deduction, amount\*, paying_method\*, note |
| 8 Table columns | checkbox, Date, Reference, Employee, Account, Amount, Method, Action, footer Total **[src]** (V/payroll/index:15-24) |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | deletebyselection |
| 12 DB tables | payrolls, employees, advances_payments, accounts |
| 13 Permissions | `payroll` :32, `-add` :55, `-edit` :122, `-delete` :139/:154 |
| 14 Business rules | Store (:52) **overwrites the typed amount** with salary + addition − deduction − all lifetime approved advances |
| 15 Bugs | computed salary/advance/total fields not fillable (dropped); update `$request->all()`; hard delete |
| 16 Dependencies | Employee salary (never saved), Advances, Accounts, Balance Sheet |
| 17 Recommendation | **Redesign** |

#### 3.14.5 Monthly Payroll (SB:288)

| Attr | Detail |
|---|---|
| 1 Menu | Monthly Payroll |
| 2 Submenus | none |
| 3 Route | GET `monthly-payroll` · `monthly-payroll.index` → @monthlyPayroll :163; GET `monthly-payroll/get-data` → :175; POST `monthly-payroll/store` → bulkSaveMonthlyPayroll :361 (W:1069, 1072-1073) |
| 4 Purpose | Bulk monthly salary run |
| 5 Main functionality | AJAX grid per employee, editable addition/deduction, bulk save |
| 6 Actions | load, edit, save |
| 7 Forms & fields | month, warehouse, per-row addition, deduction |
| 8 Table columns | per employee: present, absent, holidays, weekends, pay days, salary, advance, addition, deduction, totals |
| 9 Search/filter | month, warehouse (empty = all employees) |
| 10 Validation | — |
| 11 Endpoints | `monthly-payroll/get-data`, `monthly-payroll/store` |
| 12 DB tables | payrolls, attendances, holidays, advances_payments, employees |
| 13 Permissions | `monthly-payroll` |
| 14 Business rules | Store in a DB transaction (:377), the only one in HRM |
| 15 Bugs | int status compared with `'present'`/`'absent'` (:292-300); all approved holidays of any user subtracted from everyone (:247-273); advances double counted; advances filtered by created_at; no proration; client totals trusted, negatives accepted; several columns and `advances_payments` have no migration (UNVERIFIED) |
| 16 Dependencies | Attendance, Holiday, Advances, Employee |
| 17 Recommendation | **Redesign** |

#### 3.14.6 Advances Payment (SB:291)

| Attr | Detail |
|---|---|
| 1 Menu | Advances Payment |
| 2 Submenus | none |
| 3 Route | resource `advances-payments` · `advances-payments.index`, GET `approve/{id}`, GET `reject/{id}`, POST deletebyselection (W:1075-1078) · AdvancesPaymentController |
| 4 Purpose | Salary advances |
| 5 Main functionality | Request, approve, reject |
| 6 Actions | add, edit, delete, approve, reject |
| 7 Forms & fields | employee_id, amount, payment_date, payment_method, note |
| 8 Table columns | checkbox, Employee, Amount, Payment Date, Payment Method, Status, Note, Action **[src]** (V/advances_payment/index:16-25) |
| 9 Search/filter | — |
| 10 Validation | :131-137 |
| 11 Endpoints | approve/reject GETs; deletebyselection (**method missing**) |
| 12 DB tables | advances_payments, payrolls, accounts |
| 13 Permissions | `advances-index/add/edit/delete`; **approve and reject unchecked** |
| 14 Business rules | Approve (:185) creates a payroll row, posts to the *first active* account, auto-creates "Cash Account" |
| 15 Bugs | GET state change with no status check; re-approve duplicates payroll; reject doesn't reverse; bulk delete 500 |
| 16 Dependencies | Payroll, Monthly Payroll, Accounts |
| 17 Recommendation | **Redesign** |

#### 3.14.7 Payroll Report (SB:294)

| Attr | Detail |
|---|---|
| 1 Menu | Payroll Report |
| 2 Submenus | none |
| 3 Route | GET `payroll-report` · `report.payroll` → ReportController@payrollReport RC:79; POST `payroll-report-data` → RC:97 (W:1070-1071) |
| 4 Purpose | Salary payment report |
| 5 Main functionality | DataTable |
| 6 Actions | view, export |
| 7 Forms & fields | filters |
| 8 Table columns | Date, Ref, Employee, Designation, Present, Absent, Holiday, Weekend, Off/Pay days, Salary, Addition, Total, Advance, Deduction, Total Payment, Account, Method |
| 9 Search/filter | date filters **commented out** (RC:121-128) |
| 10 Validation | — |
| 11 Endpoints | POST `payroll-report-data` |
| 12 DB tables | payrolls, employees, accounts |
| 13 Permissions | `payroll-report` RC:82, :100 (one of the few checked data endpoints) |
| 14 Business rules | — |
| 15 Bugs | **Every request writes params to `public/debug_payroll_params.txt`** (RC:109-116; file exists) |
| 16 Dependencies | Payroll |
| 17 Recommendation | **Fix-before-migrate** (remove the debug write now). WP note: then redesign with payroll |

#### 3.14.8 Holiday (SB:297)

| Attr | Detail |
|---|---|
| 1 Menu | Holiday (actually employee leave) |
| 2 Submenus | none. Header "My Holiday" (MAIN:384) → `holidays/my-holiday/{y}/{m}` |
| 3 Route | resource `holidays` · `holidays.index`, `approve-holiday/{id}`, `holidays/my-holiday/{y}/{m}`, deletebyselection (W:1097-1104) · HolidayController |
| 4 Purpose | Leave requests |
| 5 Main functionality | List/create leave; approve |
| 6 Actions | add, edit, delete, approve |
| 7 Forms & fields | from date, to date, note |
| 8 Table columns | checkbox, Date, Created By, From, To, Note, Action **[src]** (V/holiday/index:14-22) |
| 9 Search/filter | users with `holiday` see all, others only their own |
| 10 Validation | — |
| 11 Endpoints | `approve-holiday/{id}`, `my-holiday/{y}/{m}` |
| 12 DB tables | holidays |
| 13 Permissions | `holiday`, `holiday-add/edit/delete`; **approveHoliday unchecked** (open GET) |
| 14 Business rules | auto-approved when the creator has `holiday` |
| 15 Bugs | open GET approve; myHoliday runs one query per day |
| 16 Dependencies | Monthly Payroll |
| 17 Recommendation | **Redesign** (treat as Leave) |

### 3.15 People group (SB:315-364) — WP2 §6

#### 3.15.1 User List (SB:320)

| Attr | Detail |
|---|---|
| 1 Menu | User List |
| 2 Submenus | none |
| 3 Route | GET `/user` · `user.index` (W:928) · UserController@index :31 |
| 4 Purpose | Login accounts |
| 5 Main functionality | List; edit; soft delete |
| 6 Actions | edit, delete (`is_deleted`), bulk delete |
| 7 Forms & fields | edit as 3.15.2 |
| 8 Table columns | checkbox, UserName, Email, Company Name, Phone Number, Role, Status, Action |
| 9 Search/filter | — |
| 10 Validation | name and email unique among non-deleted |
| 11 Endpoints | `user/{genpass,deletebyselection,notification,all}` |
| 12 DB tables | users, roles |
| 13 Permissions | `users-index` :34; **update unchecked** |
| 14 Business rules | role 5 users get customer fields |
| 15 Bugs | **CRITICAL**: `update` (:144) `update($request->except('password'))` with role_id fillable → privilege escalation; resource `show` has no method |
| 16 Dependencies | Roles, Warehouses, Billers |
| 17 Recommendation | **Fix-before-migrate** (escalation). WP note: migrate with a rewritten authorization model |

#### 3.15.2 Add User (SB:325)

| Attr | Detail |
|---|---|
| 1 Menu | Add User (also header quick-add MAIN:270) |
| 2 Submenus | none |
| 3 Route | GET `/user/create` · `user.create` · @create :46; POST → @store :68 |
| 4 Purpose | Create a login |
| 5 Main functionality | form |
| 6 Actions | generate password, save |
| 7 Forms & fields | name, email, password, phone_number, company_name, role_id, biller_id, warehouse_id, service_staff, is_active; role 5 adds customer_group_id and customer fields |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | name, email unique among non-deleted |
| 11 Endpoints | `user/genpass` |
| 12 DB tables | users, customers (role 5) |
| 13 Permissions | `users-add` :49; **store unchecked** |
| 14 Business rules | — |
| 15 Bugs | unchecked store lets any user create admins |
| 16 Dependencies | Roles |
| 17 Recommendation | **Fix-before-migrate** |

#### 3.15.3 Customer List (SB:330)

| Attr | Detail |
|---|---|
| 1 Menu | Customer List |
| 2 Submenus | none |
| 3 Route | GET `/customer` · `customer.index` (W:381) · CustomerController@index :42 |
| 4 Purpose | Customer master, dues and deposits |
| 5 Main functionality | Server-side DataTable; clear due; deposits; import |
| 6 Actions | Edit, Due Report, Clear Due (modal), Add Deposit, View Deposits, Delete, Import CSV (:560) |
| 7 Forms & fields | Clear Due: amount, note. Deposit: amount, note |
| 8 Table columns | checkbox, Customer Details, Reward Tiers, Reward Points, Total Due, Delete Request, custom fields, Action; deposit modal: Date, Amount, Note, Created By, Action **[src]** (V/customer/index:34-45, 162-168) |
| 9 Search/filter | DataTable search |
| 10 Validation | none on deposits / clear due |
| 11 Endpoints | POST `customers/customer-data` (:212, unchecked); `importcustomer`; `customer/{getDeposit,add_deposit,update_deposit,deleteDeposit,deletebyselection,lims_customer_search}`; `customers/{clear-due,all}` |
| 12 DB tables | customers (deposit, expense, points), deposits, sales, payments |
| 13 Permissions | `customers-index` :45; data, clear-due, deposits unchecked |
| 14 Business rules | Clear Due (:309) loops unpaid sales (no order), one shared `spr-` ref, default account, Cash; sets 4 or 2 |
| 15 Bugs | Page uses undefined routes `customer.account-delete` (index:210) and `customer.get-destroy-request` (:423) → **likely 500** (UNVERIFIED); dormant `confirmAccountDestroy` (:805) hard-deletes sales/payments; overpayment dropped; drafts not excluded; no txn; `update($request->all())` with deposit/points fillable; import crashes on unknown group |
| 16 Dependencies | Sales, Payments, Membership, Rewards |
| 17 Recommendation | **Fix-before-migrate** (verify the page loads, close mass-assignment). WP note: migrate with a receivables ledger |

#### 3.15.4 Add Customer (SB:335)

| Attr | Detail |
|---|---|
| 1 Menu | Add Customer (also header quick-add MAIN:276; POS "+") |
| 2 Submenus | none |
| 3 Route | GET `/customer/create` · `customer.create` · @create :359; POST → @store :371 |
| 4 Purpose | Create a customer (optionally also a supplier and/or user) |
| 5 Main functionality | form |
| 6 Actions | save |
| 7 Forms & fields | **[src]** customer_group_id, customer_name, company_name, email, phone_number, tax_no, address, city, state, postal_code, country, flags `both` (also supplier), `user` (also login: name, password), pos (V/customer/create) |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | **[src]** phone_number max 255 unique among active customers; if `both`: company_name/email unique among suppliers; if `user`: name/email unique among users (CustomerController.php:373-410) |
| 11 Endpoints | — |
| 12 DB tables | customers, suppliers, users |
| 13 Permissions | `customers-add`; **store unchecked** |
| 14 Business rules | — |
| 15 Bugs | unchecked store |
| 16 Dependencies | Customer Group |
| 17 Recommendation | **Migrate** |

#### 3.15.5 Biller List (SB:340)

| Attr | Detail |
|---|---|
| 1 Menu | Biller List |
| 2 Submenus | none |
| 3 Route | GET `/biller` · `biller.index` (W:391) · BillerController@index :21 |
| 4 Purpose | Invoice issuer (branch/company profile) |
| 5 Main functionality | list, edit, import |
| 6 Actions | edit, delete, bulk delete, import, export (broken) |
| 7 Forms & fields | as 3.15.6 |
| 8 Table columns | checkbox, Image, Name, Company Name, VAT Number, Email, Phone Number, Address, Action **[src]** (V/biller/index:18-28) |
| 9 Search/filter | — |
| 10 Validation | company_name, email unique among active |
| 11 Endpoints | `importbiller`, `biller/{deletebyselection,lims_biller_search}`; view calls `/exportbiller` (**not routed**) |
| 12 DB tables | billers |
| 13 Permissions | `billers-index`; store/update/import/delete unchecked |
| 14 Business rules | POS requires a biller |
| 15 Bugs | export broken |
| 16 Dependencies | POS, Sale, Biller Report |
| 17 Recommendation | **Redesign**. WP note: fold into Branch profile |

#### 3.15.6 Add Biller (SB:345)

| Attr | Detail |
|---|---|
| 1 Menu | Add Biller (also header MAIN:282) |
| 2 Submenus | none |
| 3 Route | GET `/biller/create` · `biller.create` · @create :37 |
| 4 Purpose | Create a biller |
| 5 Main functionality | form |
| 6 Actions | save |
| 7 Forms & fields | name, image, company_name, vat_number, email, phone, address, city, state, postal_code, country |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | company_name, email unique among active |
| 11 Endpoints | — |
| 12 DB tables | billers |
| 13 Permissions | `billers-add`; store unchecked |
| 14 Business rules | — |
| 15 Bugs | — |
| 16 Dependencies | POS |
| 17 Recommendation | **Redesign** (fold into Branch) |

#### 3.15.7 Supplier List (SB:350)

| Attr | Detail |
|---|---|
| 1 Menu | Supplier List |
| 2 Submenus | none |
| 3 Route | GET `/supplier` · `supplier.index` (W:324) · SupplierController@index :31 |
| 4 Purpose | Supplier master and payables |
| 5 Main functionality | list with due, clear due, import |
| 6 Actions | edit, delete, clear due, import, bulk delete |
| 7 Forms & fields | Clear due: amount, note |
| 8 Table columns | checkbox, Image, Supplier Details, Total Due, Action **[src]** (V/supplier/index:17-23) |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | `importsupplier`, `supplier/deletebyselection`, `suppliers/{clear-due,all}` |
| 12 DB tables | suppliers, purchases, payments, users |
| 13 Permissions | `suppliers-index`; store/update/clearDue/import/delete unchecked |
| 14 Business rules | Clear due (:47) same defects as customer |
| 15 Bugs | due column N+1; import `catch(\Excetion)` typo (:333); image filenames from company name collide |
| 16 Dependencies | Purchases, Supplier Due report, AI reorder (brand used as "supplier") |
| 17 Recommendation | **Migrate** (with a payables ledger) |

#### 3.15.8 Add Supplier (SB:355)

| Attr | Detail |
|---|---|
| 1 Menu | Add Supplier (also header MAIN:288) |
| 2 Submenus | none |
| 3 Route | GET `/supplier/create` · `supplier.create` · @create :97 |
| 4 Purpose | Create a supplier, optionally with a role-6 login |
| 5 Main functionality | form |
| 6 Actions | save |
| 7 Forms & fields | biller fields + `as_user` |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | — |
| 12 DB tables | suppliers, users |
| 13 Permissions | `suppliers-add`; store unchecked |
| 14 Business rules | `as_user` creates a role-6 user |
| 15 Bugs | **hard-coded password `12345678`** (:155, :247) |
| 16 Dependencies | Purchase create-new (role 6) |
| 17 Recommendation | **Fix-before-migrate** |

#### 3.15.9 Riders List (SB:360)

| Attr | Detail |
|---|---|
| 1 Menu | Riders List |
| 2 Submenus | none |
| 3 Route | GET `/riders` · `riders.index` (W:1162) · RiderController@index :13; `table-loader`, `store`, `update`, DELETE `destroy/{id}` (W:1158-1166) |
| 4 Purpose | Delivery riders for online orders |
| 5 Main functionality | list, create, edit, delete |
| 6 Actions | add, edit, delete |
| 7 Forms & fields | full_name\*, phone\*, nid, address, emergency_contact |
| 8 Table columns | Name, Phone, NID, Address, Emergency Contact, Actions **[src]** (V/riders/index:17-22) |
| 9 Search/filter | — |
| 10 Validation | phone unique, nid unique (Validator) |
| 11 Endpoints | `riders/{table-loader,store,update,destroy}` |
| 12 DB tables | riders, order_trackings |
| 13 Permissions | index `sales-index`; writes unchecked |
| 14 Business rules | delete blocked while assigned to active orders (RiderController:112-130) |
| 15 Bugs | only first 10 riders shown (paginated loader); `POST riders/update` lacks `{id}`, so update cannot work |
| 16 Dependencies | Online Orders |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: keep only if delivery is used, then add permissions |

### 3.16 Reports group (SB:415-595) — WP2 §7

**Shared rules for all 28 reports**, written "(shared)" in the rows below:
- All routes are in `ReportController` (RC, 7061 lines) under `report/…`. No `*Data` AJAX endpoint checks a permission (exceptions: payroll and AI endpoints).
- There is **no `sale_status` filter anywhere**, so drafts and pending sales count as revenue.
- Dates use `whereDate(created_at)` with APP_TIMEZONE (UNVERIFIED).
- About 30 endpoints share a DataTables template whose `orWhere` search **leaks rows across warehouses, customers and users**.
- Choosing "All" page length crashes three reports (RC:2572, 3405, 3648).
- Several sidebar entries are hidden forms that post the current month as start/end (SB:420-424 etc.). Customer, Customer Group, Supplier, Warehouse, User and Biller reports open modals defined in MAIN.
- Validation is none unless stated. Purpose = reporting. Actions = view and export unless stated.

#### 3.16.1 Summary Report (SB:418-425)

| Attr | Detail |
|---|---|
| 1 Menu | Summary Report |
| 2 Submenus | none |
| 3 Route | POST `report/profit_loss` · `report.profitLoss` (W:860) → `profitLoss` RC:2165 (sidebar hidden form: start = 1st of month, end = today) |
| 4 Purpose | P&L summary |
| 5 Main functionality | ~25 aggregates + 11 per warehouse |
| 6 Actions | view |
| 7 Forms & fields | start_date, end_date |
| 8 Table columns | Sale, Purchase, Returns, Profit/Loss, Net Profit, Payments recv/sent, Expense/Income/Payroll, Cash in hand, per-warehouse cards (V/report/profit_loss) |
| 9 Search/filter | date range |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | sales, product_sales, products, purchases, returns, return_purchases, payments, expenses, incomes, payrolls |
| 13 Permissions | sidebar `profit-loss`; controller **none** |
| 14 Business rules | COGS = sold qty × current `products.cost` (`calculateAverageCOGS` RC:2417, N+1) |
| 15 Bugs | COGS ignores units/batches/returns; "cash" = total − card/cheque/gift/paypal/deposit so bKash/Nagad count as cash (RC:2276); inconsistent payment dates; per-warehouse profit = sale − purchase; strict-mode toggle + `DB::reconnect()` |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (P&L service) |

#### 3.16.2 Daily Profit & Cost (SB:428-434)

| Attr | Detail |
|---|---|
| 1 Menu | Daily Profit & Cost |
| 2 Submenus | none |
| 3 Route | GET `report/daily_profit` · `report.dailyCostAndProfit` (W:865) → RC:1364; POST `daily_cost_profit_data` → RC:1375; GET `profit-loss/product-wise/export` → RC:1486 |
| 4 Purpose | Product-wise daily profit |
| 5 Main functionality | PHP loop over days × products |
| 6 Actions | view, PDF/Excel/CSV/Print, server XLSX export |
| 7 Forms & fields | start_date, end_date (today) |
| 8 Table columns | Date, Product, Category, Sold Qty, Price, Amount, Cost, Total Cost, Profit, Stock, Stock Worth |
| 9 Search/filter | date, warehouse (ignored) |
| 10 Validation | none |
| 11 Endpoints | `daily_cost_profit_data`, `profit-loss/product-wise/export`, `export-all` (**missing method**, W:868) |
| 12 DB tables | product_sales, products, product_warehouse |
| 13 Permissions | **none** (sidebar ungated) |
| 14 Business rules | revenue = qty × list price, not actual line price |
| 15 Bugs | warehouse_id ignored; fatal on price ≤0 (RC:1433) |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** — merge into 3.16.1 |

#### 3.16.3 Best Seller (SB:436-440)

| Attr | Detail |
|---|---|
| 1 Menu | Best Seller |
| 2 Submenus | none |
| 3 Route | GET `report/best_seller` → RC:2095; POST → RC:2130 |
| 4 Purpose | Top products |
| 5 Main functionality | Top product per month, last 3 months (chart) |
| 6 Actions | view |
| 7 Forms & fields | warehouse |
| 8 Table columns | chart |
| 9 Search/filter | warehouse |
| 10 Validation | none |
| 11 Endpoints | POST variant (by warehouse) |
| 12 DB tables | product_sales, products |
| 13 Permissions | `best-seller` on GET only |
| 14 Business rules | SUM(qty) |
| 15 Bugs | mixed units; POST ungated |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (top-N) |

#### 3.16.4 Product Report (SB:441-450)

| Attr | Detail |
|---|---|
| 1 Menu | Product Report |
| 2 Submenus | none |
| 3 Route | GET `report/product_report` · `report.product` → RC:2542; POST `product_report_data` → RC:2554 |
| 4 Purpose | Product movement summary |
| 5 Main functionality | per-product purchased/sold/returned |
| 6 Actions | view, PDF/Excel/CSV/Print |
| 7 Forms & fields | start_date, end_date, warehouse_id=0 |
| 8 Table columns | Product, Category, Purchased/Sold/Returned/Purchase-returned amount and qty, Profit, Stock, Worth |
| 9 Search/filter | date, warehouse, name |
| 10 Validation | none |
| 11 Endpoints | `product_report_data` |
| 12 DB tables | products, product_sales, product_purchases, product_returns, purchase_product_return |
| 13 Permissions | sidebar `product-report`; controller none |
| 14 Business rules | ~10 queries per product |
| 15 Bugs | profit = 100% of revenue when nothing purchased in period (RC:2743); heavy N+1 |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (one GROUP BY) |

#### 3.16.5 Daily Sale (SB:451-455)

| Attr | Detail |
|---|---|
| 1 Menu | Daily Sale |
| 2 Submenus | none |
| 3 Route | GET `report/daily_sale/{y}/{m}` → RC:1777; POST → RC:1819 |
| 4 Purpose | Sales calendar |
| 5 Main functionality | calendar view per day |
| 6 Actions | month navigation |
| 7 Forms & fields | warehouse |
| 8 Table columns | per day: discounts, tax, shipping, grand total |
| 9 Search/filter | month, warehouse |
| 10 Validation | none |
| 11 Endpoints | POST variant |
| 12 DB tables | sales |
| 13 Permissions | `daily-sale` on GET only |
| 14 Business rules | one query per day |
| 15 Bugs | no status filter; returns not netted |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** |

#### 3.16.6 Monthly Sale (SB:456-460)

| Attr | Detail |
|---|---|
| 1 Menu | Monthly Sale |
| 2 Submenus | none |
| 3 Route | GET `report/monthly_sale/{y}` → RC:1943; POST → RC:1982 |
| 4 Purpose | 12-month sales |
| 5 Main functionality | 12 months, 72 queries |
| 6 Actions | year navigation |
| 7 Forms & fields | warehouse |
| 8 Table columns | 12 months |
| 9 Search/filter | year, warehouse |
| 10 Validation | none |
| 11 Endpoints | POST variant |
| 12 DB tables | sales |
| 13 Permissions | `monthly-sale` GET only |
| 14 Business rules | — |
| 15 Bugs | as 3.16.5 |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** — merge into "Trends" |

#### 3.16.7 Daily Purchase (SB:461-465)

| Attr | Detail |
|---|---|
| 1 Menu | Daily Purchase |
| 2 Submenus | none |
| 3 Route | GET `report/daily_purchase/{y}/{m}` → RC:1860; POST → RC:1902 |
| 4 Purpose | Purchase calendar |
| 5 Main functionality | per-day calendar |
| 6 Actions | month navigation |
| 7 Forms & fields | warehouse |
| 8 Table columns | calendar |
| 9 Search/filter | month, warehouse |
| 10 Validation | none |
| 11 Endpoints | POST variant |
| 12 DB tables | purchases |
| 13 Permissions | `daily-purchase` GET only |
| 14 Business rules | — |
| 15 Bugs | counts pending POs |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** — merge into Trends |

#### 3.16.8 Monthly Purchase (SB:466-470)

| Attr | Detail |
|---|---|
| 1 Menu | Monthly Purchase |
| 2 Submenus | none |
| 3 Route | GET `report/monthly_purchase/{y}` → RC:2020; POST → RC:2058 |
| 4 Purpose | 12-month purchases |
| 5 Main functionality | 12 months |
| 6 Actions | year navigation |
| 7 Forms & fields | warehouse |
| 8 Table columns | 12 months |
| 9 Search/filter | year, warehouse |
| 10 Validation | none |
| 11 Endpoints | POST variant |
| 12 DB tables | purchases |
| 13 Permissions | `monthly-purchase` GET only |
| 14 Business rules | — |
| 15 Bugs | — |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** — merge into Trends |

#### 3.16.9 Sale Report (SB:471-480)

| Attr | Detail |
|---|---|
| 1 Menu | Sale Report |
| 2 Submenus | none |
| 3 Route | POST `report/sale_report` · `report.sale` → RC:3622; POST `sale_report_data` → RC:3633 |
| 4 Purpose | Item-wise sales |
| 5 Main functionality | per-product loop |
| 6 Actions | view, exports |
| 7 Forms & fields | start_date, end_date, warehouse_id |
| 8 Table columns | Product, Category, Sold Amount, Sold Qty, Stock |
| 9 Search/filter | date, warehouse |
| 10 Validation | none |
| 11 Endpoints | `sale_report_data` |
| 12 DB tables | product_sales, products, product_warehouse |
| 13 Permissions | sidebar `sale-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | **qty overwritten with `=` instead of `+=`** (RC:3755); paging mismatch |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** |

#### 3.16.10 Challan Report (SB:481)

| Attr | Detail |
|---|---|
| 1 Menu | Challan Report |
| 2 Submenus | none |
| 3 Route | GET `report/challan-report` · `report.challan` → RC:3881 |
| 4 Purpose | Courier challan collections |
| 5 Main functionality | list of challans with amounts by method |
| 6 Actions | view, client-side exports |
| 7 Forms & fields | date, `based_on` |
| 8 Table columns | Challan, Order, dates, amounts by method, shipping, delivery, net |
| 9 Search/filter | date; `based_on` column name taken from the request (RC:3893) |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | challans, packing_slips |
| 13 Permissions | **none** (sidebar ungated) |
| 14 Business rules | — |
| 15 Bugs | column-name injection surface; N+1 `PackingSlip::find` in view |
| 16 Dependencies | Challan List |
| 17 Recommendation | **Retire-candidate (needs business confirmation)** — WP: drop unless delivery is used |

#### 3.16.11 Sale Report Chart (SB:482-492)

| Attr | Detail |
|---|---|
| 1 Menu | Sale Report Chart |
| 2 Submenus | none |
| 3 Route | POST `report/sale-report-chart` · `report.saleChart` → RC:3898 |
| 4 Purpose | Sales qty trend |
| 5 Main functionality | line chart, one SUM per bucket |
| 6 Actions | view |
| 7 Forms & fields | start_date, end_date, warehouse_id, time_period (weekly), product codes |
| 8 Table columns | chart |
| 9 Search/filter | date, warehouse, weekly/monthly, products |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | product_sales |
| 13 Permissions | sidebar `sale-report-chart`; controller none |
| 14 Business rules | — |
| 15 Bugs | mixed units |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** |

#### 3.16.12 Payment Report (SB:493-501)

| Attr | Detail |
|---|---|
| 1 Menu | Payment Report |
| 2 Submenus | none |
| 3 Route | POST `report/payment_report_by_date` · `report.paymentByDate` → RC:3937 |
| 4 Purpose | Payments list |
| 5 Main functionality | unbounded `get()` |
| 6 Actions | view, client exports |
| 7 Forms & fields | start_date, end_date |
| 8 Table columns | Date, Payment Ref, Sale Ref, Purchase Ref, Paid By, Amount, Created By |
| 9 Search/filter | date |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | payments, sales, purchases, users |
| 13 Permissions | sidebar `payment-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | no in/out sign; no warehouse; fatal when user deleted; 3 lookups/row |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (payments ledger) |

#### 3.16.13 Purchase Report (SB:502-511)

| Attr | Detail |
|---|---|
| 1 Menu | Purchase Report |
| 2 Submenus | none |
| 3 Route | POST `report/purchase` · `report.purchase` → RC:3312; POST `purchase_report_data` → RC:3387 |
| 4 Purpose | Item-wise purchases |
| 5 Main functionality | page load loops the whole catalogue (RC:3322-3381) |
| 6 Actions | view, exports |
| 7 Forms & fields | start_date, end_date, warehouse_id |
| 8 Table columns | Product, Category, Purchased Amount/Qty, Stock |
| 9 Search/filter | date, warehouse |
| 10 Validation | none |
| 11 Endpoints | `purchase_report_data` |
| 12 DB tables | product_purchases, products |
| 13 Permissions | sidebar `purchase-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | null fatal (RC:3376) |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** |

#### 3.16.14 Customer Report (SB:512-516)

| Attr | Detail |
|---|---|
| 1 Menu | Customer Report |
| 2 Submenus | none |
| 3 Route | modal `#customer-modal` (MAIN:953-967) → POST `report/customer_report` → RC:5542; data RC:5558/5687/5772/5885 |
| 4 Purpose | Customer activity |
| 5 Main functionality | tabs Sales/Payments/Returns/Quotations |
| 6 Actions | view |
| 7 Forms & fields | customer, date |
| 8 Table columns | per tab |
| 9 Search/filter | customer, date |
| 10 Validation | none |
| 11 Endpoints | 4 AJAX tables |
| 12 DB tables | sales, payments, returns, quotations |
| 13 Permissions | sidebar `customer-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | `DB::reconnect()` per row (RC:5647-5656); search leak; status key mismatch; duplicate DOM id `customer-report-menu` |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (customer statement) |

#### 3.16.15 Customer Group Report (SB:517-521)

| Attr | Detail |
|---|---|
| 1 Menu | Customer Group Report |
| 2 Submenus | none |
| 3 Route | modal (MAIN:989-1003) → POST `report/customer-group` → RC:5988; data RC:6003/6120/6207/6322 |
| 4 Purpose | Group activity |
| 5 Main functionality | same tabs |
| 6 Actions | view |
| 7 Forms & fields | group, date |
| 8 Table columns | per tab |
| 9 Search/filter | group, date |
| 10 Validation | none |
| 11 Endpoints | 4 AJAX tables |
| 12 DB tables | as 3.16.14 + customer_groups |
| 13 Permissions | gated by `customer-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | modal sends `start_date`, controller reads `starting_date` (RC:5991) |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Retire-candidate (needs business confirmation)** — WP: drop or merge |

#### 3.16.16 Customer Due Report (SB:522-530)

| Attr | Detail |
|---|---|
| 1 Menu | Customer Due Report |
| 2 Submenus | none |
| 3 Route | POST `report/customer-due-report` · `report.customerDueByDate` → RC:6884; data RC:6913 |
| 4 Purpose | Receivables |
| 5 Main functionality | due per sale |
| 6 Actions | view, exports |
| 7 Forms & fields | start_date (−1 year), end_date, customer |
| 8 Table columns | Date, Ref, Customer, Grand Total, Returned, Paid, Due |
| 9 Search/filter | date, customer |
| 10 Validation | none |
| 11 Endpoints | data endpoint |
| 12 DB tables | sales, returns, payments, customers |
| 13 Permissions | sidebar `due-report`; controller none |
| 14 Business rules | due = total − returns (N+1) − paid |
| 15 Bugs | "all customers" likely returns 0 rows (`=== 0` vs "0", RC:6950; UNVERIFIED); no aging |
| 16 Dependencies | Customer clear-due |
| 17 Recommendation | **Redesign** (receivables with aging; high value) |

#### 3.16.17 Supplier Report (SB:531-535)

| Attr | Detail |
|---|---|
| 1 Menu | Supplier Report |
| 2 Submenus | none |
| 3 Route | modal (MAIN:1025-1039) → POST `report/supplier` → RC:6460; data RC:6475/6590/6674/6775 |
| 4 Purpose | Supplier activity |
| 5 Main functionality | tabs Purchases/Payments/Returns/Quotations |
| 6 Actions | view |
| 7 Forms & fields | supplier, date |
| 8 Table columns | per tab |
| 9 Search/filter | supplier, date |
| 10 Validation | none |
| 11 Endpoints | 4 AJAX tables |
| 12 DB tables | purchases, payments, return_purchases, quotations |
| 13 Permissions | sidebar `supplier-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | search leak |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (supplier ledger) |

#### 3.16.18 Supplier Due Report (SB:536-544)

| Attr | Detail |
|---|---|
| 1 Menu | Supplier Due Report |
| 2 Submenus | none |
| 3 Route | POST `report/supplier-due-report` · `report.supplierDueByDate` → RC:7028 |
| 4 Purpose | Payables |
| 5 Main functionality | unbounded list |
| 6 Actions | view, client exports |
| 7 Forms & fields | start_date (−1 year), end_date |
| 8 Table columns | Date, Ref, Supplier, Total, Returned, Paid, Due |
| 9 Search/filter | date |
| 10 Validation | none |
| 11 Endpoints | `supplier-due-report-data` (**missing method**, W:910) |
| 12 DB tables | purchases, return_purchases, payments, suppliers |
| 13 Permissions | sidebar `supplier-due-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | N+1; dead data route |
| 16 Dependencies | Purchase Return |
| 17 Recommendation | **Redesign** (payables with aging) |

#### 3.16.19 Warehouse Report (SB:545-549)

| Attr | Detail |
|---|---|
| 1 Menu | Warehouse Report |
| 2 Submenus | none |
| 3 Route | modal (MAIN:838-859) → POST `report/warehouse_report` → RC:3947; data RC:3963/4077/4196/4309/4411 |
| 4 Purpose | Branch activity |
| 5 Main functionality | tabs Sales/Purchases/Quotations/Returns/Expenses |
| 6 Actions | view |
| 7 Forms & fields | warehouse, date |
| 8 Table columns | per tab |
| 9 Search/filter | warehouse, date |
| 10 Validation | none |
| 11 Endpoints | 5 AJAX tables |
| 12 DB tables | sales, purchases, quotations, returns, expenses |
| 13 Permissions | sidebar `warehouse-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | search leak exposes other warehouses (RC:4000-4019) |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (branch dashboard) |

#### 3.16.20 Warehouse Stock Chart (SB:550-554)

| Attr | Detail |
|---|---|
| 1 Menu | Warehouse Stock Chart |
| 2 Submenus | none |
| 3 Route | GET `report/warehouse_stock` · `report.warehouseStock` → RC:1718 |
| 4 Purpose | Stock valuation |
| 5 Main functionality | KPIs, pie of stock value by price/cost/profit |
| 6 Actions | view |
| 7 Forms & fields | warehouse |
| 8 Table columns | chart |
| 9 Search/filter | warehouse |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | products, product_warehouse |
| 13 Permissions | `warehouse-stock-report` RC:1721 |
| 14 Business rules | products.qty × master cost |
| 15 Bugs | includes expired stock |
| 16 Dependencies | — |
| 17 Recommendation | **Migrate** — as batch-based valuation |

#### 3.16.21 Product Expiry Report (SB:555-559)

| Attr | Detail |
|---|---|
| 1 Menu | Product Expiry Report |
| 2 Submenus | none |
| 3 Route | GET `report/product-expiry` · `report.productExpiry` → RC:1701 |
| 4 Purpose | Near-expiry and expired stock |
| 5 Main functionality | products ⋈ product_batches where expired_date ≤ today+N and qty>0 |
| 6 Actions | view, client exports |
| 7 Forms & fields | — |
| 8 Table columns | Image, Name, Code, Batch, Expiry, Qty |
| 9 Search/filter | horizon from `general_settings.expiry_value/type` |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | products, product_batches, general_settings |
| 13 Permissions | sidebar `product-expiry-report`; controller **none** |
| 14 Business rules | — |
| 15 Bugs | no branch scope; no value column; unbounded; misses expiry kept only in `products.expire_date` |
| 16 Dependencies | Purchases (batches), General Setting |
| 17 Recommendation | **Migrate** — WP: preserve and enhance (critical for a pharmacy) |

#### 3.16.22 Product Quantity Alert (SB:560-564)

| Attr | Detail |
|---|---|
| 1 Menu | Product Quantity Alert (also header alert link MAIN:318) |
| 2 Submenus | none |
| 3 Route | GET `report/product_quantity_alert` · `report.qtyAlert` → RC:63 |
| 4 Purpose | Low-stock list |
| 5 Main functionality | `alert_quantity > qty`, global |
| 6 Actions | view, client exports |
| 7 Forms & fields | — |
| 8 Table columns | Image, Name, Code, Qty, Alert Qty |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | none |
| 12 DB tables | products |
| 13 Permissions | `product-qty-alert` RC:66 |
| 14 Business rules | — |
| 15 Bugs | not per warehouse; dead `productQuantityAlert_` (RC:53) |
| 16 Dependencies | Common middleware alert count |
| 17 Recommendation | **Migrate** — merge with 3.16.23 |

#### 3.16.23 Product Order Report (SB:565-569)

| Attr | Detail |
|---|---|
| 1 Menu | Product Order Report |
| 2 Submenus | none (15 sub-routes) |
| 3 Route | GET `report/product_order_quantity_alert` · `report.OrderqtyAlert` → RC:234; sub-routes W:828-842 incl. POST `report/create-purchase-from-orders` → `createPurchaseFromOrders` RC:972 (W:841) |
| 4 Purpose | AI-assisted reorder and draft POs (core purchasing workflow) |
| 5 Main functionality | sales vs stock, AI forecast, draft orders, create Pending purchases per supplier |
| 6 Actions | forecast, edit drafts, create purchases, export |
| 7 Forms & fields | brand ("supplier"), date; per-row Box/Strip/Order qty |
| 8 Table columns | Code, Product, Category, Sold, Stock, AI Forecast, Box/Strip/Order Qty |
| 9 Search/filter | brand, date |
| 10 Validation | — |
| 11 Endpoints | 15 sub-routes (W:828-842); called from `report/order_qty_draft_product_lists.blade.php:572` |
| 12 DB tables | products, product_sales, product_warehouse, draft tables (UNVERIFIED names), purchases, product_purchases |
| 13 Permissions | `product-qty-alert` on page only; **write endpoints unchecked** (RC:903, 959, 972; full method UNVERIFIED) |
| 14 Business rules | createPurchaseFromOrders groups by supplier_id → one Pending (status 3), unpaid purchase per supplier, recieved=0 (no stock); transaction; reference `Ymd-his-supplierId` |
| 15 Bugs | draft update deletes outside the transaction; `orderBy('name')` on missing column; brand used as supplier |
| 16 Dependencies | Purchases (receive via Edit), OpenAI services, box/strip/piece columns |
| 17 Recommendation | **Redesign** — WP: preserve and redesign (real suppliers, permissions) |

#### 3.16.24 Custom Product Sales Report (SB:570-574)

| Attr | Detail |
|---|---|
| 1 Menu | Custom Product Sales Report |
| 2 Submenus | none |
| 3 Route | GET `report/custom-product-report` · `report.customProduct` → RC:1203; GET data → RC:1216 |
| 4 Purpose | Sales of non-catalogue (custom) POS items |
| 5 Main functionality | SQL-paged list, discount pro-rated in PHP |
| 6 Actions | view, exports |
| 7 Forms & fields | date, search |
| 8 Table columns | Code, Product, Brand, Sale Ref, Customer, Qty, Price, Discount, Tax, Total, Dates |
| 9 Search/filter | date, search |
| 10 Validation | none |
| 11 Endpoints | data endpoint |
| 12 DB tables | custom_product_sales, sales, customers |
| 13 Permissions | `sales-index` on page only; data ungated |
| 14 Business rules | — |
| 15 Bugs | ungated data |
| 16 Dependencies | POS custom product |
| 17 Recommendation | **Migrate** |

#### 3.16.25 Adjustment Report (SB:575-577)

| Attr | Detail |
|---|---|
| 1 Menu | Adjustment Report |
| 2 Submenus | none |
| 3 Route | GET `report/adjustment` · `report.adjustment` → RC:589; POST `adjustment-data` → RC:600 |
| 4 Purpose | Stock adjustment audit |
| 5 Main functionality | proper joins, whitelisted ordering |
| 6 Actions | view, exports |
| 7 Forms & fields | date, warehouse, product (`/products/get-search-library`, adjustment_report:93) |
| 8 Table columns | Date, Ref, Warehouse, Product, Code, ±, Qty, Unit Cost, User, Note |
| 9 Search/filter | date, warehouse, product |
| 10 Validation | none |
| 11 Endpoints | `adjustment-data` |
| 12 DB tables | adjustments, product_adjustments, products, warehouses, users |
| 13 Permissions | **none** (only role existence) |
| 14 Business rules | — |
| 15 Bugs | leaks exception messages (RC:762); User column empty because adjustments.user_id never saved (3.3.6) |
| 16 Dependencies | Adjustments |
| 17 Recommendation | **Migrate** — WP: preserve and add a reason code |

#### 3.16.26 Daily Sale Objective Report (SB:578-582)

| Attr | Detail |
|---|---|
| 1 Menu | Daily Sale Objective Report (also header DSO alert MAIN:322) |
| 2 Submenus | none |
| 3 Route | GET `report/daily-sale-objective` · `report.dailySaleObjective` → RC:1346; POST data → RC:1635 |
| 4 Purpose | Products that missed a daily sale objective |
| 5 Main functionality | reads `dso_alerts` (cron `dsoalert:find`) |
| 6 Actions | view, exports |
| 7 Forms & fields | date |
| 8 Table columns | Date, Product Info, Count |
| 9 Search/filter | date |
| 10 Validation | none |
| 11 Endpoints | data endpoint |
| 12 DB tables | dso_alerts |
| 13 Permissions | `dso-report` page only |
| 14 Business rules | — |
| 15 Bugs | `.=` then `=` overwrite shows only last product (RC:1684-1686) |
| 16 Dependencies | scheduler |
| 17 Recommendation | **Retire-candidate (needs business confirmation)** — WP: drop or replace with a dead-stock report |

#### 3.16.27 User Report (SB:583-587)

| Attr | Detail |
|---|---|
| 1 Menu | User Report |
| 2 Submenus | none |
| 3 Route | modal (MAIN:881-895) → POST `report/user_report` → RC:4495; 7 data endpoints RC:4823-5455 |
| 4 Purpose | Per-user activity |
| 5 Main functionality | tabs Sales, Purchases, Quotations, Transfers, Payments, Expenses, Payroll |
| 6 Actions | view |
| 7 Forms & fields | user, date |
| 8 Table columns | per tab |
| 9 Search/filter | user, date |
| 10 Validation | none |
| 11 Endpoints | 7 AJAX tables |
| 12 DB tables | sales, purchases, quotations, transfers, payments, expenses, payrolls |
| 13 Permissions | sidebar `user-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | exposes payroll without `payroll-report` |
| 16 Dependencies | (shared) |
| 17 Recommendation | **Redesign** (cashier/shift report) |

#### 3.16.28 Biller Report (SB:588-592)

| Attr | Detail |
|---|---|
| 1 Menu | Biller Report |
| 2 Submenus | none |
| 3 Route | modal (MAIN:917-931, duplicate `id="user-id"`) → POST `report/biller_report` → RC:4505; data RC:4515/4632/4740 |
| 4 Purpose | Per-biller activity |
| 5 Main functionality | tabs Sales, Quotations, Payments |
| 6 Actions | view |
| 7 Forms & fields | biller, date |
| 8 Table columns | per tab |
| 9 Search/filter | biller, date |
| 10 Validation | none |
| 11 Endpoints | 3 AJAX tables |
| 12 DB tables | sales, quotations, payments |
| 13 Permissions | sidebar `biller-report`; controller none |
| 14 Business rules | — |
| 15 Bugs | duplicate DOM id in modal |
| 16 Dependencies | Billers |
| 17 Recommendation | **Retire-candidate (needs business confirmation)** — WP: drop or merge |

### 3.17 Addons / WooCommerce / eCommerce (SB:596-610, whole group commented) — WP2 §10

| Attr | Detail |
|---|---|
| 1 Menu | Addons, WooCommerce, eCommerce (all hidden) |
| 2 Submenus | Addons (SB:598) → `url('addon-list')`; WooCommerce (SB:601) → `route('woocommerce.index')`; eCommerce (SB:604-608) → `@include('ecommerce::backend.layout.sidebar-menu')` |
| 3 Route | GET `addon-list` (W:213) → `HomeController@addonList`; POST `woocommerce-install`, `ecommerce-install` (W:1140-1142); `woocommerce.index` is **not defined** |
| 4 Purpose | Vendor add-on marketplace / installers |
| 5 Main functionality | buy/install modals; installers download vendor zips into `Modules/`, migrate, set the modules flag |
| 6 Actions | install |
| 7 Forms & fields | — |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | installers |
| 12 DB tables | general_settings.modules |
| 13 Permissions | `addons` on the page; installers callable by any active user |
| 14 Business rules | Group shown only to role_id≠5; Addons only when not SaaS landlord; Woo/eCommerce only with the matching module flag |
| 15 Bugs | **Blade inside `<!-- -->` still runs**: with `woocommerce` or `ecommerce` in `general_settings.modules`, every backend page 500s (RouteNotFound / no hint path). `modules_statuses.json` lists 3 phantom modules (only Manufacturing exists). Remote code install. Live DB value UNVERIFIED |
| 16 Dependencies | `modules_statuses.json`, `customerauth` alias (Kernel.php:50) |
| 17 Recommendation | **Fix-before-migrate** (delete the block and the installers now). WP note: remove |

### 3.18 Settings group (SB:611-736) — WP2 §8; Brand/Unit/Barcode also WP1 §2.7-2.9

The parent group has **no gate**, so it is always shown.

#### 3.18.1 Role Permission (SB:659)

| Attr | Detail |
|---|---|
| 1 Menu | Role Permission |
| 2 Submenus | none. The permission matrix is hidden (`role.permission`) |
| 3 Route | GET `/role` · `role.index` (W:267) → RoleController@index :16; POST store :32; PUT `role/{id}` update :58; DELETE destroy :1538; GET `role/permission/{id}` · `role.permission` :75; POST `role/set_permission` · `role.setPermission` :90 |
| 4 Purpose | RBAC administration |
| 5 Main functionality | Role list and modal; permission matrix of 153 checkboxes in 30 sections |
| 6 Actions | add, edit, delete role; set permissions |
| 7 Forms & fields | Role: name, description, is_active. Matrix: 153 checkboxes + hidden role_id |
| 8 Table columns | checkbox, Name, Description, Action **[src]** (V/role/create:21-26) |
| 9 Search/filter | — |
| 10 Validation | name max 255, unique among active |
| 11 Endpoints | `role/set_permission` |
| 12 DB tables | roles, permissions, role_has_permissions (Spatie) |
| 13 Permissions | Sidebar: `$role->id <= 2` (**globally cached $role**). index, edit and permission check role_id ≤2. **store, update, setPermission and destroy check nothing** |
| 14 Business rules | A user has a single `users.role_id`. Checks use `Role::find(role_id)->hasPermissionTo()`. Hard-coded role ids: 1, 2 (super), 5 (customer), 6 (supplier/purchase-only); names UNVERIFIED |
| 15 Bugs | **Any logged-in user can grant themselves every permission** (POST set_permission). `Role::firstOrCreate(['id'=>…])` can create roles. Only the `permissions` cache key is cleared, so menus stay stale. ~1430-line hand-written if/else. Soft delete leaves users on the role. 8 permissions are settable but never checked (`department-add/edit/delete`, `attendance-edit`, `users-delete`, `billers-delete`, `suppliers-delete`, `discount`) |
| 16 Dependencies | Common middleware caches; every module |
| 17 Recommendation | **Fix-before-migrate** (lock down setPermission/store/update/destroy now). WP note: then redesign as policy-based RBAC |

#### 3.18.2 Warehouse (SB:682)

| Attr | Detail |
|---|---|
| 1 Menu | Warehouse |
| 2 Submenus | none |
| 3 Route | resource `warehouse` · `warehouse.index` (W:335) → WarehouseController@index :16; import, deletebyselection, `lims_warehouse_search`, `warehouse/all` |
| 4 Purpose | Branch master |
| 5 Main functionality | list, add/edit modals, import |
| 6 Actions | add, edit, delete (soft), import, bulk delete |
| 7 Forms & fields | name, phone, email, address |
| 8 Table columns | checkbox, Warehouse, Phone Number, Email, Address, Number of Product, Stock Quantity, Action **[src]** (V/warehouse/create:21-30) |
| 9 Search/filter | — |
| 10 Validation | name unique among active (:25-32) |
| 11 Endpoints | `warehouse/all` (scoped only when role >2, :128), `lims_warehouse_search` |
| 12 DB tables | warehouses |
| 13 Permissions | sidebar `warehouse`; controller **none** |
| 14 Business rules | — |
| 15 Bugs | `$request->all()` create; import unchecked |
| 16 Dependencies | every stock module, staff_access=warehouse |
| 17 Recommendation | **Migrate** — as Branch |

#### 3.18.3 Customer Group (SB:686)

| Attr | Detail |
|---|---|
| 1 Menu | Customer Group |
| 2 Submenus | none |
| 3 Route | resource `customer_group` · `customer_group.index` (W:359) + helpers → CustomerGroupController@index :18 |
| 4 Purpose | Customer pricing groups |
| 5 Main functionality | list, add/edit |
| 6 Actions | add, edit, delete |
| 7 Forms & fields | name, percentage |
| 8 Table columns | checkbox, Name, Percentage, Action **[src]** (V/customer_group/create:21-26) |
| 9 Search/filter | — |
| 10 Validation | name unique |
| 11 Endpoints | helpers |
| 12 DB tables | customer_groups |
| 13 Permissions | `customer_group` on index :21 only |
| 14 Business rules | percentage is applied as a markup in POS (pos:4309-4312) |
| 15 Bugs | writes unguarded; POS price compounding (3.5.2) |
| 16 Dependencies | POS, Customers |
| 17 Recommendation | **Migrate** — or merge into membership |

#### 3.18.4 Brand (SB:689)

| Attr | Detail |
|---|---|
| 1 Menu | Brand |
| 2 Submenus | none |
| 3 Route | resource `brand` · `brand.index` → BrandController@index :19; POST `importbrand` (`brand.import`), POST `brand/deletebyselection`, GET `brand/lims_brand_search` (`brand.search`, **method missing**) (W:306-313) |
| 4 Purpose | Brand master. In practice this is the manufacturer, and it is used as the "supplier" in the Product Order report |
| 5 Main functionality | server-rendered list of all active brands, create/edit/import modals |
| 6 Actions | add, edit, delete, bulk delete, import, export (broken) |
| 7 Forms & fields | title\*, image, page_title, short_description |
| 8 Table columns | Image, Brand, Action |
| 9 Search/filter | — |
| 10 Validation | title max:255 unique among active; image `image\|mimes\|max:100000` (:29, :75) |
| 11 Endpoints | view calls POST `/exportbrand` (**not routed**; `exportBrand()` :212 unreachable) |
| 12 DB tables | brands, products |
| 13 Permissions | sidebar `brand`; **no check in any method** |
| 14 Business rules | destroy refuses when products use the brand (:188-193) |
| 15 Bugs | deleteBySelection skips the in-use check (:149); import takes image from CSV; mass-assign `$request->except('image')` |
| 16 Dependencies | Products, Product Order report |
| 17 Recommendation | **Migrate** — as Manufacturer |

#### 3.18.5 Unit (SB:692)

| Attr | Detail |
|---|---|
| 1 Menu | Unit |
| 2 Submenus | none |
| 3 Route | resource `unit` · `unit.index` → UnitController@index :14; `importunit`, `unit/deletebyselection`, `unit/lims_unit_search` (registered **twice**, W:277-284 and W:286-293) |
| 4 Purpose | Units and conversions (box/strip/piece) |
| 5 Main functionality | list, add/edit, import |
| 6 Actions | add, edit, delete, import, export (broken) |
| 7 Forms & fields | unit_code\*, unit_name\*, base_unit, operator, operation_value |
| 8 Table columns | Code, Name, Base Unit, Operator, Operation Value, Action |
| 9 Search/filter | — |
| 10 Validation | code and name max:255 unique among active (:27, :69) |
| 11 Endpoints | `unit/lims_unit_search` is shadowed by `show` (dead); view calls POST `/exportunit` (**not routed**, :283) |
| 12 DB tables | units |
| 13 Permissions | `unit` on index :17 only. **Gift Card and Coupon index are also gated on `unit`** (GiftCardController:23, CouponController:20) |
| 14 Business rules | conversion via operator `*` or `/` and operation_value is used in every stock movement |
| 15 Bugs | Changing operator or value retroactively changes all conversions, with no guard. Delete has no in-use check. operator is free text. Import fatals on an unknown base unit (:120) |
| 16 Dependencies | Products, POS, purchases, transfers |
| 17 Recommendation | **Migrate**. WP notes: WP1 "migrate, lock conversions"; WP2 "preserve" |

#### 3.18.6 User Profile (SB:700)

| Attr | Detail |
|---|---|
| 1 Menu | User Profile (also header MAIN:372) |
| 2 Submenus | none |
| 3 Route | GET `user/profile/{id}` · `user.profile`; PUT `user/update_profile/{id}`; PUT `user/changepass/{id}` (W:919-921) → UserController@profile / profileUpdate :186 / changePassword |
| 4 Purpose | Own account maintenance |
| 5 Main functionality | edit profile, change password |
| 6 Actions | save, change password |
| 7 Forms & fields | name, email, phone, company; password change |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | — |
| 11 Endpoints | update_profile, changepass |
| 12 DB tables | users |
| 13 Permissions | **none** |
| 14 Business rules | sidebar passes Auth::id() |
| 15 Bugs | **IDOR**: `{id}` is not checked against the logged-in user. `User::find($id)->update($request->all())` allows mass assignment, including role_id |
| 16 Dependencies | — |
| 17 Recommendation | **Fix-before-migrate**. WP note: then redesign as "my account" |

#### 3.18.7 General Setting (SB:708)

| Attr | Detail |
|---|---|
| 1 Menu | General Setting (also header Settings shortcut MAIN:376) |
| 2 Submenus | none |
| 3 Route | GET `setting/general_setting` · `setting.general` → SettingController :74; POST `general_setting_store` · `setting.generalStore` → :89 |
| 4 Purpose | Global configuration |
| 5 Main functionality | single form |
| 6 Actions | save |
| 7 Forms & fields | site_title, site_logo, is_rtl, is_zatca, company_name, vat_registration_number, currency, currency_position, decimal, staff_access, without_stock, is_packing_slip, date_format, developed_by, invoice_format, state, timezone, expiry_type, expiry_value |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | only site_logo is validated as an image (:91-93) |
| 11 Endpoints | — |
| 12 DB tables | general_settings; **`.env` (APP_TIMEZONE)** |
| 13 Permissions | sidebar `general_setting`; controller **none** |
| 14 Business rules | staff_access, without_stock, is_packing_slip and expiry_* drive core behaviour (POS stock check, scoping, expiry report) |
| 15 Bugs | Writes `.env` without validation (:96-101). `date_default_timezone_set` loops over every zone (:81-84) |
| 16 Dependencies | Common middleware, POS, reports |
| 17 Recommendation | **Migrate**. WP note: add validation and stop writing `.env` |

#### 3.18.8 Reward Point Setting (SB:714)

| Attr | Detail |
|---|---|
| 1 Menu | Reward Point Setting |
| 2 Submenus | none |
| 3 Route | GET `setting/reward-point-setting` · `setting.rewardPoint` → :251; POST → :257 |
| 4 Purpose | Legacy points rules (ledger L1) |
| 5 Main functionality | single form |
| 6 Actions | save |
| 7 Forms & fields | is_active, per_point_amount, minimum_amount, duration, type |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | — |
| 12 DB tables | reward_point_settings |
| 13 Permissions | sidebar `reward_point_setting`; controller none |
| 14 Business rules | POS earns floor(grand_total/per_point_amount) when ≥ minimum (SC:1301-1307) |
| 15 Bugs | duplicates the new Reward engine (3.2.7) |
| 16 Dependencies | POS L1 |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: retire once L4 is the canonical ledger |

#### 3.18.9 Payment Gateways (SB:720)

| Attr | Detail |
|---|---|
| 1 Menu | Payment Gateways |
| 2 Submenus | none |
| 3 Route | GET `setting/payment-gateways/list` · `setting.gateway` → :594; POST `…/update` → :619 |
| 4 Purpose | Gateway credentials |
| 5 Main functionality | per-gateway credential form |
| 6 Actions | save |
| 7 Forms & fields | `pg_name[]`, `{pg}_{key}` credential fields, `active[]`, `module_status[]` (pos/ecommerce) |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | — |
| 12 DB tables | external_services (`details` = "keys;values" CSV) |
| 13 Permissions | **yes** (:596-614, :621-638): a raw role_has_permissions query, falling back to role ≤2 when the permission row is missing |
| 14 Business rules | — |
| 15 Bugs | credentials stored in plain text as CSV; a comma in a secret corrupts the row |
| 16 Dependencies | Stripe/PayPal paths in sale and purchase payments |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: keep only the local wallets actually in use (UNVERIFIED which) |

#### 3.18.10 POS Settings (SB:723)

| Attr | Detail |
|---|---|
| 1 Menu | POS Settings |
| 2 Submenus | none |
| 3 Route | GET `setting/pos_setting` · `setting.pos` → :713; POST → :728 |
| 4 Purpose | POS defaults |
| 5 Main functionality | single form |
| 6 Actions | save |
| 7 Forms & fields | customer_id, warehouse_id, biller_id (defaults), product_number, options[] (payment methods), invoice_size, thermal_invoice_size, keybord_active, is_table, send_sms |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | duplicate check on options only (:735-741) |
| 11 Endpoints | — |
| 12 DB tables | pos_setting |
| 13 Permissions | sidebar `pos_setting`; controller none. The POS header link checks it through a raw DB query (pos:174-208) |
| 14 Business rules | invoice template choice (A4/58mm/default); virtual keyboard; SMS on sale |
| 15 Bugs | `is_table` is a restaurant feature |
| 16 Dependencies | POS, invoices |
| 17 Recommendation | **Migrate** (pharmacy subset) |

#### 3.18.11 HRM Setting (SB:726)

| Attr | Detail |
|---|---|
| 1 Menu | HRM Setting |
| 2 Submenus | none |
| 3 Route | GET `setting/hrm_setting` · `setting.hrm` → :697; POST → :703 |
| 4 Purpose | Office hours |
| 5 Main functionality | form |
| 6 Actions | save |
| 7 Forms & fields | checkin, checkout |
| 8 Table columns | — |
| 9 Search/filter | — |
| 10 Validation | none |
| 11 Endpoints | — |
| 12 DB tables | hrm_settings |
| 13 Permissions | sidebar `hrm_setting`; controller none |
| 14 Business rules | drives the attendance late flag |
| 15 Bugs | — |
| 16 Dependencies | Attendance |
| 17 Recommendation | **Migrate** (only if HRM is kept) |

#### 3.18.12 Barcode Settings (SB:729)

| Attr | Detail |
|---|---|
| 1 Menu | Barcode Settings |
| 2 Submenus | none |
| 3 Route | resource `barcodes` · `barcodes.index` (W:967); POST `barcodes/barcode-data` (`barcodes.data`); GET `barcodes/set_default/{id}` (W:961; **method missing**) → BarcodeController |
| 4 Purpose | Label sheet templates |
| 5 Main functionality | list of custom templates (`is_custom=1`); create/edit |
| 6 Actions | add, edit, delete (does nothing) |
| 7 Forms & fields | name, description, width, height, top_margin, left_margin, row_distance, col_distance, stickers_in_one_row, paper_width, paper_height, stickers_in_one_sheet, is_default, is_continuous, is_custom |
| 8 Table columns | template list |
| 9 Search/filter | — |
| 10 Validation | none (try/catch) |
| 11 Endpoints | `barcodes/barcode-data` |
| 12 DB tables | barcodes |
| 13 Permissions | `hasPermission()` on index, data, create, store, edit and update (:13-27). This is the one controller that checks every action. destroy and setDefault are unchecked |
| 14 Business rules | — |
| 15 Bugs | destroy and show are empty (:180, :254); set_default is a missing method |
| 16 Dependencies | Print Barcode |
| 17 Recommendation | **Preserve-as-is**. WP note: preserve (shelf label printing) |

#### 3.18.13 Shipping Setting (SB:732)

| Attr | Detail |
|---|---|
| 1 Menu | Shipping Setting |
| 2 Submenus | none |
| 3 Route | GET `shipping-zones` · `shipping-zones.index` → ShippingZoneController :13; POST `load`, `store`, `update`, `delete` (W:1144-1156) |
| 4 Purpose | Delivery zones and rates |
| 5 Main functionality | zone list with create/edit |
| 6 Actions | add, edit, delete (hard) |
| 7 Forms & fields | division, district, thana, default_rate, apply_default_rate, estimated_delivery, is_active, notes, delivery_partner (rider/pathao/steadfast/sundarban/redx) |
| 8 Table columns | Division, District, Thana, Rate, Estimated Delivery, Partner, Status, Actions **[src]** (V/setting/shipping_zones:89-96) |
| 9 Search/filter | via `load` |
| 10 Validation | `Validator::make` (:75-85, :138, :161) |
| 11 Endpoints | `shipping-zones/{load,store,update,delete}` |
| 12 DB tables | shipping_zones |
| 13 Permissions | sidebar `shipping_setting`; controller **none** |
| 14 Business rules | — |
| 15 Bugs | hard delete; logs full requests (`Log::info($request->all())`, :86) |
| 16 Dependencies | Online orders / app checkout (UNVERIFIED consumer) |
| 17 Recommendation | **Retire-candidate (needs business confirmation)**. WP note: keep only if home delivery is used |

#### 3.18.H Commented-out Settings entries (routes still live) — WP2 §8

| SB line | Item | Route | Controller / permission | Notes | Recommendation |
|---|---|---|---|---|---|
| 660 | SMS Template | resource `smstemplates` (W:276) | SmsTemplateController :10-67, **no checks** | `create($request->all())`; hard delete; the `is_default_ecommerce` branch resets the wrong column (:27-29) | Migrate (if SMS receipts are used) |
| 662 | Custom Field List | resource `custom-fields` (W:1138) | CustomFieldController; `custom_field` on index/create/edit | alters tables dynamically (UNVERIFIED); hard delete :249 | Retire-candidate |
| 666 | Discount Plan | resource `discount-plans` (W:362-364) | `discount_plan` | POS limsProductSearch applies customer discount plans | Retire-candidate (or promotions) |
| 669 | Discount | resource `discounts`, `discounts/product-search/{code}` | checks `discount_plan` (the `discount` permission is unused) | — | Retire-candidate |
| 673 | All Notification | GET `notifications` (W:1123-1133) | `all_notification` on index | — | Redesign (in-app notification service) |
| 678 | Send Notification | POST `notifications/store` | — | — | Redesign |
| 684 | Tables | resource `tables` (W:338) | TableController, none | restaurant feature | Retire-candidate |
| 695 | Currency | resource `currency` (W:1136) | `currency` on index | single currency (BDT) | Retire-candidate (config only) |
| 698 | Tax | resource `tax` + import/search (W:341-348) | `tax` on index | VAT may be needed | Redesign (simplified) |
| 702 | Create SMS | GET `setting/createsms`, POST `setting/sendsms` (W:944-945) | :531/:539, none | bulk SMS to customers | Redesign (behind a permission) |
| 705 | Backup Database | GET `backup` · `setting.backup` (W:957) | :272, **none** | dump zipped into `public/` with a redirect to it. Two SQL dumps already sit in `public/` | **Fix-before-migrate** (remove) |
| 711 | Mail Setting | GET `setting/mail_setting`, POST store (W:942, 950) | :375/:381, none | SMTP password in plain text | Fix-before-migrate (move secret) |
| 717 | SMS Setting | GET `setting/sms_setting`, POST store (W:943, 951) | :399/:471, none | Tonkra, ReveSMS, BDBulkSMS, Twilio, Clickatell keys in DB | Migrate one provider; move secrets |
| 734 | Languages | `languages*`, `translations*` (W:160-170), `language_switch/{id}` (W:265) | LanguageController `language_setting` on index; TranslationController none | destroy cascades translations | Retire-candidate (static i18n) |

---

## 4. Hidden modules and routes not in the sidebar

### 4.1 Hidden modules (sidebar commented or never linked)

| Module | Routes (W) | Who links to it | State | Recommendation |
|---|---|---|---|---|
| Delivery (Sale copy, SB:105) | same as 3.6.2 | live under Online Orders | duplicate menu | — |
| Gift Card (SB:108) | `gift_cards` resource, `gencode`, `recharge/{id}`, `deletebyselection` (1016-1023) | Sale List payment modals (gift card as payment method); POS gift-card modal (unreachable) | index gated on the wrong permission, `unit`; recharge unchecked with no validation and ignores `{id}` | Retire-candidate |
| Coupon (SB:111) | `coupons` resource, `gencode`, `deletebyselection` (1027-1033), plus **`update-coupon` (W:101, unauthenticated)** | POS coupon modal (button commented out) | index gated on `unit`; store/update take `$request->all()` | **Fix-before-migrate**: remove `updateCoupon` now; module is a Retire-candidate |
| Courier (SB:113) | `couriers` resource (1025) | Challan and Delivery courier selects | CourierController has no checks | Migrate as master data (merge with delivery partners) |
| Quotation (SB:177-189) | see 3.10 | header "+" (MAIN:246); dashboard tab | reachable by URL | Retire-candidate |
| Manufacturing (SB:133-141) | see 3.7 | none | module flag | Retire-candidate |
| Addons/Woo/eCommerce (SB:596-610) | see 3.17 | none | dangerous | Fix-before-migrate |
| Labels | `/labels/show` (`print.labels`), `/labels/add-product-row` (**method missing**), `/labels/print` (`print.label`) (W:969-971) | Print Barcode uses only `/labels/print` | no permission; imports non-existent classes | Retire `show`/add-row; keep print behind `print_barcode` |
| Variant | none (VariantController empty) | tables still read by PC and AdjustmentController | — | Retire-candidate (UNVERIFIED data) |
| Cash Register | `cash-register` (`cashRegister.index`), `check-availability/{wh}`, `store`, `getDetails/{id}`, `showDetails/{wh}`, `close` (W:1107-1120) | header icon (MAIN:310, role ≤2); POS details and close; Add Sale and return-edit availability checks | POS open modal commented out; `close` has no ownership check; showDetails 500s with no open register; cash total includes every payment method | Redesign (shift close-out with variance) |
| Product History | GET `products/history` (`products.history`) + 4 `*-history-data` POSTs (W:248-252) | Product List row action | data endpoints unchecked; `orWhere` leaks other products (PC:623-636) | Redesign |
| Online POS | `online-pos/{id?}` | Online list "Add Sale" (UNVERIFIED) | likely 500 (posSale missing in OnlineOrderController) | Retire-candidate |
| Customer Ecosystem sub-pages | plans store/update/destroy, customers review, rewards settings, wallets, adjust, transactions, campaigns, expiry, reports, export | in-page links | no authorization | see 3.2 |

### 4.2 Routes not linked from the sidebar (by area) and their callers

| Area | Routes | Caller |
|---|---|---|
| Product | `products/{id}/edit`, `products/update`, `products/history`, `get-search-library` (W:236), `products/search` (W:239, no caller found), `lims_product_search` (244), `gencode`, `saleunit/{id}`, `getdata/{id}/{vid}`, `product_warehouse/{id}`, `variant-data/{id}`, `check-batch-availability/{pid}/{batch}/{wh}` (260), `all-product-in-stock` and `show-all-product-online` (258-259; **GETs that mass-update every product**, no caller), `exportproduct` (257, **missing method**), `importproduct`, `bulkUpdateproduct`, `download-bulk` (254-256), `products/deletebyselection` (245), `download-sample-products` (180), resource PUT (missing `update()`) | Product List, create/edit, POS, adjustment, transfer, sale/quotation/return pages |
| Category/Brand/Unit/Barcode | `category/{id}/edit` JSON, `brand/lims_brand_search` (missing method), `/exportbrand` and `/exportunit` (not routed), `unit/lims_unit_search` (shadowed), `barcodes/set_default/{id}` (missing method) | page JS |
| Purchase | `purchases/{id}/edit`, `PUT purchases/{id}`, `purchases/duplicate/{id}`, `DELETE purchases/{id}`, `purchase-data`, `product_purchase/{id}`, `getpayment/{id}`, `add_payment`, `updatepayment`, `deletepayment`, `deletebyselection`, `search-product`, `purchases/lims_product_search`, `purchases-new/lims_product_search/search`, `report/create-purchase-from-orders` (W:841) | Purchase List rows/modals; create, create-new, edit, duplicate; `quotation/create_purchase.blade:653`; `report/order_qty_draft_product_lists.blade:572`; header quick-add `purchases.create` (MAIN:228) |
| Sale | `sales/{id}/edit`, `PUT sales/{id}`, `sales/{id}/create` (`sale.draft` → redirects to POS), `gen_invoice/{id}`, `sendmail`, `sendsms`, `whatsapp-notification`, payment routes, `get-sold-items/{id}`, `custom-products[/{sale_id}]` (no caller found, UNVERIFIED), `recent-sale`, `recent-draft`, `today-sale`, `today-profit/{wh}`, `check-discount`, `getfeatured`, `print-last-reciept`, `paypalPaymentSuccess/{id}` | Sale List, POS, Add Sale |
| Broken local block (W:607-649) | `/report/sales/*` string actions (30 missing ReportController methods incl. `getSale`, `productSaleData`, `importSale`, `limsSaleSearch`, `convertDraftToSale`, `testDraftConversion`, `checkSaleStatus`), `/report/importsale` | POS relative URLs, Import Sale. **Local snapshot only**; production differs (§1.1) |
| Online orders | `online-sales/{ref}` show, `online-orders/show/{ref}`, `online-orders/assign-rider`, `admin.online-sale.update-status`, `admin.online-sale.assign-rider`, `online-sales/export-all`, `online-sales/create`/`store` (**missing**), `online-sales/sale_by_csv` | Orders List and show page |
| Packing/Challan/Delivery | `packing-slips/{store,invoice/{id},delete/{id},packing-slip-data}`, `challans/{create,store,invoice,money-reciept,finalize,update,challan-data}`, `delivery/{create/{id},store,update,{id}/edit,sendmail,delivery_list_data,product_delivery/{id},deletebyselection,delete/{id}}` | Sale List modals, Packing Slip and Challan pages |
| Transfer | `transfers/{id}/edit`, `DELETE transfers/{id}`, `transfer-data`, `product_transfer/{id}`, `getproduct/{wh}`, `lims_product_search`, `deletebyselection`, `importtransfer` | transfer views; header quick-add (MAIN:252) |
| Returns | `return-sale/create`, `return-purchase/create` (header modals MAIN:700-750), `return-sale/product_return/{id}` (also `customer_index.blade.php:624`), `getcustomergroup`, `getproduct`, `lims_product_search`, `sendmail`; `return-purchase/{add-payment,getpayment/{id},mark-paid}` (**dead, methods missing**); every module's resource `show` (missing) | return views, header |
| Expense/Income | `expense_categories/{gencode,import,deletebyselection,all}`, `expenses/{expense-data,deletebyselection}`; `income_categories/{gencode,import*,deletebyselection*,all*}` (* missing methods), `incomes/{income-data,deletebyselection}` | page JS |
| Accounting | `make-default/{id}` (W:1046, URL mismatch), `accounts/all` (1049), `account-statement` (1048) | page JS, modal |
| HRM | `{departments,employees,payroll,attendance,holidays}/deletebyselection`, `attendance/delete/{date}/{emp}`, `attendance/importDeviceCsv`, `monthly-payroll/{get-data,store}`, `advances-payments/{approve,reject}/{id}`, `advances-payments/deletebyselection` (missing), `approve-holiday/{id}`, `holidays/my-holiday/{y}/{m}` | page JS, header |
| People | `user/{profile,update_profile,changepass,genpass,deletebyselection,notification,all}`; `importcustomer`, `customer/{getDeposit,add_deposit,update_deposit,deleteDeposit,deletebyselection,lims_customer_search}`, `customers/{clear-due,customer-data,all}`; `importbiller`, `biller/{deletebyselection,lims_biller_search}`; `importsupplier`, `supplier/deletebyselection`, `suppliers/{clear-due,all}`; `riders/{table-loader,store,update,destroy}`. Referenced but **undefined**: `customer.account-delete`, `customer.get-destroy-request`, `/exportbiller` | page JS |
| Reports | POST variants of daily/monthly sale/purchase and best_seller; all `*-data` endpoints (W:862-910); product-order sub-routes (828-842); `profit-loss/product-wise/export` (867); `export-all` (868, missing); `supplier-due-report-data` (910, missing); `payroll-report-data` | report page JS |
| Settings | `setting/{mail_setting,sms_setting,createsms,sendsms,empty-database,general_setting/change-theme/{t}}`, `switch-theme/{theme}` (W:211), `backup`, `smstemplates`, `custom-fields`, `discount-plans`, `discounts`, `notifications`, `tables`, `currency`, `tax`, `couriers`, `gift_cards`, `coupons`, `languages*`, `translations*`, `language_switch/{id}`, `barcodes/set_default/{id}`, `role/permission/{id}`, `role/set_permission` | commented sidebar, header, Role page |
| Dashboard | `dashboard-filter/...`, `yearly-best-selling-*`, `monthly-best-selling-qty`, `recent-*` (W:204-212); `/home` (W:135) | dashboard JS |
| POS vision | POST `pos/product/vision-search` (W:142-143, `throttle:20,1`) | POS camera partial |
| **Vendor / dev / unauthenticated** | `GET /migrate`, `GET /cc` (W:72-99); **`GET /update-coupon` (W:101: truncates all tables and deletes the `?data=` directory)**; `auto-update-dashboard` (named `dashboard`, W:103); `developer-section*` (W:106-115, writes `.env`); `new-release`, `bugs`, POST `version-upgrade`, `bug-update` (W:117-123, download vendor zips over `base_path()`, migrate and db:seed); `saas/install/*` (W:218-230); `addon-list` (W:213); `woocommerce-install`, `ecommerce-install` (W:1140-1142); `phpfileinfo` (W:1035-1040, phpinfo); `documentation`, `ecommerce-documentation`; `my-transactions/{y}/{m}` (header). Files: `public/delete.php`, `public/salepro_backup_*.sql`, `public/debug_payroll_params.txt`. Scheduler: `reset:db` and `quote:daily` every minute (Kernel.php:39-41; production cron UNVERIFIED) | mostly none | **Fix-before-migrate: remove immediately** |

---

## 5. Header / top-bar entry points (MAIN = `resources/views/backend/layout/main.blade.php`)

Line numbers were re-read from MAIN for this document **[src]**, and they agree with WP1 and WP2.

| MAIN line | Entry | Target | Gate | Inventory § |
|---|---|---|---|---|
| 216 | "+" Add Category | `#category-modal` | category | 3.3.1 |
| 222 | "+" Add Product | `products.create` | products-add | 3.3.3 |
| 228 | "+" Add Purchase | `purchases.create` (desktop form, even for role 6) | purchases-add | 3.4.2 |
| 234 | "+" Add Sale | `sales.create` | sales-add | 3.5.3 |
| 240 | "+" Add Expense | `#expense-modal` | expenses-add | 3.8.3 |
| 246 | "+" Add Quotation | `quotations.create` (hidden module) | quotes-add | 3.10 |
| 252 | "+" Add Transfer | `transfers.create` | transfers-add | 3.11.2 |
| 258 | "+" Add Return | `#add-sale-return` (MAIN:700-724) | returns-add | 3.12.1 |
| 264 | "+" Add Purchase Return | `#add-purchase-return` (MAIN:727-750) | purchase-return-add | 3.12.2 |
| 270 / 276 / 282 / 288 | "+" Add User / Customer / Biller / Supplier | `user.create`, `customer.create`, `biller.create`, `supplier.create` | users-add, customers-add, billers-add, suppliers-add | 3.15.2/4/6/8 |
| 303 | POS button | `sale.pos` | none in header (server: sales-add) | 3.5.2 |
| 310 | Cash Register List | `cashRegister.index` | role_id ≤ 2 | 4.1 |
| 318 | Qty alert link | `report.qtyAlert` | product-qty-alert | 3.16.22 |
| 322 | DSO alert link | `report.dailySaleObjective` | — | 3.16.26 |
| 372 | Profile | `user.profile` (Auth::id()) | none | 3.18.6 |
| 376 | Settings | `setting.general` | general_setting | 3.18.7 |
| 380 | My Transaction | `url('my-transactions/Y/m')` | none | 4.2 |
| 384 | My Holiday | `url('holidays/my-holiday/Y/m')` | role_id ≠ 5 | 3.14.8 |
| 389 | **Empty Database** | `setting.emptyDatabase` (GET `setting/empty-database`, W:954 → SettingController :40-71) | empty_database (**controller none**, CSRF-triggerable via GET) | **Fix-before-migrate: remove** |
| 393-397 | Logout | `logout` (POST form) | — | — |
| 1191-1196 | Theme switch | `switchTheme` | — | — |
| modals 553-623, 626-696, 754-788, 791-835, 838-859, 881-895, 917-931, 953-967, 989-1003, 1025-1039 | Expense, Income, Account, Account Statement, Warehouse/User/Biller/Customer/Customer Group/Supplier report modals | as cited | sidebar gates | 3.8.3, 3.9.3, 3.13.2, 3.13.5, 3.16.x |

---

## 6. Verification: `sidebar.blade.php` re-read against this inventory

Method: `resources/views/backend/layout/sidebar.blade.php` (737 lines) was re-opened and read in full for this document. Every `<li>` that carries a link (live or inside `<!-- -->`) was matched to an inventory section. Group-header `<li>`s (collapse toggles) are counted as groups, not items.

### 6.1 Group headers (18)

| SB line | Group | § | Documented |
|---|---|---|---|
| 2 | Dashboard (single link) | 3.1 | ✓ |
| 3 | Customer Ecosystem | 3.2 | ✓ |
| 24 | Product | 3.3 | ✓ |
| 55 | Purchase | 3.4 | ✓ |
| 90 | Sale | 3.5 | ✓ |
| 122 | Online Orders | 3.6 | ✓ |
| 135 (commented) | Manufacturing | 3.7 | ✓ |
| 147 | Expense | 3.8 | ✓ |
| 161 | Income | 3.9 | ✓ |
| 178 (commented) | Quotation | 3.10 | ✓ |
| 194 | Transfer | 3.11 | ✓ |
| 214 | Return | 3.12 | ✓ |
| 236 | Accounting | 3.13 | ✓ |
| 273 | HRM | 3.14 | ✓ |
| 316 | People | 3.15 | ✓ |
| 416 | Reports | 3.16 | ✓ |
| 596-610 (commented) | Addons / WooCommerce / eCommerce | 3.17 | ✓ |
| 611 | Settings | 3.18 | ✓ |

### 6.2 Live links (102 `<li>` lines = 100 logical items)

| SB line | Link | § | ✓/✗ |
|---|---|---|---|
| 2 | Dashboard | 3.1 | ✓ |
| 5 | Membership Dashboard | 3.2.1 | ✓ |
| 5 | Membership Plans | 3.2.2 | ✓ |
| 6 | Customers | 3.2.3 | ✓ |
| 6 | Membership History | 3.2.4 | ✓ |
| 7 | Renewals | 3.2.5 | ✓ |
| 7 | Reports (membership) | 3.2.6 | ✓ |
| 8 | Reward Management | 3.2.7 | ✓ |
| 27 | Category | 3.3.1 | ✓ |
| 30 | Product List | 3.3.2 | ✓ |
| 35 | Add Product | 3.3.3 | ✓ |
| 39 | Print Barcode | 3.3.4 | ✓ |
| 42 | Adjustment List | 3.3.5 | ✓ |
| 43 | Add Adjustment | 3.3.6 | ✓ |
| 46 | Stock Count | 3.3.7 | ✓ |
| 57 | Purchase List | 3.4.1 | ✓ |
| 63 | Add Purchase (role 6 → new.create) | 3.4.2 | ✓ |
| 65 | Add Purchase (mobile → new.create) | 3.4.2 | ✓ |
| 66 | Add Purchase (desktop → create) | 3.4.2 | ✓ |
| 70 | Import Purchase By CSV | 3.4.3 | ✓ |
| 93 | Sale List | 3.5.1 | ✓ |
| 96 | POS | 3.5.2 | ✓ |
| 97 | Add Sale | 3.5.3 | ✓ |
| 98 | Import Sale By CSV | 3.5.4 | ✓ |
| 101 | Packing Slip List | 3.5.5 | ✓ |
| 102 | Challan List | 3.5.6 | ✓ |
| 125 | Orders List | 3.6.1 | ✓ |
| 128 | Delivery List | 3.6.2 | ✓ |
| 149 | Expense Category | 3.8.1 | ✓ |
| 150 | Expense List | 3.8.2 | ✓ |
| 155 | Add Expense | 3.8.3 | ✓ |
| 163 | Income Category | 3.9.1 | ✓ |
| 164 | Income List | 3.9.2 | ✓ |
| 169 | Add Income | 3.9.3 | ✓ |
| 196 | Transfer List | 3.11.1 | ✓ |
| 201 | Add Transfer | 3.11.2 | ✓ |
| 202 | Import Transfer By CSV | 3.11.3 | ✓ |
| 217 | Return → Sale | 3.12.1 | ✓ |
| 220 | Return → Purchase | 3.12.2 | ✓ |
| 239 | Account List | 3.13.1 | ✓ |
| 240 | Add Account | 3.13.2 | ✓ |
| 243 | Money Transfer | 3.13.3 | ✓ |
| 246 | Balance Sheet | 3.13.4 | ✓ |
| 249 | Account Statement | 3.13.5 | ✓ |
| 276 | Department | 3.14.1 | ✓ |
| 279 | Employee | 3.14.2 | ✓ |
| 282 | Attendance | 3.14.3 | ✓ |
| 285 | Payroll | 3.14.4 | ✓ |
| 288 | Monthly Payroll | 3.14.5 | ✓ |
| 291 | Advances Payment | 3.14.6 | ✓ |
| 294 | Payroll Report | 3.14.7 | ✓ |
| 297 | Holiday | 3.14.8 | ✓ |
| 320 | User List | 3.15.1 | ✓ |
| 325 | Add User | 3.15.2 | ✓ |
| 330 | Customer List | 3.15.3 | ✓ |
| 335 | Add Customer | 3.15.4 | ✓ |
| 340 | Biller List | 3.15.5 | ✓ |
| 345 | Add Biller | 3.15.6 | ✓ |
| 350 | Supplier List | 3.15.7 | ✓ |
| 355 | Add Supplier | 3.15.8 | ✓ |
| 360 | Riders List | 3.15.9 | ✓ |
| 419-425 | Summary Report | 3.16.1 | ✓ |
| 428-434 | Daily Profit & Cost | 3.16.2 | ✓ |
| 437-439 | Best Seller | 3.16.3 | ✓ |
| 442-449 | Product Report | 3.16.4 | ✓ |
| 452-454 | Daily Sale | 3.16.5 | ✓ |
| 457-459 | Monthly Sale | 3.16.6 | ✓ |
| 462-464 | Daily Purchase | 3.16.7 | ✓ |
| 467-469 | Monthly Purchase | 3.16.8 | ✓ |
| 472-479 | Sale Report | 3.16.9 | ✓ |
| 481 | Challan Report | 3.16.10 | ✓ |
| 483-491 | Sale Report Chart | 3.16.11 | ✓ |
| 494-500 | Payment Report | 3.16.12 | ✓ |
| 503-510 | Purchase Report | 3.16.13 | ✓ |
| 513-515 | Customer Report | 3.16.14 | ✓ |
| 518-520 | Customer Group Report | 3.16.15 | ✓ |
| 523-529 | Customer Due Report | 3.16.16 | ✓ |
| 532-534 | Supplier Report | 3.16.17 | ✓ |
| 537-543 | Supplier Due Report | 3.16.18 | ✓ |
| 546-548 | Warehouse Report | 3.16.19 | ✓ |
| 551-553 | Warehouse Stock Chart | 3.16.20 | ✓ |
| 556-558 | Product Expiry Report | 3.16.21 | ✓ |
| 561-563 | Product Quantity Alert | 3.16.22 | ✓ |
| 566-568 | Product Order Report | 3.16.23 | ✓ |
| 571-573 | Custom Product Sales Report | 3.16.24 | ✓ |
| 575-577 | Adjustment Report | 3.16.25 | ✓ |
| 579-581 | Daily Sale Objective Report | 3.16.26 | ✓ |
| 584-586 | User Report | 3.16.27 | ✓ |
| 589-591 | Biller Report | 3.16.28 | ✓ |
| 659 | Role Permission | 3.18.1 | ✓ |
| 682 | Warehouse | 3.18.2 | ✓ |
| 686 | Customer Group | 3.18.3 | ✓ |
| 689 | Brand | 3.18.4 | ✓ |
| 692 | Unit | 3.18.5 | ✓ |
| 700 | User Profile | 3.18.6 | ✓ |
| 708 | General Setting | 3.18.7 | ✓ |
| 714 | Reward Point Setting | 3.18.8 | ✓ |
| 720 | Payment Gateways | 3.18.9 | ✓ |
| 723 | POS Settings | 3.18.10 | ✓ |
| 726 | HRM Setting | 3.18.11 | ✓ |
| 729 | Barcode Settings | 3.18.12 | ✓ |
| 732 | Shipping Setting | 3.18.13 | ✓ |

### 6.3 Commented-out links (27 `<li>` lines = 25 distinct hidden items)

| SB line | Link | § | ✓/✗ |
|---|---|---|---|
| 68 | Add Purchase (old desktop variant) | 3.4.2 | ✓ |
| 69 | Add Purchase (old mobile variant) | 3.4.2 | ✓ |
| 105 | Delivery List (Sale copy) | 3.6.2, 4.1 | ✓ |
| 108 | Gift Card List | 4.1 | ✓ |
| 111 | Coupon List | 4.1 | ✓ |
| 113 | Courier List | 4.1 | ✓ |
| 137 | Production List | 3.7 | ✓ |
| 138 | Add Production | 3.7 | ✓ |
| 180 | Quotation List | 3.10 | ✓ |
| 185 | Add Quotation | 3.10 | ✓ |
| 598 | Addons | 3.17 | ✓ |
| 601 | WooCommerce | 3.17 | ✓ |
| 604-608 | eCommerce include | 3.17 | ✓ |
| 660 | SMS Template | 3.18.H | ✓ |
| 662 | Custom Field List | 3.18.H | ✓ |
| 666 | Discount Plan | 3.18.H | ✓ |
| 669 | Discount | 3.18.H | ✓ |
| 673 | All Notification | 3.18.H | ✓ |
| 678 | Send Notification | 3.18.H | ✓ |
| 684 | Tables | 3.18.H | ✓ |
| 695 | Currency | 3.18.H | ✓ |
| 698 | Tax | 3.18.H | ✓ |
| 702 | Create SMS | 3.18.H | ✓ |
| 705 | Backup Database | 3.18.H | ✓ |
| 711 | Mail Setting | 3.18.H | ✓ |
| 717 | SMS Setting | 3.18.H | ✓ |
| 734 | Languages | 3.18.H | ✓ |

### 6.4 Result

| Count | Value |
|---|---|
| Groups | 18 / 18 documented (15 with live children, 3 fully commented) |
| Live sidebar links | 102 `<li>` lines / **100 logical items**, all ✓ |
| Commented sidebar links | 27 `<li>` lines / 25 distinct items, all ✓ |
| Header/top-bar entry points | 25 rows in §5 (MAIN re-read) |
| Gaps found and closed | (a) The WP2 count of "76 live links" plus the WP1 core links adds up to 100 once Brand, Unit and Barcode Settings are counted once; reconciled here. (b) Missing list-column and field data for 16 items was read from source and marked **[src]**: Department, Employee, Attendance, Payroll, Advances, Holiday, Warehouse, Customer Group, Biller, Supplier, Riders, Role, Shipping Zones, Balance Sheet, Customer List columns, Add Customer fields and validation. |
| Open gaps (not closable from code) | Live production UI and routes (no logged-in session); Challan List and Quotation columns/fields (not in the workpapers, not re-read); all items marked UNVERIFIED in WP1 §8 and WP2 §14 |

---

## 7. Gap closure (lead auditor, 2026-11-23)

| Item | Detail read from source |
|---|---|
| Challan List: table columns (`resources/views/backend/challan/index.blade.php`) | Date · Reference No · Order No · Courier · Status · Closing Date · Total Amount · Created By · Closed By · Action (footer row: Total). Related views: `challan/create`, `finalize`, `invoice`, `money_reciept` |
| Quotation (hidden): Add Quotation form fields (`resources/views/backend/quotation/create.blade.php`) | Header: biller_id, supplier_id, customer_id, warehouse_id, quotation_status, document (file), note. Lines: product_id[], product_code[], product_batch_id[], imei_number[], qty[], sale_unit[], net_unit_price[], discount[], tax_rate[], tax[], subtotal[]. Totals: item, total_qty, total_discount, total_tax, total_price, order_tax_rate, order_tax, order_discount, shipping_cost, grand_total. The line-edit modal has edit_qty, edit_unit, edit_unit_price, edit_discount, edit_tax_rate. Views `create_sale` and `create_purchase` convert a quotation to a sale or purchase |

Both gaps from §6 are now closed. **What remains open is verification against the live, logged-in production UI**, which needs credentials or a supervised session (see `legacy-system-audit.md` §9).
