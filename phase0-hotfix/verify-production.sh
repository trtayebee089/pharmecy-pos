#!/usr/bin/env bash
# Phase 0 hotfix: non-destructive production checks.
# Uses HEAD/GET only and never downloads file bodies.
# It deliberately NEVER requests /update-coupon, /migrate or /cc: on an unpatched
# server those URLs wipe the database or run migrations. Verify those with
# `php artisan route:list --path=...` on the server instead.
#
# Usage: ./verify-production.sh [before|after] [base-url]
set -u
MODE="${1:-after}"
BASE="${2:-https://pos.redpharmabd.com}"

check() { # label, method, path, expected-after
  local code
  code=$(curl -s -o /dev/null -m 20 -X "$2" -I -w '%{http_code}' "$BASE$3" 2>/dev/null)
  [ "$2" = GET ] && code=$(curl -s -o /dev/null -m 20 -r 0-0 -w '%{http_code}' "$BASE$3" 2>/dev/null)
  local verdict="info"
  if [ "$MODE" = after ]; then
    case " $4 " in *" $code "*) verdict="OK";; *) verdict="FAIL";; esac
  fi
  printf '%-5s %-45s %s (expect after: %s)\n' "$verdict" "$3" "$code" "$4"
}

echo "== $MODE checks against $BASE"
# Leaked files: must be blocked or gone
check "backup 1"   HEAD /salepro_backup_1689056748.sql  "403 404"
check "backup 2"   HEAD /salepro_backup_1738037751.sql  "403 404"
check "any sql"    HEAD /phase0-probe.sql               "403"
check "env"        HEAD /.env                           "403 404"
check "git"        HEAD /.git/config                    "403 404"
check "debug txt"  HEAD /debug_payroll_params.txt       "403 404"
check "composer"   HEAD /composer.json                  "403 404"
check "laravel log" HEAD /storage/logs/laravel.log      "403 404"
# delete.php: HEAD would EXECUTE it if present and unblocked. Only check it AFTER
# the .htaccess block is live (it must answer 403 without running).
[ "$MODE" = after ] && check "installer" HEAD /delete.php "403 404"
# Harmless vendor pages (GET on the unpatched server just renders a page)
check "new-release" GET /new-release                    "404"
check "bugs"        GET /bugs                           "404"
check "dev section" GET /developer-section              "404"
# The app must still work
check "login page"  GET /login                          "200"
check "pos"         GET /pos                            "200 302"
check "home"        GET /                               "200 302"
