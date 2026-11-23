# Red Pharma: API Architecture (current and target)

Evidence: `workpapers/wp4-api-consumers.md`, which has the full endpoint table with `file:line`, request and response shapes, and the consumer matrix.

---

## 1. Current state

### 1.1 Hosts

| Host | Serves | Consumers | Status (2026-11-23) |
|---|---|---|---|
| `pos.redpharmabd.com` | Blade POS (`routes/web.php`) and, after the pending 2026-11-20 release, `/api/*` | Staff browsers; **live website** (`web/`) | `/api/*` returns **404** on production. The release has not been applied, so the website's catalog/checkout calls fail |
| `redpharma-api.techrajshahi.com` | Same Laravel codebase (second deployment) serving `/api/*` | **Android app**, web-v2 | Not inspected. DB relationship to POS is UNVERIFIED |
| `api.redpharmabd.com` | – | – | **No DNS record** |

### 1.2 API surface today (`routes/api.php`, 102 routes, plus 1 module route)

| Group | Routes | Auth | Used by |
|---|---|---|---|
| Public catalog: `/categories`, `/products`, `/products/{featured,best-selling,suggestions,{slug}}`, `/search/{q}`, `/shipping-zones`, `/reward-point-tiers` | 12 | none | web, web-v2, app |
| Public **mutating** maintenance: `/categories/boot-slug/update`, `/products/boot-slug/update` | 2 | **none** | nobody (should be removed or protected) |
| Orders: `POST /checkout`, `GET /orders/{id}`, `GET /tracking/{code}`, `POST /orders/{sale}/reorder` | 4 | checkout and order lookup public; reorder Sanctum | web, web-v2, app |
| Legacy customer auth/profile/orders/membership `/customer/*` | 23 | public auth + Sanctum | web, app |
| Rewards `/reward/*` | 7 | Sanctum | web-v2, app |
| `/v1/customer/*` (auth, profile, membership) | 19 | public auth + Sanctum | web-v2, app (membership) |
| `/v1/admin/membership/*`, `/v1/admin/rewards/*` | 39 | Sanctum (works only via a leaked staff session) | **nobody** |
| `/api/manufacturing` (module) | 1 | legacy `auth:api` token | nobody |

**POS operations have no JSON API.** The Blade POS uses about 600 session-authenticated web routes, many of them AJAX endpoints that return JSON or HTML fragments (e.g. `sales/lims_product_search`, `sales/getproduct/{id}`, `check-batch-availability/...`, `pos/product/vision-search`). These are the de facto POS API that the Nuxt POS must replace.

### 1.3 Authentication today
- **Customers:** Sanctum personal access tokens on `App\Models\Customer`.
  - TTL is 30 days for new tokens, but the 148 existing tokens never expire.
  - The `customer` ability is set but never checked.
  - Password reset is a 6-digit OTP sent **by email only**. Its attempt counter is rolled back on failure (bug).
- **Staff:** session cookie on the `web` guard.
  - The `api` middleware group starts sessions without CSRF or `EnsureFrontendRequestsAreStateful`, so a staff session authenticates `/api/*` with **no CSRF protection**.
- **CORS:** `paths=['api/*']`, origins from `CORS_ALLOWED_ORIGINS`, `supports_credentials=false`.

### 1.4 Backward-compatibility constraints (must not break)
1. **The Android app hard-codes its host** (`redpharma-api.techrajshahi.com`; 9 literals, 3 of them `http://`).
   - The old host must keep serving `/api/*` with identical shapes until an app update has rolled out.
   - Flutter `http` does not follow redirects on POST, so a 301 breaks login and checkout. Use a reverse proxy, not redirects.
2. Response shapes are load-bearing:
   - Auth returns both `data.{user,token}` and top-level `user/token`, and the profile duplicates every key.
   - `/products/suggestions` returns a bare array.
   - `/products` returns a raw paginator.
3. Field aliases must keep working: `phone`/`phone_number`/`mobile`, `currentPassword`/`confirmPassword`, `reward_points`/`used_points`, `shipping_cost`/`delivery_charge`.
4. Guest checkout with phone-based customer find-or-create and token issuance must keep working (with the reward-theft fix; see KI register).
5. web uses legacy `/customer/*`, web-v2 uses `/v1/customer/*`, and the app mixes both. `/api/v1` is already a public contract.
6. While the Blade POS runs in parallel with the Nuxt POS, the web routes and sessions must keep working.

Endpoints consumers call that do not exist (404 today): `POST /account-removal-request` (web; likely needed for Play Store policy), `/cart*`, `/track-order` and `/request-order` (web-v2), `/banners` and `/pages/*` (app constants, unused).

---

## 2. Target architecture

```
  Nuxt POS (pos.redpharmabd.com, SPA)        Vue website            Android app / web-v2
        │  cookie session (Sanctum SPA)          │ bearer                 │ bearer
        ▼                                        ▼                        ▼
  ┌──────────────────────────── api.redpharmabd.com (one Laravel app) ─────────────────────────┐
  │ /api/pos/v1/*   staff channel   (api.pos group: stateful, CSRF, Spatie policies)            │
  │ /api/v1/*       customer channel (api.customer group: stateless bearer, abilities:customer)│
  │ /api/*          legacy, frozen   (byte-compatible; security fixes only; Deprecation hdr)   │
  │                                                                                             │
  │ Domain services (single source of business rules):                                          │
  │  SaleService · PricingService · StockService(ledger+locks) · PurchaseService · ReturnService │
  │  PaymentService · CashRegisterService · CustomerLedger · SupplierLedger · ReportQueries      │
  │  MembershipService · RewardEngine · CheckoutPricingService (exists) · AI services (exist)    │
  └────────────────────────────────────────────┬────────────────────────────────────────────────┘
                                               ▼
                                   MariaDB (existing prod schema)
  Transitional aliases (reverse proxy, same app): pos.redpharmabd.com/api/*, redpharma-api.techrajshahi.com/api/*
```

### 2.1 Principles
- **One Laravel codebase, one database.** No business logic in Nuxt. Nuxt only renders and calls the API.
- **Extract and reuse, don't rewrite.** Move logic out of the Blade controllers into services. The legacy Blade controllers and the new `/api/pos/v1` controllers then both call the same services, so behaviour stays identical during the parallel run.
- **The server decides money and stock.**
  - Prices, discounts, tax, coupon, points, totals, due, change and payment status are recomputed server-side.
  - The client sends only intents: product, batch, qty, unit, chosen discount within permitted limits, and tendered payments.
  - Stock changes go through one `StockService`: `DB::transaction`, `lockForUpdate` on stock rows, batch/expiry checks, and an append-only movement ledger (new table, add-only).
- **Idempotency** on sale, payment, return, purchase, adjustment and transfer creation: an `Idempotency-Key` header stored with a unique index on a new `idempotency_keys` table.
- **Authorization:** every `/api/pos/v1` route is gated by a policy or `can:` middleware mapped to the **existing 153 Spatie permission names**, so roles carry over unchanged.

### 2.2 Channels and versioning

| Prefix | Audience | Middleware group | Auth | Rules |
|---|---|---|---|---|
| `/api/*` (legacy) | Live website, current app builds | `api.legacy` | Sanctum bearer (customer) | **Frozen shapes.** Fix security bugs in place (IDORs, reward theft, OTP counter, public boot-slug). Add `Deprecation`/`Sunset` headers and per-route usage logging |
| `/api/v1/*` | Website and app going forward | `api.customer` (stateless) | Sanctum bearer + `abilities:customer` | Extend the existing v1 with catalog, orders (idempotent guest/auth checkout), tracking, rewards, account removal and content. Envelope: `{success,message,data,meta,errors}` |
| `/api/pos/v1/*` | **Nuxt POS** | `api.pos` (`EnsureFrontendRequestsAreStateful`, CSRF, JSON-only auth/active middleware) | Sanctum SPA cookie session on the `web` guard; optional short-lived device tokens for shared terminals | Spatie-permission policies, idempotency keys, warehouse scoping, audit log |
| `/api/pos/v1/admin/membership/*`, `/rewards/*` | POS staff | `api.pos` | as above | Replaces `/api/v1/admin/*`. Old paths kept as aliases briefly; there are no callers today |

Breaking changes get a new major version (`/api/v2`, `/api/pos/v2`), never a silent shape change.

### 2.3 Nuxt POS authentication (Sanctum SPA)
1. Publish `config/sanctum.php`: `SANCTUM_STATEFUL_DOMAINS=pos.redpharmabd.com` (plus the staging host), and a token `expiration` for bearer tokens.
2. Split middleware groups. Remove the raw `StartSession` from the generic `api` group, which closes the CSRF hole.
3. Session: `SESSION_DOMAIN=.redpharmabd.com`, `SESSION_SECURE_COOKIE=true`, `same_site=lax`, and a **distinct cookie name**.
4. `cors.php`: add `sanctum/csrf-cookie` and `api/pos/v1/*`, set `supports_credentials=true`, and list explicit origins.
5. New endpoints:
   - `POST /api/pos/v1/auth/login`: throttled per username+IP, `session()->regenerate()`.
   - `POST /api/pos/v1/auth/logout`.
   - `GET /api/pos/v1/me`: user, role, permissions, warehouse and biller defaults, POS settings.
   - `PUT /api/pos/v1/me/password`.
6. Nuxt runs in SPA mode (`ssr: false`) for the POS. Its `$fetch`/axios wrapper uses `credentials: 'include'`, calls `/sanctum/csrf-cookie` once, and sends `X-XSRF-TOKEN`. **No tokens in localStorage.**

### 2.4 Indicative `/api/pos/v1` resource map (final list driven by the parity matrix)

| Domain | Endpoints (indicative) |
|---|---|
| auth / me | login, logout, me, password, settings bootstrap |
| dashboard | summary(date range, warehouse), charts, alerts (low stock, expiry) |
| catalog | products (search by code/name/generic, barcode lookup, paginated), product CRUD, categories, brands, units, variants, barcode labels, import/export jobs |
| stock | stock by warehouse/batch, batches (FEFO list), adjustments, stock counts, transfers, movement history |
| sales | POS cart quote (server pricing), create sale (idempotent), drafts/hold, resume, list/filter, show/invoice, edit, delete/void, payments CRUD, returns, gift card/coupon/points validation, vision search |
| purchases | create/edit/list, payments, returns, CSV import, AI/qty-alert draft orders |
| parties | customers (+ due, deposits, history), customer groups, suppliers (+ due, payments), billers |
| finance | accounts, money transfers, expenses, incomes, cash register open/close/summary, balance sheet, account statement |
| online orders | list, status transitions, rider assignment, delivery |
| reports | one endpoint per legacy report, with server-side pagination and CSV/XLSX export |
| admin | users, roles/permissions, warehouses, settings (general/POS/invoice/tax/SMS/mail/reward), membership & rewards admin |
| HRM / manufacturing | only if confirmed in scope (see migration plan decisions) |

### 2.5 Host consolidation
1. Decide the canonical API host. Recommended: `api.redpharmabd.com`, which needs a new DNS record (your approval required).
2. Point it at the same Laravel app and DB as the POS.
3. Keep `pos.redpharmabd.com/api/*` and `redpharma-api.techrajshahi.com/api/*` working through reverse proxy or vhost aliases to the same app until web and app releases move to the canonical host.
4. **Prerequisite:** establish whether the techrajshahi deployment writes to a different database. If it does, plan a customer/order/token merge before consolidation. Moving the app to a different DB logs every app user out.
