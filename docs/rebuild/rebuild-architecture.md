# Red Pharma: Full Rebuild Architecture

**Status: design, awaiting approval. No implementation has started.**
This document supersedes the "centralize the legacy API" strategy in `../migration-plan.md`. The audit documents (`../pos-*.md`, `../known-issues.md`, …) remain the functional reference for **what** the new system must do. The rebuild changes **how**.

## 1. Decisions taken (2026-11-23)

| # | Decision | Choice |
|---|---|---|
| R1 | Approach | **Full rebuild.** New Laravel API, **new database schema**, new Nuxt POS. Legacy data is imported once at cutover; no legacy code is reused |
| R2 | Website and Android app | **Rebuilt later.** Until then they stay on the legacy system and the legacy database. A **legacy bridge** keeps the two databases consistent (§5) |
| R3 | Stock model | **Batch + expiry on every stock movement.** FEFO auto-allocation at sale (cashier may override), **expired stock cannot be sold** |
| R4 | Units | **Box / strip / piece** with automatic conversion. Stock is held in the base unit (piece) |
| R5 | Modules kept | Core POS, products, inventory, purchases, sales and returns, customers and suppliers, reports, administration, **plus** online orders and delivery, membership and rewards, accounting and expenses, HRM and payroll |
| R6 | Modules dropped (history archived, not migrated) | Variants, combo products, IMEI, gift cards, coupons, quotations, discount plans, restaurant tables, WooCommerce/SaaS/addons, multi-currency, custom fields, manufacturing, packing slips/challans/couriers (all 0 rows or unused in production) |

### Open decisions (needed before the schema is frozen)

| # | Question | Recommendation |
|---|---|---|
| O1 | **Generic names.** The new POS needs generic search, but the legacy data has **no generic field** (strength sits inside the product name, e.g. "Tryptin 10 mg Tab."). Where does generic data come from? | Import a licensed or official medicine dataset (e.g. the DGDA registered-drug list) matched by brand name, plus manual review. The schema supports it from day one; the column stays nullable until filled |
| O2 | **Opening stock at cutover.** Legacy stock is unreliable (2,591 products out of sync, 11,568 duplicate rows) and has no batch/expiry data. | **Physical stock count with batch + expiry** during the cutover weekend, entered in the new POS as opening stock. Fallback: import legacy quantities as one "legacy opening" batch per product with expiry *unknown*, which must be recounted within N weeks |
| O3 | **Free-text "custom product" lines.** 4,520 legacy sale lines were typed in for items not in the catalog | Allow a *misc* line type: no stock effect, needs a permission and a reason |
| O4 | **Prescription-only flag.** No legacy rule exists | Store the flag, show a reminder at the POS, **no blocking** until the business confirms the rule |
| O5 | **Historical documents.** How much history goes into the new reports? | Import all sales, purchases, returns and payments since 2025-03 as **read-only legacy documents** (no stock or ledger effect), plus opening balances at cutover |

## 2. System landscape

```
                 ┌──────────────────────── NEW SYSTEM ───────────────────────────┐
 Staff ──HTTPS──►│  Nuxt POS (pos.redpharmabd.com, SPA)                           │
                 │        │ cookie session (Sanctum SPA) + CSRF                   │
                 │        ▼                                                       │
                 │  Laravel API (api.redpharmabd.com)  ──►  NEW DB (MySQL 8 /     │
                 │   /api/pos/v1/*                          MariaDB, new schema)   │
                 │   queue worker · scheduler · bridge jobs                        │
                 └───────────────┬───────────────────────────────────────────────┘
                                 │ LEGACY BRIDGE (scheduled jobs in new API)
                                 │  new→legacy: catalog, prices, stock, order status, points
                                 │  legacy→new: website/app orders, new web customers
                 ┌───────────────▼──────────── LEGACY (until web/app rebuild) ────┐
 Website ───────►│  legacy Laravel /api/* (unchanged)  ──►  LEGACY DB             │
 Android app ───►│  Blade POS: retired at cutover (read-only archive access)      │
                 └───────────────────────────────────────────────────────────────┘
```

- **One writer per fact.** After cutover the new system owns the catalog, prices, stock, POS sales, purchases, customers' loyalty balances and finance. The legacy DB owns only what the website and app create (web orders, web customer accounts) until the bridge imports them.
- The Blade POS is **switched off** at cutover. The legacy app stays online **only** to serve `/api/*` for the website and app. Phase 0 containment of that legacy app therefore still matters for as long as the website and app depend on it.

## 3. Technology

| Layer | Choice | Notes |
|---|---|---|
| API | Laravel (latest stable LTS-track release at project init), PHP 8.3+ | Fresh repository. Sanctum (SPA cookie auth), spatie/laravel-permission, Pest tests, Larastan level ≥ 6, Pint |
| DB | MySQL 8.0+ or MariaDB 10.11+/11.x (InnoDB, utf8mb4) | Same engine family as legacy, so hosting stays simple. Money is `DECIMAL`, never `DOUBLE` |
| Queue / cache | Redis if the host allows, otherwise the database queue | Bridge jobs, exports, SMS/mail, AI jobs run off the request path |
| POS frontend | Nuxt (latest stable), Vue 3, TypeScript, Tailwind CSS, Pinia, VueUse, Zod | SPA mode (`ssr: false`), static deploy |
| Search | MySQL FULLTEXT + prefix indexes on name/generic/barcode (≈25k products) | Add Meilisearch only if measured latency needs it |
| AI | Existing OpenAI features re-implemented as services: camera product search, reorder forecast, draft orders | Behind feature flags |
| Printing | Browser print of thermal-receipt and A4 invoice templates (HTML/CSS) | Thermal 58/80 mm layouts |
| CI | GitHub Actions: lint, static analysis, tests, Nuxt type-check and build | |

## 4. Repository layout (in `CLAUDE_AUDIT_NEW_VERSION/`)

```
api/            Laravel API (domain-oriented: app/Domain/{Catalog,Inventory,Sales,Purchasing,Parties,Finance,Loyalty,Orders,HR,Admin})
pos/            Nuxt POS
docs/           audit + rebuild design (this folder)
tools/etl/      legacy → new import commands live in api/ (artisan etl:*); runbooks here
phase0-hotfix/  legacy containment package (independent)
```

## 5. Legacy bridge (exists only until website and app are rebuilt)

The website and app keep reading and writing the legacy DB, so both directions must stay in sync. All bridge jobs are idempotent and keyed by watermarks and a `legacy_id_map` table. They are monitored, and they are **feature-flagged so they can be stopped instantly**.

| Direction | Data | Frequency | Rule |
|---|---|---|---|
| new → legacy | Products: name, active, price per piece, image, category | on change (queued) + nightly full reconcile | Writes only the columns the website reads (`products.name/price/is_active/image/category_id/brand_id/slug`) |
| new → legacy | Available stock per product (sum of non-expired, sellable batches) | every 2 min | Writes `products.qty` and a **single** `product_warehouse` row per product. The website only displays this |
| legacy → new | New website/app orders (`sales.sale_type='website'`, id > watermark) and their lines, customer and address | every 1 min | Creates an online order in the new system. **Stock is reserved in the new system.** If stock is unavailable the order is flagged for the operator, not dropped |
| legacy → new | New web customer accounts (phone, name, email) | every 5 min | Matched by normalized phone; creates or links the customer |
| new → legacy | Order status (confirmed → packed → in transit → delivered/cancelled) and rider | on change | Updates legacy `order_trackings` + history so customers see tracking |
| new → legacy | Loyalty point balance per customer | on change | Writes legacy `customers.points` so website checkout redemption uses the true balance. Redemptions arriving with web orders are posted to the new reward ledger |

**Known limits, accepted until the website and app rebuild:**
- Website stock is up to ~2 minutes stale.
- A web order can oversell; it is then flagged for manual handling.
- The legacy checkout still decrements legacy stock, which the next bridge sync overwrites.

The legacy `/api` security fixes (order IDOR, reward theft) are still needed while the website and app use it.

## 6. Core domain rules (enforced only in the API)

1. **Server-side money.**
   - The client sends product, unit, qty, optional batch override, requested discount and tendered payments.
   - The API computes line prices from the price list, applies discount limits per role, and calculates tax, totals, rounding, change and due.
   - Any client total is ignored or only compared.
2. **Stock ledger.**
   - Every stock change is an append-only `stock_movements` row.
   - `stock_balances` (warehouse × batch) is a projection updated **in the same DB transaction** with `SELECT … FOR UPDATE`.
   - Negative balances are impossible (DB `CHECK` + service guard).
3. **FEFO.**
   - Allocation picks the non-expired batch with the earliest expiry and splits across batches when needed.
   - The cashier may choose another *non-expired* batch.
   - Expired or quarantined batches are unsellable. Near-expiry thresholds come from settings.
4. **Units.**
   - Each product has a unit ladder (piece = 1, strip = n, box = n×m).
   - Lines store unit, qty, factor and `qty_base`. Stock and costs are in base units.
5. **Documents are immutable once completed.** Corrections are voids or returns, never edits or deletes. Every document has a gap-free number from `document_sequences`.
6. **Idempotency.** Every create-document endpoint requires an `Idempotency-Key`. Replays return the original result.
7. **Ledgers.** Customer and supplier balances come from `party_ledger_entries`. Cash and bank balances come from `account_transactions`. Loyalty comes from `reward_ledger`. Balances are derived or projected, never typed in.
8. **Authorization.** Every endpoint is gated by a permission (seeded set derived from the legacy 153 permissions, cleaned up) and scoped to the user's warehouses.
9. **Audit.** Who, when, what and before/after for master data and every document state change.

## 7. Delivery phases

| Phase | Content | Exit criterion |
|---|---|---|
| A. Foundations | Repos, CI, Laravel and Nuxt skeletons, auth (staff login, roles, permissions, warehouses), app shell + sidebar from the verified inventory, schema migrations for all domains | Login → permission-filtered empty shell on staging |
| B. Catalog and inventory | Products (units, barcodes, prices, generics, manufacturers, dosage forms), batches, stock movements and balances, adjustments, counts, transfers, expiry views | Stock invariants test suite green |
| C. Purchasing | Suppliers, purchases with batch/expiry receipt, supplier payments and ledger, purchase returns, reorder drafts (manual + AI) | Purchase → stock → supplier due verified |
| D. POS and sales | POS screen (barcode, keyboard, FEFO, units, discounts, customer, multi-payment, due, hold/resume, receipt), cash register sessions, sales list, voids, sale returns/refunds | Counter UAT script passes |
| E. Parties, loyalty, online orders | Customers and groups, due collection, membership and rewards, online-order fulfilment, riders, shipping zones | |
| F. Finance and HR | Accounts, transfers, expenses, income, account statements, balance sheet; HRM (employees, attendance, holidays, payroll, advances) | |
| G. Reports and dashboard | All legacy reports re-specified on the new model + exports | Report parity checklist |
| H. ETL and bridge | Legacy import commands, reconciliation reports, bridge jobs, cutover rehearsals (≥ 2 full dry runs on a prod copy) | Rehearsal reconciles within agreed tolerances |
| I. Cutover | Freeze → final import → stock count → go-live → bridge on → hypercare | Business sign-off |

Each phase ends with commits per feature, tests, and an update of `../pos-feature-parity-matrix.md`.

## 8. Cutover outline (details in `data-migration-plan.md`)

1. Two or more rehearsals on a production copy: full ETL, then reconciliation reports reviewed by the business.
2. Cutover window (e.g. Friday night to Saturday):
   - Set the Blade POS to read-only.
   - Run the final ETL.
   - Do the physical stock count (O2).
   - Turn the bridge on.
   - Point `pos.redpharmabd.com` at the Nuxt POS.
3. Rollback until the first new sale: point DNS back and re-enable the Blade POS (no data written to the new system yet). After the first new sales, roll forward only: fix and continue, with the legacy DB kept read-only.
