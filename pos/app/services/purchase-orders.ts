import type { AiRun, Company, DraftDetail, DraftSummary, OrderForecast, OrderRow } from '~/types/purchase-orders'

const mock = () => import('~/mocks/purchase-orders')

/**
 * Purchase orders by company (/api/pos/v1/purchase-orders), for the current warehouse.
 * Sold quantity, stock and the forecast come from the server (the forecast is
 * statistical: 28-day rate with a 7-day trend, 21 days of cover); the
 * box/strip/piece maths shown in the UI is re-validated by the API on save.
 */
export function usePurchaseOrdersApi() {
  const api = useApi('purchases')
  const wh = () => useAuthStore().warehouseId
  return {
    async companies(): Promise<Company[]> {
      if (api.useMocks) { await mockDelay(150); return (await mock()).companies() }
      return api.get('/purchase-orders/companies')
    },
    async report(companyId: number, from: string, to: string): Promise<OrderRow[]> {
      if (api.useMocks) { await mockDelay(450); return (await mock()).orderRows(companyId, from, to) }
      return api.get('/purchase-orders/report', { company_id: companyId, from, to, warehouse_id: wh() })
    },
    async forecast(productIds: number[], refresh = false): Promise<Record<number, OrderForecast>> {
      if (api.useMocks) { await mockDelay(refresh ? 1400 : 900); return (await mock()).forecasts(productIds) }
      return api.post('/purchase-orders/forecast', { product_ids: productIds, refresh, warehouse_id: wh() })
    },
    async saveDraft(companyId: number, date: string, rows: OrderRow[], source: 'manual' | 'ai', idempotencyKey: string): Promise<DraftDetail> {
      if (api.useMocks) { await mockDelay(500); return (await mock()).saveDraft(companyId, date, rows, source, useAuthStore().user?.name ?? 'You') }
      return api.post('/purchase-orders/drafts', { company_id: companyId, date, source, rows, warehouse_id: wh() }, idempotencyKey)
    },
    async drafts(f: { from?: string; to?: string; companyId?: number; status?: string }): Promise<DraftSummary[]> {
      if (api.useMocks) { await mockDelay(300); return (await mock()).listDrafts(f) }
      return api.get('/purchase-orders/drafts', f)
    },
    async draft(id: number): Promise<DraftDetail | null> {
      if (api.useMocks) { await mockDelay(300); return (await mock()).getDraft(id) }
      return api.get(`/purchase-orders/drafts/${id}`)
    },
    async updateDraft(id: number, rows: OrderRow[]): Promise<void> {
      if (api.useMocks) { await mockDelay(450); (await mock()).updateDraft(id, rows); return }
      return api.put(`/purchase-orders/drafts/${id}`, { rows })
    },
    async deleteDraft(id: number): Promise<void> {
      if (api.useMocks) { await mockDelay(300); (await mock()).deleteDraft(id); return }
      return api.del(`/purchase-orders/drafts/${id}`)
    },
    /** Creates a pending (ordered) purchase for the chosen supplier; stock is added only when it is received. */
    async createPurchase(id: number, supplierId: number, supplierName: string, idempotencyKey: string): Promise<{ purchaseId: number; number: string }> {
      if (api.useMocks) { await mockDelay(700); return (await mock()).convertDraft(id, supplierName) }
      return api.post(`/purchase-orders/drafts/${id}/purchase`, { supplier_id: supplierId }, idempotencyKey)
    },
    async startAiRun(): Promise<AiRun> {
      if (api.useMocks) { await mockDelay(300); return (await mock()).startRun() }
      return api.post('/purchase-orders/ai-runs', { warehouse_id: wh() })
    },
    async processAiRun(id: number): Promise<AiRun> {
      if (api.useMocks) { await mockDelay(700); return (await mock()).processRun(id) }
      return api.post(`/purchase-orders/ai-runs/${id}/process`)
    },
  }
}

/** Box → strip → piece linkage used by every quantity editor. */
export function setBoxQty(r: OrderRow, box: number) {
  r.boxQty = Math.max(0, Number(box) || 0)
  r.stripQty = round2(r.boxQty * r.stripsPerBox)
  r.orderQty = Math.round(r.stripQty * r.piecesPerStrip)
}
export function setStripQty(r: OrderRow, strip: number) {
  r.stripQty = Math.max(0, Number(strip) || 0)
  r.boxQty = round2(r.stripQty / r.stripsPerBox)
  r.orderQty = Math.round(r.stripQty * r.piecesPerStrip)
}
export function setPieceQty(r: OrderRow, pcs: number) {
  r.orderQty = Math.max(0, Math.round(Number(pcs) || 0))
  r.stripQty = round2(r.orderQty / r.piecesPerStrip)
  r.boxQty = round2(r.stripQty / r.stripsPerBox)
}
/** Legacy "AI Generate Order": boxes needed to cover the shortage (sold − in stock), rounded up. */
export function boxesFromShortage(r: OrderRow): number {
  const shortage = Math.max(0, r.soldQty - r.inStock)
  return shortage > 0 ? Math.ceil(shortage / (r.piecesPerStrip * r.stripsPerBox)) : 0
}
function round2(n: number) { return Math.round(n * 100) / 100 }
