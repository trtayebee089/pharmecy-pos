/**
 * Domain API modules for catalog writes, receiving, sale detail/returns,
 * cash registers, adjustments and administration (/api/pos/v1).
 * With useMocks on (development only) they resolve from app/mocks.
 */
import type {
  AdjustmentSummary, CatalogLookups, ListQuery, Paginated, PermissionGroup, PosSettings, Product, ProductInput,
  RegisterSession, Role, SaleDetail, StaffUser, Supplier,
} from '~/types/domain'

const seed = () => import('~/mocks/seed')
const extra = () => import('~/mocks/extra')

export function useCatalogApi() {
  const api = useApi()
  return {
    async lookups(): Promise<CatalogLookups> {
      if (api.useMocks) { await mockDelay(150); return (await extra()).lookups }
      return api.get('/catalog/lookups')
    },
    async product(id: number): Promise<Product | null> {
      if (api.useMocks) { await mockDelay(250); return (await seed()).products.find(p => p.id === id) ?? null }
      return api.get(`/catalog/products/${id}`)
    },
    /** The API validates unique SKU and barcodes, exactly one base unit, factors > 0 and price rules. */
    async save(input: ProductInput): Promise<{ id: number }> {
      if (api.useMocks) {
        await mockDelay(600)
        const barcodes = input.units.map(u => u.barcode).filter(Boolean)
        if (new Set(barcodes).size !== barcodes.length) throw new ApiError('The given data was invalid.', 422, { units: ['Each barcode must be unique.'] })
        return { id: input.id ?? 999 }
      }
      return input.id ? api.put(`/catalog/products/${input.id}`, input) : api.post('/catalog/products', input)
    },
  }
}

export function useSuppliersApi() {
  const api = useApi()
  return {
    async lookup(term = ''): Promise<Supplier[]> {
      if (api.useMocks) {
        await mockDelay(100)
        const s = term.toLowerCase()
        return (await extra()).suppliers.filter(x => x.name.toLowerCase().includes(s))
      }
      return api.get('/suppliers/lookup', { q: term })
    },
  }
}

export interface ReceivePayload {
  /** received: post stock now; ordered: create a purchase order to receive later */
  status: 'received' | 'ordered'
  warehouseId: number
  supplierId: number
  supplierInvoiceNo: string
  invoiceDate: string
  otherCharges: number
  paidAmount: number
  paymentMethod: string
  note: string
  lines: { productId: number; unitId: number; qty: number; freeQty: number; batchNo: string; expiryDate: string; unitCost: number; discountPct: number; updateRetailPrice: number | null }[]
}

export function useReceivingApi() {
  const api = useApi()
  return {
    /** Posts a received purchase: batches are found or created, stock movements and supplier ledger posted. */
    async receive(payload: ReceivePayload, idempotencyKey: string): Promise<{ number: string }> {
      if (api.useMocks) {
        await mockDelay(800)
        return { number: `P-DHK1-2026-${String(8601 + Math.floor(Math.random() * 90)).padStart(5, '0')}` }
      }
      return api.post('/purchases', payload, idempotencyKey)
    },
  }
}

export function useSaleDetailApi() {
  const api = useApi()
  return {
    async get(id: number): Promise<SaleDetail | null> {
      if (api.useMocks) { await mockDelay(300); return (await extra()).saleDetail(id) }
      return api.get(`/sales/${id}`)
    },
    /** Return quantities are re-validated by the API against sold minus already returned, per batch, under lock. */
    async createReturn(saleId: number, payload: { items: { saleItemBatchId: number; qtyBase: number; restock: boolean }[]; refundMethod: string; reason: string }, idempotencyKey: string): Promise<{ number: string }> {
      if (api.useMocks) { await mockDelay(700); return { number: `R-DHK1-2026-${String(2081 + Math.floor(Math.random() * 50)).padStart(5, '0')}` } }
      return api.post(`/sales/${saleId}/returns`, payload, idempotencyKey)
    },
    async void(saleId: number, reason: string): Promise<void> {
      if (api.useMocks) { await mockDelay(500); return }
      return api.post(`/sales/${saleId}/void`, { reason })
    },
  }
}

export function useRegisterApi() {
  const api = useApi()
  return {
    async sessions(): Promise<RegisterSession[]> {
      if (api.useMocks) { await mockDelay(); return structuredClone((await extra()).sessions) }
      return api.get('/cash-registers/sessions')
    },
    async open(registerId: number, openingFloat: number): Promise<RegisterSession> {
      if (api.useMocks) {
        await mockDelay(500)
        return { id: 313, register: `Counter ${registerId}`, status: 'open', openedBy: 'You', openedAt: new Date().toISOString(), openingFloat, cashSales: 0, cashRefunds: 0, cashIn: 0, cashOut: 0, expectedCash: openingFloat, countedCash: null, difference: null, closedAt: null, nonCash: [] }
      }
      return api.post('/cash-registers/sessions', { register_id: registerId, opening_float: openingFloat })
    },
    /** Petty cash in/out at the till; recorded against the open session and the cash account. */
    async cashMovement(sessionId: number, type: 'cash_in' | 'cash_out', amount: number, reason: string, idempotencyKey: string): Promise<void> {
      if (api.useMocks) { await mockDelay(400); return }
      return api.post(`/cash-registers/sessions/${sessionId}/movements`, { type, amount, reason }, idempotencyKey)
    },
    async close(sessionId: number, countedCash: number, note: string): Promise<void> {
      if (api.useMocks) { await mockDelay(600); return }
      return api.post(`/cash-registers/sessions/${sessionId}/close`, { counted_cash: countedCash, note })
    },
  }
}

export function useAdjustmentsApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<AdjustmentSummary>> {
      if (api.useMocks) {
        await mockDelay()
        let rows = (await extra()).adjustments
        if (q.filters?.reason) rows = rows.filter(a => a.reason === q.filters!.reason)
        return paginate(rows, q, (a, s) => `${a.number} ${a.reason} ${a.createdBy}`.toLowerCase().includes(s))
      }
      return api.get('/inventory/adjustments', { ...q, ...q.filters })
    },
    async post(payload: { warehouseId: number; reason: string; note: string; items: { productId: number; batchId: number; qtyBase: number }[] }, idempotencyKey: string): Promise<{ number: string }> {
      if (api.useMocks) { await mockDelay(700); return { number: 'ADJ-DHK1-2026-00091' } }
      return api.post('/inventory/adjustments', payload, idempotencyKey)
    },
  }
}

export function useAdminApi() {
  const api = useApi()
  return {
    async users(): Promise<StaffUser[]> {
      if (api.useMocks) { await mockDelay(); return (await extra()).users }
      return api.get('/admin/users')
    },
    async roles(): Promise<{ roles: Role[]; groups: PermissionGroup[] }> {
      if (api.useMocks) { await mockDelay(); const m = await extra(); return { roles: structuredClone(m.roles), groups: m.permissionGroups } }
      return api.get('/admin/roles')
    },
    async saveRole(role: Role): Promise<void> {
      if (api.useMocks) { await mockDelay(500); return }
      return api.put(`/admin/roles/${role.id}`, role)
    },
    async settings(): Promise<PosSettings> {
      if (api.useMocks) { await mockDelay(); return structuredClone((await extra()).settings) }
      return api.get('/admin/settings/pos')
    },
    async saveSettings(s: PosSettings): Promise<void> {
      if (api.useMocks) { await mockDelay(500); return }
      return api.put('/admin/settings/pos', s)
    },
  }
}
