# WP2: Navigation inventory, admin half of the sidebar

**Scope.** This paper covers every sidebar entry not owned by the Dashboard/Product/Purchase/Sale/POS/Transfer workpaper:
- Customer Ecosystem (Membership and Rewards)
- Expense and Income
- Accounting
- HRM
- People
- Reports
- Settings
- Role/Permission (RBAC)
- Hidden or commented menus: Quotation, Manufacturing, Woo/eCommerce, Addons
- Cash Register
- Vendor installer, updater and developer routes
- Routes in these areas that the sidebar does not link to

**Source of truth.** `D:\01 REDPHARMA\00 CODEBASE\backend - ocr pos\`, read-only. All paths below are relative to that folder.

**Abbreviations**

| Short form | Full path |
|---|---|
| SB | `resources/views/backend/layout/sidebar.blade.php` (737 lines) |
| W | `routes/web.php` (1195 lines) |
| MAIN | `resources/views/backend/layout/main.blade.php` |
| C/ | `app/Http/Controllers/` |
| RC | `C/ReportController.php` (7061 lines) |
| V/ | `resources/views/backend/` |

**Method.** I read the sidebar, `web.php`, RoleController, SettingController and the settings controllers in full or at the cited ranges. Report, finance/HRM/people and membership/modules detail came from three parallel read-only sub-audits, each citing `file:line`. I spot-checked their headline claims myself: `update-coupon`, the scheduler `reset:db`, RoleController and Common middleware caching.

**Confidence.** "UNVERIFIED" marks anything that depends on the live DB, `.env` or runtime behaviour. Secrets were not copied.

---

## 0. Executive findings: the 10 worst bugs and risks in this half

| # | Severity | Finding | Evidence |
|---|---|---|---|
| 1 | CRITICAL | **Unauthenticated kill switch.** `GET /update-coupon` sets `FOREIGN_KEY_CHECKS=0`, truncates every table, then recursively deletes the directory given in `?data=` (any path). | W:101 → `C/CouponController.php:83-102` (verified) |
| 2 | CRITICAL | **Scheduler wipes the database every minute.** `reset:db` runs `everyMinute()`. It truncates all tables and loads a missing `public/salepro_data.sql`. `quote:daily` also runs every minute ("Testing Purpose"). The production cron state is UNVERIFIED. | `app/Console/Kernel.php:39-41` (verified), `app/Console/Commands/ResetDB.php:43-49` |
| 3 | CRITICAL | **Anyone can rewrite roles and permissions.** `RoleController@setPermission`, `store`, `update` and `destroy` have no `role_id<=2` check; only `index`, `edit` and `permission` do. Any logged-in user can POST `role/set_permission` with their own `role_id` and grant themselves all 153 permissions. `Role::firstOrCreate(['id'=>…])` can also create roles. | `C/RoleController.php:32-46, 58-73, 90-96, 1538-1546` vs `:18, 50, 77` |
| 4 | CRITICAL | **Privilege escalation through users.** `UserController@update` has no permission check and runs `update($request->except('password'))` with `role_id` fillable. `profileUpdate` runs `User::find($id)->update($request->all())`, an IDOR. EmployeeController creates linked users with `User::create($request->except('image'))`, so a role can be chosen. | `C/UserController.php:144, 186`; `C/EmployeeController.php:82` |
| 5 | CRITICAL | **Database backups and dumps are exposed in the public web root.** `setting/backup` writes `{db}_backup_{time}.zip` into `public/` and redirects to it, with no permission check. Two full SQL dumps already sit in `public/`. `phpinfo()` is open to any active user. | `C/SettingController.php:272-365`; `public/salepro_backup_1689056748.sql`, `public/salepro_backup_1738037751.sql`; W:1035-1040 |
| 6 | CRITICAL | **Vendor remote-code update and install endpoints.** ClientAutoUpdate (`version-upgrade`, `bug-update`) sits outside `auth`. It downloads zips from the vendor, extracts them over `base_path()`, then runs `migrate` and `db:seed`. DeveloperSection `submit` (also outside `auth`) writes `.env`. AddonInstall and SaasInstall can be called by any active user and overwrite code and `.env` DB settings. `/migrate` and `/cc` have no auth. | W:72-123, 213, 218-230, 1140-1142 |
| 7 | HIGH | **Sidebar gating is cosmetic.** Most controllers in this half check permission only on `index`. Store, update, delete, data and AJAX endpoints are open to any active user. That covers: all report `*Data` endpoints and most report pages; expense and income writes; account and money-transfer writes; customer and supplier clear-due and deposits; advance and holiday approve; every settings page except Payment Gateway and Barcode; the whole Customer Ecosystem; Manufacturing. | Sections 2-9 |
| 8 | HIGH | **Permission caches are wrong.** In `Common` middleware, `user_role` is cached under one global key for 1 year. The `$role` used by the sidebar (`SB:658 @if($role->id <= 2)`) is therefore whichever user populated the cache first. `role_has_permissions` is also a global key. `setPermission` only forgets `permissions`, not `role_has_permissions_list{role_id}`, so permission changes do not appear in the menu until `/cc` is hit or a year passes. | `app/Http/Middleware/Common.php:75-91`; `C/RoleController.php:1528` |
| 9 | HIGH | **Financial logic is wrong in several places.** P&L COGS = qty × current `products.cost` (ignores units, batches and returns). Draft and pending sales are counted in every report (there is no `sale_status` filter anywhere in RC). Income is booked as a *debit* in the account statement and left out of the balance sheet. `accounts.total_balance` is never maintained. Payroll salary is never saved (not fillable). Monthly payroll compares an int attendance status with strings. Customer and supplier "clear due" have no transaction or validation and post everything as Cash. | RC:2417-2426, RC:2276; `C/AccountsController.php:110-121, 177-202`; `app/Models/Employee.php`; `C/PayrollController.php:292-300`; `C/CustomerController.php:309` |
| 10 | HIGH | **Customer Ecosystem has no authorization and can break POS.** Any active user can edit plans, delete plans and adjust reward wallets. The membership listener runs synchronously on `Sale::saved` and uses `firstOrFail`, so a deactivated default plan makes every POS sale fail. Four reward ledgers run in parallel. | `C/MembershipManagementController.php`, `C/RewardManagementController.php`; `app/Models/Sale.php:12-16`; `MembershipEngine.php:48` |

**Other significant defects**
- DataTables search uses `orWhere`, which escapes the warehouse, customer and user scope. This is a cross-branch data leak in about 30 report endpoints.
- `GET setting/empty-database` has no controller permission check and can be triggered by CSRF.
- `generalSettingStore` writes `timezone` into `.env` without validation (`C/SettingController.php:96-101`).
- Mail and SMS gateway credentials are stored in plain text in DB rows.
- The Customer List page references the undefined routes `customer.account-delete` and `customer.get-destroy-request`, so it likely returns 500 (UNVERIFIED at runtime).
- If `general_settings.modules` contains `woocommerce` or `ecommerce`, every backend page returns 500. Blade code inside `<!-- -->` still executes `route('woocommerce.index')` and `@include('ecommerce::…')` (SB:600-609).

---

## 1. Sidebar map: what is in my scope

Visibility depends on `$role_has_permissions_list`, which `app/Http/Middleware/Common.php:88-91` shares to every view. It is cached per role for 365 days. `$role` comes from the global cache key described in finding 8.

| Menu (SB line) | Sidebar gate (permission names) | Submenus (live) | Commented-out / hidden (Blade still evaluates the `route()` inside HTML comments, and the markup still reaches the browser) |
|---|---|---|---|
| Customer Ecosystem (3-10) | **none, shown to every role including customers (role 5)** | Membership Dashboard, Membership Plans, Customers, Membership History, Renewals, Reports, Reward Management | — |
| Manufacturing (133-141) | `general_settings.modules` contains `manufacturing` | — | Production List, Add Production |
| Expense (142-159) | `expenses-index`; Add requires `expenses-add` | Expense Category, Expense List, Add Expense (modal) | — |
| Income (160-173) | `incomes-index`; Add requires `incomes-add` | Income Category, Income List, Add Income (modal) | — |
| Quotation (174-189) | `quotes-index` / `quotes-add` | — | Quotation List and Add Quotation are fully commented out. Routes stay live (W:507-522 and W:728-741) and the header "+" menu still links them (MAIN:246). |
| Accounting (225-253) | `account-index` \| `money-transfer` \| `balance-sheet` \| `account-statement` | Account List, Add Account (modal), Money Transfer, Balance Sheet, Account Statement (modal) | — |
| HRM (254-301) | `department` \| `employees-index` \| `attendance` \| `payroll` \| `monthly-payroll` \| `advances-index` \| `holiday` \| `payroll-report` | Department, Employee, Attendance, Payroll, Monthly Payroll, Advances Payment, Payroll Report, Holiday | — |
| People (302-364) | `users-index` \| `customers-index` \| `billers-index` \| `suppliers-index` | User List, Add User (`users-add`), Customer List, Add Customer (`customers-add`), Biller List, Add Biller (`billers-add`), Supplier List, Add Supplier (`suppliers-add`), **Riders List (gated by `sales-index`)** | — |
| Reports (366-595) | any of 23 report permissions | 28 links (see §6) | — |
| Addons / WooCommerce / eCommerce (596-610) | `role_id != 5`, plus a `modules` flag | — | Addons, WooCommerce, the eCommerce include (**dangerous, see §10**) |
| Settings (611-736) | **the parent has no gate** | Role Permission (`$role->id<=2`), Warehouse (`warehouse`), Customer Group (`customer_group`), Brand (`brand`), Unit (`unit`), User Profile (no gate), General Setting (`general_setting`), Reward Point Setting (`reward_point_setting`), Payment Gateways (`payment_gateway_setting`), POS Settings (`pos_setting`), HRM Setting (`hrm_setting`), Barcode Settings (`barcode_setting`), Shipping Setting (`shipping_setting`) | SMS Template, Custom Field List, Discount Plan, Discount, All Notification, Send Notification, Tables, Currency, Tax, Create SMS, Backup Database, Mail Setting, SMS Setting, Languages |

**Header and top-bar entries in my scope** (MAIN):
- Cash Register list (MAIN:310, role ≤ 2)
- Settings shortcut (MAIN:374-378, `general_setting`)
- My Transaction (MAIN:380)
- My Holiday (MAIN:384, role ≠ 5)
- **Empty Database** (MAIN:387-390, `empty_database`, a GET link)
- Qty alert and DSO alert links (MAIN:318-322)
- The "+" quick-add menu links to Add User, Customer, Biller and Supplier (MAIN:270-288)

**Count.** 76 live sidebar links and 22 commented or hidden entries are documented. Header and modal entry points and about 140 non-sidebar routes are covered in §11.

---

## 2. Customer Ecosystem (Membership and Rewards)

**Authorization.** There is **no** authorization in either controller. The policies `MembershipPlanPolicy`, `CustomerMembershipPolicy` and `RewardWalletPolicy` are registered (`app/Providers/AuthServiceProvider.php:18-20`), but the controllers never call them, and the policies only check `is_active` anyway.

**Views.** Each area is a single Blade file that switches on `$section`: `V/membership/index.blade.php` and `V/rewards/index.blade.php`. Everything is a full-page form; there is no AJAX.

### 2.1 Membership: `C/MembershipManagementController.php` (17 lines), prefix `customer-ecosystem/membership`, name prefix `membership.admin.` (W:1183-1187)

| Submenu | Route / method | Purpose and actions | Fields and validation | Columns / filters | Tables |
|---|---|---|---|---|---|
| Membership Dashboard | GET `/` `.dashboard` → `dashboard` :7 | 4 KPIs (Active, Expired, Renewals due, Revenue), a tier doughnut chart, top 25 customers by lifetime spend | — | Customer, Phone, Lifetime | `customer_membership_profiles`, `customer_memberships`, `membership_plans`, `membership_histories`, `membership_renewals`, `membership_payments`, `sales` (`MembershipReportingService.php:16-53`) |
| Membership Plans | GET `plans` `.plans` :8. POST `plans` `.plans.store` and PUT `plans/{plan}` `.plans.update` → `savePlan` :13. DELETE `plans/{plan}` `.plans.destroy` → `deletePlan` :14 | Accordion edit list; collapsed create form. Soft delete is blocked when the plan is the default or has ever been assigned (`MembershipPlanService.php:39-47`). | name req ≤100; slug req alpha_dash (**no unique rule, but the DB index is unique, so a duplicate gives a 500**); type automatic/premium; minimum_annual_purchase and annual_fee ≥0; duration_months ≥1; sort_order; color; is_active; benefit_values[] | — | `membership_plans`, `membership_plan_benefit`, `membership_benefits` |
| Customers | GET `customers` `.customers` :9. POST `customers/review` `.customers.review` → `bulkReview` :15 | Search, checkbox selection, "Review selected" (runs `MembershipEngine::review` on each) | customers[] required, max 100, each must exist | ☐, Customer, Tier, Current-year spend, Lifetime spend, Status; filters q and status; 25 per page | all membership tables |
| Membership History | GET `history` :10 | Read-only list | — | Date, Customer, Event, From, To, Reason | `membership_histories` |
| Renewals | GET `renewals` :11 | Read-only. Approve/confirm exists **only** in the API (`routes/api.php:91`). | — | Requested, **raw Customer ID**, Period, Fee, Status | `membership_renewals` |
| Reports | GET `reports` :12 | **Identical to the dashboard.** It ignores from/to and has no export (export is API-only, `api.php:89`). | — | — | — |

**Business rules**
- **Default tier.** The default tier is Silver (`MembershipEngine.php:23-69`, `firstOrFail`).
- **Qualification.**
  - Spend is calendar-year `sales.grand_total` where `sale_status=1` and `payment_status=4`, minus returns (`MembershipEligibilityService.php:15-27`).
  - The tier is the highest active *automatic* plan whose threshold is at or below that spend.
  - Premium "Red" is kept while there is a paid `membership_payments` row that has not expired.
- **Triggers.**
  - `Sale::saved` and `Returns::saved` dispatch `MembershipReviewRequested`.
  - The listener is `ShouldQueue`, but `QUEUE_CONNECTION=sync`, so it runs **inside the POS request** (`app/Models/Sale.php:12-16`, `app/Listeners/ReviewCustomerMembership.php`).
- **Scheduled jobs** (`Kernel.php:42-48`): `membership:review` daily 01:15; `expire` 01:45; `benefits-refresh` 02:00; `renewal-reminders` hourly (**no listener**); `statistics` monthly; `annual-reset` on 1 January.

**Bugs**
- **POS hard dependency.** If no default plan or no matching automatic plan exists, `firstOrFail` throws and the sale fails.
- **Editing a plan silently disables benefits.** Booleans are rendered as `text_value=''`, and a numeric `0` (free delivery) is saved as `enabled=false` (`V/membership/index.blade.php:9`, controller :13, `MembershipDeliveryService.php:11-12`).
- **Jan-1 downgrade.** The annual reset at 00:15 followed by the review at 01:15 drops every automatic tier to Silver on 1 January.
- **"Expired" status is dead.** `membership_status` is never set to `expired`, so the "Expired" KPI is always 0.
- **Early renewal shortens Red.** `MembershipRenewalService.php:50-79`.
- The slug `red` is hard-coded (`MembershipEngine.php:114`).
- The create form hard-codes `sort_order=50`.
- Validation errors are never rendered.
- Chart.js is loaded from an unpinned CDN.

**Recommendation.** Redesign and migrate. The feature is valuable for a pharmacy loyalty programme. Add permissions, make the listeners truly asynchronous or failure-tolerant, use rolling 12-month qualification, and fix the benefit form.

### 2.2 Rewards: `C/RewardManagementController.php` (32 lines), prefix `customer-ecosystem/rewards`, name prefix `rewards.admin.` (W:1188-1192)

The sidebar's "Reward Management" link opens the dashboard. The other pages are reached from links inside that view.

| Page | Route / method | Actions | Validation | Columns | Tables |
|---|---|---|---|---|---|
| Dashboard | GET `/` :19 | from/to filter, CSV export link, 6 KPIs, top 20 wallets | from and to not validated | Customer, Available, Earned, Redeemed | `reward_wallets`, `reward_transactions`, `reward_expiry_batches` (`RewardReportingService.php:11-28`) |
| Settings | GET `settings` :20; POST `settings` → `saveSettings` :21 | `updateOrCreate` per key, then cache clear | minimum_redemption_points ≥0; maximum_redemption_percentage 0-100; expiry_mode none/rolling; expiry_months 1-120; pending_days 0-365; **all required** | — | `reward_settings` |
| Wallets | GET `wallets` :22; GET `wallets/{customer}` :23 | search; wallet detail with an adjust form and the last 100 transactions (`firstOrCreate` the wallet on view) | q | Customer, Available, Pending, Lifetime, Open / Date, Type, Points, Balance, Status | `reward_wallets`, `reward_transactions` |
| Adjust | POST `wallets/{customer}/adjust` :24 | credit or debit with a reason (`RewardEngine.php:73-84`) | direction; points >0; reason 5-1000 | — | `reward_transactions`, `reward_expiry_batches`, `reward_adjustments`, `reward_wallets` |
| Transactions | GET `transactions` :25 | ledger | type (no UI control) | Date, Customer, Type, Points, Balance, Order | `reward_transactions` |
| Campaigns | GET `campaigns` :26; POST :27 | create only (edit and deactivate are API-only, `api.php:103-104`) | name ≤150; code alpha_dash unique; dates; rule_type multiplier/bonus; value >0; minimum_order_amount nullable (**NULL into a NOT NULL column gives a 500 in strict mode**) | name, desc, dates | `reward_campaigns`, `reward_campaign_rules` |
| Expiry | GET `expiry` :28 | FIFO lot list | — | Customer, Remaining, Available, Expires, Status | `reward_expiry_batches` |
| Reports / Export | GET `reports` :29 (same as the dashboard); GET `reports/export` :30 (CSV stream) | — | from/to date, to ≥ from | Date, Customer, Phone, Type, Points, Balance, Status, Sale | `reward_transactions` |

**Business rules**
- **Earning.** `RewardEngine::syncSale` computes (paid grand_total − returns) / `reward_spend` × `reward_points` from the plan benefits. Seeded rates: Silver 0, Gold 1 per 100 BDT, Platinum 1.5, Red 2.
- **Campaigns** add multipliers or bonuses.
- **Lots.** Points go into pending lots first, then into FIFO expiry lots.

**Four ledgers run in parallel**
- **L1:** `customers.points` plus the legacy `reward_point_settings`, which Settings → Reward Point Setting edits. The POS earns into L1 and redeems from it (`SaleController.php:1301-1306, 1586, 1641, 4101-4107`).
- **L2:** `reward_point_tiers` (no migration found).
- **L3:** `customer_membership_profiles.reward_balance`.
- **L4:** `reward_wallets`.

One paid POS sale earns in L1, L3 and L4, but the POS debits only L1.

**Bugs**
- The idempotency key is a hash of the desired total, so a 10 → 5 → 10 sequence can never re-credit (`RewardEngine.php:44,125`).
- A reversal ignores pending lots (`RewardEngine.php:154-157`).
- A double-submitted manual adjustment credits twice.

**Recommendation.** Consolidate on L4 as the single ledger. Migrate the screens; retire L1, L2 and L3 and the legacy Reward Point Setting.

---

## 3. Expense and Income

| Item | Route (name) | Controller@method | Permission in code |
|---|---|---|---|
| Expense Category | GET `/expense_categories` (`expense_categories.index`, W:982) | `C/ExpenseCategoryController@index` :13 | **none** |
| Expense List | GET `/expenses` (`expenses.index`, W:991) | `C/ExpenseController@index` :20 | `expenses-index` :23 |
| Add Expense | `#add-expense`, modal `#expense-modal` (MAIN:553-623, JS :1236); POST `/expenses` | `@store` :176 | **none** |
| Income Category | GET `/income_categories` (W:1003) | `C/IncomeCategoryController@index` :14 | **none** |
| Income List | GET `/incomes` (W:1012) | `C/IncomeController@index` :20 | `incomes-index` :23 |
| Add Income | `#add-income`, modal `#income-modal` (MAIN:626-696, JS :1241); POST `/incomes` | `@store` :176 | **none** |

**Pages and actions**
- **Category pages** (`V/expense_category/index`, `V/income_category/index`)
  - Columns: Code, Name.
  - Add/Edit modals; Generate code (`*/gencode`, an 8-digit Keygen); delete (soft, via `is_active`).
  - CSV import; PDF/CSV/Print; bulk delete.
  - Validation: `code` unique among active records (`ExpenseCategoryController.php:32-39, 59-66`).
- **List pages**
  - GET filter on date range (default: last 1 year) and warehouse.
  - Server-side DataTable: POST `expenses/expense-data` (`expenses.data`, :52) or `incomes/income-data`.
  - Columns: Date, Reference, Warehouse, Category, Amount, Note, with a footer total.
  - Row actions Edit and Delete; PDF/Excel/CSV/Print; bulk delete.
- **Modal fields:** created_at, expense_category_id*, warehouse_id* (locked to the user's warehouse when role > 2), amount*, account_id (default preselected), note.
- **Server validation:** none on expense or income store/update; `required` exists only in the HTML.

**Effects**
- Rows go to `expenses` / `incomes`. References are `er-YmdHis` / `ir-YmdHis` (12-hour `h`, so references can collide).
- The record is tagged with `cash_register_id` when a register is open.
- Deletes are **hard deletes**.
- `accounts.total_balance` is never updated. Expenses are a debit in the Balance Sheet (`C/AccountsController.php:115`) and a deduction in the cash register.
- **Income is left out of the Balance Sheet entirely.** In the Account Statement it is loaded in the debit block and shown as a debit (`AccountsController.php:177`, `V/account/account_statement:30`).

**Bugs**
- No permission checks on any write.
- `update($request->all())` is mass assignment and can rewrite `user_id`, `reference_no` and `cash_register_id`.
- `Role::firstOrCreate` in `edit` (:203).
- The search branch drops the date and warehouse filters.
- IncomeCategoryController has **no** `import`, `deleteBySelection` or `incomeCategoriesAll` methods, yet the routes exist (W:997-999), so they return 500.
- IncomeController is a copy-paste of ExpenseController.

**Recommendation.** Migrate as one typed "Expense/Other income" journal that posts to a real ledger. Add validation, policies and void instead of delete.

---

## 4. Accounting

| Item | Route | Controller@method | Permission |
|---|---|---|---|
| Account List | GET `/accounts` (W:1052) | `C/AccountsController@index` :24 | `account-index` :27 |
| Add Account | `#add-account`, modal (MAIN:754-788); POST `/accounts` | `@store` :40 | **none** |
| Money Transfer | GET `/money-transfers` (W:1055) | `C/MoneyTransferController@index` :14 | `money-transfer` :17 (store, update and destroy: **none**) |
| Balance Sheet | GET `/balancesheet` `accounts.balancesheet` (W:1047) | `@balanceSheet` :103 | `balance-sheet` :106 |
| Account Statement | `#account-statement`, modal (MAIN:791-835); POST `/account-statement` `accounts.statement` (W:1048) | `@accountStatement` :132 | **none** |

### Accounts

**Form fields and validation.** account_no* (unique among active, :42-49), name*, initial_balance, note.

**Store and update behaviour**
- Store sets `total_balance = initial_balance`.
- The first account becomes `is_default`.
- Update *resets* `total_balance` (:82). The field is otherwise never maintained, so it is stale.

**Delete.** Soft delete. It is blocked for the default account and gated on `env('USER_VERIFIED')` (:214).

**Index page**
- Columns: Account No, Name, Initial Balance, Default toggle, Note.
- Actions: Edit modal, Delete, PDF/Excel/CSV/Print.

**Bugs**
- **The "make default" toggle is broken.** The JS calls `accounts/make-default/{id}` (`V/account/index:143`), but the route is `/make-default/{id}` (W:1046), so it returns 404.
- `makeDefault` is a GET with no permission check, so it is CSRF-able.

### Balance Sheet (:110-121)

Calculated per account:
- **Credit** = sale payments + purchase returns + transfers in + initial balance.
- **Debit** = purchase payments + sale returns + expenses + payrolls + transfers out.
- **Balance** = credit − debit.

**Problems**
- Income is excluded.
- There is no date filter (lifetime figures only).
- 8 queries per account.
- Cancelled sales are not excluded.
- Customer deposits are never booked as receipts.

### Account Statement

**Modal fields.** account_id, type (0 = all, 1 = debit, 2 = credit), date range.

**Bugs**
- **type = 2 crashes.** `$income_list` is undefined in that branch (:202).
- Debit vs credit is decided by **reference-prefix sniffing** (`spr`, `prr`, `mtr`) in the view.
- The running balance starts at 0, with no opening balance, and runs over a list sorted newest-first, so the balance column is meaningless.
- The view does a `Sale::find` / `Purchase::find` for every row (N+1).

### Money Transfer

**Page.** Columns: Date, Ref (`mtr-…`), From, To, Amount. Create and Edit happen in modals.

**Bugs**
- No validation: from = to, negative amounts and inactive accounts are all accepted.
- Hard delete.
- `update` finds the record by `id` taken from the request body.

**Recommendation.** Redesign as a double-entry or signed ledger table. Keep only the account master data from the current module.

---

## 5. HRM

| Submenu | Routes (W) | Controller | Permission checks in code |
|---|---|---|---|
| Department | resource `departments` + `departments/deletebyselection` (1059-1060) | `DepartmentController` | index `department` :17; store, update, destroy and bulk: **none** |
| Employee | resource `employees` + deletebyselection (1063-1064) | `EmployeeController` | `employees-index/add/edit/delete` (:24, 42, 58, 125, 182, 207) |
| Attendance | resource `attendance`, `attendance/delete/{date}/{employee_id}`, deletebyselection, `attendance/importDeviceCsv` (1081-1084) | `AttendanceController` | `attendance` :20, `attendance-add` :73/:123, `attendance-delete` :219/:233 (`attendance-edit` is never checked) |
| Payroll | resource `payroll` + deletebyselection (1067-1068) | `PayrollController` | `payroll` :32, `-add` :55, `-edit` :122, `-delete` :139/:154 |
| Monthly Payroll | GET `monthly-payroll` (`monthly-payroll.index`), GET `monthly-payroll/get-data`, POST `monthly-payroll/store` (1069, 1072-1073) | `@monthlyPayroll` :163, `@getMonthlyPayrollData` :175, `@bulkSaveMonthlyPayroll` :361 | `monthly-payroll` |
| Advances Payment | resource `advances-payments`, GET `approve/{id}`, GET `reject/{id}`, POST deletebyselection (1075-1078) | `AdvancesPaymentController` | `advances-index/add/edit/delete`; **approve and reject: none**; `deleteBySelection` **method missing** |
| Payroll Report | GET `payroll-report` `report.payroll`, POST `payroll-report-data` (1070-1071) | `ReportController@payrollReport` RC:79 / `@payrollReportData` RC:97 | `payroll-report` RC:82, :100 |
| Holiday | resource `holidays`, `approve-holiday/{id}`, `holidays/my-holiday/{y}/{m}`, deletebyselection (1097-1104) | `HolidayController` | `holiday` (sees everyone; otherwise only own records), `holiday-add/edit/delete`; **approveHoliday: none** |

### Department and Employee

**Department.** A single `name` field, unique among active records. Soft delete.

**Employee fields** (`V/employee/create`): employee_name*, image, department_id*, email*, phone_number*, address, city, country, staff_id, **salary***. An optional "create user" section adds name, password, role_id, warehouse_id and biller_id.

**Employee bugs**
- **`salary` is not in `Employee::$fillable`**, so it is never saved and payroll always computes 0. Whether live rows have it is UNVERIFIED.
- **Escalation:** `User::create($request->except('image'))` lets the form set `role_id`.
- The new user's id is read back with `User::latest()->first()`, which is a race condition.

### Attendance

**Manual entry.** Multiple employees, date, check-in, check-out, note. Status is an int: 1 = on time, 0 = late, compared against `hrm_settings.checkin`.

**Device CSV import.** Rows are matched on staff_id plus a datetime and upserted.

**Index.** Grouped by date and employee. There is no edit (the `update` method is a stub, :211). Delete is a hard delete.

### Payroll (manual)

**Fields.** created_at, employee_id*, account_id, addition, deduction, amount*, paying_method*, note.

**Store**
- Store (:52) **overwrites the amount you typed** with salary + addition − deduction − **all lifetime approved advances**.
- The computed `salary`, `advance`, `total_salary` and `total_payment` are not fillable, so they are dropped.
- Store emails the employee.

**Edit and delete.** Update is `$request->all()` (mass assignment). Delete is a hard delete.

### Monthly Payroll

**Flow.** An AJAX grid shows per employee: present, absent, holidays, weekends, pay days, salary and advance. Addition and deduction are editable. Store saves inside a DB transaction (:377), the only transaction in this area.

**Bugs**

| # | Problem | Where |
|---|---|---|
| a | The int status is compared with the strings `'present'` / `'absent'`, so both counts actually return **late check-in** rows | :292-300 |
| b | Every approved holiday from **any** user is subtracted from **all** employees | :247-273 |
| c | Advances are double counted: approval creates a Payroll row that is counted again | AdvancesPayment :203-211, Payroll :307-319 |
| d | Advances are filtered by `created_at`, not `payment_date`, so the last day is excluded | — |
| e | Pay is not prorated; the server trusts client-computed totals and accepts negative values | `V/payroll/monthly:250` |
| f | An empty warehouse filter falls back to all employees | — |
| g | Several columns (`total_salary`, …) and the `advances_payments` table have **no migration** (UNVERIFIED in the live DB) | — |

### Advances

**Fields.** employee_id, amount, payment_date, payment_method, note. Validated at :131-137.

**Approve (:185)**
- GET request, no permission check, no status check.
- Re-approving creates duplicate payroll rows.
- It posts to the *first active* account, not the default one.
- It auto-creates a "Cash Account".

**Reject** does not reverse an earlier approval.

### Holiday

Holiday is actually employee leave. Records are auto-approved when the creator has the `holiday` permission. `approveHoliday` is an open GET. `myHoliday` (linked from the header at MAIN:384) runs one query per day.

### Payroll Report

**Leaking debug file.** Every request writes the request parameters to **`public/debug_payroll_params.txt`** (RC:109-116), and the file exists now.

**Other bugs.** The date filters are commented out (RC:121-128).

**Columns.** Date, Ref, Employee, Designation, Present, Absent, Holiday, Weekend, Off/Pay days, Salary, Addition, Total, Advance, Deduction, Total Payment, Account, Method.

**Recommendation.** For a pharmacy, HRM is low priority.
- **Migrate:** Department and Employee.
- **Keep** device attendance import if a biometric device is in use.
- **Redesign:** payroll entirely; treat Holiday as Leave.
- **Remove now:** the debug write.

---

## 6. People

| Submenu | Route | Controller@method | Permission |
|---|---|---|---|
| User List | GET `/user` `user.index` (W:928) | `UserController@index` :31 | `users-index` :34 |
| Add User | GET `/user/create` | `@create` :46; POST `@store` :68 | `users-add` :49; **store: none** |
| Customer List | GET `/customer` (W:381) | `CustomerController@index` :42 | `customers-index` :45 |
| Add Customer | GET `/customer/create` | `@create` :359; `@store` :371 | `customers-add`; **store: none** |
| Biller List / Add | `/biller`, `/biller/create` (W:391) | `BillerController@index` :21 / `@create` :37 | `billers-index` / `billers-add`; store, update, import and delete: **none** |
| Supplier List / Add | `/supplier`, `/supplier/create` (W:324) | `SupplierController@index` :31 / `@create` :97 | `suppliers-index` / `suppliers-add`; store, update, clearDue, import and delete: **none** |
| Riders List | GET `/riders` `riders.index` (W:1162) | `RiderController@index` :13 | `sales-index`; tableLoader, store, update and destroy: **none** |

### Users

**Fields.** name, email, password, phone_number, company_name, role_id, biller_id, warehouse_id, service_staff, is_active. Role 5 (customer) adds customer_group_id and the customer fields.

**Validation.** Name and email unique among non-deleted users.

**Index page.** Columns: UserName, Email, Company, Phone, Role, Status. Delete is a soft delete (`is_deleted`).

**Security problems**
- **CRITICAL:** `update` (:144) and `profileUpdate` (:186) allow role escalation and IDOR.
- `profile($id)` is an IDOR read.
- The resource route includes `show`, but there is no method.

### Customers

**Index page**
- Server-side DataTable: POST `customers/customer-data` (:212), with no permission check.
- Columns: Customer Details, Reward Tier, Reward Points, Total Due, Delete Request, custom fields.
- Row actions: Edit, Due Report, Clear Due (modal), Add Deposit, View Deposits, Delete.
- Import CSV (:560). It crashes on an unknown group.

**Likely page crash.** The view uses `route('customer.account-delete')` (index:210) and `route('customer.get-destroy-request')` (:423), and **neither route is defined**, so the page likely returns 500 (UNVERIFIED at runtime).

**Dormant cascade.** `confirmAccountDestroy` (:805) would hard-delete a customer's sales, payments, returns and deposits.

**Deposits**
- `customers.deposit` / `customers.expense` hold the balance; `deposits` holds the rows.
- No ledger posting, no validation, no transaction. Delete is a hard delete.

**Clear Due (:309)**
- Loops over unpaid sales in no defined order.
- Creates `payments` rows with one shared `spr-` reference, the default account, and the method hard-coded to Cash.
- Sets status 4 or 2 (partial should be 3).
- Drops any overpayment.
- No transaction, validation or permission check. Drafts are not excluded.

**Mass assignment.** `update($request->all())` with `deposit` and `points` fillable lets a request overwrite balances.

### Billers

**Fields.** name, image, company_name, vat_number, email, phone, address, city, state, postal_code, country.

**Validation.** company_name and email unique among active records.

**Bug.** The view's export calls `/exportbiller`, which does **not exist**.

**Note.** POS requires a biller, so either preserve billers or fold them into a "branch profile".

### Suppliers

**Fields.** Same as billers, plus `as_user`.

**Bugs**
- `as_user` creates a role-6 user with the **hard-coded password `12345678`** (:155, :247).
- Image file names are derived from the company name, so two suppliers can overwrite each other's images.
- The due column runs per-row queries (N+1).
- Clear due (:47) has the same defects as the customer version.
- Import has a `catch(\Excetion)` typo (:333).

### Riders

**Fields and validation.** full_name*, phone* (unique), nid (unique), address, emergency_contact.

**Bugs**
- The table loads the paginated `table-loader`, so **only the first 10 riders are ever shown**.
- `POST riders/update` has no `{id}` while `update($request, $id)` requires one, so update cannot work.

**Recommendation**
- **Migrate:** Users (with a rewritten authorization model), Customers (with a receivables ledger instead of clear-due), Suppliers (payables).
- **Fold:** Billers into Branch.
- **Keep Riders** only if delivery is used.

---

## 7. Reports (28 sidebar links, plus Payroll Report in HRM)

**Shared problems across every report**
- Every route sits inside `['common','auth','active']`, but **no `*Data` AJAX endpoint checks a permission** (the exceptions are payroll and the AI endpoints).
- **No `sale_status` filter anywhere**, so drafts and pending sales count as revenue.
- Dates use `whereDate(created_at)` with `APP_TIMEZONE` (the value is UNVERIFIED).
- About 30 endpoints share one DataTables template whose `orWhere` search **leaks rows across warehouses, customers and users**.
- Choosing "All" in the page-length menu crashes three reports (`$totalData` is used before it is defined: RC:2572, 3405, 3648).

| # | Menu (SB, gate) | Route → method | Controller permission | Filters | Columns | Query / logic | Exports | Key bugs | Recommendation |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Summary Report (418, `profit-loss`) | POST `report/profit_loss` `report.profitLoss` (W:860) → `profitLoss` RC:2165 | **none** | date range | Sale, Purchase, Returns, Profit/Loss, Net Profit, Payments recv/sent, Expense/Income/Payroll, Cash in hand, per-warehouse cards (`V/report/profit_loss`) | COGS = sold qty × current `products.cost` (`calculateAverageCOGS` RC:2417, N+1); about 25 aggregates plus 11 per warehouse; toggles strict mode and calls `DB::reconnect()` | none | COGS ignores units, batches and returns; "cash" = total − card/cheque/gift/paypal/deposit, so bKash/Nagad count as cash (RC:2276); inconsistent payment date basis; per-warehouse profit is sale − purchase | **Redesign** (a P&L service) |
| 2 | Daily Profit & Cost (428, **no gate**) | GET `report/daily_profit` (W:865) → RC:1364; POST `daily_cost_profit_data` → RC:1375; GET `profit-loss/product-wise/export` → RC:1486 | none | date, warehouse | Date, Product, Category, Sold Qty, Price, Amount, Cost, Total Cost, Profit, Stock, Stock Worth | revenue = qty × list price, not the actual line price; computed in PHP over all days × products | AJAX; PDF/Excel/CSV/Print; server XLSX | `warehouse_id` ignored; fatal error on price ≤0 (RC:1433); `export-all` → missing method (W:868) | Redesign and merge into #1 |
| 3 | Best Seller (436, `best-seller`) | GET `report/best_seller` → RC:2095; POST → RC:2130 | GET only | warehouse | top product per month, last 3 months (chart) | SUM(qty) of mixed units | none | units mixed; POST ungated | Redesign as top-N |
| 4 | Product Report (441, `product-report`) | GET `report/product_report` `report.product` → RC:2542; POST `product_report_data` → RC:2554 | none | date, warehouse, name | Product, Category, Purchased/Sold/Returned/Purchase-returned amount and qty, Profit, Stock, Worth | about 10 queries per product (heavy N+1); IMEI logic | AJAX; PDF/Excel/CSV/Print | profit = 100% of revenue when nothing was purchased in the period (RC:2743) | Redesign as one GROUP BY |
| 5 | Daily Sale (451, `daily-sale`) | GET `report/daily_sale/{y}/{m}` → RC:1777; POST → RC:1819 | GET only | month, warehouse | calendar: discounts, tax, shipping, grand total | one query per day | none | no status filter; returns not netted | Redesign |
| 6 | Monthly Sale (456, `monthly-sale`) | GET `report/monthly_sale/{y}` → RC:1943; POST → RC:1982 | GET only | year, warehouse | 12 months | 72 queries | none | same as #5 | Merge into "Trends" |
| 7 | Daily Purchase (461, `daily-purchase`) | GET `report/daily_purchase/{y}/{m}` → RC:1860; POST → RC:1902 | GET only | month, warehouse | calendar | per day | none | counts pending POs | Merge |
| 8 | Monthly Purchase (466, `monthly-purchase`) | GET `report/monthly_purchase/{y}` → RC:2020; POST → RC:2058 | GET only | year, warehouse | 12 months | — | none | — | Merge |
| 9 | Sale Report (471, `sale-report`) | POST `report/sale_report` `report.sale` → RC:3622; POST `sale_report_data` → RC:3633 | none | date, warehouse | Product, Category, Sold Amount, Sold Qty, Stock | per-product loop | AJAX; exports | **qty overwrite `=` instead of `+=`** (RC:3755); paging mismatch | Redesign (item-wise) |
| 10 | Challan Report (481, **no gate**) | GET `report/challan-report` `report.challan` → RC:3881 | none | date, **`based_on` column taken from the request** (RC:3893) | Challan, Order, dates, amounts by method, shipping, delivery, net | N+1 `PackingSlip::find` in the view | client-side exports | column-name injection surface | Drop unless delivery is used |
| 11 | Sale Report Chart (482, `sale-report-chart`) | POST `report/sale-report-chart` → RC:3898 | none | date, warehouse, weekly/monthly, product codes | qty line chart | one SUM per bucket | none | units mixed | Redesign |
| 12 | Payment Report (493, `payment-report`) | POST `report/payment_report_by_date` → RC:3937 | none | date | Date, Payment Ref, Sale Ref, Purchase Ref, Paid By, Amount, Created By | unbounded `get()`; 3 lookups per row in the view | client-side exports | no in/out sign; no warehouse; fatal when the user is deleted | Redesign as a payments ledger |
| 13 | Purchase Report (502, `purchase-report`) | POST `report/purchase` → RC:3312; POST `purchase_report_data` → RC:3387 | none | date, warehouse | Product, Category, Purchased Amount/Qty, Stock | the page load **loops the whole catalogue** (RC:3322-3381) | AJAX; exports | null fatal (RC:3376) | Redesign |
| 14 | Customer Report (512, `customer-report`) | modal `#customer-modal` (MAIN:953-967); POST `report/customer_report` → RC:5542; data: RC:5558/5687/5772/5885 | none | customer, date | tabs Sales/Payments/Returns/Quotations | **`DB::reconnect()` per row** (RC:5647-5656) | 4 AJAX tables | search leak; status key mismatch | Redesign as a customer statement |
| 15 | Customer Group Report (517, `customer-report`) | modal (MAIN:989-1003); POST `report/customer-group` → RC:5988; data RC:6003/6120/6207/6322 | none | group, date | same tabs | — | AJAX | the modal sends `start_date` but the controller reads `starting_date` (RC:5991) | Drop or merge |
| 16 | Customer Due Report (522, `due-report`) | POST `report/customer-due-report` → RC:6884; POST data → RC:6913 | none | date, customer | Date, Ref, Customer, Grand Total, Returned, Paid, Due | due = total − returns (N+1) − paid | AJAX; exports | "all customers" probably returns 0 rows (`=== 0` against the string "0", RC:6950); no aging | Redesign as receivables with aging (high value) |
| 17 | Supplier Report (531, `supplier-report`) | modal (MAIN:1025-1039); POST `report/supplier` → RC:6460; data RC:6475/6590/6674/6775 | none | supplier, date | Purchases/Payments/Returns/Quotations | — | AJAX | search leak | Redesign as a supplier ledger |
| 18 | Supplier Due Report (536, `supplier-due-report`) | POST `report/supplier-due-report` → RC:7028 | none | date | Date, Ref, Supplier, Total, Returned, Paid, Due | unbounded; N+1 | client-side | `supplier-due-report-data` → **missing method** (W:910) | Redesign as payables with aging |
| 19 | Warehouse Report (545, `warehouse-report`) | modal (MAIN:838-859); POST `report/warehouse_report` → RC:3947; data RC:3963/4077/4196/4309/4411 | none | warehouse, date | Sales/Purchases/Quotations/Returns/Expenses | — | 5 AJAX tables | **the search leak exposes other warehouses** (RC:4000-4019) | Fold into a branch dashboard |
| 20 | Warehouse Stock Chart (550, `warehouse-stock-report`) | GET `report/warehouse_stock` → RC:1718 | RC:1721 | warehouse | KPIs, pie of stock value by price/cost/profit | `products.qty × master cost` | none | includes expired stock | Migrate as batch-based valuation |
| 21 | Product Expiry (555, `product-expiry-report`) | GET `report/product-expiry` → RC:1701 | **none** | horizon from `general_settings.expiry_value/type` | Image, Name, Code, Batch, Expiry, Qty | products ⋈ product_batches where expired_date ≤ today+N and qty > 0 | client-side exports | no branch scope; no value column; unbounded | **Preserve and enhance** (critical for a pharmacy) |
| 22 | Product Quantity Alert (560, `product-qty-alert`) | GET `report/product_quantity_alert` → RC:63 | RC:66 | — | Image, Name, Code, Qty, Alert Qty | `alert_quantity > qty`, global | client-side exports | not per warehouse; dead duplicate method `productQuantityAlert_` (RC:53) | Migrate and merge with #23 |
| 23 | Product Order Report (565, `product-qty-alert`) | GET `report/product_order_quantity_alert` → RC:234 plus 15 sub-routes (W:828-842) | page only | brand (called "supplier"), date | Code, Product, Category, Sold, Stock, AI Forecast, Box/Strip/Order Qty | AI forecast and draft orders → **creates Purchase records** (`createPurchaseFromOrders` RC:972) | AJAX; exports | **write endpoints have no permission check** (RC:903, 959, 972); draft update deletes outside the transaction; `orderBy('name')` on a missing column | **Preserve and redesign** (the core purchasing workflow) |
| 24 | Custom Product Sales Report (570, `sales-index`) | GET `report/custom-product-report` → RC:1203; GET data → RC:1216 | page only | date, search | Code, Product, Brand, Sale Ref, Customer, Qty, Price, Discount, Tax, Total, Dates | SQL paging; discount pro-rated in PHP | AJAX; exports | data endpoint ungated | Migrate |
| 25 | Adjustment Report (575, **no gate**) | GET `report/adjustment` → RC:589; POST `adjustment-data` → RC:600 | role exists only | date, warehouse, product | Date, Ref, Warehouse, Product, Code, ±, Qty, Unit Cost, User, Note | proper joins, whitelisted ordering | AJAX; exports | leaks exception messages (RC:762) | Preserve and add a reason code |
| 26 | Daily Sale Objective (578, `dso-report`) | GET `report/daily-sale-objective` → RC:1346; POST data → RC:1635 | page only | date | Date, Product Info, Count | reads `dso_alerts` (cron `dsoalert:find`) | AJAX; exports | string `.=` then `=` overwrites, so only the last product shows (RC:1684-1686) | Drop or replace with a dead-stock report |
| 27 | User Report (583, `user-report`) | modal (MAIN:881-895); POST `report/user_report` → RC:4495; 7 data endpoints RC:4823-5455 | none | user, date | Sales, Purchases, Quotations, Transfers, Payments, Expenses, Payroll | — | 7 AJAX tables | exposes payroll without the `payroll-report` permission | Redesign as a cashier/shift report |
| 28 | Biller Report (588, `biller-report`) | modal (MAIN:917-931, duplicate `id="user-id"`); POST `report/biller_report` → RC:4505; data RC:4515/4632/4740 | none | biller, date | Sales, Quotations, Payments | — | AJAX | — | Drop or merge |

### Broken route block (W:607-649)

**What it is.** A second `prefix('report')` group sits inside `Route::controller(ReportController)`. It registers 30 sale-style string actions that **do not exist on ReportController**: `productSaleData`, `getSale`, `importSale`, `limsSaleSearch`, `genInvoice`, `addPayment`, and others.

**Route names it claims**
- It owns the only definitions of the names **`sale.pos` → `/report/pos/{id?}`**, `pos.index` → `/report/pos`, **`sale.search`** and **`sale.import`**. The last two resolve to missing methods.
- So the sidebar POS link (SB:96) and the header POS link (MAIN:303) go to `/report/pos`, which works because that entry explicitly uses SaleController.

**Nesting.** The group's closing brace at W:649 leaves the `Route::controller(ReportController)` wrapper open until W:1194. Every later string-action route inherits ReportController unless it names its own controller.

**Duplicates.** The whole sales/purchase/return/transfer block is defined twice (W:394-604 and W:650-817). The later definitions win.

---

## 8. Settings

**Settings, Role and HRM settings controllers:** `C/SettingController.php` (~760 lines).

| Submenu (SB) | Route (name) | Controller@method | Permission in code | Form fields (view) | Validation | Tables | Bugs / notes | Recommendation |
|---|---|---|---|---|---|---|---|---|
| Role Permission (659, `$role->id <= 2` from a **globally cached** role) | GET `/role` `role.index` (W:267) → `RoleController@index` :16; POST `role` store :32; PUT `role/{id}` update :58; DELETE destroy :1538; GET `role/permission/{id}` `role.permission` :75; POST `role/set_permission` `role.setPermission` :90 | index, edit and permission check `role_id <= 2`; **store, update, setPermission and destroy check nothing** | Role modal: name, description, is_active (`V/role/create`); matrix: 153 checkboxes plus hidden role_id (`V/role/permission.blade.php`) | name max 255, unique among active | `roles`, `permissions`, `role_has_permissions` (Spatie) | **Any user can grant permissions** (finding 3). `Role::firstOrCreate(['id'=>…])`. Only `permissions` is removed from cache, so menus stay stale. The ~1430-line `setPermission` is a hand-written if/else block per permission. Destroy is a soft delete, and users keep the role. | **Redesign**: a policy-based RBAC with a seeded permission catalogue (§9) |
| Warehouse (682, `warehouse`) | resource `warehouse` (W:335), import, deletebyselection, `lims_warehouse_search`, `warehouse/all` | `WarehouseController@index` :16 | **none** (only `warehouseAll` scopes when role > 2, :128) | name, phone, email, address | name unique among active (:25-32) | `warehouses` | `$request->all()` create; soft delete; import has no permission check | Migrate as **Branch** |
| Customer Group (686, `customer_group`) | resource `customer_group` (W:359) and helpers | `CustomerGroupController@index` :18 | index :21 only | name, percentage (price adjustment %) | name unique | `customer_groups` | writes unguarded; percentage feeds POS pricing | Migrate (or merge into membership) |
| Brand (689, `brand`) | resource `brand` (W:313) | `BrandController@index` :19 | **none** | title, image, short_description, page_title | title unique, image ≤100 MB | `brands` | "Brand" doubles as **supplier** in the Product Order report | Migrate (as manufacturer) |
| Unit (692, `unit`) | resource `unit` (registered **twice**, W:277 and W:286) | `UnitController@index` :14 | index :17 only | unit_code, unit_name, base_unit, operator, operation_value | code and name unique | `units` | box/strip/piece conversion is central to pharmacy stock | Preserve |
| User Profile (700, **no gate**) | GET `user/profile/{id}` `user.profile`; PUT `user/update_profile/{id}`; PUT `user/changepass/{id}` (W:919-921) | `UserController@profile` / `profileUpdate` :186 / `changePassword` | **none, IDOR** | name, email, phone, company; password change | — | `users` | mass assignment and IDOR (finding 4) | Redesign as "my account" |
| General Setting (708, `general_setting`) | GET `setting/general_setting` `setting.general` → :74; POST `general_setting_store` `setting.generalStore` → :89 | **none** | site_title, site_logo, is_rtl, is_zatca, company_name, vat_registration_number, currency, currency_position, decimal, staff_access, without_stock, is_packing_slip, date_format, developed_by, invoice_format, state, timezone, expiry_type, expiry_value | only `site_logo` image (:91-93) | `general_settings`, **`.env` (APP_TIMEZONE)** | **unvalidated `.env` write** (:96-101); `date_default_timezone_set` loops over every zone, changing process state (:81-84); `staff_access` and `without_stock` control core behaviour | Migrate (validate; no `.env` writes) |
| Reward Point Setting (714, `reward_point_setting`) | GET `setting/reward-point-setting` → :251; POST → :257 | **none** | is_active, per_point_amount, minimum_amount, duration, type | none | `reward_point_settings` (ledger L1) | duplicates the new Reward engine | **Retire** once L4 is canonical |
| Payment Gateways (720, `payment_gateway_setting`) | GET `setting/payment-gateways/list` `setting.gateway` → :594; POST `…/update` → :619 | **yes** (:596-614, :621-638; raw `role_has_permissions` query; falls back to role ≤ 2 if the permission row is missing) | per gateway: `pg_name[]`, `{pg}_{key}` credential fields, `active[]`, `module_status[]` (pos/ecommerce) | none | `external_services` (`details` = "keys;values" CSV) | credentials stored in plain text in CSV format; a comma in a secret corrupts it | Mostly unused (PayPal/Stripe…); **likely-unused**; keep only the local wallets in use (UNVERIFIED which) |
| POS Settings (723, `pos_setting`) | GET `setting/pos_setting` → :713; POST → :728 | **none** | customer_id, warehouse_id, biller_id (defaults), product_number, options[] (payment methods, uniqueness checked :735-741), invoice_size, thermal_invoice_size, keybord_active, is_table, send_sms | duplicates check only | `pos_setting` | `is_table` is a restaurant feature | Migrate (pharmacy subset) |
| HRM Setting (726, `hrm_setting`) | GET `setting/hrm_setting` → :697; POST → :703 | **none** | checkin, checkout | none | `hrm_settings` | drives the attendance late flag | Migrate if HRM is kept |
| Barcode Settings (729, `barcode_setting`) | resource `barcodes` (W:967); POST `barcodes/barcode-data`; GET `barcodes/set_default/{id}` (W:961) | `BarcodeController` (`hasPermission()` :13-27 on index, data, create, store, edit, update) | name, description, width, height, margins, row/col distance, stickers_in_one_row/sheet, paper_width/height, is_default, is_continuous, is_custom | none (try/catch) | `barcodes` | `destroy` :254 and `setDefault` have no check | Preserve (label printing for shelves) |
| Shipping Setting (732, `shipping_setting`) | GET `shipping-zones` `shipping-zones.index` → :13; POST `load`, `store`, `update`, `delete` (W:1144-1156) | **none** | division, district, thana, default_rate, apply_default_rate, estimated_delivery, is_active, notes, delivery_partner (rider/pathao/steadfast/sundarban/redx) | `Validator::make` (:75-85, :138, :161) | `shipping_zones` | hard delete; logs full requests (`Log::info($request->all())` :86) | Keep only if home delivery is used |

### Commented or hidden settings (routes still live)

| Item | Route | Controller and permission | Notes | Recommendation |
|---|---|---|---|---|
| SMS Template | resource `smstemplates` (W:276) | `SmsTemplateController` :10-67, **no checks** | `create($request->all())`; hard delete; the `is_default_ecommerce` branch resets the wrong column (:27-29) | Migrate if SMS receipts are used |
| Custom Field | resource `custom-fields` (W:1138) | `CustomFieldController`, `custom_field` on index, create and edit | alters tables dynamically (UNVERIFIED); hard delete :249 | Likely-unused |
| Discount Plan / Discount | resources `discount-plans`, `discounts`, GET `discounts/product-search/{code}` (W:362-364) | `DiscountPlanController`, `DiscountController`; **both** check `discount_plan` (so `discount` is unused) | — | Possibly useful for promotions; otherwise likely-unused |
| All Notification / Send Notification | GET `notifications`, POST `notifications/store`, GET `mark-as-read` (W:1123-1133) | `NotificationController`, `all_notification` on index | — | Replace with an in-app notification service |
| Tables | resource `tables` (W:338) | `TableController`, none | restaurant | **Drop** |
| Currency | resource `currency` (W:1136) | `CurrencyController`, `currency` on index | single currency (BDT) | Drop (config only) |
| Tax | resource `tax` + import/search (W:341-348) | `TaxController`, `tax` on index | VAT may be needed | Keep a simplified version |
| Create SMS / Send SMS | GET `setting/createsms`, POST `setting/sendsms` (W:944-945) | :531/:539, none | bulk SMS to customers | Redesign behind permission |
| Backup Database | GET `backup` `setting.backup` (W:957) | :272, **none** | mysqli dump → **zip in `public/`** → redirect | **Remove** (use server-side backups) |
| Mail Setting | GET `setting/mail_setting`, POST store (W:942, 950) | :375/:381, none | SMTP password stored in plain text (`mail_settings.password`) | Move to env/secret store |
| SMS Setting | GET `setting/sms_setting`, POST store (W:943, 951) | :399/:471, none | gateways Tonkra, ReveSMS, BDBulkSMS, Twilio, Clickatell; API keys in DB | Keep one local provider; move secrets |
| Languages / Translations | `languages*`, `translations*` (W:160-170), `language_switch/{id}` (W:265) | `LanguageController`, `language_setting` on index only; `TranslationController`, none | destroy cascades translations | Use static i18n files instead |
| Empty Database (header) | GET `setting/empty-database` (W:954) | :40-71, **none** | truncates everything except a whitelist; CSRF-triggerable; FK failures; forgets the wrong cache key | **Remove** |
| Change Theme | GET `setting/general_setting/change-theme/{theme}` (W:941), `switch-theme/{theme}` (W:211) | — | — | UI preference only |
| Couriers | resource `couriers` (W:1025) | `CourierController`, none | sidebar link is commented out (SB:113) | Merge with delivery partners |
| Gift Card / Coupon | resources `gift_cards`, `coupons` (W:1016-1033) | `gift_card` / `coupon` perms | sidebar commented out (SB:107-112); `coupons` controller hosts the kill switch | Coupons: maybe. **Remove `updateCoupon` now.** |

---

## 9. Roles and permissions model (for the new RBAC)

### 9.1 How it works

**Storage.** Spatie `laravel-permission` tables `permissions` and `role_has_permissions`, plus the app's own `roles` table (`App\Models\Roles`, `is_active`). The user has a single `users.role_id`. Spatie's `model_has_roles` is **not used** for checks; every check does `Role::find(Auth::user()->role_id)->hasPermissionTo('x')`.

**Hard-coded role IDs**

| Role ID | Meaning |
|---|---|
| 1 | Admin, seeded (UNVERIFIED name) |
| 2 | Owner, seeded (UNVERIFIED name) |
| ≤2 | "super" (Role menu, cash register, `warehouseAll` scope) |
| 5 | Customer |
| 6 | Supplier user / purchase-only (Add Purchase variant, SB:62) |

**Matrix page.** `role/permission/{id}` shows one row per module and columns Index/Add/Edit/Delete, plus checkbox groups for dashboard, accounting, reports, settings and miscellaneous (`V/role/permission.blade.php`, 30 sections). It submits to `setPermission`, which revokes or grants each permission by name and calls `Permission::firstOrCreate` (`C/RoleController.php:90-1529`).

**Caching.** `Common` middleware: `user_role` is a global key (bug); `permissions` is global (fine); `role_has_permissions` is a global key (bug); `role_has_permissions_list{role_id}` is cached for 365 days and never removed by `setPermission`.

**Non-Spatie checks**
- Payment Gateway and Barcode query `role_has_permissions` directly.
- Customer Ecosystem, Manufacturing, Shipping, Riders writes, Settings pages and most writes have **no** check.

### 9.2 Full permission catalogue: 153 names

This list was extracted from every `$request->has('…')` in `setPermission`. It matches the checkbox `name=` attributes in the matrix view exactly; the view's only extra field is `role_id`.

| Group (matrix section) | Permission names | Count |
|---|---|---|
| Product | `products-index`, `products-add`, `products-edit`, `products-delete`, `category`, `print_barcode`, `adjustment`, `stock_count`, `product_history` | 9 |
| Purchase | `purchases-index`, `purchases-add`, `purchases-edit`, `purchases-delete` | 4 |
| Purchase Payment | `purchase-payment-index`, `purchase-payment-add`, `purchase-payment-edit`, `purchase-payment-delete` | 4 |
| Sale | `sales-index`, `sales-add`, `sales-edit`, `sales-delete`, `online-orders`, `packing_slip_challan`, `delivery`, `gift_card`, `coupon` | 9 |
| Sale Payment | `sale-payment-index`, `sale-payment-add`, `sale-payment-edit`, `sale-payment-delete` | 4 |
| Quotation | `quotes-index`, `quotes-add`, `quotes-edit`, `quotes-delete` | 4 |
| Transfer | `transfers-index`, `transfers-add`, `transfers-edit`, `transfers-delete` | 4 |
| Sale Return | `returns-index`, `returns-add`, `returns-edit`, `returns-delete` | 4 |
| Purchase Return | `purchase-return-index`, `purchase-return-add`, `purchase-return-edit`, `purchase-return-delete` | 4 |
| Expense | `expenses-index`, `expenses-add`, `expenses-edit`, `expenses-delete` | 4 |
| Income | `incomes-index`, `incomes-add`, `incomes-edit`, `incomes-delete` | 4 |
| Accounting | `account-index`, `money-transfer`, `balance-sheet`, `account-statement` | 4 |
| HRM: Department | `department`, `department-add`†, `department-edit`†, `department-delete`† | 4 |
| HRM: Employee | `employees-index`, `employees-add`, `employees-edit`, `employees-delete` | 4 |
| HRM: Attendance | `attendance`, `attendance-add`, `attendance-edit`†, `attendance-delete` | 4 |
| HRM: Payroll | `payroll`, `payroll-add`, `payroll-edit`, `payroll-delete`, `monthly-payroll`, `payroll-report` | 6 |
| HRM: Advances | `advances-index`, `advances-add`, `advances-edit`, `advances-delete` | 4 |
| HRM: Holiday | `holiday`, `holiday-add`, `holiday-edit`, `holiday-delete` | 4 |
| People: User | `users-index`, `users-add`, `users-edit`, `users-delete`† | 4 |
| People: Customer | `customers-index`, `customers-add`, `customers-edit`, `customers-delete` | 4 |
| People: Biller | `billers-index`, `billers-add`, `billers-edit`, `billers-delete`† | 4 |
| People: Supplier | `suppliers-index`, `suppliers-add`, `suppliers-edit`, `suppliers-delete`† | 4 |
| Dashboard | `revenue_profit_summary`, `cash_flow`, `monthly_summary`, `yearly_report`, `today_sale`, `today_profit` | 6 |
| Reports | `profit-loss`, `best-seller`, `product-report`, `daily-sale`, `monthly-sale`, `daily-purchase`, `monthly-purchase`, `sale-report`, `sale-report-chart`, `payment-report`, `purchase-report`, `customer-report`, `due-report`, `supplier-report`, `supplier-due-report`, `warehouse-report`, `warehouse-stock-report`, `product-expiry-report`, `product-qty-alert`, `dso-report`, `user-report`, `biller-report` | 22 |
| Settings | `general_setting`, `pos_setting`, `hrm_setting`, `mail_setting`, `sms_setting`, `create_sms`, `reward_point_setting`, `payment_gateway_setting`, `barcode_setting`, `shipping_setting`, `warehouse`, `customer_group`, `brand`, `unit`, `currency`, `tax`, `discount_plan`, `discount`†, `custom_field`, `language_setting`, `backup_database`, `empty_database`, `all_notification`, `send_notification` | 24 |
| Miscellaneous | `addons` | 1 |
| **Total** | | **153** |

† Permissions that can be set but are **never checked** anywhere in app, resources or Modules: `department-add`, `department-edit`, `department-delete`, `attendance-edit`, `users-delete`, `billers-delete`, `suppliers-delete`, `discount` (the `discount_plan` permission is checked instead).

**Grouping caveat.** I assigned 4 permissions to groups by inference (`product_history`, `delivery`, `gift_card`, `coupon`); confirm their exact matrix row in `V/role/permission.blade.php`.

### 9.3 Gaps the new RBAC must cover

**Areas with no permission at all**
- Customer Ecosystem: plans, review, rewards settings, wallet adjust, campaigns
- Riders writes; Shipping zones; Cash register (role ≤ 2 only)
- Manufacturing/production
- Product-order AI draft and create-purchase
- Clear-due and deposits
- Advance and holiday approve
- Daily Profit, Challan and Adjustment reports
- Settings stores
- SMS templates, Tables, Couriers, Translations, Backup, Empty DB
- User profile

**Stray permission strings.** `resources/views/vendor/translation/layout.blade.php` contains about 80 permission strings (`view-appraisal`, `core_hr`, …) from another product. They are not part of this catalogue; ignore them.

**Recommended new permission set.** Use resource × action (`view`, `create`, `update`, `delete`, `approve`, `export`) for each module, plus scoped data rights (`branch:own` / `branch:all`, which replaces `general_settings.staff_access` and the role ≤ 2 checks). Use explicit permissions for financial actions: `sale.void`, `payment.clear_due`, `account.transfer`, `reward.adjust`, `membership.plan.manage`, `purchase.create_from_forecast`, `report.profit.view`.

---

## 10. Modules, addons and vendor endpoints

| Item | Where | What it does | Risk | Recommendation |
|---|---|---|---|---|
| `modules_statuses.json` | repo root | enables Woocommerce, Ecommerce, Middleware and Manufacturing; **only `Modules/Manufacturing` exists** | nwidart ignores missing modules | Remove the three phantom entries |
| WooCommerce link | SB:600-602 (inside `<!-- -->`) | `route('woocommerce.index')` runs if `general_settings.modules` contains `woocommerce` | **RouteNotFoundException on every backend page** | Delete the block; check the DB value (UNVERIFIED) |
| eCommerce include | SB:603-609 (inside `<!-- -->`) | `@include('ecommerce::backend.layout.sidebar-menu')` runs if the modules value contains `ecommerce` | "No hint path" error, so every page returns 500 | Delete |
| `customerauth` middleware alias | `app/Http/Kernel.php:50` → `Modules\Ecommerce\…\CustomerAuth` | not used by any route | latent | Remove |
| `config('addons')` contains `ecommerce` | Brand, Category, Product and PackingSlip controllers | writes slug/SEO fields | low | Clean up |
| Middleware module | — | no references | — | Remove the status entry |
| Manufacturing | `Modules/Manufacturing`: routes `Routes/web.php:35-48` (`productions` resource, `productions/production-data`, `productions/product_production/{id}`, `manufacturing` stub); API stub `Routes/api.php:16`; migrations for `productions` and `product_productions` | "Production" of **combo** products: deducts component stock and adds combo stock (`ProductionController.php:208-349`); `destroy` reverses it | no permission checks except the addon flag on index; no transactions; `show`/`edit` views missing (500); `update` is empty; SaaS branch fatal. **When enabled, combo sales stop deducting component stock** (`SaleController.php:1415, …`) | Disable unless pharmacy kitting is used |
| Addons page | GET `addon-list` (W:213) → `HomeController@addonList` (`addons` permission) | buy and install modals | entry to the installers | Remove |
| Addon installers | POST `woocommerce-install`, `ecommerce-install` (W:1140-1142) | download a zip from the vendor, extract into `Modules/`, migrate, set the modules flag even if the download failed | remote code install by any active user; triggers the 500s above | Remove |
| SaaS installer | `saas/install/*` (W:218-230) | SSL verification off; extracts a zip over the app root; rewrites `.env` DB settings | takeover / outage | Remove |
| Developer section | `developer-section*` (W:106-115), **no auth** | `submit` and the two settings routes write `.env` VERSION/BUG_NO and `track/*.json` | `.env` injection or corruption | Remove |
| Auto-update | `new-release`, `bugs`, POST `version-upgrade`, `bug-update` (W:117-123), **no auth** | downloads vendor zips into `base_path()`, then migrate and **db:seed** | unauthenticated code overwrite | Remove; deploy through git/CI |
| `auto-update-dashboard` | W:103, no auth | calls the vendor API | SSRF-lite / DoS | Remove |
| `/migrate`, `/cc` | W:72-99, no auth | run migrations / flush caches | — | Remove |
| `phpfileinfo` | W:1035-1040 | `phpinfo()` | leaks the environment | Remove |
| `public/delete.php` | file | deletes `./install` | — | Delete |

---

## 11. Cash register (not in the sidebar)

| Route (W:1107-1120) | Method (`C/CashRegisterController.php`) | Caller |
|---|---|---|
| GET `cash-register` `cashRegister.index` | index :15 (role ≤ 2) | header icon (MAIN:310). Columns: User, Warehouse, Cash in Hand, Opened/Closed, Status |
| GET `check-availability/{warehouse_id}` | :127 | Add Sale page `V/sale/create.blade.php:982`, return edit. **The POS open-register modal is commented out** (`V/sale/pos.blade.php:1240`) |
| POST `store` | :24 | open-register modal |
| GET `getDetails/{id}` | :33 | register list |
| GET `showDetails/{warehouse_id}` | :74 | POS "Cash Register Details" (`pos.blade.php:195`). **Throws a null error when no register is open**, and the POS never opens one |
| POST `close` | :119 | POS and list. No ownership or permission check. No counted-cash or variance capture. |

**Formula** (:69, :114): cash = opening + **all** payments (including card and deposits) − returns − expenses. Income is ignored.

**Recommendation.** Redesign as a proper shift close-out: opening float, expected cash-only total, counted amount, variance, and an approval step.

---

## 12. Routes in these areas not linked from the sidebar

Header, modal and AJAX callers are noted where they exist.

| Area | Routes (W line) | Linked from |
|---|---|---|
| Quotation | `quotations` resource and helpers (507-522, 728-741) | header "+" (MAIN:246); sidebar commented out |
| Expense / Income helpers | `expense_categories/{gencode,import,deletebyselection,all}`, `expenses/{expense-data,deletebyselection}` (975-988); `income_categories/{gencode,import*,deletebyselection*,all*}` (* = **missing methods**), `incomes/{income-data,deletebyselection}` (996-1009) | page JS |
| Accounting | `make-default/{id}` (1046, URL mismatch), `accounts/all` (1049), `account-statement` (1048) | page JS / modal |
| HRM | `{departments,employees,payroll,attendance,holidays}/deletebyselection`, `attendance/delete/{date}/{emp}`, `attendance/importDeviceCsv`, `monthly-payroll/{get-data,store}`, `advances-payments/{approve,reject}/{id}`, `advances-payments/deletebyselection` (**missing method**), `approve-holiday/{id}`, `holidays/my-holiday/{y}/{m}` (header) | page JS / header |
| People | `user/{profile,update_profile,changepass,genpass,deletebyselection,notification,all}`; `importcustomer`, `customer/{getDeposit,add_deposit,update_deposit,deleteDeposit,deletebyselection,lims_customer_search}`, `customers/{clear-due,customer-data,all}`; `importbiller`, `biller/{deletebyselection,lims_biller_search}`; `importsupplier`, `supplier/deletebyselection`, `suppliers/{clear-due,all}`; `riders/{table-loader,store,update,destroy}` | page JS |
| Referenced but undefined | `customer.account-delete`, `customer.get-destroy-request` (customer index), `/exportbiller` (biller index) | — |
| Reports | POST variants `report/{daily_sale,monthly_sale,daily_purchase,monthly_purchase,best_seller}` (by warehouse); all `*-data` endpoints (862-910); product-order sub-routes (828-842); `profit-loss/product-wise/export` (867); `export-all` (868, **missing method**); `supplier-due-report-data` (910, **missing method**); W:607-649 broken block (30 missing methods) | page JS |
| Settings | `setting/{mail_setting,sms_setting,createsms,sendsms,empty-database,general_setting/change-theme/{t}}`, `backup`, `smstemplates`, `custom-fields`, `discount-plans`, `discounts`, `notifications`, `tables`, `currency`, `tax`, `couriers`, `gift_cards`, `coupons`, `languages*`, `translations*`, `language_switch/{id}`, `barcodes/set_default/{id}` | commented sidebar / header / none |
| Membership and rewards | every sub-route except the 7 sidebar entries (plans store/update/destroy, customers review, rewards settings, wallets, adjust, transactions, campaigns, expiry, reports, export) | in-page links |
| Manufacturing | `productions*`, `manufacturing` | none (sidebar commented out) |
| Cash register | all 6 | header and POS |
| Vendor / dev | `migrate`, `cc`, `update-coupon`, `auto-update-dashboard`, `developer-section*`, `new-release`, `bugs`, `version-upgrade`, `bug-update`, `documentation`, `ecommerce-documentation`, `saas/install/*`, `addon-list`, `woocommerce-install`, `ecommerce-install`, `phpfileinfo`, `download-sample-products`, `my-transactions/{y}/{m}` (header) | mostly none |

---

## 13. Disposition summary

| Disposition | Items |
|---|---|
| **Preserve / enhance** | Product Expiry report; Product Order and AI reorder workflow (with real suppliers and permissions); Adjustment report; Unit (conversion); Barcode/label settings; Warehouse as Branch; Customer Ecosystem (after fixes); Users, Customers, Suppliers masters |
| **Migrate after redesign** | Summary P&L + Daily Profit; item-wise sales and purchase reports; trends (daily/monthly/best seller); payments ledger; customer and supplier statements and dues with aging; stock valuation; Expense/Income journal; Accounts + Money Transfer (real ledger); Cash register (shift close); General / POS settings; Role/Permission (policy RBAC); Customer Group |
| **Low priority / optional** | HRM (department, employee, attendance import, payroll after a rewrite); Riders, Shipping zones, Couriers (only with delivery); SMS settings and templates (one provider); Tax |
| **Likely unused for a pharmacy** | Quotation; Billers (fold into branch); Customer Group report; Biller report; Challan report; DSO report; Tables; Currency; Custom fields; Discount plans; Gift card; Payment gateways except local wallets; Languages/Translations UI; Manufacturing |
| **Remove immediately (security)** | `update-coupon`; scheduler `reset:db` / `quote:daily`; `public/*.sql` dumps and `public/debug_payroll_params.txt`; Backup-to-public; Empty Database; `migrate`, `cc`, `phpfileinfo`; developer-section; auto-update; SaaS/addon installers; `delete.php`; phantom module blocks in the sidebar |

## 14. Unverifiable items (UNVERIFIED)

- Live DB values: `general_settings.modules` and `staff_access`; whether `employees.salary`, the extra `payrolls` columns, `advances_payments`, `riders`, `reward_point_tiers` and `reward_point_usages` exist.
- Whether production cron runs `schedule:run`, and therefore whether `reset:db` actually fires.
- The `APP_TIMEZONE` value; whether the web server document root exposes `.env` or `track/*.json`.
- Runtime confirmation that the Customer List page throws (missing routes) and that Customer Due "all" returns 0 rows.
- Seeded role names for IDs 1, 2, 5 and 6; which payment gateways and SMS provider are actually configured.
- Exact matrix row of 4 permissions (`product_history`, `delivery`, `gift_card`, `coupon`).
