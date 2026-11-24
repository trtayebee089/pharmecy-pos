# Red Pharma POS (Nuxt)

The back-office and counter UI of the rebuilt Red Pharma system. It talks only to the Laravel API (`/api/pos/v1`). Business rules (prices, FEFO batch allocation, stock, discounts, totals) are enforced by the API; the UI only previews them.

**Status:** UI foundation. The API does not exist yet, so data comes from the **development mock layer** (`app/mocks/`, enabled by `NUXT_PUBLIC_USE_MOCKS=true`). Production builds must run with `NUXT_PUBLIC_USE_MOCKS=false`.

## Stack
Nuxt 4 (SPA mode) · Vue 3 · TypeScript (strict) · Tailwind CSS 4 · Pinia · VueUse · GSAP 3 · lucide icons

## Run

```bash
npm install
cp .env.example .env        # NUXT_PUBLIC_USE_MOCKS=true for local UI work
npm run dev                 # http://localhost:3100  (mock login: any username, password ≥ 4 chars)
npm run typecheck
npm run generate            # static build in .output/public
```

## Structure

| Path | Purpose |
|---|---|
| `app/config/navigation.ts` | Sidebar generated from the verified legacy inventory, with permission, rebuild phase and legacy menu per item |
| `app/composables/useApi.ts` | Central API client: Sanctum cookie session, CSRF, 401/419/422 handling, idempotency keys |
| `app/services/api.ts` | Domain API modules (dashboard, products, inventory, sales, purchases, customers). The only place that knows about mocks |
| `app/composables/useMotion.ts` | GSAP motion toolkit: stagger reveal, count-up, path draw, fly-to-cart, magnetic buttons, spotlight, page transitions. All effects respect `prefers-reduced-motion` |
| `app/stores/` | `auth` (`/me`, permissions, warehouse), `cart` (POS preview: units, FEFO, totals, hold/resume), `ui` |
| `app/components/ui/` | Design system: Button, Input, Badge, Modal, Popover, DataTable, Segmented, StatCard, EmptyState, Toaster, ExpiryBadge… |
| `app/components/pos/` | Counter: ProductCard, CartLine, PaymentModal (multi-method, change/due), CustomerPicker, 80 mm Receipt |
| `app/pages/` | **Every sidebar module has a working screen** (60+ routes): counter, sales/returns/held, online orders + fulfilment board, products (list/add/edit/import), catalog masters, barcode labels, stock, batches, adjustments, counts, transfers, purchases (receive/detail/returns/AI reorder), suppliers, customers (profile, groups, due collection), loyalty, delivery, finance (registers, accounts, transfers, expenses, income, balance sheet), 9 reports, HR (employees, attendance, holidays, payroll), admin (users, roles, warehouses, settings) and the user account pages. Unknown routes show a 404 page |
| `app/components/ui/CrudPage.vue`, `ReportPage.vue` | Config-driven templates for master-data CRUD and reports (date range, KPIs, trend, totals, CSV export, print) |

## POS keyboard map
F2 search/scan · arrows move · Enter add (an exact barcode/SKU adds instantly) · F4 customer · F7 held sales · F8 hold · F9 pay · Ctrl+Enter complete · Ctrl+K command palette · F1 shortcuts

## Not done yet (next steps)
- Laravel API `/api/pos/v1` (phase A+), then switch off the mocks.
- Expense/income attachment upload (UI validates only).
- Code 128 barcode labels are encoded per spec but not yet test-scanned on a physical scanner.
- Frontend tests (Vitest + Playwright) for cart math, keyboard flow and permissions.
