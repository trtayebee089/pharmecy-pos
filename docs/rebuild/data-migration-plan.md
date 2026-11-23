# Red Pharma: Legacy → New Data Migration (ETL) Plan

Source: the legacy MariaDB (`u241572975_redpharma_app`), always read through a **read-only DB user** on a copy or replica, never the live primary during rehearsals.
Target: the new schema (`database-design.md`).
Tooling: `php artisan etl:*` commands in the new API. They are idempotent, use `legacy_id_map`, and are re-runnable from scratch on an empty target.

## 1. Principles

1. **Never modify the legacy DB** during ETL. The only legacy writes are the bridge jobs after cutover, limited to the documented columns.
2. **Import facts, rebuild derived values.** Balances, dues, stock and points are recomputed in the new model from imported facts plus explicit opening entries, never copied from legacy totals that are known to drift.
3. **Every row is accounted for.** Each step emits a reconciliation report: rows read / imported / skipped (with reason) / merged, and money and qty checksums.
4. **Dirty data is quarantined, not silently fixed.** Rows that fail validation go to `etl_rejects` (entity, legacy_id, reason, payload JSON) for business review.
5. **Personal data:** ETL runs only on servers; local copies are restricted. Customer passwords and tokens are **not** imported (the website and app stay on legacy).

## 2. Table mapping

### 2.1 Master data

| Legacy | Rows | → New | Rules |
|---|---|---|---|
| `warehouses` | 1 | `warehouses` | code generated |
| `billers` | 12 | `warehouses.invoice_header/footer` + `settings` | Business confirms which biller header is current |
| `brands` | 362 | `manufacturers` | Trim and dedupe by normalized name; inactive kept as inactive |
| `categories` (leaf, e.g. Tablet/Syrup) | ~200 | `dosage_forms` | Leaf names = dosage forms |
| `categories` (parents) | rest | `categories` | Shop grouping tree |
| `units` | 5 | – | Replaced by per-product `product_units` |
| `products` | 24,506 | `products`, `product_units`, `product_barcodes`, `product_prices` | See §3 |
| `suppliers` | 91 | `suppliers` | + one "Unknown supplier" for 2,488 purchases with NULL supplier |
| `customers` | 733 | `customers` | Phone normalized to `01XXXXXXXXX`; 18 duplicate phones merged (the most recent profile is kept, others linked through `legacy_id_map`, sales re-pointed). The walk-in customer (118k sales) maps to the system walk-in row. Junk columns (`aaaaaaaa`, `dsf`…) are dropped |
| `customer_groups` | 1 | `customer_groups` | |
| `users` | 87 | `users` + `model_has_roles` | Staff only (role ≠ customer/supplier). bcrypt hashes are compatible and kept, but **`must_change_password=1` for everyone** (the hashes were exposed in public backups). Supplier logins (role 6) and advertiser/online-order roles are reviewed with the business |
| `roles`/`permissions` | 7 / 155 | `roles`/`permissions` | New, cleaner permission set. A mapping table converts legacy role grants. The business re-approves the matrix |
| `accounts` | 1 | `accounts` | |
| `expense_categories`, `income_categories` | 16 / 10 | same | |
| `shipping_zones`, `riders` | 41 / 4 | same | |
| `reward_point_settings/tiers` | 1 / 3 | `settings` + `membership_plans` | Business confirms the earn/redeem rules |
| HRM: `departments`, `employees`, `holidays`, `attendances`, `payrolls`, `advances_payments` | ~1–4 each | HR tables | Salary values entered by HR (never stored in legacy) |

### 2.2 Documents (history)

Imported as **read-only legacy documents** (`is_legacy=1`), O5. They create **no** stock movements, ledger entries or reward entries; the opening balances in §2.3 carry the effect forward. Reports therefore show continuous history.

| Legacy | Rows | → New | Rules |
|---|---|---|---|
| `sales` (`sale_status` 1 completed, 4 returned) | ~119.5k | `sales` status `completed` | Number = legacy `reference_no`; the 511 duplicates get a `-2`, `-3` suffix. channel from `sale_type`. Totals copied as-is (historical) |
| `sales` draft (3) / pending | 57 / few | not imported | Listed in the reconciliation report |
| `sales` website (`sale_status` 0) | 27 | `sales` + `order_fulfilments` | Status from `order_trackings` |
| `product_sales` | 302k | `sale_items` (+ `sale_item_batches` only where a batch existed: 0 rows) | 643 lines pointing at deleted products → a placeholder product "Deleted product (legacy #id)" |
| `custom_product_sales` | 4,520 | `sale_items` line_type `misc` | |
| `returns` / `product_returns` | 2,080 / 3,985 | `sale_returns` / `sale_return_items` | |
| `purchases` / `product_purchases` | 8,484 / 65k | `purchases` / `purchase_items` | 219 lines on deleted products → placeholder |
| `return_purchases` / `purchase_product_return` | 5 / 15 | purchase returns | |
| `payments` | 121.7k | `payments` + `payment_allocations` | `account_id=0` (2,397) → default cash account. 10 orphans → `etl_rejects` |
| `adjustments` / `product_adjustments` | 30 / 95 | `stock_adjustments` (posted, legacy) | |
| `expenses` / `incomes` | 285 / 18 | same | |
| `cash_registers` | 3 (all open) | `cash_register_sessions` (closed at cutover) | |
| `order_trackings` / histories | 37 / 79 | `order_fulfilments` / `order_status_history` | |

### 2.3 Opening balances at cutover (the live state)

| Balance | Source | New entry |
|---|---|---|
| **Stock** | **Physical count (recommended, O2)**: batch no + expiry + qty per product, entered through a `stock_counts` session on the new POS | `batches` + `opening` stock movements |
| Stock, fallback | Legacy qty per product = reconciled value (dedupe `product_warehouse`, compare with `products.qty`; differences reported) | One `LEGACY-OPENING` batch per product with `is_legacy_opening=1`, `expiry_date NULL`. **Not sellable until a batch and expiry are assigned**, or sellable with a warning (business choice), and recounted within N weeks |
| Customer dues | Per customer: Σ(grand_total − returns − paid) over completed sales, the legacy customer-due report formula, reviewed with the business | `party_ledger_entries` type `opening` |
| Supplier dues | Per supplier: Σ(grand_total − paid_amount) over purchases | `opening` entries |
| Account balances | Business-confirmed cash/bank balance at cutover | `accounts.opening_balance` |
| Loyalty points | `customers.points` (373 customers) | `reward_ledger` type `opening` + wallets. The new reward engine was never live in legacy, so there is nothing else to merge |
| Memberships | Not live in legacy | Start fresh with the configured plans |

## 3. Product transformation (the most important step)

| New field | From legacy | Rule |
|---|---|---|
| `sku` | `code` | 0 duplicates today. UNIQUE enforced |
| `name`, `strength` | `name` (e.g. "Tryptin 10 mg Tab.") | Strength parsed by pattern (`\d+(\.\d+)? ?(mg|mcg|g|ml|%|IU)(/\d+ ?ml)?`, combos in parentheses). The original name is kept as-is. Unparsed names go to the review list |
| `manufacturer_id` | `brand_id` | via the manufacturers map |
| `dosage_form_id` | `category_id` | leaf category → dosage form |
| `generic_id` | – | **Empty.** Filled by the enrichment step (O1) |
| `product_units` | `box_qty`, `stripe_qty`, `piece_qty` (8,520 / 3,173 / 8,518 products) | piece factor 1 always. If `piece_qty > 0`: strip factor = `piece_qty`. If `stripe_qty > 0` and `piece_qty > 0`: box factor = `stripe_qty × piece_qty` (e.g. Tryptin: strip = 10, box = 200). Non-integer or inconsistent values → review list. The meaning of `box_qty` (default order size?) must be confirmed before mapping |
| `product_prices` (retail) | `price` (per piece) | piece price = legacy price. Strip and box prices default to factor × piece price, overridable. **15,359 products have price ≤ 0**: imported **inactive** with "needs price" status |
| wholesale price | `wholesale_price` | when present |
| cost | `cost` | becomes the opening batch unit cost (fallback) / informational |
| `reorder_level_base` | `alert_quantity` | |
| `requires_prescription` | – | default false (O4) |
| `is_active` | `is_active` | NULL → false. 747 inactive |
| `image_path` | `image` | Files copied from the legacy `public/images/product/` to the new storage, with checksums verified |
| barcodes | `code` | the legacy code is used as the barcode when it is numeric; otherwise sku only |

## 4. Reconciliation reports (each rehearsal; business sign-off)

| Check | Pass criterion |
|---|---|
| Row counts per entity: read = imported + rejected + skipped(reason) | exact |
| Σ sales.grand_total per month, legacy vs new | exact (history copied) |
| Σ payments per month and method | exact, except documented rejects |
| Customer due per customer, legacy formula vs new opening ledger | exact; differences listed |
| Supplier due per supplier | exact |
| Points per customer | exact |
| Stock (fallback path) per product, legacy reconciled qty vs new opening | exact; drift report reviewed |
| Product units sanity: factors > 0, box > strip > piece | 100% or listed |
| Price completeness of active products | listed for pricing team |

## 5. Rehearsal and cutover runbook

1. **Rehearsal 1** (staging, prod copy): full ETL → reports → fix the mappings. Repeat until clean.
2. **Rehearsal 2** (timed): measure ETL duration. Run a UAT pass on the imported data (look up old invoices, customer dues, product search, make test sales on a cloned DB).
3. **Cutover:**

| T | Step |
|---|---|
| T−7d | Freeze master-data changes in legacy, except prices. Print count sheets. Train staff on the new POS (staging) |
| T−0 (after closing) | Close legacy cash registers. Set the Blade POS read-only (maintenance page for staff; legacy `/api` stays up for the website and app) |
| T+0:15 | Full DB backup of legacy (kept offline) |
| T+0:30 | Run the final ETL (masters, history) → reconciliation reports |
| T+1:30 | Physical stock count session(s) in the new POS (batch, expiry, qty) → post as opening stock |
| T+N | Business sign-off on the reports → switch `pos.redpharmabd.com` to Nuxt → enable bridge jobs (catalog/stock push first, then order pull) |
| T+N+1 | First test sale and return, cash register open, receipt print, web order import test |
| Day 1–14 | Hypercare: daily reconciliation of sales/payments/stock, bridge error dashboard |

4. **Rollback:** possible until the first live sale on the new system. Repoint DNS, lift the Blade POS read-only mode, bridge off. After that, roll forward only.

## 6. Bridge specifics (until the website and app are rebuilt)

See `rebuild-architecture.md` §5. Implementation notes:
- Separate DB connection `legacy` with a **dedicated legacy DB user granted only** SELECT on the needed tables and UPDATE on `products(qty, price, name, is_active, image, slug, category_id, brand_id)`, `product_warehouse(qty)`, `customers(points)`, `order_trackings`, `order_tracking_histories`.
- New products created in the new system are inserted into legacy `products` (minimal columns) so the website can show them. The id mapping goes into `legacy_id_map`.
- Every bridge run writes to `bridge_watermarks`. Failures alert the admin (mail/SMS) and leave the watermark unchanged, so the next run retries.
