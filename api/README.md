# Red Pharma API

Centralized Laravel 13 API for the rebuilt Red Pharma system. The Nuxt POS (`../pos`)
talks to `/api/pos/v1` with Sanctum SPA cookie auth. The design is in
`../docs/rebuild/` (architecture, database design, data migration plan).

## What is implemented

| Area | Endpoints | Notes |
|---|---|---|
| Auth | `POST auth/login`, `POST auth/logout`, `GET me`, `PUT me/password` | Username login, 5 attempts/min lockout, audit log, session cookie only (no tokens in the browser) |
| Catalog | `GET catalog/products/search`, `GET catalog/products`, `GET catalog/products/{id}` | Units with current retail price, sellable batches in FEFO order, sellable stock; `includeExpired=1` on search lists every batch on hand (expired too) for users who can post purchase returns |
| Customers | `GET customers`, `GET customers/lookup`, `POST customers` | Unique normalised BD mobile number, due balance from the ledger |
| Sales | `POST sales` (Idempotency-Key), `GET sales`, `GET sales/{id}` | Server pricing, role discount limits, FEFO allocation under lock, expired stock never sold, payments, account and customer ledgers |
| Voids | `POST sales/{id}/void` | Whole sale, reason required, only while its register session is open (or same day without a session) and before any return; stock, payments and ledger reversed, invoice kept as void |
| Returns | `POST sales/{id}/returns` (Idempotency-Key), `GET sales/returns` | Per sold batch, never more than sold minus returned; refund includes the order-discount share; restock to the same batch or write off; refund as cash, bKash or customer credit |
| Cash register | `GET cash-registers`, `GET/POST cash-registers/sessions`, `POST sessions/{id}/movements` (Idempotency-Key), `POST sessions/{id}/close` | One open session per register and per cashier; sales, payments and refunds attach to the cashier's open session; expected cash = float + cash sales − cash refunds + cash in − cash out |
| Purchases | `GET purchases`, `GET purchases/{id}`, `POST purchases`, `POST purchases/{id}/payments`, `/void`, `/duplicate` (POSTs take an Idempotency-Key) | `received`: batches + stock movements at landed cost, supplier ledger, optional payment and retail price update; `ordered`: purchase order (shown as draft), no stock or money; receiving with `orderId` closes the order; void only while the received stock is unsold, unpaid and not returned (otherwise a purchase return); duplicate copies as a new order; `due = total − paid − returned` |
| Purchase returns | `GET purchase-returns`, `POST purchase-returns`, `POST purchase-returns/{id}/void` (POSTs take an Idempotency-Key) | Per batch, expired stock included, never more than the batch holds in the warehouse; priced at the batch cost (client cost ignored); a batch bought from other suppliers only is refused (opening stock may go to any supplier); supplier ledger debited; settlement `reduce_due` lowers the source invoice, then the oldest open invoices, the rest stays as supplier credit; `refund` records an incoming payment so the balance is unchanged; void restocks the batches and reverses the ledger, invoice reductions or refund. Number `PR-DHK1-2026-00001`; permission `purchase_returns.create` |
| Suppliers | `GET/POST suppliers`, `GET/PUT/DELETE suppliers/{id}`, `GET suppliers/lookup`, `GET suppliers/{id}/ledger`, `POST suppliers/{id}/payments` (Idempotency-Key) | Balance from the supplier ledger; account payments are allocated to the oldest open invoices; suppliers with purchases are deactivated instead of deleted |
| Dashboard | `GET dashboard/summary` | Live figures from sales, ledgers and stock |

Not yet built (the POS still uses its mock layer for these):
adjustments/transfers/counts, the reorder/AI purchase-order drafts, the other admin
modules, reports, online orders, loyalty, accounting and HR.

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
