# Phase 0 hotfix: production containment for the legacy POS

**Status: prepared and tested locally. Not applied to production.** Applying it needs your approval and a maintenance window of about 15 minutes.

This package closes the critical holes found in the audit (`docs/known-issues.md`, *Immediate production actions*). It changes no business logic: sales, stock, purchases and reports behave exactly as before.

## What's in the package

| Path | Purpose |
|---|---|
| `patches/phase0-hotfix.patch` | One unified diff against the local `backend - ocr pos/` snapshot (9 files, +188/−118) |
| `patched-files/` | The same 9 files in their final form, as a reference for manual merging |
| `verify-production.sh` | Non-destructive HTTP checks to run before and after deployment |

## What the patch changes

| # | File | Change | Closes |
|---|---|---|---|
| 1 | `routes/web.php` | Handlers for `/update-coupon`, `/migrate`, `/cc`, `/auto-update-dashboard`, `developer-section/*`, `/new-release`, `/bugs`, `version-upgrade`, `bug-update`, `saas/install/*`, `woocommerce-install`, `ecommerce-install`, `setting/empty-database` and `phpfileinfo` now return **404**. **Route names are kept**, so the Blade `route()` calls in the header, dashboard and addon pages don't break | Unauthenticated DB wipe, migrations on demand, code and `.env` overwrite, phpinfo leak |
| 2 | `CouponController::updateCoupon` | Body replaced with `abort(404)`, as defense in depth if the route is ever re-added | Same |
| 3 | `app/Console/Kernel.php` | Removed `reset:db` (every minute), `quote:daily` (test mail every minute) and `purchase:auto` (an automatic purchase every 5 minutes) from the schedule | The DB gets wiped the moment cron is enabled |
| 4 | `ResetDB.php` | Refuses to run unless `ALLOW_DEMO_DB_RESET=true` is set and the environment is not `production` | The same, even if run by hand |
| 5 | `RoleController` | `store`, `update`, `setPermission` and `destroy` require Admin or Owner (role_id ≤ 2), the same rule `index`, `edit` and `permission` already used | A cashier granting themselves all permissions |
| 6 | `UserController` | `store`, `update`, `destroy` and `deleteBySelection` now check `users-add`, `users-edit` and `users-delete` (Admin and Owner always pass). Non-admins can't assign the Admin or Owner role or modify Admin or Owner accounts. `profile`, `profileUpdate` and `changePassword` work only on your own account (admins can open any). `profileUpdate` accepts only `name`, `email`, `phone` and `company_name` | A user promoting themselves to admin through the profile form |
| 7 | `SettingController::backup` | Admin or Owner only. The dump is now built in `storage/app/private-backups/`, streamed as a download, then deleted. It is **no longer written to `public/`** | This is how `salepro_backup_*.sql` ended up downloadable |
| 8 | `public/.htaccess` | Blocks dotfiles; `.sql`/`.zip`/`.gz`/`.bak`/`.log`; `delete.php` and `debug_*.txt`; and script execution in upload folders (`images/`, `documents/`, `product/`, `sale/` and so on) | Backup download; uploaded web shells |
| 9 | root `.htaccess` | The same rules, for when the web root is the Laravel project root instead of `public/`. Also blocks `app/`, `config/`, `storage/logs` and similar paths, `composer.json`, and direct `.php` requests other than the front controllers | Source and config disclosure |

### Local verification done (2026-11-23)
- `php -l` passes on all patched PHP files (PHP 8.2.12).
- `git apply --check` passes against `backend - ocr pos/`, and applying the patch reproduces `patched-files/`.
- Comparing `route:list` before and after: **914 → 914 routes, no route names lost**. Only the targeted routes changed handler.
- Real HTTP dispatch through the Laravel kernel:
  - `/update-coupon`, `/migrate`, `/cc`, `/auto-update-dashboard`, `/developer-section`, `/new-release` and `/bugs` return **404**.
  - The POST variants return 419 (CSRF) before reaching the handler.
  - Inside the login-protected group, `empty-database`, `phpfileinfo`, the installers and SaaS steps run `abort(404)`.
- Authorization helpers, tested with Admin, Owner and staff users:
  - Staff is **denied** assigning Admin, editing an Owner, opening someone else's profile, and all role writes.
  - Staff is **allowed** to edit staff accounts and use their own profile.
  - Admin and Owner are allowed everything.
- `php artisan reset:db` now exits 1 with "disabled". It refuses before touching anything.

### Not verified locally
- **The `.htaccess` rules.** No Apache or LiteSpeed server is available here. They must be checked on the server with `verify-production.sh`.
- **Controller paths that need a database.** `hasPermissionTo` and the backup download were exercised by code review and helper tests only. Smoke-test them after deployment (see step 6).

## ⚠ Production is not the same code as this patch's baseline

The live POS runs a different `routes/web.php`: `/pos` works live, while the local code puts it at `/report/pos`. **Don't upload `patched-files/` over production blindly.** Instead:
1. Download the live versions of the 9 files.
2. Run `git apply --check patches/phase0-hotfix.patch` against them.
3. If a hunk fails, merge it by hand using `patched-files/` as the reference. Every change is marked with a `PHASE 0 HOTFIX` comment, so it's easy to find.

The `.htaccess` rules (items 8 and 9) don't depend on the PHP code. **They can go live first, on their own, within minutes.**

## Deployment steps (after your approval)

1. **Backup.** Take a full DB export from the hosting panel (not the app's backup page) and a copy of the live app folder. Store both outside any public web root.
2. **Record the current state.** Run `./verify-production.sh before`. Expect the `.sql` checks to show `200` for now.
3. **Web-server rules first.** Merge the `PHASE 0 HOTFIX` block into the live `public/.htaccess`, and into the root `.htaccess` if the web root is the project root. Then run `./verify-production.sh after`: the `.sql` and `delete.php` checks must now be `403`, and `/login` and `/pos` must still answer `200` or `302`.
4. **Delete the leaked files** from the live `public/`: `salepro_backup_1689056748.sql`, `salepro_backup_1738037751.sql`, `delete.php`, `debug_payroll_params.txt`, and any other `*_backup_*.sql` or `*.zip` the backup page created. Use the file manager and **don't open delete.php in a browser**: it executes.
5. **Code patch.**
   - `php artisan down`.
   - Apply or merge the patch.
   - `php artisan route:clear && php artisan config:clear && php artisan view:clear` (run `route:cache` again only if production used it).
   - `php artisan up`.
6. **Verify on the server (read-only):**
   - `php artisan route:list --path=update-coupon` and `--path=migrate` must show **Closure**. **Never test these two URLs over HTTP.** If the patch did not take, opening them wipes the database or runs migrations.
   - `php artisan schedule:list` must not list `reset:db`, `quote:daily` or `purchase:auto`.
   - In a browser: log in as Admin, open the user list and role permissions, save a role unchanged, and open your profile. Log in as a staff user: role save must be refused (403), and opening another user's profile must be refused.
   - Make one POS test sale and void it, following your usual procedure, to confirm the sales path is untouched.
7. **Rotate secrets** that the public backups may have exposed: staff passwords (force a reset for Admin and Owner first), and SMTP, SMS and payment-gateway credentials stored in the DB. Change the SMS key that is hard-coded in `app/SMSProviders/SmsToday.php`/`Twilio.php` at the provider.
8. **Check the access logs** for past requests to `salepro_backup_`, `update-coupon`, `migrate`, `developer-section`, `version-upgrade` and `bug-update`. That shows whether anything was exploited.

## Rollback
- **Code:** restore the 9 files from the step 1 copy, then `php artisan route:clear`.
- **`.htaccess`:** remove the `PHASE 0 HOTFIX` block.
- No database changes are made by this package, so no DB rollback is needed.

## Deliberately not in this package (next steps, need decisions)
- The public `/api/products|categories/boot-slug/update` routes and the order IDOR and reward-theft fixes on the customer API. They are only relevant once the API is deployed on this host, which the pending 2026-11-20 release does. Fix them **in that release before it goes out**.
- Server-side permission checks on all the other write endpoints (sales, purchases and so on). That is Phase 2 work in `docs/migration-plan.md`.
- Re-enabling `purchase:auto`, which needs a business decision.

## Production baseline recorded 2026-11-23 (`verify-production.sh before`)
| Path | Status | Meaning |
|---|---|---|
| `/salepro_backup_1689056748.sql`, `/salepro_backup_1738037751.sql` | **200** | Database backups publicly downloadable |
| `/debug_payroll_params.txt` | **200** | Debug file exposed |
| `/new-release`, `/bugs` | **200** | Vendor auto-update pages are deployed, so `/update-coupon` is very likely live as well |
| `/.env`, `/.git/config` | 403 | Already blocked by the host |
| `/developer-section` | 404 | `product_mode` is not DEVELOPER |
| `/login` 200, `/pos` 302, `/` 302 | – | App healthy |
