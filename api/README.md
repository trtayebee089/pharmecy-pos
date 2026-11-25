# Red Pharma API

Centralized Laravel 13 API for the rebuilt Red Pharma system. The Nuxt POS (`../pos`)
talks to `/api/pos/v1` with Sanctum SPA cookie auth. The design is in
`../docs/rebuild/` (architecture, database design, data migration plan).

## What is implemented

| Area | Endpoints | Notes |
|---|---|---|
| Auth | `POST auth/login`, `POST auth/logout`, `GET me`, `PUT me/password` | Username login, 5 attempts/min lockout, audit log, session cookie only (no tokens in the browser) |
| Catalog | `GET catalog/products/search`, `GET catalog/products`, `GET catalog/products/{id}` | Units with current retail price, sellable batches in FEFO order, sellable stock; `includeExpired=1` on search and show lists every batch on hand (expired too) for users who return, adjust, count or transfer stock |
| Products (writes) | `POST catalog/products`, `PUT catalog/products/{id}`, `GET catalog/products/{id}/form`, `POST catalog/products/{id}/deactivate`, `/duplicate`, `POST catalog/products/import` (Idempotency-Key on the last three) | Unique SKU (deactivated products keep theirs); exactly one base unit, one unit per code and per factor, prices > 0; barcodes unique across products; one sale and one purchase default; units used on sales or purchases keep their factor and cannot be removed; retail and wholesale prices are versioned, changing an existing price needs `products.price`; never deleted, only deactivated; duplicate = inactive copy (`SKU-C`, no barcodes); CSV import is all or nothing, new SKUs only, generic/manufacturer/dosage form matched by name or created |
| Catalog masters | `GET catalog/lookups`, `GET/POST catalog/{generics,manufacturers,dosage-forms,categories}`, `GET/PUT/DELETE catalog/{type}/{id}` | Unique names; writes need `catalog.manage`; a master used by products cannot be deleted (deactivate it: hidden from product forms, kept on products); categories nest with a path (`Medicine › Antibiotics`) and refuse cycles; lookups list active masters only |
| Customers | `GET customers`, `GET customers/lookup`, `POST customers` | Unique normalised BD mobile number, due balance from the ledger |
| Customer accounts | `GET/PUT customers/{id}`, `POST customers/{id}/deactivate` (Idempotency-Key) | Profile with visits, spend, purchases and running ledger; edit keeps a unique normalised phone and an existing group; the walk-in customer cannot be edited or deactivated; a customer who owes cannot be deactivated |
| Due collection | `GET customers/due`, `GET customers/{id}/open-invoices`, `POST customers/{id}/payments` (Idempotency-Key), `POST customers/{id}/due-reminders` | Amount at most the ledger due; allocations only to the customer's open invoices, each at most its due; unallocated money only for debt not on an invoice (opening balance); incoming payment `PAY-` with account transaction and ledger credit, attached to the collector's open register; a sale with a later collection can no longer be voided. Reminders answer 503 until an SMS gateway exists |
| Customer groups | `GET/POST customer-groups`, `GET/PUT/DELETE customer-groups/{id}` | Unique name, discount 0–100 %, notes; writes need `customer_groups.manage` (not given to cashiers); a group with customers cannot be deleted |
| Sales | `POST sales` (Idempotency-Key), `GET sales`, `GET sales/{id}` | Server pricing, customer group discount (the larger of group and manual line discount; only a manual discount above the group one is checked against the role limit; not on custom items; recorded on the sale), role discount limits, FEFO allocation under lock, expired stock never sold, payments, account and customer ledgers |
| Held sales | `GET sales/held`, `POST sales/held` (Idempotency-Key), `POST sales/held/{number}/resume` (Idempotency-Key), `DELETE sales/held/{number}` | Cart parked on the server (`H-DHK1-2026-00001`), any counter of the warehouse can resume it; no stock reserved, no money, prices never stored (list totals and resumed lines use current prices and sellable batches; inactive products are dropped and reported); resumed or discarded once under a row lock; kept on record |
| Voids | `POST sales/{id}/void` | Whole sale, reason required, only while its register session is open (or same day without a session) and before any return; stock, payments and ledger reversed, invoice kept as void |
| Returns | `POST sales/{id}/returns` (Idempotency-Key), `GET sales/returns` | Per sold batch, never more than sold minus returned; refund includes the order-discount share; restock to the same batch or write off; refund as cash, bKash or customer credit |
| Cash register | `GET cash-registers`, `GET/POST cash-registers/sessions`, `POST sessions/{id}/movements` (Idempotency-Key), `POST sessions/{id}/close` | One open session per register and per cashier; sales, payments and refunds attach to the cashier's open session; expected cash = float + cash sales − cash refunds + cash in − cash out |
| Purchases | `GET purchases`, `GET purchases/{id}`, `POST purchases`, `POST purchases/{id}/payments`, `/void`, `/duplicate` (POSTs take an Idempotency-Key) | `received`: batches + stock movements at landed cost, supplier ledger, optional payment and retail price update; `ordered`: purchase order (shown as draft), no stock or money; receiving with `orderId` closes the order; void only while the received stock is unsold, unpaid and not returned (otherwise a purchase return); duplicate copies as a new order; `due = total − paid − returned` |
| Purchase orders (planning) | `GET purchase-orders/companies`, `GET purchase-orders/report`, `POST purchase-orders/forecast`, `GET/POST purchase-orders/drafts`, `GET/PUT/DELETE purchase-orders/drafts/{id}`, `POST drafts/{id}/purchase` (Idempotency-Key), `POST purchase-orders/ai-runs`, `POST ai-runs/{id}/process` | Per company (manufacturer) and warehouse: pieces sold net of returns, sellable stock, unit ladder, last landed cost per piece. Forecast is statistical, not an external AI: 28-day daily rate × 7-day trend (±50 %), 21 days of cover, confidence from days with sales. One open draft per company/date/warehouse (`POD-`), no stock or money; converting creates an ordered purchase (`PO-`) in the largest unit that fits, once. Batch runs draft every company that needs an order, 3 per request. Needs `reorder.manage` (+ `purchases.create` to convert) |
| Purchase returns | `GET purchase-returns`, `POST purchase-returns`, `POST purchase-returns/{id}/void` (POSTs take an Idempotency-Key) | Per batch, expired stock included, never more than the batch holds in the warehouse; priced at the batch cost (client cost ignored); a batch bought from other suppliers only is refused (opening stock may go to any supplier); supplier ledger debited; settlement `reduce_due` lowers the source invoice, then the oldest open invoices, the rest stays as supplier credit; `refund` records an incoming payment so the balance is unchanged; void restocks the batches and reverses the ledger, invoice reductions or refund. Number `PR-DHK1-2026-00001`; permission `purchase_returns.create` |
| Suppliers | `GET/POST suppliers`, `GET/PUT/DELETE suppliers/{id}`, `GET suppliers/lookup`, `GET suppliers/{id}/ledger`, `POST suppliers/{id}/payments` (Idempotency-Key) | Balance from the supplier ledger; account payments are allocated to the oldest open invoices; suppliers with purchases are deactivated instead of deleted |
| Inventory views | `GET inventory/stock`, `GET inventory/stock/summary`, `GET inventory/batches` | Per warehouse (`warehouse_id`, default the user's): sellable and expired pieces, value of sellable stock at batch cost, low/out/expiring status and filters; batch list by expiry (`within=expired|30|90|0`) |
| Adjustments | `GET/POST inventory/adjustments`, `POST inventory/adjustments/{id}/void` (POSTs take an Idempotency-Key) | Per batch with a reason and note: damage/lost out, found in, correction either way, expiry write-off only from expired batches; out never exceeds the batch; in at batch cost; void reverses (refused if added stock was since used). Number `ADJ-` |
| Stock counts | `GET/POST inventory/counts`, `GET inventory/counts/{id}`, `PUT counts/{id}/lines`, `POST counts/{id}/post` (Idempotency-Key), `POST counts/{id}/cancel` | Scope all / dosage form / manufacturer / category (by name); lists batches on hand; posting compares each counted line with the system quantity at posting time and posts `count` movements; uncounted lines unchanged. Number `SC-` |
| Transfers | `GET/POST inventory/transfers`, `POST transfers/{id}/receive`, `/void` (POSTs take an Idempotency-Key) | Sellable batches only; send = `transfer_out` (in transit), receive at the destination at the same cost (destination staff only), void only in transit (back to source). Number `TR-` |
| Finance | `GET/POST finance/accounts`, `PUT accounts/{id}`, `POST accounts/{id}/activate|deactivate|default`, `GET accounts/{id}/statement`, `GET/POST finance/money-transfers`, `POST money-transfers/{id}/void`, `GET/POST finance/expenses|incomes`, `POST {id}/void`, `POST/GET {id}/attachment`, `finance/expense-categories|income-categories` (CRUD), `GET finance/balance-sheet` | Balances are derived (opening + Σ in − Σ out of account transactions). Opening balance locked once used; deactivate only at zero balance, not the default, no active payment method on it. Transfers and expenses never overdraw the source (checked against the current balance); voids reverse and never pull money an account no longer holds. Attachments are private files (PDF/image ≤ 5 MB) served by a 5-minute signed link. Balance sheet: account balances by type, inventory at cost from the stock history (expired excluded), customer receivables/credit, supplier payables/advances, opening capital + balancing retained earnings; P&L month to date (sales, returns, COGS net of restocked returns, expenses, income). Numbers `MT-`, `EXP-`, `INC-` |
| Reports | `GET reports/{sales,profit,billers,custom-products,stock,expiry,purchases,customers,customer,suppliers,payments,cashiers}`, `GET reports/monthly-summary`, `GET reports/options`, `GET billers` | `ReportResult` (kpis, series, columns, rows, totals) for the selected `warehouse_id` or every warehouse the user can access; `from`/`to` inclusive; empty filter = all. Revenue = completed sales (voids excluded), COGS = batch cost recorded on the sale, expenses/income = posted finance entries, balances from the ledgers. A biller is the branch (warehouse). Each report keeps its menu permission (`reports.sales`, `reports.profit`, …) |
| Loyalty | `GET loyalty/wallets`, `GET loyalty/wallets/{customer}/ledger`, `POST loyalty/wallets/{customer}/adjustments` (Idempotency-Key), `GET/POST/PUT/DELETE loyalty/campaigns`, `GET loyalty/membership-plans`, `PUT/DELETE loyalty/membership-plans/{plan}`, `GET loyalty/members`, `POST loyalty/customers/{customer}/membership`, `POST loyalty/members/{member}/{renew,plan,cancel}`, `GET/PUT settings/reward-points` | Points earn on sales and pay as the `points` payment method (rules in `LoyaltySettings`, see HANDOFF §3); voids and returns reverse them; tiers upgrade automatically by annual spend; `loyalty:expire` runs daily. `loyalty.view` / `loyalty.manage`; changing the rules needs `settings.manage` |
| Online orders & delivery | `GET/POST orders`, `GET orders/{id}`, `POST orders/{id}/status`, `POST orders/{id}/rider` (POSTs take an Idempotency-Key), `GET/PUT orders/settings`, `GET/POST/PUT/DELETE delivery/riders`, `POST delivery/riders/{id}/handover`, `GET delivery/riders/{id}/cash`, `GET/POST/PUT/DELETE delivery/zones` | Phone orders priced on the server with zone shipping; stock taken once at confirm (FEFO from the online branch) and put back on cancel; COD held by the rider until handover into Cash in hand (see HANDOFF §3). `orders.view` / `orders.fulfil`, `riders.manage`, `zones.manage` |
| HR & payroll | `GET/POST/PUT hr/employees`, `hr/departments`, `hr/designations`, `hr/holidays` (+ DELETE for the last three), `GET/POST hr/attendance`, `GET hr/attendance/month`, `GET/PUT hr/settings`, `GET/POST hr/payroll-runs`, `GET/DELETE hr/payroll-runs/{id}`, `POST hr/payroll-runs/{id}/{approve,pay}`, `PUT hr/payroll-runs/{id}/payslips/{payslip}`, `GET/POST hr/salary-advances`, `POST hr/salary-advances/{id}/{recoveries,void}`, `GET finance/accounts/payout` | Absence = basic ÷ 30 per absent day; weekly off days, late rule and advance recovery are admin settings; paying posts the net per payslip, one salary expense for what was earned and the advance recoveries (see HANDOFF §3). `hr.view`, `hr.manage`, `hr.attendance`, `hr.payroll` |
| Administration | `GET/POST admin/users`, `PUT admin/users/{id}`, `POST admin/users/{id}/{enable,disable,password-reset}`, `GET/POST admin/roles`, `PUT admin/roles/{id}`, `PUT admin/roles/{id}/details`, `DELETE admin/roles/{id}`, `GET/POST/PUT/DELETE admin/warehouses`, `GET/PUT admin/settings/pos` (general), `GET/PUT settings/pos`, `POST settings/printers/{id}/test`, `GET/PUT me/profile` | Admins only (`roles.manage`) for users and roles; temporary passwords are emailed and must be changed (`password.fresh` middleware); general settings drive rounding and the expired/negative stock switches (see HANDOFF §3). `users.view`, `warehouses.manage`, `settings.manage` |
| Dashboard | `GET dashboard/summary` | Live figures from sales, ledgers and stock |

Not yet built (the POS still uses its mock layer for these):
the inventory reorder suggestions screen (`/reorder/*`) and voiding a sale return.

## Customer API (website and Android app)

Routes: `routes/customer.php`. Bearer tokens (Sanctum, ability `customer`, 30 days); the staff `pos/v1` group
refuses customer tokens (`staff` middleware) and customer routes refuse staff sessions (`customer` middleware).
Every customer route answers JSON even without an `Accept` header (`ForceJson`), and money stays a JSON float
(`60.0`, `JSON_PRESERVE_ZERO_FRACTION`) because the app assigns some values straight into a Dart `double`.

| Area | `/api/v1` endpoints | Notes |
|---|---|---|
| Account | `POST customer/{register,login,forgot-password,reset-password}`, `GET/PUT customer/profile`, `PUT customer/password`, `POST customer/{logout,logout-all,account-removal-request}` | Sign in with phone or email + password (min 8, letters and numbers). Registering an existing phone or email is refused (no account takeover). Reset: 6-digit code to the account's email, 15 min, 5 tries, 60 s between codes, same answer for unknown accounts; a reset signs out every device. Changing the password needs the current one. The phone cannot be changed online. Throttled per IP and per account |
| Catalogue | `GET categories`, `GET categories/{slug}` (`data` + top-level `products` paginator), `GET products` (`category`, `search`, `sort`, `price=a-b`, `in_stock`, `prescription_required`, `page`, `per_page` ≤ 60), `GET products/{featured,best-selling}`, `GET products/suggestions?query=`, `GET products/{slug or id}`, `GET shipping-zones` | Active products with a retail price for one piece; `price`, `stock` and order quantities are **per piece** (`unit` = the piece label, `pack` = how it is usually sold); stock = sellable (active, not expired) batches at the online branch |
| Orders | `POST checkout` (optional bearer, `Idempotency-Key`), `GET orders`, `GET orders/{number}`, `POST orders/{number}/reorder`, `GET tracking/{code}` | Through `OnlineOrders::place`: server prices, zone shipping, stock check, cash on delivery. A signed-in customer gets the customer discount (group / membership per the reward settings) and may redeem `reward_points`; a guest gets neither, even with an existing customer's phone (the order is linked to that customer, nothing else). A new guest may leave an email to set a password later. Orders are addressed by number and only the owner sees them; tracking by the random `RP…` code shows status only (no name, phone or address). Same key + same phone returns the first order |
| Rewards & membership | `GET customer/rewards`, `GET customer/rewards/transactions`, `POST customer/rewards/quote`, `GET customer/membership` (+ `/progress`), `GET customer/membership/history`, `POST customer/membership/renew` | Quote only checks the rules; points leave the wallet when the order is placed and come back if it is cancelled. Renewal answers 422: the fee is paid at a branch |

**Legacy paths for the installed Android app** (`LegacyAppController`, remove when old installs have aged out):
`customer/{login,register,profile,profile/update,profile/update-password,forget-password,verify-otp,logout,orders,orders/{id}}`,
`orders/{id}/reorder`, `categories(/{slug})`, `products/{featured,best-selling,suggestions,{slug}}`, `search/{q}`,
`shipping-zones`, `checkout`, `reward/{profile,transactions,redeem}`. Same services and rules, old response shapes
(see `tests/Feature/LegacyAppApiTest.php`). Differences: the old checkout sends no delivery area, so the zone is
matched by the shipping rate (preferring the district named in the address); client prices, discount and `user_id`
in the old body are ignored; a guest no longer gets the 5 % the old app shows (the confirmation shows the real
total); the old password change sends no current password, so it is accepted on that path only and signs out every
other device.

## Local development (Windows)

Herd's PHP 8.4 is used with a cleaned php.ini kept outside the repo
(`../../.php84/`): Herd's own ini loads a missing `dump-loader.php`, which breaks
Composer and artisan.

```bash
export PATH="/d/01 REDPHARMA/00 CODEBASE/.php84:$PATH"   # php + composer wrappers
cp .env.example .env && php artisan key:generate           # first time only
php artisan migrate:fresh --seed --seeder=DemoSeeder       # demo catalogue, stock and users
php vendor/bin/pest                                        # test suite (in-memory SQLite, fast)
php vendor/bin/pest -c phpunit.mysql.xml                   # same suite on MySQL/MariaDB
```

### Database (MySQL / MariaDB)

The app database is MySQL 8 or MariaDB 10.11+ (utf8mb4, `utf8mb4_unicode_ci`).
Create the app and test databases and a local user once, then put the password
in `.env` (`DB_PASSWORD`):

```sql
CREATE DATABASE redpharma_pos      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE redpharma_pos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'redpharma'@'localhost' IDENTIFIED BY '<password>';
CREATE USER 'redpharma'@'127.0.0.1' IDENTIFIED BY '<password>';
GRANT ALL PRIVILEGES ON redpharma_pos.*      TO 'redpharma'@'localhost', 'redpharma'@'127.0.0.1';
GRANT ALL PRIVILEGES ON redpharma_pos_test.* TO 'redpharma'@'localhost', 'redpharma'@'127.0.0.1';
```

`phpunit.mysql.xml` always targets `redpharma_pos_test` (tests wipe it), never the
app database. MySQL notes for new code:

- Identifiers are limited to 64 characters: give composite indexes an explicit
  name when `table_col1_col2_..._index` would be longer.
- `SUM()` of an integer column comes back as a DECIMAL string: cast aggregates
  (`(int)`, `(float)`, `SaleService::paisa()`) before using or returning them.

The API dev server runs from the launch config `redpharma-api`
(`../../.php84/serve-api.cmd`, http://localhost:8000). Demo logins are listed in
`database/seeders/DemoSeeder.php`; `DemoSeeder` refuses to run in production.
`DatabaseSeeder` only creates reference data (roles, permissions, payment
methods, price lists, the walk-in customer).

In the POS `.env`, `NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases`
sends those domains to this API while the rest of the UI stays on mocks.

## Rules the code relies on

- Stock changes only through `App\Services\StockLedger` (append-only movements plus a locked balance per warehouse and batch).
- Money is computed in integer paisa and stored as DECIMAL.
- Documents are numbered per type, warehouse and year (`S-DHK1-2026-00001`) under a row lock.
- Every POS route checks a permission from `config/pos.php`; Admin passes all checks.
