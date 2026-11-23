# Red Pharma: Migration Plan (Blade POS → Nuxt POS on a centralized Laravel API)

Inputs: `legacy-system-audit.md`, `api-architecture.md`, `database-dependencies.md`, `known-issues.md`, `pos-feature-parity-matrix.md`. This plan builds on the earlier `UNIFIED_SYSTEM_MIGRATION_PLAN.md` and agrees with its direction. It differs where the audit found new facts: the code is a single codebase, the API is not live on the POS host, critical backdoors exist, and the production source is missing from the workspace.

**Non-negotiables:**
- The website and app keep working.
- One database.
- No destructive schema changes.
- No production, DNS or live-config change without your explicit approval.
- The Blade POS stays in service until the Nuxt POS passes UAT.

---

## Phase 0: Production containment (legacy, before anything else). Needs your approval

These fixes are small, reversible, and independent of the rebuild. Each is a change to production, so each needs your go-ahead.

| # | Action | Why |
|---|---|---|
| 0.1 | Remove or block `GET /update-coupon`, `/migrate`, `/cc`, `/auto-update-dashboard`, `developer-section/*`, `/phpfileinfo`, the SaaS/addon installers and the public `boot-slug` routes (route removal or a web-server deny rule) | Unauthenticated DB wipe, code overwrite, and info leaks |
| 0.2 | Delete `public/salepro_backup_*.sql`, `public/delete.php` and `public/debug_payroll_params.txt` from the live POS, and move backup output outside `public/` | DB backups are downloadable today (HTTP 200) |
| 0.3 | Remove `reset:db` and `quote:daily` from `app/Console/Kernel.php` **before** anyone enables cron | One cron run would truncate every table |
| 0.4 | Add server-side permission checks to the role, permission and user update endpoints | Any cashier can make themselves admin today |
| 0.5 | Rotate any credential that may have been exposed: SMS key hard-coded in `app/SMSProviders/*`, and anything inside the public SQL backups (user password hashes, settings) | Treat the backups as leaked |
| 0.6 | Take a fresh full backup (stored off the web root) and a copy of the deployed source | Rollback point and baseline |

## Phase 1: Baseline and source of truth (no production impact)

1. Obtain the **actual deployed source** of `pos.redpharmabd.com` and of `redpharma-api.techrajshahi.com`. Diff them against `backend - ocr pos/`.
2. Create the **single API repository** (fresh git repo), seeded from the reconciled code: production code plus the OCR/AI features plus the 2026-11-20 release.
3. Determine whether the techrajshahi deployment uses its own database. If it does, plan a customer/order/token reconciliation before host consolidation.
4. Stand up **staging**: a clone of the prod DB (restricted access, PII-handled), the API at `api-staging.…`, and the Nuxt POS at `pos-staging.…`. Generate a schema-dump baseline so migrations match prod.
5. Apply the 2026-11-20 release on staging first. That release restores the website's API, so verify the website and app against staging.
6. Agree on the open business decisions (§ Decisions).

**Exit:** reproducible staging, all code in git, website and app verified against staging.

## Phase 2: Characterize, then centralize the business core (API, no UI change)

1. **Characterization tests** that record what the legacy code does today, run against a staging DB snapshot, for: POS sale, form sale, draft, payment add/edit/delete, sale return, purchase create/edit/return, adjustment, transfer, customer due/deposit, cash register, and the key reports. These become the parity oracle.
2. Extract services from the fat controllers: `PricingService`, `StockService` (transaction, `lockForUpdate`, batch/expiry checks, `stock_movements` ledger), `SaleService`, `PaymentService`, `ReturnService`, `PurchaseService`, `CashRegisterService`, `CustomerLedger`, `SupplierLedger`.
   - The **legacy Blade controllers are re-pointed to the same services**, so both UIs share one rule set during the parallel run.
   - Behaviour fixes that change outcomes go behind feature flags and need your sign-off: server-side totals, a negative-stock block, an expired-batch block, and the batch-restore fix on delete.
3. Add-only DB work on staging, then prod (with approval):
   - indexes;
   - `idempotency_keys`, `stock_movements`, `audit_logs`;
   - `product_warehouse` dedupe and `products.qty` reconciliation (logged, reviewed);
   - collision-free reference number generator.
4. Split the middleware groups and publish the Sanctum config. Add `/api/pos/v1/auth/*` and `/me`. Fix the legacy API security bugs in place (IDORs, reward theft, OTP counter), keeping response shapes identical.

**Exit:** the service layer has tests; characterization tests pass with flags off; the website and app regression suite passes.

## Phase 3: Canonical API host (needs your approval for DNS)

1. Create `api.redpharmabd.com` → the same app and DB. Keep `pos.redpharmabd.com/api/*` and `redpharma-api.techrajshahi.com/api/*` alive through a reverse proxy or vhost alias, not redirects, because Flutter does not resend POST bodies on a redirect.
2. Ship a website config change, then an app update, pointing at the canonical host. Monitor legacy-host traffic. Retire aliases only when traffic drops to zero.

## Phase 4: Nuxt POS foundation

- Nuxt 3 (SPA mode), Vue 3, TypeScript, Tailwind, Pinia, VueUse, Zod.
- A central `$api` client: credentials, CSRF, 401/419 handling, validation errors, cancellation, pagination.
- Domain API modules mirroring `/api/pos/v1`.
- App shell: collapsible sidebar **generated from the verified navigation inventory and filtered by the user's Spatie permissions**, top bar, breadcrumbs, warehouse/biller selector, notifications.
- Shared components: data table (server pagination, filters, export), searchable select, product/batch picker, date range, form fields, modal, confirm, toast, skeletons, empty and error states.
- Auth pages: login, logout, profile, change password. Route middleware enforces permissions (UI only; the backend enforces too).

## Phase 5: Vertical slices (each = API endpoints + services + Nuxt screens + tests + parity sign-off)

Order chosen by business criticality and dependency:

1. **Catalog read + POS sales screen.**
   - Product search by code, name and generic; barcode scanner input; keyboard flow; batch/expiry visibility.
   - Customer select; discounts within permissions; coupon, gift card and points (if kept).
   - Multi-payment, due, change; hold/resume draft; cash register dependency.
   - Invoice and receipt print; camera vision search.
2. Sales list, sale detail/invoice reprint, payments, sale edit/void, **sale returns**.
3. Cash register open/close/report, customer due collection and deposits.
4. Purchases: create, edit, payments, returns, CSV import, qty-alert/AI draft orders.
5. Product management: CRUD, import/export, categories, brands, units, barcode labels; stock adjustments, stock counts, transfers.
6. Customers, suppliers, billers, customer groups; online orders, delivery and riders; membership and rewards admin.
7. Accounting: accounts, money transfer, balance sheet, account statement, expenses, income.
8. Dashboard and **all reports** (server-side queries, exports).
9. Administration: users, roles and permissions, warehouses, settings (general, POS, invoice, tax, SMS, mail, reward), notifications.
10. Confirmed-in-scope extras only: HRM/payroll, manufacturing, quotations (see Decisions).

A slice is **done** only when its parity-matrix rows are *Implemented* and *Verified*: functionality, validation, permissions, data writes compared against the legacy oracle, and workflow walkthrough. A page that only renders does not count.

## Phase 6: Parallel run and UAT

- The Nuxt POS runs at `pos-new.redpharmabd.com` (or staging) against the **same production API and DB**, in parallel with the Blade POS (shared services means consistent data).
- Pilot with one counter or user group. Daily reconciliation: sales totals, payments, stock movements, and cash register totals compared between the old and new paths.
- UAT checklist built from `pos-business-workflows.md`, with sign-off per workflow by the business.

## Phase 7: Cutover (needs your approval)

1. Pre-cutover: full backup, frozen release tag, rollback rehearsal on staging.
2. Switch `pos.redpharmabd.com` to serve the Nuxt build (static hosting or Node). Move the Blade POS to `pos-legacy.redpharmabd.com` (staff-only, IP-restricted) for the rollback window (proposed: 30 days).
3. **Rollback:** point `pos.` back at the Blade vhost. No data migration is needed, because both UIs use the same API services and DB.
4. After the window, disable the Blade UI routes. The API stays.

## Phase 8: Cleanup

Retire unused SalePro modules confirmed by the business. Remove the legacy `/api/v1/admin/*` aliases and the deprecated hosts once traffic is zero. Plan the Laravel 11/12 upgrade.

---

## Decisions needed from you (block Phase 2 behaviour changes and Phase 5 scope)

| # | Decision | Context from audit |
|---|---|---|
| D1 | Enforce **batch/expiry (FEFO)** at sale? Block expired stock? | Schema supports batches, but 0 sales use them. Enforcing changes counter workflow and requires batch entry on purchases |
| D2 | Server-calculated prices and discount limits per role? | Today the browser decides prices and totals. Recommended: server-side, with a per-role max discount |
| D3 | Negative stock: block or allow with warning? | `without_stock='no'` but 26 products are negative |
| D4 | Sell by **box / strip / piece**? | Pack fields exist only for reordering; units are all `Pc` |
| D5 | Modules to retire vs keep: HRM/payroll, manufacturing, quotation, gift card, coupon, courier/challan/packing slip, restaurant tables, WooCommerce, multi-currency, custom fields, SaaS/addons | Many are empty tables or commented out in the sidebar |
| D6 | Loyalty: keep legacy `customers.points` or move to the new reward wallets (with opening-balance migration)? | Two ledgers today |
| D7 | Hosting for Nuxt (static SPA on current host vs Node) and the canonical API host (`api.redpharmabd.com`) | DNS record does not exist yet |
| D8 | Does the techrajshahi API host use a separate DB? | Determines whether a data merge is needed |
| D9 | Approve Phase 0 containment on production | Critical exposure today |
