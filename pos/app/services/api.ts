/**
 * Domain API modules for /api/pos/v1. UI components call these functions only.
 * When useMocks is on (development), the same functions resolve from app/mocks.
 */
import type {
  BatchRow, Customer, DashboardSummary, ListQuery, Paginated, PaymentLine, Product, PurchaseSummary, SaleSummary,
} from '~/types/domain'

export function paginate<T>(rows: T[], q: ListQuery, match: (row: T, s: string) => boolean): Paginated<T> {
  const s = (q.search ?? '').trim().toLowerCase()
  const filtered = s ? rows.filter(r => match(r, s)) : rows
  const perPage = q.perPage ?? 15
  const page = Math.max(1, q.page ?? 1)
  return {
    data: filtered.slice((page - 1) * perPage, page * perPage),
    meta: { page, perPage, total: filtered.length, lastPage: Math.max(1, Math.ceil(filtered.length / perPage)) },
  }
}

async function mocks() {
  return import('~/mocks/seed')
}

export function useDashboardApi() {
  const api = useApi('dashboard')
  return {
    async summary(warehouseId: number, days = 14): Promise<DashboardSummary> {
      if (api.useMocks) { await mockDelay(500); return (await mocks()).dashboard(days) }
      return api.get('/dashboard/summary', { warehouse_id: warehouseId, days })
    },
  }
}

export function useProductsApi() {
  const api = useApi('catalog')
  return {
    /** POS search: name, strength, generic, SKU or exact barcode. */
    async search(term: string, warehouseId: number, signal?: AbortSignal): Promise<Product[]> {
      if (api.useMocks) {
        await mockDelay(120)
        const s = term.trim().toLowerCase()
        const { products } = await mocks()
        if (!s) return products.slice(0, 24)
        return products.filter(p =>
          p.sku === s || p.name.toLowerCase().includes(s) || (p.generic ?? '').toLowerCase().includes(s)
          || (p.strength ?? '').toLowerCase().includes(s) || p.manufacturer.toLowerCase().includes(s)).slice(0, 40)
      }
      return api.get('/catalog/products/search', { q: term, warehouse_id: warehouseId }, signal)
    },
    async list(q: ListQuery): Promise<Paginated<Product>> {
      if (api.useMocks) {
        await mockDelay()
        const { products } = await mocks()
        let rows = products
        const f = q.filters ?? {}
        if (f.dosageForm) rows = rows.filter(p => p.dosageForm === f.dosageForm)
        if (f.stock === 'low') rows = rows.filter(p => p.stockBase <= p.reorderLevelBase)
        if (f.rx === true) rows = rows.filter(p => p.requiresPrescription)
        return paginate(rows, q, (p, s) => `${p.name} ${p.strength} ${p.generic} ${p.sku} ${p.manufacturer}`.toLowerCase().includes(s))
      }
      return api.get('/catalog/products', { ...q, ...q.filters })
    },
  }
}

export function useInventoryApi() {
  const api = useApi()
  return {
    async batches(q: ListQuery): Promise<Paginated<BatchRow>> {
      if (api.useMocks) {
        await mockDelay()
        const { batchRows } = await mocks()
        const within = Number(q.filters?.within ?? 0)
        let rows = [...batchRows].sort((a, b) => a.expiryDate.localeCompare(b.expiryDate))
        if (q.filters?.within === 'expired') rows = rows.filter(b => daysUntil(b.expiryDate) < 0)
        else if (within) rows = rows.filter(b => { const d = daysUntil(b.expiryDate); return d >= 0 && d <= within })
        return paginate(rows, q, (b, s) => `${b.productName} ${b.batchNo} ${b.manufacturer}`.toLowerCase().includes(s))
      }
      return api.get('/inventory/batches', { ...q, ...q.filters })
    },
  }
}

export function useSalesApi() {
  const api = useApi('sales')
  return {
    async list(q: ListQuery): Promise<Paginated<SaleSummary>> {
      if (api.useMocks) {
        await mockDelay()
        const { sales } = await mocks()
        let rows = sales
        if (q.filters?.status) rows = rows.filter(s => s.paymentStatus === q.filters!.status || s.status === q.filters!.status)
        if (q.filters?.channel) rows = rows.filter(s => s.channel === q.filters!.channel)
        return paginate(rows, q, (x, s) => `${x.number} ${x.customer} ${x.cashier}`.toLowerCase().includes(s))
      }
      return api.get('/sales', { ...q, ...q.filters })
    },
    /**
     * Completes a sale. The API re-prices every line, allocates batches FEFO,
     * validates stock/expiry/discount limits and returns the authoritative totals.
     * The idempotency key makes double-clicks / retries safe.
     */
    async complete(payload: {
      warehouseId: number
      customerId: number
      lines: { productId: number; unitId: number; qty: number; batchId?: number; discount?: number; custom?: { name: string; code: string; price: number } }[]
      orderDiscount: number
      payments: PaymentLine[]
    }, idempotencyKey: string): Promise<{ number: string; grandTotal: number; change: number; due: number }> {
      if (api.useMocks) {
        await mockDelay(700)
        const n = 120161 + Math.floor(Math.random() * 999)
        return { number: `S-DHK1-2026-${String(n).padStart(6, '0')}`, grandTotal: 0, change: 0, due: 0 }
      }
      return api.post('/sales', payload, idempotencyKey)
    },
  }
}

export function usePurchasesApi() {
  const api = useApi('purchases')
  return {
    async list(q: ListQuery): Promise<Paginated<PurchaseSummary>> {
      if (api.useMocks) {
        await mockDelay()
        const { purchases } = await mocks()
        return paginate(purchases, q, (x, s) => `${x.number} ${x.supplier} ${x.supplierInvoiceNo}`.toLowerCase().includes(s))
      }
      return api.get('/purchases', { ...q, ...q.filters })
    },
  }
}

export function useCustomersApi() {
  const api = useApi('customers')
  return {
    async list(q: ListQuery): Promise<Paginated<Customer>> {
      if (api.useMocks) {
        await mockDelay()
        const { customers } = await mocks()
        let rows = customers
        if (q.filters?.due) rows = rows.filter(c => c.dueBalance > 0)
        return paginate(rows, q, (c, s) => `${c.name} ${c.phone} ${c.code}`.toLowerCase().includes(s))
      }
      return api.get('/customers', { ...q, ...q.filters })
    },
    async lookup(term: string): Promise<Customer[]> {
      if (api.useMocks) {
        await mockDelay(100)
        const s = term.trim().toLowerCase()
        const { customers } = await mocks()
        return customers.filter(c => !s || `${c.name} ${c.phone}`.toLowerCase().includes(s)).slice(0, 8)
      }
      return api.get('/customers/lookup', { q: term })
    },
  }
}
