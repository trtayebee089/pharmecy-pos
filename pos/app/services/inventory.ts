/**
 * Inventory & purchasing API modules (/api/pos/v1): stock overview, stock counts,
 * transfers, purchase returns, reorder suggestions and the supplier ledger.
 * With useMocks on (development only) they resolve from app/mocks/inventory.
 */
import type { ListQuery, Paginated } from '~/types/domain'
import type {
  CountLine, CountSession, PurchaseReturnSummary, ReorderLine, StockRow, SupplierLedgerEntry, SupplierRow, TransferLine, TransferSummary,
} from '~/types/inventory'

const inv = () => import('~/mocks/inventory')

export function useStockApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<StockRow>> {
      if (!api.useMocks) return api.get('/inventory/stock', { ...q, ...q.filters })
      await mockDelay()
      let rows = (await inv()).stockRows()
      const f = q.filters?.status
      if (f === 'low') rows = rows.filter(r => r.status === 'low')
      else if (f === 'out') rows = rows.filter(r => r.status === 'out')
      else if (f === 'expiring') rows = rows.filter(r => r.expiringSoon > 0)
      return paginate(rows, q, (r, s) => `${r.name} ${r.strength} ${r.manufacturer} ${r.sku}`.toLowerCase().includes(s))
    },
    async totals(): Promise<{ value: number; out: number; low: number; expiring: number; products: number }> {
      if (!api.useMocks) return api.get('/inventory/stock/summary')
      await mockDelay(200)
      const rows = (await inv()).stockRows()
      return {
        value: rows.reduce((s, r) => s + r.valueAtCost, 0), out: rows.filter(r => r.status === 'out').length,
        low: rows.filter(r => r.status === 'low').length, expiring: rows.filter(r => r.expiringSoon > 0).length, products: rows.length,
      }
    },
  }
}

export function useCountsApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<CountSession>> {
      if (!api.useMocks) return api.get('/inventory/counts', { ...q, ...q.filters })
      await mockDelay()
      let rows = (await inv()).countSessions
      if (q.filters?.status) rows = rows.filter(r => r.status === q.filters!.status)
      return paginate(rows, q, (r, s) => `${r.number} ${r.name} ${r.scope}`.toLowerCase().includes(s))
    },
    async get(id: number): Promise<{ session: CountSession; lines: CountLine[] } | null> {
      if (!api.useMocks) return api.get(`/inventory/counts/${id}`)
      await mockDelay(300)
      const m = await inv()
      const session = m.countSessions.find(s => s.id === id)
      return session ? { session, lines: m.countLinesFor(session) } : null
    },
    async start(payload: { name: string; scope: string }, idempotencyKey: string): Promise<CountSession> {
      if (!api.useMocks) return api.post('/inventory/counts', payload, idempotencyKey)
      await mockDelay(500)
      const m = await inv()
      const id = Math.max(...m.countSessions.map(s => s.id)) + 1
      const s: CountSession = { id, number: `SC-DHK1-2026-${String(id).padStart(4, '0')}`, name: payload.name, warehouse: 'Red Pharma · Dhanmondi', scope: payload.scope, status: 'counting', startedBy: 'You', startedAt: new Date().toISOString(), postedAt: null, lines: 0, counted: 0, varianceQty: 0, varianceValue: 0 }
      m.countSessions.unshift(s)
      m.summarise(s)
      return s
    },
    /** Saves counted quantities (draft). The API recomputes system quantities at posting time. */
    async saveCounts(id: number, counts: { batchId: number; countedQty: number | null }[]): Promise<void> {
      if (!api.useMocks) return api.put(`/inventory/counts/${id}/lines`, { counts })
      await mockDelay(250)
      const m = await inv()
      const s = m.countSessions.find(x => x.id === id)
      if (s) m.summarise(s)
    },
    /** Posts the variances as `count` stock movements. Final, cannot be edited afterwards. */
    async post(id: number, idempotencyKey: string): Promise<void> {
      if (!api.useMocks) return api.post(`/inventory/counts/${id}/post`, {}, idempotencyKey)
      await mockDelay(700)
      const s = (await inv()).countSessions.find(x => x.id === id)
      if (s) { s.status = 'posted'; s.postedAt = new Date().toISOString() }
    },
    async cancel(id: number, reason: string): Promise<void> {
      if (!api.useMocks) return api.post(`/inventory/counts/${id}/cancel`, { reason })
      await mockDelay(400)
      const s = (await inv()).countSessions.find(x => x.id === id)
      if (s) s.status = 'cancelled'
    },
  }
}

export function useTransfersApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<TransferSummary>> {
      if (!api.useMocks) return api.get('/inventory/transfers', { ...q, ...q.filters })
      await mockDelay()
      let rows = (await inv()).transfers
      if (q.filters?.status) rows = rows.filter(r => r.status === q.filters!.status)
      return paginate(rows, q, (r, s) => `${r.number} ${r.from} ${r.to} ${r.createdBy}`.toLowerCase().includes(s))
    },
    /** Sending posts transfer_out movements; stock is in transit until received. */
    async send(payload: { from: string; to: string; note: string; lines: TransferLine[] }, idempotencyKey: string): Promise<TransferSummary> {
      if (!api.useMocks) return api.post('/inventory/transfers', payload, idempotencyKey)
      await mockDelay(600)
      const m = await inv()
      const id = Math.max(...m.transfers.map(t => t.id)) + 1
      const t: TransferSummary = { id, number: `TR-2026-${String(id).padStart(5, '0')}`, date: new Date().toISOString(), ...payload, qty: payload.lines.reduce((s, l) => s + l.qtyBase, 0), status: 'sent', createdBy: 'You' }
      m.transfers.unshift(t)
      return t
    },
    async receive(id: number, idempotencyKey: string): Promise<void> {
      if (!api.useMocks) return api.post(`/inventory/transfers/${id}/receive`, {}, idempotencyKey)
      await mockDelay(500)
      const t = (await inv()).transfers.find(x => x.id === id)
      if (t) t.status = 'received'
    },
    async void(id: number, reason: string): Promise<void> {
      if (!api.useMocks) return api.post(`/inventory/transfers/${id}/void`, { reason })
      await mockDelay(400)
      const t = (await inv()).transfers.find(x => x.id === id)
      if (t) { t.status = 'void'; t.note = reason }
    },
  }
}

export interface PurchaseReturnPayload {
  supplierId: number
  creditNote: string
  reason: string
  settlement: 'reduce_due' | 'refund'
  lines: { productId: number; batchId: number; qtyBase: number; unitCost: number }[]
}

export function usePurchaseReturnsApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<PurchaseReturnSummary>> {
      if (!api.useMocks) return api.get('/purchase-returns', { ...q, ...q.filters })
      await mockDelay()
      return paginate((await inv()).purchaseReturns, q, (r, s) => `${r.number} ${r.supplier} ${r.creditNote} ${r.reason}`.toLowerCase().includes(s))
    },
    /** Posts the return: purchase_return movements per batch, supplier ledger credit. */
    async create(payload: PurchaseReturnPayload, supplierName: string, idempotencyKey: string): Promise<PurchaseReturnSummary> {
      if (!api.useMocks) return api.post('/purchase-returns', payload, idempotencyKey)
      await mockDelay(700)
      const m = await inv()
      const id = Math.max(...m.purchaseReturns.map(r => r.id)) + 1
      const r: PurchaseReturnSummary = {
        id, number: `PR-DHK1-2026-${String(id).padStart(5, '0')}`, date: new Date().toISOString(), supplier: supplierName,
        creditNote: payload.creditNote, lines: payload.lines.length, qty: payload.lines.reduce((s, l) => s + l.qtyBase, 0),
        total: Math.round(payload.lines.reduce((s, l) => s + l.qtyBase * l.unitCost, 0) * 100) / 100, reason: payload.reason, status: 'posted',
      }
      m.purchaseReturns.unshift(r)
      return r
    },
    /** Voids a posted return: pieces go back into their batches and the supplier credit is reversed. */
    async void(id: number, reason: string): Promise<void> {
      if (!api.useMocks) return api.post(`/purchase-returns/${id}/void`, { reason }, crypto.randomUUID())
      await mockDelay(450)
      const r = (await inv()).purchaseReturns.find(x => x.id === id)
      if (r) r.status = 'void'
    },
  }
}

export function useReorderApi() {
  const api = useApi()
  return {
    /** AI + low-stock suggestions. The API runs the forecast; this only displays it. */
    async generate(params: { days: number; coverDays: number }): Promise<ReorderLine[]> {
      if (!api.useMocks) return api.post('/reorder/suggestions', params)
      await mockDelay(1600)
      return (await inv()).reorderSuggestions()
    },
    async convert(lines: { productId: number; supplierId: number; unit: string; qty: number }[], idempotencyKey: string): Promise<{ drafts: { supplier: string; number: string }[] }> {
      if (!api.useMocks) return api.post('/reorder/convert', { lines }, idempotencyKey)
      await mockDelay(700)
      const m = await inv()
      const bySupplier = [...new Set(lines.map(l => l.supplierId))]
      return { drafts: bySupplier.map((sid, i) => ({ supplier: m.supplierRows.find(s => s.id === sid)?.name ?? 'Supplier', number: `P-DHK1-2026-D${String(41 + i).padStart(4, '0')}` })) }
    },
  }
}

export function useSupplierLedgerApi() {
  const api = useApi()
  return {
    resource: useResource<SupplierRow>('suppliers', async () => (await inv()).supplierRows),
    async ledger(supplier: SupplierRow): Promise<SupplierLedgerEntry[]> {
      if (!api.useMocks) return api.get(`/suppliers/${supplier.id}/ledger`)
      await mockDelay(350)
      return (await inv()).supplierLedger(supplier)
    },
    /** Supplier payment; the API allocates it to the oldest open purchases first. */
    async pay(supplierId: number, payload: { amount: number; method: string; reference: string; paidAt: string; note: string }, idempotencyKey: string): Promise<{ number: string }> {
      if (!api.useMocks) return api.post(`/suppliers/${supplierId}/payments`, payload, idempotencyKey)
      await mockDelay(600)
      return { number: `PAY-OUT-2026-${String(3100 + Math.floor(Math.random() * 99)).padStart(5, '0')}` }
    },
  }
}
