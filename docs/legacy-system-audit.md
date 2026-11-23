# Red Pharma: Legacy System Audit

| | |
|---|---|
| Audit date | 2026-11-23 |
| Audit type | Read-only source audit, plus non-destructive HTTP checks (HEAD/GET only) against production |
| Codebase of record | `backend - ocr pos/` (newest local Laravel snapshot, with OCR/AI features) |
| Status | **Phase 1–5 audit complete from source. Logged-in UI verification of the live POS is still pending** (see §9) |

Detailed evidence is in `docs/workpapers/wp1…wp5`. Every claim there cites `file:line`. This document is the overview.

---

## 1. Executive summary

1. **There is one Laravel application, not two.** The "POS" and the "API" are the same Laravel 10 codebase, derived from the commercial *SalePro* POS template. The POS UI is server-rendered (Blade + jQuery). The JSON API for the website and app lives in the same app under `routes/api.php`. The "duplicate" is a **second deployment of the same code**, not a separate implementation.
2. **The deployment topology does not match the brief.**
   - `api.redpharmabd.com` **does not exist in DNS** (NXDOMAIN, checked 2026-11-23).
   - The live Vue website calls `https://pos.redpharmabd.com/api`.
   - The Android app and the unreleased web-v2 call `redpharma-api.techrajshahi.com/api`.
3. **The live website's API is currently not deployed.** On production, `GET https://pos.redpharmabd.com/api/categories`, `/api/products`, `/api/products/featured`, `/api/shipping-zones`, `/api/reward-point-tiers` and `/api/search/{q}` all return **404 JSON**. The 2026-11-20 deployment package (`deployment-redpharma-2026-11-20/`) has evidently not been applied to the live POS.
4. **Production is running code that is not in this workspace.**
   - Live `/pos` exists (302 to login) but `/report/pos` returns 404.
   - Both local snapshots register the POS route under `/report/pos` because of a mis-nested route group (`routes/web.php:607-649`).
   - The `deployment-work/github-pos` git clone is corrupt (`bad tree object HEAD`), and neither local backend folder is under version control.
5. **Critical, production-exploitable security defects exist** and should be contained before any rebuild work (see `known-issues.md` → *Immediate production actions*):
   - An unauthenticated `GET /update-coupon` truncates every table and deletes an arbitrary directory (`routes/web.php:101`, `CouponController.php:83-102`).
   - SQL backups are publicly downloadable: `https://pos.redpharmabd.com/salepro_backup_*.sql` returns `200 text/x-sql`.
   - The scheduler runs `reset:db` (truncate all tables) every minute (`app/Console/Kernel.php:40`). It is harmless only while cron is off.
   - Most write endpoints have no server-side permission checks, and a cashier can escalate to admin.
6. **The business core is financially fragile.**
   - The POS sale path trusts browser-computed prices, discounts and totals.
   - It runs without a DB transaction (commented out at `SaleController.php:1155`), without row locks and without idempotency.
   - Stock is denormalised into 3–4 places with measurable drift: 2,591 products are out of sync, and 11,568 duplicate `product_warehouse` keys exist.
   - Batch/expiry tracking exists in the schema but is effectively unused: 11 batch rows, and 0 sale lines carry a batch.

---

## 2. Codebases discovered

All paths are relative to `D:\01 REDPHARMA\00 CODEBASE\`.

| Folder | What it is | VCS | Role in target architecture |
|---|---|---|---|
| `backend - ocr pos/` | Laravel 10.48.29 / PHP ^8.2 SalePro-based POS + API. Newest snapshot: adds OpenAI camera product search, AI stock forecast and AI draft orders | **None** | **Basis for the centralized API** (after reconciliation with production) |
| `backend/` | Older snapshot of the same app. Differs only in 8 files (AI/vision additions, `ProductController`, `ReportController`, `Product` model, `Kernel`, routes) | None | Baseline for diffing only |
| `deployment-work/github-pos/` | Partial (`blob:none`) clone of `github.com/trtayebee089/redpharma-pos`, 1 commit (2026-06-03) | Git, **corrupt HEAD** | Must be re-cloned and compared to production |
| `deployment-redpharma-2026-11-20/` | Release overlay (backend patches + compiled website) with deployment instructions | – | Pending deployment; explains the current website 404s |
| `web/` | **Live** customer website: Vue 3.5, Vite 7, Pinia 3, Tailwind 4, PrimeVue 3, axios | Git (`redpharma-vue-frontend`), clean | **Keep unchanged.** Consumer of `/api` |
| `web-v2/` | Unreleased website redesign: Vue 3.5, Vite 5, Pinia 2, Tailwind 3 | Git (`redpharmabd-frontend-v2`) | Out of scope. Consumer of `/api/v1` |
| `app/` | Flutter Android app 1.0.3+8 (Dart ^3.9.2, `http`, `provider`) | Git (`redpharma-app`), 4 uncommitted changes | **Must keep working.** Hard-codes its API host |
| `u241572975_redpharma_app.sql` | Production DB dump, 2026-03-10, MariaDB 11.8.3, 94 tables | – | Schema source of truth (contains customer PII; keep private) |
| `UNIFIED_SYSTEM_ARCHITECTURE.md`, `UNIFIED_SYSTEM_MIGRATION_PLAN.md`, `PHASE_*.md` | Prior architecture/membership work | – | Prior context. Consistent with this audit's direction |

> The signing key files `app/redpharma-upload.jks` and `.pem` sit in the app project folder. Make sure they are git-ignored and never published.

---

## 3. Current architecture (as discovered)

```
                       ┌──────────────────────────────────────────────┐
 Staff browser ───────►│ pos.redpharmabd.com  (Laravel 10, Blade+jQuery)│
  (session cookie)     │  routes/web.php   ~1,200 lines, ~600 routes    │
                       │  routes/api.php   103 routes (NOT deployed     │
 Vue website  ────────►│                   on this host today → 404)    │──► MariaDB (prod)
 redpharmabd.com       └──────────────────────────────────────────────┘
  (bearer token)
                       ┌──────────────────────────────────────────────┐
 Android app ─────────►│ redpharma-api.techrajshahi.com (same codebase,│──► DB: UNVERIFIED
 web-v2 (unreleased) ─►│ 2nd deployment; serves /api)                  │   (same or separate?)
                       └──────────────────────────────────────────────┘
 api.redpharmabd.com : no DNS record
```

- **POS UI:** Blade views in `resources/views/backend/**`, jQuery + DataTables + bootstrap-select. Nearly every screen talks to session-authenticated web routes that return HTML or JSON. **There is no JSON API for POS operations today.** A Nuxt POS needs a new API surface; see `api-architecture.md`.
- **Modules:** `nwidart/laravel-modules`.
  - Only `Modules/Manufacturing` exists on disk (its sidebar entry is commented out).
  - `modules_statuses.json` also enables `Woocommerce`, `Ecommerce` and `Middleware`, which are **missing**.
  - `Kernel.php` aliases a missing `Modules\Ecommerce\...\CustomerAuth` middleware.
- **Sidebar:** 18 groups, about 100 live links plus about 20 commented-out or hidden modules. Full inventory: `pos-navigation-inventory.md`.

---

## 4. Frameworks and versions

| Layer | Version / library |
|---|---|
| PHP | `^8.2` (composer). Production runtime version UNVERIFIED |
| Laravel | 10.48.29 (Laravel 10 is past end of security support; plan an 11/12 upgrade) |
| Auth | laravel/ui session auth (staff); laravel/sanctum 3.3.3 personal tokens (customers only); spatie/laravel-permission ^5.8 (roles via `users.role_id`) |
| Data/UI libs (backend) | yajra/laravel-datatables, maatwebsite/excel, milon/barcode, intervention/image 3, laravelcollective/html, joedixon/laravel-translation |
| Template remnants | stripe, paypal, razorpay, xendit, twilio, salla/zatca, automattic/woocommerce, gladcodes/keygen, SaaS installer, client auto-update |
| Database | MariaDB 11.8.3, InnoDB, utf8mb4 |
| Website | Vue 3.5.18, Vite 7, Pinia 3, Tailwind 4, PrimeVue 3.53, axios 1.12, pusher-js |
| App | Flutter / Dart ^3.9.2, http ^1.1, provider ^6 |

---

## 5. Authentication and authorization

| Actor | Mechanism | Notes |
|---|---|---|
| Staff (POS) | Laravel session (`web` guard, file driver, 3-day lifetime), login by username or email | Custom `LoginController@login` removes the login throttle. No `session()->regenerate()`. `demo_type` parameter can switch DB if `USER_VERIFIED` env is falsy (UNVERIFIED in prod) |
| Staff roles | `users.role_id` → `roles` → `role_has_permissions` (Spatie tables, `model_has_roles` unused/absent) | **153 permission names** across 26 modules (`workpapers/wp2` §Permissions). Checks are ad hoc per controller. Many write endpoints have **none**. Sidebar gating is cosmetic. `role_id <= 2` hard bypass exists |
| Customers (web/app) | Sanctum personal access tokens on `App\Models\Customer`, 30-day `expires_at` for new tokens. **148 existing tokens have no expiry** | Token ability `customer` is never enforced. No customer guard |
| Admin API (`/api/v1/admin/*`) | Works only via a staff session leaking into the `api` middleware group | No CSRF, no permission checks. Nobody calls it today |

Details: `api-architecture.md` §2.

---

## 6. Integrations, background work, storage

| Area | Finding |
|---|---|
| OpenAI | Product vision search at the POS camera, stock forecast, AI draft purchase orders (`app/Services/OpenAI/*`) |
| SMS | Tonkra / Reve / BdBulk (credentials in DB `external_services`). Classes for SmsToday, Clickatell and Twilio are also present. Used for sale notifications only |
| Mail | SMTP via `mail_settings` + `.env`. Customer password-reset OTP is **email-only** |
| Pusher | Public channel `online-orders` broadcasts new website orders (includes customer name and phone) |
| Payment gateways | Code references Stripe, PayPal, Razorpay, bKash, SSLCommerz, Pesapal. `app/Payment/*` classes are **missing**. In practice all 121,748 payments are `Cash`. Website checkout is cash-on-delivery |
| Queue | `sync`. No jobs. Mailables and listeners run inline |
| Scheduler | `purchase:auto` (5 min), `dsoalert:find`, **`reset:db` every minute**, `quote:daily` every minute (test), membership/reward lifecycle jobs, AI draft orders. Production cron status is UNVERIFIED; it is probably off, given that data still exists |
| Files | Uploads are written directly into `public/` (about 72 call sites, some without extension validation). Product images are referenced relative to the POS origin |
| Printing | Browser print of invoice/receipt Blade views; `PRINT_NODE_API_KEY` env key exists |

---

## 7. Database overview

94 tables, 56 holding data. Largest: `product_sales` ≈302k, `payments` ≈122k, `sales` ≈120k, `products` ≈24.5k. One warehouse, one account and one currency are in use. Only 8 declared foreign keys. No secondary indexes on the sales, payments or purchase chain. Migrations **cannot** rebuild production: there are 13 hand-made tables and 6 pending 2026 migrations. Full analysis: `database-dependencies.md`.

---

## 8. Technical debt (summary)

- The SalePro template has many unused modules: restaurant tables, WooCommerce, SaaS, addons, ZATCA, quotation, gift card, coupon, HRM/payroll, manufacturing. They add attack surface; see the `pos-feature-parity-matrix.md` retire candidates.
- Controllers are 2,000–7,000 lines (`SaleController`, `ReportController`) with business logic, SQL and HTML mixed. There is no service layer for sales, purchases or stock. Newer code (membership, reward, checkout pricing, AI) does use services and FormRequests.
- Money is stored as `double`. Status codes are magic integers. There is CSV-in-column data. Reference numbers come from a 12-hour clock with second resolution, which has produced duplicates.
- Caching uses global keys for per-user data (`user_role`, `permissions`), cached for a year.
- Test coverage: two feature tests (customer auth, membership engine). **No tests for sales, purchases, returns or stock.**
- No version control for the backend, and no reproducible deployment.

---

## 9. Access limitations and what is still needed

| Item | Status | Needed from you |
|---|---|---|
| Live POS UI (logged-in) | **Not inspected.** No credentials; I will not enter passwords | Either log in yourself in the in-app browser pane so I can walk every sidebar page read-only, or provide a staging copy with a test account |
| Production source code | Not available locally (production routes differ from both snapshots) | A copy of the deployed `pos.redpharmabd.com` app (without `.env`), or confirmation of which git commit is deployed |
| `redpharma-api.techrajshahi.com` deployment | Not inspected | Which database it uses (same as POS or separate?), and its code version |
| Production cron / `.env` flags (`USER_VERIFIED`, `APP_DEBUG`, `BROADCAST_DRIVER`) | UNVERIFIED | Confirm from the hosting panel (do not paste secrets) |
| Fresh DB dump | Local dump is from 2026-03-10 | A recent schema-only dump (and a data dump for staging, kept private) |
| DNS for `api.redpharmabd.com` | Missing | Decision and DNS change (your approval required) |
