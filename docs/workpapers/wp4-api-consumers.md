# WP4 — API Surface, Authentication & Consumer Mapping

Audit date: 2026-11-23 · Scope: read-only · Codebase of record: `backend - ocr pos/` (abbreviated **BE** below). Paths are relative to `D:\01 REDPHARMA\00 CODEBASE\`.

Legend: **PUB** = no auth · **SANC** = `auth:sanctum` · **CUST** = Customer model expected · **STAFF** = `App\Models\User` expected · `ca` = `throttle:customer-auth` (10/min/IP + 5/min/identifier) · `pr` = `throttle:password-reset` (3/min per IP|phone) · `api120` = `throttle:customer-api` (120/min per user or IP). Every route also gets the `api` group's `throttle:60,1` (BE `app/Http/Kernel.php:32`). Rate limiter definitions: `BE/app/Providers/RouteServiceProvider.php:31-36`.

---

## 0. Deployment / host reconciliation

| Source | API base it uses | Evidence |
|---|---|---|
| Deployment note (2026-11-20) | `https://pos.redpharmabd.com/api` — "Same POS Laravel application, existing `/api` route prefix" | `deployment-redpharma-2026-11-20/DEPLOYMENT-INSTRUCTIONS.md:9-11, 207, 216-218` |
| `web/` (live redpharmabd.com) | `pos.redpharmabd.com/api` (env + hard fallback) | `web/.env:1-3`, `web/.env.production:1`, `web/src/api/config.js:3-4`; compiled `web/dist/assets/index-*.js` contains `pos.redpharmabd.com/api` |
| `web-v2/` (unreleased) | `redpharma-api.techrajshahi.com/api/` | `web-v2/.env:1`, `web-v2/src/services/api.js:3` |
| `app/lib` (Flutter) | `redpharma-api.techrajshahi.com/api` (constant **and** 9 hard-coded literals, 3 of them plain `http://`) | `app/lib/constants/api_endpoints.dart:2`; `app/lib/screens/home.dart:77,101,128` (http://); `categories_screen.dart:84,435`; `product_detail.dart:79,149`; `search_results.dart:47` |

Findings:
- **The string `api.redpharmabd.com` does not appear anywhere** in web/, web-v2/, app/lib, either backend's config/routes, or the planning/deployment markdown. The "duplicate API deployment" the consumers actually talk to is `redpharma-api.techrajshahi.com`. Whether `api.redpharmabd.com` is a DNS alias of that host is **UNVERIFIED** (needs hosting panel / DNS check).
- The deployment note is correct for the **website**: since the 2026-11-20 release the Vue site calls `pos.redpharmabd.com/api`. The note's line 289 ("Restore the previous environment/domain configuration only if reverting the domain move itself") confirms the website was *moved* off the old API host. The **Android app and web-v2 still point at the old techrajshahi host**. Two live databases/deployments may therefore be receiving orders (**UNVERIFIED** whether both hosts share one DB — if not, app orders and website orders/customers/reward balances diverge).
- Old snapshot `backend/` vs latest: `routes/api.php`, `app/Http/Controllers/Api/*`, `config/cors.php`, `app/Http/Kernel.php` are **byte-identical** (`diff -q` clean). Only `web.php` differs (latest adds AI-draft, vision-search, `sales/getfeatured`, `sales/recent-draft|recent-sale`, `sales/lims_product_search`). So the API contract is the same on both deployments *if* both run one of these two snapshots (**UNVERIFIED** for the techrajshahi host).
- Route dump `deployment-work/api-routes.json` lists **103** API routes and matches current `routes/api.php` (102) + Manufacturing module (1).

---

## 1. Endpoint inventory (routes/api.php — prefix `/api`, middleware group `api`)

Route file loaded at `BE/app/Providers/RouteServiceProvider.php:76-81`. Line numbers below are `BE/routes/api.php`.

### 1.1 Public catalog & services

| # | Method | Path | Controller@method | Auth / throttle | Params | Response | Side effects |
|---|---|---|---|---|---|---|---|
| 1 | GET | /categories | Api\CategoryController@index (L112) | PUB | – | `{success,data:[parent{children[product_count]}]}` cached 1h | none |
| 2 | GET | /categories/boot-slug/update | CategoryController@bootSlug (L113) | **PUB** | – | `{success,…}` | **Rewrites every category slug** (`CategoryController.php:15-32`) — public mutating GET |
| 3 | GET | /categories/{slug} | CategoryController@show (L114) | PUB | `sort`, `page` | category + paginated(20) products | none |
| 4 | GET | /products | Api\ProductController@index (L119) | PUB | `search, brand, minPrice, maxPrice, sort, page` | paginator(20), cached 5 min keyed by md5(query) | none |
| 5 | GET | /products/featured | @featured (L120) | PUB | – | `{success,data}` cached 1h | none |
| 6 | GET | /products/best-selling | @bestSelling (L121) | PUB | – | `{success,data}` cached 1h | none |
| 7 | GET | /products/suggestions | @suggestionsData (L122) | PUB | `query` | bare array `[{name}]` | none |
| 8 | GET | /products/boot-slug/update | @bootSlug (L123) | **PUB** | – | summary | **Rewrites all product slugs in chunks** (`ProductController.php:168-230`) |
| 9 | GET | /products/{slug} | @show (L124) | PUB | – | product + related | none |
| 10 | GET | /search/{query} | @search (L27) | PUB | `page` | paginated | none |
| 11 | GET | /shipping-zones | Api\ServiceController@shippingZones (L26) | PUB | – | `{success,data:[zones]}` | none |
| 12 | GET | /reward-point-tiers | ServiceController@getRewardPointTiers (L25) | PUB | – | `{success,tiers,settings,usages}` | none. Bug: `usages` uses `auth()->id()` on the default **web** guard (`ServiceController.php:20`), so it returns rows for a *staff* session id, never the customer |

### 1.2 Orders / checkout (legacy, unversioned)

| # | Method | Path | Controller@method | Auth | Params | Response | Side effects |
|---|---|---|---|---|---|---|---|
| 13 | POST | /checkout | Api\OrderController@store (L12) | **PUB, no route throttle** (only 60/min group) | `customer{name,phone|phone_number,email,address,division,district,thana}`, `items[{product_id,variant_id,product_batch_id,qty}]`, `coupon_id|coupon_code`, `reward_points|used_points`, `shipping_cost|delivery_charge`, totals (server re-prices; understated client totals rejected `CheckoutPricingService.php:172`) | `{success,sale,tracking,is_new_customer,temporary_password:null,password_setup_required,token,pricing{…}}` | **Creates Sale** (`sale_type=website`, `user_id=1`, `warehouse_id=1`, `currency_id=3` hard-coded, `OrderController.php:62-85`), Product_Sale rows, **decrements `products.qty`, `product_variants.qty`, `product_warehouse.qty`** (L109-146, no row lock), OrderTracking + history, coupon `used++`, **reward redemption** (L166-172), broadcasts `OnlineOrderEvent` to public Pusher channel (L174), **find-or-create Customer by phone** and issues a Sanctum token for new customers (L59, L179-181) |
| 14 | GET | /orders/{order_id} | @getOrder (L13) | **PUB** | numeric id | `{success,order(with customer, productSales, tracking),tracking}` | none — **IDOR: anyone can enumerate sale IDs and read customer name/phone/address and items** (`OrderController.php:215-241`) |
| 15 | GET | /tracking/{tracking_code} | @getTrackingDetails (L14) | PUB | code `TRK-YYYYMMDD-HHMMSS-XXX` | `{success,order,tracking}` | none (code has only 3 random chars + timestamp → guessable) |
| 16 | POST | /orders/{sale}/reorder | Api\ReorderController@store (L15) | SANC, api120 | `customer{division,district,thana}`, `shipping_cost` | `{success,message,data:cart}` | none (quote only); ownership enforced (`ReorderService.php:17`) |

### 1.3 Customer auth & profile — legacy `/api/customer/*` (L29-54)

| # | Method | Path | Controller@method | Auth / throttle | Params | Response | Side effects |
|---|---|---|---|---|---|---|---|
| 17 | POST | /customer/forget-password | AuthController@forgetPassword | PUB, pr | `phone_number|mobile|phone` | generic success | writes `customer_password_resets` (hashed 6-digit code, 15 min TTL), **emails** the code (no SMS) — `AuthenticationService.php:93-132` |
| 18 | POST | /customer/verify-otp | @verifyOTP | PUB, pr | `phone_number|mobile|phone, otp(6), password, password_confirmation` | success | resets password, **revokes all tokens** |
| 19 | POST | /customer/resend-otp | @resendOTP | PUB, pr | as 17 | as 17 | as 17 |
| 20 | POST | /customer/register | @register | PUB, ca | `name, phone_number (BD regex), email?, address?, gender?, password+confirmation (min 8, letters+numbers), device_name?` | 201 `{success,message,data{user,token,token_type,expires_in},user,token}` | creates Customer, Sanctum token (ability `customer`, TTL 43200 min) |
| 21 | POST | /customer/login | @login | PUB, ca | `identifier|email|phone_number|phone|mobile, password, device_name?` | as 20 (200) | new token per login |
| 22 | POST | /customer/logout | @logout | SANC (no api120) | – | success | deletes current token; invalidates session if any |
| 23 | POST | /customer/logout-all | @logoutAll | SANC | – | success | deletes all tokens |
| 24 | GET | /customer/profile | @profile | SANC | – | `{success,data{user,orders,reward_tiers,membership,membership_profile}, +same keys at top level}` | none |
| 25 | POST | /customer/profile/update | @updateProfile | SANC | `name, phone_number, email?, gender?, address?, city?, avatar (image ≤2MB)` | `{success,data,user}` | updates customer; stores avatar on `public` disk |
| 26 | POST | /customer/profile/update-password | @updatePassword | SANC | `current_password|currentPassword` (**nullable**), `newPassword`, `newPassword_confirmation|confirmPassword` | success | changes password **without requiring current password** (`UpdateCustomerPasswordRequest.php:20`, `AuthenticationService.php:79`); revokes other tokens |
| 27 | GET | /customer/orders | OrderController@getOrders | SANC | – | `{success,data:[{id,date,status,items,total,discount,tracking}]}` | none |
| 28 | GET | /customer/orders/{order_id} | @getOrderDetail | SANC | – | `{success,data{…,customer,tracking,items}}` | none — **IDOR: no ownership check**, any customer token reads any order (`OrderController.php:336-346`) |
| 29-37 | GET | /customer/membership, /card, /benefits, /history, /transactions, /progress, /statistics | CustomerMembershipController@current/card/benefits/history/transactions/progress/statistics | SANC, api120 | `per_page` (≤50) for history/transactions | `{success,message,data[,meta]}` | none (progress "recalculates") |
| 38 | POST | /customer/membership/renew | @renewal | SANC, api120 (FormRequest requires Customer) | `auto_renew?` | 201 renewal payment request | creates MembershipRenewal/Payment request |
| 39 | POST | /customer/membership/upgrade-request | @upgradeRequest | SANC, api120 | `note?` | 201 | creates MembershipUpgradeRequest |

### 1.4 Customer rewards `/api/reward/*` (L16-24) — all SANC + api120

| # | Method | Path | Method | Params | Response | Side effects |
|---|---|---|---|---|---|---|
| 40 | GET | /reward/profile | RewardController@profile | – | `{success,data{wallet,membership{plan,benefits},settings,campaigns}}` | none (wallet lazily created **UNVERIFIED**) |
| 41 | GET | /reward/wallet | @wallet | – | wallet resource | – |
| 42 | GET | /reward/history | @history (= transactions) | `per_page` ≤100 | resource collection + `success` | – |
| 43 | GET | /reward/transactions | @transactions | `per_page` | same | – |
| 44 | POST | /reward/redeem | @redeem | `points, order_total` | quote only | none ("valid for checkout") |
| 45 | GET | /reward/campaigns | @campaigns | – | collection | – |
| 46 | GET | /reward/settings | @settings | – | `{success,data}` | – |

### 1.5 `/api/v1/customer/*` (L56-81) — same controllers, new paths

| # | Method | Path | Maps to | Throttle |
|---|---|---|---|---|
| 47 | POST | /v1/customer/register | AuthController@register | ca |
| 48 | POST | /v1/customer/login | @login | ca |
| 49 | POST | /v1/customer/forgot-password | @forgetPassword | pr |
| 50 | POST | /v1/customer/resend-otp | @resendOTP | pr |
| 51 | POST | /v1/customer/reset-password | @verifyOTP | pr |
| 52 | GET | /v1/customer/profile | @profile | SANC, api120 |
| 53 | PUT\|POST | /v1/customer/profile | @updateProfile | SANC, api120 |
| 54 | POST | /v1/customer/logout | @logout | SANC, api120 |
| 55 | POST | /v1/customer/logout-all | @logoutAll | SANC, api120 |
| 56 | POST | /v1/customer/profile/password | @updatePassword | SANC, api120 |
| 57-65 | GET×7 / POST×2 | /v1/customer/membership{,/card,/benefits,/history,/transactions,/progress,/statistics,/renew,/upgrade-request} | CustomerMembershipController | SANC, api120 |

Note: v1 has **no** catalog, checkout, orders or reward routes — v1 is only customer auth + membership.

### 1.6 `/api/v1/admin/membership/*` (L82-92) — SANC + api120 + controller closure `User && is_active` (`AdminMembershipController.php:38-41`)

| Method | Path | Method | Side effects |
|---|---|---|---|
| GET | dashboard (`from,to`) | dashboard | – |
| GET / POST | plans | plans / store | create plan |
| PUT / DELETE | plans/{plan} | update / destroy | modify/delete plan |
| GET / POST | benefits | benefits / storeBenefit | create benefit, cache flush |
| PUT / DELETE | benefits/{benefit} | updateBenefit / destroyBenefit | – |
| GET | customers, customers/{customer} | customers / customer | – |
| POST | customers/{customer}/assign | assign | **changes customer membership** |
| POST | customers/{customer}/review | review | re-evaluates membership |
| GET | history, transactions, renewals, upgrade-requests | … | – |
| POST | upgrade-requests/{upgradeRequest}/review | reviewUpgrade | approve/reject upgrade |
| GET | reports, reports/export (CSV) | reports / export | – |
| GET / POST | settings | settings / saveSetting | – |
| PUT / DELETE | settings/{setting} | saveSetting / destroySetting | – |
| POST | payments/{payment}/confirm | confirmPayment | **confirms membership payment** |

(25 routes.)

### 1.7 `/api/v1/admin/rewards/*` (L93-108) — SANC + api120; admin check via `admin()` helper or FormRequest `authorize()` (`AdminRewardController.php:28`; `RewardAdjustmentRequest/RewardCampaignRequest/RewardSettingRequest`)

GET dashboard · GET wallets · GET wallets/{customer} · GET transactions · **POST wallets/{customer}/adjustments** (credit/debit points) · GET settings · **PUT settings** · GET/POST campaigns · PUT/DELETE campaigns/{campaign} · GET expiries · GET reports · GET reports/export. (14 routes.) `adjust`, `saveSettings`, `storeCampaign`, `updateCampaign` check only `instanceof User`, **not `is_active`**, and none check Spatie role permissions — any staff user can credit reward points.

### 1.8 Module routes

| Method | Path | Handler | Auth |
|---|---|---|---|
| GET | /api/manufacturing | closure returning `$request->user()` | `auth:api` (legacy `token` driver on `users.api_token`; `config/auth.php` `api` guard) — `BE/Modules/Manufacturing/routes/api.php` |

`modules_statuses.json` enables `Woocommerce`, `Ecommerce`, `Middleware`, `Manufacturing`, but **only `Modules/Manufacturing` exists on disk**. `Kernel.php:50` registers `customerauth => Modules\Ecommerce\Http\Middleware\CustomerAuth` (class absent — dead/fatal if referenced).

### 1.9 Defined but unrouted
- `Api\PrescriptionController@process` (Tesseract OCR, stores to `public/prescriptions`, hard-coded medicine stub) — **no route** (`Api/PrescriptionController.php`).
- `app/Http/Controllers/Api/V1/` — empty directory.

### 1.10 JSON endpoints in web.php
No web.php route is consumed by web/web-v2/app. All web JSON endpoints are POS-internal AJAX under session+CSRF (`['common','auth','active']`, `web.php:140`), e.g. `sales/lims_product_search` (L145, L625), `sales/getproduct/{id}` (L627), `sales/getcustomergroup/{id}` (L626), `check-batch-availability/...` (L260), `online-sales/*` (L433-440), `pos/product/vision-search` (L142, throttle 20/1, OpenAI vision), `online-orders/show|assign-rider` (L1176-1177), AI draft endpoints. These are the de-facto "POS API" a Nuxt POS would need to replace.

**Unauthenticated dangerous web routes (not API, but same host):** `GET /migrate` runs `artisan migrate` (`web.php:73-77`), `GET /cc` runs `optimize:clear` (L79-100), `GET /update-coupon` (L102), `GET /auto-update-dashboard` (L104), `developer-section` GET/POST (L106-115), `version-upgrade`/`bug-update` POST (L117-123, CSRF-protected only).

### 1.11 Counts
- api.php: **102** route registrations (44 unversioned + 58 under `/v1`, including 39 admin). Plus 1 module route = **103** (matches `deployment-work/api-routes.json`).
- Public (no auth): 20 (12 catalog/service + 3 orders + 5 legacy auth; plus 5 v1 auth = 25 PUB in total). Mutating public: `/checkout`, both `boot-slug/update`, auth/OTP routes.

---

## 2. Authentication model

### 2.1 Guards & providers — `BE/config/auth.php`
- `defaults.guard = web`; `web` = session/`users` provider (`App\Models\User`); `api` = legacy `token` driver on `users` (`hash=false`). **No customer provider/guard**.
- Customers authenticate **only** through Sanctum personal access tokens: `App\Models\Customer` extends `Authenticatable` and `use HasApiTokens` (`app/Models/Customer.php:5,13,15`). `User` also has `HasApiTokens` (`app/Models/User.php:8,12`) but **no code issues User tokens** (grep `createToken` → only `AuthenticationService.php:53`).
- `personal_access_tokens` is polymorphic, so `auth:sanctum` resolves whichever model owns the token; the code differentiates with `instanceof Customer/User` checks in FormRequests/policies (`app/Policies/CustomerPolicy.php`, `CustomerMembershipPolicy.php`, `RewardWalletPolicy.php`).

### 2.2 Sanctum config
- **No `config/sanctum.php` published** → vendor defaults apply (`vendor/laravel/sanctum/config/sanctum.php`): `guard=['web']` (L36), `expiration=null` (L49), stateful = `localhost…,127.0.0.1…, APP_URL host` unless `SANCTUM_STATEFUL_DOMAINS` set. Sanctum v3.3.3, Laravel v10.48.29 (`composer.lock:2119,2440`).
- Customer token TTL comes from `expires_at` on each token: `config/authentication.php:4` `CUSTOMER_TOKEN_TTL_MINUTES` (default 43200 = 30 days). Ability `['customer']` is set (`AuthenticationService.php:49-53`) but **never checked** (no `abilities`/`ability` middleware anywhere).
- **Important quirk:** the `api` middleware group includes `EncryptCookies` + `StartSession` (`Kernel.php:29-34`) but **not** `EnsureFrontendRequestsAreStateful` and **not** CSRF. Sanctum's guard first checks the `web` guard (`vendor/laravel/sanctum/src/Guard.php` `__invoke`): a request to `pos.redpharmabd.com/api/...` carrying the POS staff session cookie is authenticated as that `User` with a `TransientToken` — **with no CSRF verification**. That is the only way the `/api/v1/admin/*` routes can currently be used, and it makes them CSRF-able from any same-site origin (e.g. a page on `redpharmabd.com` posting a form to `pos.redpharmabd.com/api/v1/admin/rewards/wallets/{id}/adjustments`; session `same_site=null` → browser default Lax still sends cookies on same-*site* requests). Risk: High.

### 2.3 Customer token issuance (AuthController + AuthenticationService)
- register → `CustomerIdentityService::register` (normalised BD phone `01[3-9]XXXXXXXX`, unique phone/email) → token → `CustomerAuthenticationEvent('registered')` (`AuthenticationService.php:21-30`).
- login → resolve by email or phone → `Hash::check` → inactive = 403 → token (L32-47). Identifier aliases handled in `LoginCustomerRequest.php:7-11` (web sends `phone_number`; app sends `phone_number` + `device_name: redpharma-flutter`, `app/lib/providers/auth_provider.dart:107-111`).
- Checkout auto-creates customers and returns a token (`OrderController.php:179-181`) with `password_setup_required=true`; password is null until reset.
- Logout deletes current token; logout-all deletes all; password change revokes all other tokens; reset revokes all.
- Events: `CustomerAuthenticationEvent` → `RecordCustomerAuthenticationActivity` listener (`EventServiceProvider`).

### 2.4 OTP / password reset flow
- **Email-only, not SMS.** `sendPasswordResetCode` silently returns if the customer has no email (`AuthenticationService.php:100-102`), sends `mail.otp_code` view via `Mail::send` (L125). Most BD customers registered by phone only → reset impossible for them (UX gap; check whether UI copy promises SMS — **UNVERIFIED**).
- Code: 6 digits, bcrypt-hashed, TTL 15 min, resend cooldown 60 s, max attempts 5 (`config/authentication.php:5-7`).
- **Bug — attempt counter never persists:** on an invalid code the `attempts` increment runs inside the same `DB::transaction` that then throws (`AuthenticationService.php:142-157`), so the increment is rolled back. Only the `pr` throttle (3/min per IP|phone) limits guessing; rotating IPs defeats it (10^6 space, 15-min window). Risk: Medium-High.
- SMS providers exist (`app/SMSProviders`) but are used only by POS sale notifications, not auth.

### 2.5 Staff web login — `BE/app/Http/Controllers/Auth/LoginController.php`
- `Auth::routes()` (`web.php:126`). Custom `login()` (L66-92) accepts `name` (username or email) + password → `auth()->attempt` on web guard → sets plain cookie `login_now` → redirect `/dashboard`. The override **drops the `ThrottlesLogins` lockout** of `AuthenticatesUsers` — no brute-force throttle on staff login. No session regeneration call beyond what `attempt` does (Laravel's `attempt` does not regenerate; `AuthenticatesUsers::sendLoginResponse` would) → **session fixation exposure**.
- `if (!env('USER_VERIFIED') && $request->has('demo_type')) session(['database' => …])` (L69-71) + `Common` middleware switches the MySQL database to `session('database')` (`app/Http/Middleware/Common.php` handle, first lines). If production `.env` lacks `USER_VERIFIED=true`, an unauthenticated visitor can point their session at any database name on the server. **UNVERIFIED** (env not inspected; secrets not read).
- `Active` middleware redirects to `/home` (not JSON 401) — unusable for an SPA.
- Permissions: Spatie roles, checked ad-hoc per controller (`$role->hasPermissionTo('sales-add')`, e.g. `PosVisionSearchController.php:44`, `SaleController.php:95,1117`).

### 2.6 Middleware (BE/app/Http/Kernel.php)
- Global: TrustProxies, **HandleCors**, maintenance, post size, TrimStrings, ConvertEmptyStringsToNull (L9-16).
- `web`: EncryptCookies, queued cookies, StartSession, ShareErrors, VerifyCsrfToken, bindings (L19-27).
- `api`: EncryptCookies, StartSession, throttle:60,1, bindings (L29-34).
- Aliases: auth, auth.basic, can, guest, password.confirm, signed, throttle, verified, `active`, `common`, `superadminauth`, `customerauth` (missing class), `checkSmsBalance` (L36-52).

### 2.7 CORS — `BE/config/cors.php`
`paths=['api/*']`, methods `*`, origins from `CORS_ALLOWED_ORIGINS` (default `http://localhost:5174`; deployment sets `https://redpharmabd.com,https://www.redpharmabd.com`, `DEPLOYMENT-INSTRUCTIONS.md:218`), headers `*`, `supports_credentials=false`. The techrajshahi host's CORS must separately allow web-v2's origin (**UNVERIFIED**). Native Flutter is not subject to CORS.

### 2.8 CSRF
`VerifyCsrfToken::$except` is empty. API routes have no CSRF at all (group lacks the middleware) — fine for bearer tokens, unsafe given session auth leaks into the api group (2.2).

### 2.9 Session — `BE/config/session.php`
driver `file` (L8), lifetime **4320 min (3 days)** (L11), `domain` from `SESSION_DOMAIN` (null → host-only) (L43), `secure` from env default false (L46), `same_site => null` (L52).

### 2.10 What Sanctum SPA cookie auth needs for a Nuxt POS on another subdomain
Assume Nuxt at `posapp.redpharmabd.com`, API at `api.redpharmabd.com` (same registrable domain ⇒ same-site; SPA auth works; a different registrable domain would not).
1. Publish `config/sanctum.php`; set `SANCTUM_STATEFUL_DOMAINS=posapp.redpharmabd.com` (exact host[:port]); keep `guard=['web']`; set a token `expiration` for any bearer tokens.
2. Rebuild the `api` group: add `\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class` **and remove the raw `EncryptCookies`/`StartSession`** so sessions (and CSRF) only apply to requests from stateful origins; bearer clients (web/app) stay stateless. This also closes the CSRF hole in 2.2.
3. `SESSION_DOMAIN=.redpharmabd.com`, `SESSION_SECURE_COOKIE=true`, `same_site='lax'`; consider a **distinct session cookie name** for the API host so the Blade POS (`pos.`) and the public website (`redpharmabd.com`, third-party scripts: Tawk, Meta Pixel) don't share a parent-domain session cookie. Note: a parent-domain cookie is readable/settable by every subdomain — any XSS on the website becomes a POS session risk.
4. `cors.php`: `paths` add `sanctum/csrf-cookie`, `pos/v1/*` (or the auth routes); `supports_credentials=true`; explicit origins (no `*`).
5. Add JSON staff auth endpoints (e.g. `POST /api/pos/v1/auth/login|logout`, `GET /api/pos/v1/me`) using `Auth::guard('web')->attempt` + `session()->regenerate()`, `throttle` per username+IP, returning user + Spatie permissions; replace redirecting `Active`/`Common` middleware with JSON-safe equivalents (401/403, no DB switching, no view sharing).
6. Nuxt/axios: `withCredentials: true`, `withXSRFToken: true` (axios ≥1.6), call `GET /sanctum/csrf-cookie` before login; SSR must forward cookies + `Referer`/`Origin` for Sanctum to treat requests as stateful.
7. Authorise every POS API action via policies/`can:` middleware mapped to existing Spatie permission names; current admin API checks only `instanceof User`.
8. Alternative for shared terminals/offline: Sanctum device tokens for `User` with abilities (`pos:sell`, `pos:refund`), short expiry — not issued anywhere today.

---

## 3. Consumer mapping

Base URLs: **web** → `pos.redpharmabd.com/api`; **web-v2** → `redpharma-api.techrajshahi.com/api/`; **app** → `redpharma-api.techrajshahi.com/api` (some `http://`). Auth: web stores bearer in `localStorage.token` (`web/src/api/config.js:11-16`); web-v2 `localStorage.redpharma_token` (`web-v2/src/services/api.js`); app passes `Authorization: Bearer` headers per call (shared_preferences).

| Endpoint | web | web-v2 | app | Evidence |
|---|:-:|:-:|:-:|---|
| GET /categories | ✔ | ✔ | ✔ | `web/src/composables/useCategories.js:31`; `web-v2/src/services/categoryService.js:28`; `app/lib/screens/home.dart:128`, `categories_screen.dart:84` |
| GET /categories/{slug} | ✔ | ✔ | ✔ | `useCategories.js:79`; `categoryService.js:59`; `categories_screen.dart:435`, `product_detail.dart:149` |
| GET /products | ✔ | ✔ (fallbacks `/product`, `/shop/products`) | – | `useProducts.js:23`; `productService.js:117` |
| GET /products/{slug} | ✔ | ✔ (fallbacks) | ✔ | `useProducts.js:43`; `productService.js:142`; `product_detail.dart:79`, `recently_viewed.dart:31` |
| GET /products/featured | ✔ | – | ✔ | `useProducts.js:64`; `home.dart:77` |
| GET /products/best-selling | ✔ | – | ✔ | `useProducts.js:114`; `home.dart:101` |
| GET /products/suggestions | – | – | ✔ | `search_results.dart:144` |
| GET /search/{q} | ✔ | – | ✔ | `useProducts.js:92`; `search_results.dart:197` |
| GET /shipping-zones | ✔ | – | ✔ | `useCheckout.js:40`; `checkout.dart:72` |
| GET /reward-point-tiers | ✔ | – | – | `useServices.js:42`, `stores/auth.js:69` |
| POST /checkout | ✔ | ✔ | ✔ | `useCheckout.js:113`; `orderService.js:17`; `checkout.dart:163` (sends `reward_points`, L138) |
| GET /orders/{id} | ✔ | – | – | `useCheckout.js:22` |
| GET /tracking/{code} | ✔ | – | – | `useCheckout.js:130` |
| POST /orders/{sale}/reorder | – | ✔ | ✔ | `orderService.js:21`; `reorder_repository.dart:8` |
| POST /customer/login | ✔ | – | ✔ | `stores/auth.js:102`; `auth_provider.dart:103` |
| POST /customer/register | ✔ | – | ✔ | `stores/auth.js:128`; `auth_provider.dart:137,166` |
| GET /customer/profile | ✔ | – | ✔ | `stores/auth.js:160`; `auth_provider.dart:206` |
| POST /customer/profile/update | ✔ | – | ✔ | `stores/auth.js:189`; `api_endpoints.dart:28` / `auth_provider.dart:306` |
| POST /customer/profile/update-password | ✔ | – | ✔ | `stores/auth.js:270`; `auth_provider.dart:257` |
| GET /customer/orders | ✔ | ✔ | ✔ | `stores/auth.js:212`; `orderService.js:6`; `auth_provider.dart:355` |
| GET /customer/orders/{id} | ✔ | ✔ | ✔ | `stores/auth.js:241`; `orderService.js:13`; `order_detail.dart:58` |
| POST /customer/logout | ✔ | – | ✔ | `stores/auth.js:313`; `auth_provider.dart:440` |
| POST /customer/forget-password | ✔ | – | ✔ | `pages/auth/ForgotPassword.vue:210,232`; `auth_provider.dart:388` |
| POST /customer/verify-otp | ✔ | – | ✔ | `ForgotPassword.vue:267`; `auth_provider.dart:417` |
| GET /reward/profile | – | ✔ | ✔ | `rewardService.js:4`; `reward_repository.dart:14` |
| GET /reward/wallet | – | ✔ | – | `rewardService.js:5` |
| GET /reward/transactions | – | ✔ | ✔ | `rewardService.js:6`; `reward_repository.dart:26` |
| GET /reward/history | – | ✔ | – | `rewardService.js:7` |
| GET /reward/campaigns | – | ✔ | – | `rewardService.js:8` |
| GET /reward/settings | – | ✔ | – | `rewardService.js:9` |
| POST /reward/redeem | – | ✔ | ✔ | `rewardService.js:10`; `reward_repository.dart:47` |
| POST /v1/customer/login, register, forgot-password, reset-password | – | ✔ | – | `web-v2/src/services/authService.js:13-25` |
| GET / PUT /v1/customer/profile | – | ✔ | – | `authService.js:29,33` |
| POST /v1/customer/logout | – | ✔ | – | `authService.js:38` |
| GET /v1/customer/membership | – | ✔ | ✔ | `membershipService.js:4`; `screens/auth/membership.dart:39` |
| GET /v1/customer/membership/history | – | ✔ | ✔ | `membershipService.js:5`; `membership.dart:40` |
| GET /v1/customer/membership/progress | – | ✔ | – | `membershipService.js:6` |
| POST /v1/customer/membership/renew | – | ✔ | ✔ | `membershipService.js:7`; `membership.dart:75` |

### 3.1 Called by consumers but NOT in api.php (→ 404)
| Call | Consumer | Evidence |
|---|---|---|
| POST /account-removal-request | web (account deletion — likely a Play Store / policy requirement) | `web/src/stores/auth.js:298` |
| GET /brand/logo, /about, /testimonials, /policies/privacy, /contact, /delivery-areas, /returns | web (helpers defined; no component calls them → dead code) | `web/src/composables/useServices.js:32-38` |
| GET/POST /cart, PUT/DELETE /cart/{id} | web-v2 (`cartService`); web declares `CART` constant only | `web-v2/src/services/cartService.js:5-17`; `web/src/api/endpoints.js` |
| POST /track-order, POST /request-order | web-v2 | `web-v2/src/services/orderService.js:24,28` |
| GET /product, /shop/products (+/{slug}) | web-v2 fallbacks | `productService.js:117,142` |
| /banners, /pages/privacy-and-policy, /pages/refund-and-returns | app (constants declared, not referenced) | `app/lib/constants/api_endpoints.dart:17-19` |
| Pusher channel `online-orders` | web (composable present, no component uses it) | `web/src/composables/usePusher.js` |

Payload mismatches: web/app login & forgot use `phone_number`/`mobile` — accepted via aliases (`LoginCustomerRequest.php:9`, `ForgotPasswordRequest.php:9`). web checkout sends `user_id`, `warehouse_id`, `sale_status`, totals — ignored by the server (server prices) (`useCheckout.js:80-110`).

### 3.2 Endpoints nobody calls
`/categories/boot-slug/update`, `/products/boot-slug/update` (maintenance — should not be public), `/customer/logout-all`, `/customer/resend-otp`, `/v1/customer/resend-otp`, `/v1/customer/logout-all`, `/v1/customer/profile/password`, membership `card`, `benefits`, `statistics`, `transactions`, `upgrade-request` (legacy + v1, except as listed), legacy `/customer/membership/*` entirely (app/web-v2 use v1), **all 39 `/v1/admin/*` routes** (POS Blade uses web routes `customer-ecosystem/membership|rewards` instead — `web.php:1183-1193`), `/api/manufacturing`.

---

## 4. Frameworks & versions

| Project | Stack (from manifests) |
|---|---|
| Backend (both snapshots) | PHP `^8.2`; laravel/framework `^10.15` → **v10.48.29** locked; laravel/sanctum **v3.3.3**; laravel/ui ^4; spatie/laravel-permission ^5.8; nwidart/laravel-modules ^8.3; intervention/image ^3; maatwebsite/excel; yajra datatables ^10; guzzle ^7; plus SaaS-template payment/SMS SDKs (below) — `BE/composer.json:10-36`, `composer.lock:2119,2440`. Front-end build for Blade via `webpack.mix.js` |
| web | Vue ^3.5.18, vue-router ^4.5.1, **pinia ^3.0.3**, vite ^7.1.2 (+@vitejs/plugin-vue ^6), **tailwindcss ^4.1.12** (@tailwindcss/postcss), PrimeVue ^3.53 + PrimeFlex, axios ^1.12, vue-i18n ^11, pusher-js ^8.5, swiper, notivue — `web/package.json`. Hosted with `vercel.json` present + Apache `.htaccess` per deployment note |
| web-v2 | Vue ^3.5.13, vue-router ^4.5.0, **pinia ^2.3.1**, vite ^5.4.10 (@vitejs/plugin-vue ^5.2.3), **tailwindcss ^3.4.17**, axios ^1.9, lucide-vue-next — `web-v2/package.json` |
| app | Dart SDK `^3.9.2`; **`http` ^1.1.0** (no dio); provider ^6.0.5; shared_preferences ^2.5.4; cached_network_image; image_picker; flutter_typeahead; package_info_plus — `app/pubspec.yaml`. Note: `app/redpharma-upload.jks` and `.pem` (signing material) sit in the project folder — not opened; ensure they're not committed |

---

## 5. Integrations, jobs, schedule, storage

### 5.1 Third-party services (config key **names** only)
| Service | Where | Config / source of credentials |
|---|---|---|
| **OpenAI** (Responses API `api.openai.com/v1/responses`) — POS camera product vision, stock forecast, AI draft purchase orders | `app/Services/OpenAI/ProductVisionService.php:21-72`, `StockForecastService.php:108-131`, `AiDraftOrderGenerationService.php:202` | `services.openai.api_key` (`OPENAI_API_KEY`), `vision_model` (`OPENAI_VISION_MODEL`), `forecast_model` (`OPENAI_FORECAST_MODEL`, default gpt-4.1-mini), `order_generation_time` (`AI_ORDER_GENERATION_TIME`), `order_created_by_user_id` (`AI_ORDER_CREATED_BY_USER_ID`) — `config/services.php:22-27` |
| **Pusher** broadcasting | `app/Events/OnlineOrderEvent.php` (ShouldBroadcast, **public** channel `online-orders`, event `online.order.placed`, payload includes customer name + phone); web client `usePusher.js` (cluster ap2) | `PUSHER_APP_KEY/SECRET/ID/CLUSTER`, `BROADCAST_DRIVER` (default `null`) — `config/broadcasting.php:18,33-39` |
| **SMS** (POS notifications, not auth) | `app/Services/SmsService.php` → `TonkraSms` (sms.tonkra.com), `ReveSms`, `BdBulkSms`; also `SmsToday`, `Clickatell`, `Twilio` classes in `app/SMSProviders/` | Credentials in DB table `external_services` (`type='sms'`, `SaleController.php:1666,1801`); `CLICKATELL_API_KEY` env; `checkSmsBalance` middleware |
| **Mail** | password-reset OTP (`AuthenticationService.php:125`), 16 Mailables in `app/Mail`, `DailyQuote` command | `MAIL_DRIVER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION, MAIL_FROM_ADDRESS, MAIL_FROM_NAME`; mailgun/postmark/ses keys in services.php |
| **Payment gateways** | `app/Services/PaymentService.php` switch: stripe, paypal, razorpay, bkash, sslcommerz (+ others) — but **`app/Payment/` directory does not exist** → these classes are missing (fatal if invoked). Inline Stripe/PayPal in `OnlineOrderController.php:2130-2186, 2333-2370`; Pesapal via `external_services` (`SaleController.php:4845-4940`). Composer: stripe-php ^7.57, srmklive/paypal ^3, razorpay ^2.9, xendit-php ^5, twilio/sdk ^6.10 | Keys stored in DB (`pos_setting.stripe_secret_key` etc., `external_services`). Website checkout itself is **cash-on-delivery only** (`payment_status=0`, no gateway call in `Api\OrderController@store`) |
| **WooCommerce** | `automattic/woocommerce ^3.1` in composer; `Woocommerce` enabled in `modules_statuses.json` but module folder absent | – |
| **Courier APIs** | `CourierController` exists; no outbound HTTP found (courier appears to be a local master table) — **UNVERIFIED** |
| **Tesseract OCR** | `thiagoalessio/tesseract_ocr` used by unrouted `Api\PrescriptionController` (package not in composer.json require → **UNVERIFIED** install) |
| Push notifications (FCM) / Cloudinary | **None found** |
| Front-end 3rd parties | web: Tawk.to (`useTawk.js:11`), Meta Pixel (deployment note), WhatsApp/Messenger links |
| ZATCA, Keygen, auto-update (`config/auto_update.php`), SaaS landlord (`Common.php`) | SalePro template remnants |

### 5.2 Queues / jobs / events
- `QUEUE_CONNECTION` default **`sync`** (`config/queue.php:16`); `app/Jobs/` empty. ShouldQueue: `ReviewCustomerMembership` listener, Mailables, `SendNotification` — all run inline under `sync`.
- `OnlineOrderEvent` is `ShouldBroadcast` (not `ShouldBroadcastNow`) and dispatched **inside the checkout DB transaction** (`OrderController.php:174`). With `sync` queue + Pusher driver, a Pusher outage throws → transaction rolled back → **checkout fails** (**UNVERIFIED** at runtime; depends on `BROADCAST_DRIVER`).
- Events (15) in `app/Events`; registered listeners only: `CustomerAuthenticationEvent → RecordCustomerAuthenticationActivity`, `MembershipReviewRequested → ReviewCustomerMembership` (`app/Providers/EventServiceProvider.php` `$listen`). Others are fired without listeners (or auto-discovery **UNVERIFIED**). No `app/Observers`.

### 5.3 Scheduled tasks — `BE/app/Console/Kernel.php:35-52`
| Command | Schedule | Notes |
|---|---|---|
| `purchase:auto` | every 5 min | AutoPurchase |
| `dsoalert:find` | daily 00:00 | |
| **`reset:db`** | **every minute** | `ResetDB.php`: **TRUNCATEs every table** then imports `public/salepro_data.sql` (file absent in BE → tables are wiped and import fails). Also in old `backend/` (L38). The deployment note warns about this (`DEPLOYMENT-INSTRUCTIONS.md:267`) |
| `quote:daily` | every minute ("Testing Purpose") | emails user #1 from a hard-coded personal gmail sender |
| `membership:review` 01:15, `membership:expire` 01:45, `membership:benefits-refresh` 02:00, `membership:renewal-reminders` hourly, `membership:statistics` monthly, `membership:annual-reset` yearly, `rewards:process` hourly | withoutOverlapping | Membership/reward lifecycle **depends on a scheduler that cannot safely be enabled** until `reset:db`/`quote:daily` are removed. Implies production probably runs no `schedule:run` cron today → reward expiry/pending→available, membership expiry are not happening (**UNVERIFIED**) |
| `orders:generate-ai-drafts` | daily at `AI_ORDER_GENERATION_TIME` | withoutOverlapping(360) |

### 5.4 File storage / images
- `FILESYSTEM_DRIVER` default `local` (`config/filesystems.php:16`); disks local/public/s3 (s3 keys `AWS_*` unused **UNVERIFIED**).
- POS uploads write straight into `public/` (`$file->move(public_path('images/...'))`, ~72 occurrences, e.g. `BrandController.php:50`, `BillerController.php:73`, `AdjustmentController.php:192`). Customer avatars on `public` disk (`CustomerProfileService.php:22`). Product image URLs returned relative; web resolves them against the POS origin (deployment note L207) — in a split API/POS world images must be served from a stable asset origin.
- intervention/image ^3 available.

---

## 6. Backward-compatibility constraints & versioning recommendation

### 6.1 Hard constraints
1. **The Android app hard-codes the host and paths** (9 literals + constants, some `http://`). Any host move needs (a) the old host to keep serving `/api/*` with identical shapes until the app update reaches most installs, or (b) a reverse proxy/301 — note Flutter `http` follows redirects for GET only, **not POST** (checkout/login would break on a redirect).
2. Response shape duplication is load-bearing: auth endpoints return both `data.{user,token}` and top-level `user/token` (`AuthController.php:130-147`); profile duplicates every key (L71-80); consumers read either. Some endpoints return bare arrays (`/products/suggestions`), raw paginators (`/products`), or `{success,tiers,settings,usages}`. Legacy routes must stay byte-compatible.
3. Field aliases (`phone`/`phone_number`/`mobile`, `currentPassword`/`confirmPassword`, `reward_points`/`used_points`, `shipping_cost`/`delivery_charge`) must be kept on legacy routes.
4. Customers are identified by phone at checkout (no auth on `/checkout`); guest checkout + auto-account creation must keep working for web/app.
5. Two client generations: web uses legacy `/customer/*`; web-v2 uses `/v1/customer/*` for auth/membership but legacy for orders/rewards/catalog; app mixes both. "v1" is already a public contract (partially).
6. Shared Laravel app = shared sessions/DB with the Blade POS; POS Blade JSON endpoints (web.php) must keep working while a Nuxt POS is built.
7. Two deployments (pos.redpharmabd.com vs redpharma-api.techrajshahi.com) — consolidating means picking one DB of record and migrating customers/tokens/orders from the other (tokens are DB rows; a DB switch logs every app user out).

### 6.2 Recommended approach
- **`/api/*` (legacy, frozen):** keep all 44 unversioned + existing `/api/v1/customer/*` routes exactly as-is; add `Deprecation`/`Sunset` headers and per-route usage logging (client id from `User-Agent`/`X-Client` header). Fix security bugs in place (IDORs, reward theft, OTP counter, boot-slug) — these are behavioural fixes, not shape changes.
- **`/api/v1/*` (customer channel: website + app):** extend the *existing* v1 namespace (it is already used by web-v2/app) rather than inventing v2: add `v1/catalog/*` (products, categories, search, suggestions), `v1/orders` (authenticated + guest checkout with idempotency key), `v1/tracking/{code}`, `v1/rewards/*`, `v1/account-removal-request`, `v1/content/*` (banners/pages the app expects). Single envelope `{success,message,data,meta,errors}`, API Resources, FormRequests, enforce `abilities:customer`, per-route throttles. Host: one canonical `api.redpharmabd.com`, with `pos.redpharmabd.com/api` and `redpharma-api.techrajshahi.com/api` proxied to it during transition.
- **`/api/pos/v1/*` (staff channel: Nuxt POS):** separate route file, `EnsureFrontendRequestsAreStateful` + Sanctum SPA cookie (2.10) for browsers and optional `User` device tokens with abilities for terminals; Spatie-permission policies on every route; idempotency keys on sale/payment/return; warehouse-scoped stock with row locks. Move `/api/v1/admin/membership|rewards/*` under `/api/pos/v1/admin/...` (keep old paths as aliases; nobody calls them today, so they can be retired quickly).
- Remove session from the generic `api` group; give each channel its own middleware group (`api.customer`, `api.pos`).
- Breaking changes → new major (`/api/v2`, `/api/pos/v2`), never silent shape changes (consistent with `UNIFIED_SYSTEM_ARCHITECTURE.md:359`).

---

## 7. Top risks (ranked)

| # | Risk | Evidence | Severity |
|---|---|---|---|
| 1 | Scheduler contains `reset:db` every minute (truncates all tables). Enabling cron for membership/rewards wipes production | `Console/Kernel.php:39`, `ResetDB.php` | Critical (latent) |
| 2 | **Guest checkout can spend another customer's reward points**: `/checkout` is unauthenticated, finds the customer by phone (`CustomerIdentityService.php:134-137`) and honours `reward_points` from the payload (`CheckoutPricingService.php:47-48`, `MembershipRewardService.php:93-107`) | as cited | Critical |
| 3 | Order IDORs: public `GET /api/orders/{id}` returns order + customer PII; `GET /api/customer/orders/{id}` has no ownership check | `OrderController.php:215-241, 336-346` | High |
| 4 | Session leaks into `api` group → staff session authenticates `/api/v1/admin/*` without CSRF; admin API has no role/permission checks | `Kernel.php:29-34`, Sanctum `Guard.php`, `AdminRewardController.php:28-39` | High |
| 5 | Unauthenticated `GET /migrate`, `/cc`, `/products|categories/boot-slug/update` | `web.php:73-100`, `api.php:113,123` | High |
| 6 | OTP attempt counter rolled back → brute-forceable password reset; OTP only via email | `AuthenticationService.php:142-157, 100-102` | Medium-High |
| 7 | Split deployments: app/web-v2 on techrajshahi host, web on pos host → possible divergent DBs, orders, reward balances | §0 | High (UNVERIFIED) |
| 8 | Staff login has no throttle, no session regenerate; `demo_type` DB switching if `USER_VERIFIED` falsy | `LoginController.php:66-92`, `Common.php` | Medium-High (UNVERIFIED env) |
| 9 | Password change without current password; customer token ability never enforced; 30-day tokens | `UpdateCustomerPasswordRequest.php:20` | Medium |
| 10 | Checkout: no route throttle, no idempotency, stock decremented without row locks; broadcast inside transaction; hard-coded user/warehouse/currency ids | `OrderController.php:56-176` | Medium |
| 11 | Public Pusher channel broadcasts customer name + phone for every web order | `OnlineOrderEvent.php:38-53` | Medium |
| 12 | Missing code: `app/Payment/*`, `Modules/Ecommerce|Woocommerce`, `/account-removal-request` route | §3.1, §5.1 | Medium |
| 13 | App uses cleartext `http://` for catalog calls | `app/lib/screens/home.dart:77,101,128` | Low-Medium |
