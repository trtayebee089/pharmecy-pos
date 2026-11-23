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
| `app/pages/` | login, dashboard, pos, products (list, add, edit with unit ladder), inventory (batches, adjustments), sales (list, detail with per-batch returns and void), purchases (list, receive with batch/expiry), customers, cash registers (open/close with note count), admin (users, roles matrix, settings). Any other sidebar route shows an honest "planned" page with its phase and the legacy menu it replaces |

## POS keyboard map
F2 search/scan · arrows move · Enter add (an exact barcode/SKU adds instantly) · F4 customer · F7 held sales · F8 hold · F9 pay · Ctrl+Enter complete · Ctrl+K command palette · F1 shortcuts

## Not done yet (next steps)
- Laravel API `/api/pos/v1` (phase A+), then switch off the mocks.
- Remaining modules: held-sales page, purchase returns, stock counts, transfers, online orders/delivery, loyalty, finance (accounts, expenses, income), HR, reports and the catalog lookup pages (sidebar items marked with their phase).
- Frontend tests (Vitest + Playwright) for cart math, keyboard flow and permissions.
