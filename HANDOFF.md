# Red Pharma rebuild: handoff

Status as of **25 Nov 2026** (evening) on `main`, last feature: HR & payroll (see §10)
(everything pushed to `origin` = github.com/trtayebee089/pharmecy-pos).

Read this file first on a new device or in a new AI session. It carries the
decisions, rules and state that are not obvious from the code.

---

## 0. Continue here (after the office PC, 25 Nov 2026 evening)

The office PC built **Loyalty**, **Online orders & delivery** and **HR & payroll** on top of `6b4a7ca`. On any other machine:

```bash
git pull
cd api
php artisan migrate                        # 3 new additive migrations: 2026_11_25_101400 (loyalty), 101500 (online orders), 101600 (HR & payroll)
php artisan db:seed                        # DatabaseSeeder: adds the "points" payment method and the three
                                           # membership plans (Silver/Gold/Platinum) INACTIVE with 0 discount
php vendor/bin/pest                        # expect 165 passing (also on MySQL: -c phpunit.mysql.xml)
php artisan db:seed --class=DemoSeeder     # dev only: 5 Dhaka shipping zones, 2 riders, 2 departments, 3 employees
```

Then add `loyalty`, `orders` and `hr` to the live domains in **`pos/.env`** and restart `npm run dev`:

```
NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases,inventory,finance,reports,loyalty,orders,hr
```

Loyalty is **off** until an admin switches it on in Administration › Reward points (the API defaults are inactive,
membership discount mode `off`), and the three tiers do nothing until an admin activates and prices them in
Loyalty › Memberships.

**Office PC database (25 Nov):** MySQL Server 8.0.46 (already installed in `C:\Program Files\MySQL`) runs as a dev
server with a private data folder `D:\01 REDPHARMA\00 CODEBASE\.mysql80\` (outside the repo: `my.ini`, data, root
password in `root-password.txt`) on **port 3307**, because XAMPP's old MariaDB 10.4 on that PC may hold 3306. It is
started from `.claude/launch.json` (`redpharma-mysql`, before the API). `api/.env` there points at 127.0.0.1:3307,
databases `redpharma_pos` and `redpharma_pos_test`, user `redpharma`; the old SQLite settings are kept in the
ignored `api/.env.sqlite.bak`.

**Next task:** §5 item 1, **Admin** (users, roles & permissions screen, warehouses, POS settings, general settings).
Contracts: `useUserAdminApi`, `useWarehousesResource` (`pos/app/services/hr.ts`), `useAdminApi` (`operations.ts`), `useSettingsApi`
(`settings.ts`). Mind KI-010: only admins may change roles or other users; self-service only name, email, password.

---

## 1. Goal

Replace the legacy Red Pharma POS (Laravel 10 SalePro fork, Blade/jQuery,
MariaDB) with:

- a **new Laravel API** (`api/`) with a **new database schema**, and
- a **new Nuxt POS / admin panel** (`pos/`).

The legacy customer **website (Vue) and Android app stay unchanged for now** and
are rebuilt later. Until then a legacy bridge must keep them working (designed,
not built: see `docs/rebuild/rebuild-architecture.md`).

## 2. Rules from the owner (always apply)

- Audit first, rebuild second; preserve all legacy functionality.
- Commit every feature with a **short title** (e.g. `add sales api`). End commit messages with the
  `Co-Authored-By` line used in the history.
- Write tests for every rule that moves stock or money.
- **Never** expose secrets from `.env` files in docs, logs or commits; never commit `.env`, the SQLite DB,
  `vendor/`, `node_modules/` or build output.
- **Never** change DNS, production database records or live config without explicit approval.
  Do **not** point `pos.redpharmabd.com` at the new app until production readiness is verified.
- No Git history rewrite or force-push without approval. Never overwrite uncommitted user changes.
- No destructive database changes (migrations are additive).
- No fake data in production-facing modules (demo data only via `DemoSeeder`, which refuses production).
- Don't mark incomplete features as done. If behaviour is unclear: read code/docs first, then ask.
- Never copy customer PII from the legacy SQL dump.
- Never call legacy `GET /update-coupon` or `/migrate` over HTTP (they destroy data / run migrations).

## 3. Decisions already made (do not re-ask)

| Topic | Decision |
|---|---|
| Approach | Full rebuild with a new schema (not a patch of the legacy app) |
| Website & app | Rebuild later; keep legacy running meanwhile |
| Stock | Batch-level stock, **FEFO** allocation, **expired stock blocked** from sale |
| Units | Unit ladder **box / strip / piece**; stock is stored in base pieces |
| Extra modules kept | Online orders & delivery, Membership & rewards, Accounting & expenses, HRM & payroll |
| UI | Nuxt 4 SPA, Tailwind 4, GSAP animations; POS layout selectable in settings: **Grid** or **Classic** (legacy-style table) |
| Tables | Every table has an Actions column (View / Edit / Delete-or-Void / more) |
| Purchases menu | Legacy `product_order_quantity_alert` = purchase orders per supplier, plus **New purchase** |
| Reports added | Biller, Custom product sales, Customer report, Monthly summary |
| Admin added | POS settings (printers), Reward point settings |

Engineering decisions taken during the build (change only on purpose):

- Server prices every sale; client totals are ignored. Money is computed in integer paisa, stored as DECIMAL.
- Stock changes only through `App\Services\StockLedger` (append-only `stock_movements` + locked `stock_balances`).
- Documents are immutable: void or return with a reason, never edit or delete.
- Document numbers per type/warehouse/year under a row lock (`App\Services\DocumentNumbers`): sales `S-DHK1-2026-00001`,
  purchases `P-`, purchase orders `PO-`, sale returns `R-`, payments `PAY-`, purchase returns `PR-`, adjustments `ADJ-`,
  stock counts `SC-`, transfers `TR-`, held sales `H-`, purchase order drafts `POD-`, money transfers `MT-`,
  expenses `EXP-`, income `INC-`.
- Document-creating POSTs require an `Idempotency-Key` header (retry-safe).
- POS auth is Sanctum **SPA cookie** (no tokens in the browser); username login, 5 tries/min lockout.
- Permissions live in `api/config/pos.php` and match the Nuxt menu; Admin passes every check.
- Discount limits per role: Cashier 5 %, Manager 15 % (`role_limits`).
- Customer group discount (owner decision, 25 Nov 2026): every catalogue line gets the group %; a manual line discount
  replaces it only when larger, and only that larger manual part is checked against the role limit. The group % itself
  applies in full (a manager set it). Not applied to custom items or walk-ins; stored on `sales.group_discount_pct`.
  (Legacy treated the group % as a client-side price markup that compounded, KI-081.)
- Walk-in customers cannot buy on credit; customer credit limits are enforced.
- Voids: only while the sale's register session is open (or same day without a session) and before any return.
- Returns: per sold batch, never more than sold − returned; refund includes the order-discount share; restock or write off.
- A sale attaches to the cashier's open register if there is one. **Opening a register is not yet required to sell.**
- Cash refunds are not netted against a customer's due (ledger stays correct; prefer "customer credit" for credit customers).
- Purchase orders (`ordered`, shown as "draft") carry no stock or money; receiving with `orderId` sets the order to
  `fulfilled` (shown "Closed"). A received purchase can be voided only while its stock is unsold and unpaid.
- Supplier account payments are allocated to the oldest open invoices; suppliers with purchases are deactivated, not deleted.
- Purchase returns are per batch (expired allowed), priced at the batch cost, and refused for batches bought only from
  other suppliers. `reduce_due` lowers the source invoice then the oldest (`due = total − paid − returned`), the rest stays
  as supplier credit; `refund` records the supplier's cash. A purchase with returns cannot be voided.
- Inventory documents: adjustments need a reason and note (expiry write-offs only from expired batches); transfers move
  sellable batches only and are in transit until the destination receives them; stock counts post the difference
  between the counted and the system quantity at posting time, uncounted batches stay as they are. Stock value on the
  overview is sellable stock at batch cost. There is no warehouse list endpoint yet, so transfers go only to
  warehouses the user is assigned to (the API accepts any active one).
- Catalog: products are never deleted (deactivate); a unit used on a sale or purchase keeps its factor and cannot be
  removed; prices are versioned per price list (retail, wholesale) and changing one needs `products.price`; catalog
  masters used by products are deactivated, not deleted. Duplicated products start inactive.
- Customers: due collections are allocated to the customer's open invoices (each at most its due); unallocated money
  only covers debt that is not on an invoice. A sale with a later due collection cannot be voided (use a return).
  Customer groups carry discounts, so they need the new `customer_groups.manage` permission (Manager and Admin), not
  the cashier's `customers.manage`. Customers who owe money cannot be deactivated.
- Held sales live on the server (with the sales domain live) and store lines, not prices: resuming re-prices from the
  current price list and live batches. Any counter of the warehouse may resume one; the first resume wins.
- Purchase order "AI" forecast is a transparent statistical model on the server (28-day rate, 7-day trend, 21 days of
  cover); no data leaves the system. A real model can replace `PurchaseOrderPlanner::forecast` behind the same endpoint.
- Finance: account balances are always derived from `account_transactions`; finance documents are voided with
  reversing transactions, never edited. Expenses and incomes share `finance_entries` / `finance_categories` (column
  `kind`) instead of the two table pairs in the design doc. Attachments live on the private `local` disk.
- Date columns compared as dates (`txn_date`, `entry_date` on finance entries and party ledger, `transfer_date`,
  `order_date`) must not get an Eloquent `date` cast without `whereDate`: the cast stores a time and string comparisons
  then fail on SQLite.
- Reports run on the server over the selected warehouse (or all the user's). A "biller" is the branch (warehouse) an
  invoice is issued from.
- Loyalty (owner decisions, 25 Nov 2026), rules in `App\Services\LoyaltySettings` (settings key `reward_points`, edited in
  Administration › Reward points):
  - **Earn** on the bill after all discounts, minus the part paid with points, custom items and (optional) discounted
    lines: `floor(base ÷ soldAmountPerPoint × membership multiplier × best active campaign multiplier)`; bills under
    `minimumSoldAmount` and walk-ins earn nothing.
  - **Redeem** = payment method `points` (no money account): the customer must hold at least `minRedeemPoints`, the bill
    must be at least `minRedeemSaleAmount` (new setting), points pay at most `maxRedeemPercent` of the bill, in whole
    points (1 point = `pointValue` ৳), checked against the locked wallet.
  - **Membership discount at the POS** is the admin's choice (`membershipDiscountMode`): `off` (group % only, as
    legacy), `larger` (larger of group and membership %), `stack` (both, compounded). The manual line discount rule is
    unchanged (replaces the automatic % only when larger; only that larger part counts against the role limit).
  - **Tiers**: automatic upgrade when the last-12-month spend (completed sales − returns) reaches an active plan's
    minimum; never auto-downgraded (a tier runs to its end date); staff can assign, renew (plan fee collected in cash),
    change plan or cancel, always with a reason.
  - **Reversals**: a void gives spent points back and takes earned points away (the wallet may go negative if they were
    already spent; a negative wallet cannot redeem). A return does the same in proportion to the value returned, and the
    share that was paid with points goes back as points, not cash.
- Online orders (owner decisions, 25 Nov 2026), rules in `App\Services\OnlineOrders`:
  - **Source**: staff place phone/WhatsApp orders (Online orders › New phone order); the website and app will call the
    same `place()` through `/api/v1` later. Prices are fixed at placement from the retail list; shipping from the active
    zone (free at or above its minimum). Customer matched by normalised phone, created if new. Sellable stock is checked
    at placement but only taken at confirm. No group/membership discount or points on online orders yet.
  - **Stock leaves once, at confirm**: a sale (channel = order channel, `OO-` order number kept on the order) is posted
    with FEFO batches from the **online branch** (setting `online_orders.warehouseId`, chosen on Delivery › Shipping
    zones). Cancelling before delivery voids that sale (the counter's time window does not apply) and puts the stock
    back into the same batches. A delivered order is closed; later returns go through the normal sale return.
  - Status flow pending → confirmed → packed → in_transit → delivered, cancel from any open status with a reason;
    in_transit and delivered need an active rider.
  - **COD**: delivered = a cash payment on the sale with no account, held by the rider (`rider_cash_entries` collect).
    Delivery › Riders › Receive COD cash records the handover (never more than held) into Cash in hand. A rider who
    holds cash or has open orders cannot be removed; riders/zones with history are deactivated, not deleted.
- HR & payroll (owner decisions, 25 Nov 2026), rules in `App\Services\Payroll`:
  - **Absence deduction = basic ÷ 30 × absent days** (capped at the basic). Leave is paid.
  - **Admin settings** (Payroll › Settings, settings key `hr_payroll`, needs `hr.payroll`): weekly off days (working days on
    payslips = days − off days − holidays of all branches or the employee's branch), the late rule (every N late days
    count as one absent day; 0 = lates are paid) and how advances are recovered (`installment` = the monthly amount set
    on each advance, `full` = the whole open balance in the next run). Recovery never exceeds the net pay.
  - Runs go draft → approved → paid; drafts take manual additions (bonus/overtime with a note) and can be deleted;
    approving recomputes from attendance and locks that month's attendance; paid runs are final.
  - **Paying** posts one cash-out per payslip (net) from the chosen account, one "Salaries & wages" expense for what was
    earned (net + advance recovered; linked to the run via `finance_entries.source_type = payroll_run`, so Finance
    refuses to void it alone) and the advance recoveries. Advances are paid out from an account (an asset "Staff salary
    advances" on the balance sheet, not an expense) and recovered by payroll or in cash into Cash in hand; an advance can
    be voided only before any recovery.
  - One append-only `reward_ledger` + `reward_wallets` projection under lock (`App\Services\RewardLedger`); spending and
    expiry consume the oldest-expiring points first; `php artisan loyalty:expire` (scheduled daily 00:15) expires points
    and memberships. Production needs the Laravel scheduler (`schedule:run` every minute).

## 4. What is done

### Audit and design (docs)
- `docs/` – legacy audit, navigation inventory, 450-row feature parity matrix, workflows, API, DB, 120-issue register.
- `docs/rebuild/` – rebuild architecture, **new database design**, data migration plan (legacy → new ETL).
- `phase0-hotfix/` – containment patch for the legacy production server (see §7).

### Nuxt POS (`pos/`) – UI complete
About 65 screens covering every module in the sidebar (sales, POS grid + classic, products, catalog, inventory,
purchases + purchase orders, customers, suppliers, loyalty, delivery, finance, reports, HR, admin).
Every screen works on a **development mock layer** (`pos/app/mocks`), and finished domains switch to the real API.

### Laravel API (`api/`) – live slices (165 Pest tests passing)

| Slice | Status |
|---|---|
| Auth: login/logout, `/me`, change password | live |
| Catalog: product search (FEFO batches, sellable stock), list, show | live |
| Customers: list, lookup, create | live |
| Sales: complete sale, list, detail | live |
| Voids, sale returns, returns list | live |
| Cash register: sessions, open, cash in/out, close & count | live |
| Purchases: receive, order, list, detail, pay invoice, void, duplicate, receive an order | live |
| Suppliers: CRUD, lookup, ledger, account payment | live |
| Purchase returns: post (reduce due or refund), list, void | live |
| Inventory: stock overview, batches, adjustments, stock counts, transfers | live |
| Catalog writes: products (units, prices, barcodes), deactivate, duplicate, CSV import; generics, manufacturers, dosage forms, categories | live |
| Customers: profile, edit, deactivate, groups, due list, open invoices, due collection | live |
| Held sales: hold (F8), list, resume on any counter of the warehouse, discard | live |
| Purchase orders: company report, forecast, drafts, convert to ordered purchase, drafts for all companies | live |
| Finance: accounts, statements, money transfers, expenses and income (with attachments), categories, balance sheet + P&L | live |
| Reports: sales, profit, billers, custom items, stock, expiry, purchases, customers, customer statement, suppliers, payments, cashiers, monthly summary | live |
| Loyalty: reward wallets + ledger, adjustments, campaigns, membership plans, members (auto tier, assign, renew, change, cancel), reward point settings, points as a POS payment | live |
| Online orders & delivery: phone orders, status flow (stock at confirm, COD held by riders), rider assignment, riders with cash handover, shipping zones, online branch setting | live |
| HR & payroll: employees, departments, designations, holidays, attendance (day + month), payroll runs (draft → approved → paid, manual additions), salary advances with installment/full recovery, payroll settings | live |
| Dashboard summary | live |

Endpoint details: `api/README.md`. Schema: `api/database/migrations/` (16 migrations). The database is **MySQL/MariaDB**
(`redpharma_pos`, tests also run on `redpharma_pos_test`); all tests pass on MariaDB 10.11, MySQL 8.0 and SQLite.

In `pos/.env` the live domains are:
`NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases,inventory,finance,reports,loyalty,orders,hr`
(everything else still runs on mocks while `NUXT_PUBLIC_USE_MOCKS=true`). A new domain needs three things: its name in
`ApiDomain` (`pos/app/composables/useApi.ts`), `useApi('<domain>')` in the service, and the name in `.env`.

Still on mocks: admin (users, roles, warehouses,
settings), the inventory reorder suggestions screen, and voiding a sale return.

## 5. What needs to be built (suggested order)

1. **Admin**: users, roles & permissions UI, warehouses, POS settings, general settings (server-side).
2. **Customer channel `/api/v1`** (website/app checkout calls `OnlineOrders::place`, order tracking by code) for website/app + **legacy bridge**; then **data migration** (ETL per `docs/rebuild/data-migration-plan.md`).
3. **Production readiness**: deployment, backups, monitoring, then cutover (needs owner approval).

Small open items:
- Overdraw checks use the account's current balance, so an expense or transfer backdated to before the money arrived
  can show a negative balance on historic statements and balance sheets.
- Cash expenses are not tied to a cash register session (use the register's cash out for drawer money).
- Screens build date ranges from the browser clock; counter PCs must use Bangladesh time (Asia/Dhaka) or "today"
  can differ from the API's day around midnight.
- The inventory reorder suggestions screen (`useReorderApi`, `/reorder/*`) still runs on mocks; it overlaps with the
  purchase orders forecast and could reuse `PurchaseOrderPlanner`.
- Due reminder SMS: the endpoint answers 503 until an SMS gateway is chosen and configured.
- Transfers can only target warehouses the user is assigned to until a warehouse list endpoint exists (Admin module).
- Voiding a sale return (`/sales/returns` row action) still runs on mocks; the API has no sale-return void yet.
- Register page always opens register id 1 (`pos/app/pages/finance/registers.vue`); the API has `GET /cash-registers` for a picker.
- Decide whether selling requires an open register.
- HR: payroll is paid from one account per run; salary expense is booked to the first active branch (no per-branch
  split yet); attendance has no check-in device integration (entered on the Attendance page).
- Online orders: no discounts or reward points on online orders yet; delivery partners (Pathao, Steadfast, RedX) are
  only a label on the zone (no courier integration); the fulfilment board has no "New phone order" button (it is on the
  Online orders page).
- Loyalty: the POS has no screen yet to give a customer a membership by hand (`POST /loyalty/customers/{id}/membership`
  exists; tiers otherwise come from spend). Membership fees are collected in cash only and are not tied to the register
  session. Points paid at the counter show as "Reward points" under non-cash on the register.

## 6. Set up on another device

Requirements: Git, **PHP 8.3+** (8.4 and 8.5 used) with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `bcmath`,
`fileinfo`, `zip`; Composer 2; Node 20+ with npm; **MySQL 8 or MariaDB 10.11+** (XAMPP's MariaDB works).

```bash
git clone https://github.com/trtayebee089/pharmecy-pos.git
cd pharmecy-pos

# API
cd api
composer install
# create databases redpharma_pos + redpharma_pos_test and user redpharma (SQL in api/README.md)
cp .env.example .env           # MySQL by default; fill DB_PASSWORD. APP_TIMEZONE=Asia/Dhaka is already set
php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder   # demo catalogue, stock, users, registers
php vendor/bin/pest                              # expect 131 passing (in-memory SQLite)
php vendor/bin/pest -c phpunit.mysql.xml         # expect 131 passing on MySQL (uses redpharma_pos_test)
php artisan serve --port=8000                    # http://localhost:8000

# POS (second terminal)
cd ../pos
npm install
cp .env.example .env
#   NUXT_PUBLIC_API_BASE=http://localhost:8000/api/pos/v1
#   NUXT_PUBLIC_USE_MOCKS=true
#   NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases,inventory,finance,reports
npm run dev                                      # http://localhost:3100
```

**Windows with XAMPP (home PC, 25 Nov):** XAMPP's MariaDB 10.11 holds `redpharma_pos` and `redpharma_pos_test`
(user `redpharma`, password only in `api/.env`). PHP 8.5 is `C:\xampp\php\php.exe`. The Claude Code desktop app starts
both dev servers from `.claude/launch.json` (not committed: it holds that machine's PHP path), roughly:

```json
{ "version": "0.0.1", "configurations": [
  { "name": "redpharma-api", "runtimeExecutable": "C:/xampp/php/php.exe", "runtimeArgs": ["api/artisan", "serve", "--host=localhost", "--port=8000"], "port": 8000 },
  { "name": "redpharma-pos", "runtimeExecutable": "npm", "runtimeArgs": ["--prefix", "pos", "run", "dev"], "port": 3100 } ] }
```

Use the same file on another PC with that PC's PHP path. After changing `pos/.env`, restart the POS dev server.

- Demo logins are listed in `api/database/seeders/DemoSeeder.php` (admin / cashier / store keeper).
- Keep the POS on `localhost:3100` and the API on `localhost:8000`: Sanctum (`SANCTUM_STATEFUL_DOMAINS`)
  and CORS (`CORS_ALLOWED_ORIGINS`) in `api/.env` must list the POS origin.
- Checks before committing: `php vendor/bin/pest`, `php vendor/bin/pest -c phpunit.mysql.xml`, `php vendor/bin/pint` (in `api/`), `npx nuxi typecheck` (in `pos/`).

**Windows with Laravel Herd (original machine):** Herd's php.ini auto-prepends a missing `dump-loader.php`,
which silently breaks Composer and artisan. The original machine uses a cleaned copy plus `php`/`composer`
wrappers in `D:\01 REDPHARMA\00 CODEBASE\.php84\` (outside the repo) and serves the API with
`.php84\serve-api.cmd` (PHP built-in server from `api/public`). On a new Windows machine either copy Herd's
php.ini without the `auto_prepend_file` line and point `PHPRC` at it, or use a non-Herd PHP.

The legacy source folders used for the audit (`backend - ocr pos`, `backend`, `web`, `web-v2`, `app`, SQL dump)
live **next to** this repo on the original machine and are **not** in Git. Copy them separately if needed for
parity checks; never commit them.

## 7. Legacy production (Phase 0)

`phase0-hotfix/` holds a containment patch for the live legacy server (remove the unauthenticated
`/update-coupon` DB wipe, stop the every-minute `reset:db` schedule, protect public SQL backups, etc.).
The owner was walked through it step by step and **paused after step 3**. Applying it to production is the
owner's decision and requires explicit approval for each production action.

## 8. Repository map

```
HANDOFF.md            this file
README.md             index of audit and design documents
docs/                 audit + docs/rebuild (architecture, database design, migration plan)
phase0-hotfix/        legacy containment patch and verification script
api/                  Laravel 13 API (README.md lists endpoints and rules)
  app/Services/       one class per set of rules:
                        stock     StockLedger (only writer of stock), StockAdjustmentService, StockCountService, StockTransferService
                        sales     SaleService, SaleReversalService (voids, returns), HeldSales, Pricing, DiscountLimits
                        buying    PurchaseService, PurchaseReturnService, PurchaseOrderPlanner, SupplierPayments
                        money     CustomerPayments (due collection), Finance (accounts, transfers, expenses, income,
                                  balance sheet), RegisterService
                        catalog   ProductService
                        shared    DocumentNumbers
  app/Reports/        report queries: SalesReports, StockReports, PartyReports (+ ReportScope helpers)
  app/Support/        Present*.php: JSON shapes matching pos/app/types
  app/Http/Controllers/Pos/   one controller per area
  tests/Feature/      Pest tests, one file per area (shared helpers in tests/Pest.php)
  phpunit.mysql.xml   runs the same suite on MySQL/MariaDB (database redpharma_pos_test)
pos/                  Nuxt 4 POS
  app/composables/useApi.ts   API client + per-domain live/mock switch
  app/services/       one module per domain (mock branch + API branch)
  app/mocks/          development-only mock data
  app/config/navigation.ts    sidebar and permission keys
```

## 9. How to continue (a prompt for a new AI session)

> Read `HANDOFF.md` (start with §0) and `api/README.md`. Continue the Red Pharma rebuild with the next item in §5.
> For each slice: read the Nuxt service and page contracts first, add additive migrations, services that keep
> stock/money rules in one place, controllers with permission checks, Pest tests for every rule (run them on SQLite
> and on MySQL with `-c phpunit.mysql.xml`, plus Pint), switch the domain live (`ApiDomain`, the service's
> `useApi('<domain>')`, `pos/.env`), typecheck and verify in the browser, then commit API and POS separately with
> short titles and update this file. Ask the owner before deciding business rules that change money (as with the
> group discount). Follow the rules in §2.

## 10. Commit history

```
cf27187 connect hr and payroll in pos
f0f8943 add hr and payroll api
4f4107e update handoff notes
52808b3 connect online orders in pos
a91ae5f add online orders and delivery api
996a115 update handoff notes
302abd9 connect loyalty in pos
2b509f8 add loyalty api
6b4a7ca update handoff notes
9b1de8d connect reports in pos
61072c5 add reports api
64c4b31 connect finance in pos
d2eb461 add finance api
e898967 show group discount in pos cart
98e6105 apply customer group discount in sales api
a4725ac connect purchase orders in pos
b930598 add purchase order planning api
9b9d0cc connect held sales in pos
102ef19 add held sales api
51038b5 connect customers in pos
a5a9876 add customer accounts api
8bef3f4 connect catalog writes in pos
3756243 add catalog writes api
6531187 connect inventory in pos
cd51d38 add inventory api
d00254e connect purchase returns in pos
7b4371c add purchase returns api
2924b79 configure mysql database
62dd94b add handoff notes
688cef7 connect suppliers and purchases in pos
15b97ba add suppliers and purchases api
5378877 connect returns and register in pos
cfebb42 add returns, voids and register api
66b601d connect pos to live api domains
0bfb0a8 add catalog, sales and purchase api
a720e1a add pos auth api
6e8cdc4 add core database schema
1884f70 setup laravel api
9e8808b add table actions and classic pos layout
850adad add purchase orders, reports and pos settings
8c68757 complete pos ui for all modules
8cbaae0 add pos operations and admin screens
a6f7078 setup nuxt pos admin ui
6e8802e design full rebuild
9829d13 prepare phase 0 hotfix
677f1b7 audit existing pos
```
