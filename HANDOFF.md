# Red Pharma rebuild: handoff

Status as of **25 Nov 2026** on `main`, last feature commit `9b1de8d connect reports in pos`
(everything pushed to `origin` = github.com/trtayebee089/pharmecy-pos).

Read this file first on a new device or in a new AI session. It carries the
decisions, rules and state that are not obvious from the code.

---

## 0. Continue here (office PC, 26 Nov 2026)

Work on 25 Nov happened on the home PC (XAMPP). The office PC last saw commit `62dd94b`. To catch up:

```bash
git pull                                   # 20 new commits: 2924b79 … 9b1de8d + this handoff
cd api
composer install                           # no new packages, but harmless
php artisan migrate                        # 8 new additive migrations (2026_11_25_100600 … 101300)
php artisan db:seed                        # DatabaseSeeder only: syncs the NEW permissions
                                           # purchase_returns.create and customer_groups.manage
php vendor/bin/pest                        # expect 131 passing
cd ../pos
npm install
```

Then in **`pos/.env`** (not in Git) set the live domains to the full list and restart `npm run dev`:

```
NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases,inventory,finance,reports
```

**Database choice on the office PC.** Since `2924b79` the API defaults to **MySQL/MariaDB** (`api/.env.example`).
If the office PC still runs on SQLite, either keep SQLite (`DB_CONNECTION=sqlite` in `api/.env` still works) or set up
MySQL as in `api/README.md` → "Database (MySQL / MariaDB)". MySQL is recommended: it caught two bugs SQLite hid
(index names over 64 characters, date comparisons). Run `php vendor/bin/pest -c phpunit.mysql.xml` when MySQL is set up.

**Not in Git (stays on the home PC):** the home PC's demo database contents (test sales for "Farhana Hossain",
held sale H-DHK1-2026-00001, PO-DHK1-2026-00001, product VERIF250, group "Senior citizen", expense EXP-…-00001),
`api/.env`, `pos/.env`, and `.claude/launch.json` (dev-server launch config with the home PC's PHP path). None of it is
needed: `php artisan migrate:fresh --seed --seeder=DemoSeeder` gives a clean demo database anywhere.

**Next task:** §5 item 1, **Loyalty** (reward wallets and ledger, membership plans, reward point settings). Contracts:
`pos/app/services/people.ts` (`useLoyaltyApi`, `/loyalty/*`) and `pos/app/types/people.ts`. When it is live, fill the
placeholders that read 0 / `none` today: `points` and `membership` in `Present::customers`, the customer profile
(`CustomerAccountController::show`), and the Customer due / Customer report (`App\Reports\PartyReports`).

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
  invoice is issued from; memberships/points read `none`/0 until the loyalty module exists.

## 4. What is done

### Audit and design (docs)
- `docs/` – legacy audit, navigation inventory, 450-row feature parity matrix, workflows, API, DB, 120-issue register.
- `docs/rebuild/` – rebuild architecture, **new database design**, data migration plan (legacy → new ETL).
- `phase0-hotfix/` – containment patch for the legacy production server (see §7).

### Nuxt POS (`pos/`) – UI complete
About 65 screens covering every module in the sidebar (sales, POS grid + classic, products, catalog, inventory,
purchases + purchase orders, customers, suppliers, loyalty, delivery, finance, reports, HR, admin).
Every screen works on a **development mock layer** (`pos/app/mocks`), and finished domains switch to the real API.

### Laravel API (`api/`) – live slices (131 Pest tests passing)

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
| Dashboard summary | live |

Endpoint details: `api/README.md`. Schema: `api/database/migrations/` (13 migrations). The database is **MySQL/MariaDB**
(`redpharma_pos`, tests also run on `redpharma_pos_test`); all 131 tests pass on MariaDB 10.11 and SQLite.

In `pos/.env` the live domains are:
`NUXT_PUBLIC_LIVE_DOMAINS=auth,catalog,customers,sales,dashboard,registers,purchases,inventory,finance,reports`
(everything else still runs on mocks while `NUXT_PUBLIC_USE_MOCKS=true`). A new domain needs three things: its name in
`ApiDomain` (`pos/app/composables/useApi.ts`), `useApi('<domain>')` in the service, and the name in `.env`.

Still on mocks: loyalty, online orders & delivery, riders/zones, HR & payroll, admin (users, roles, warehouses,
settings), the inventory reorder suggestions screen, and voiding a sale return.

## 5. What needs to be built (suggested order)

1. **Loyalty**: reward wallets/ledger, membership plans, reward point settings.
2. **Online orders & delivery**: orders, fulfilment board, riders, zones.
3. **HR & payroll**: employees, attendance, holidays, payroll.
4. **Admin**: users, roles & permissions UI, warehouses, POS settings, general settings (server-side).
5. **Customer channel `/api/v1`** for website/app + **legacy bridge**; then **data migration** (ETL per `docs/rebuild/data-migration-plan.md`).
6. **Production readiness**: deployment, backups, monitoring, then cutover (needs owner approval).

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
- Loyalty points in customer responses are `0` until the loyalty module exists.

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
