import type { PurchaseDetail } from '~/mocks/purchase-detail'

export function usePurchaseDetailApi() {
  const api = useApi()
  return {
    async get(id: number): Promise<PurchaseDetail | null> {
      if (api.useMocks) { await mockDelay(300); return (await import('~/mocks/purchase-detail')).purchaseDetail(id) }
      return api.get(`/purchases/${id}`)
    },
    /** Supplier payment against this invoice; the API posts it to the supplier ledger and the account. */
    async pay(id: number, payload: { amount: number; method: string; reference: string }, idempotencyKey: string): Promise<{ number: string }> {
      if (api.useMocks) { await mockDelay(600); return { number: `PAY-${Date.now().toString().slice(-6)}` } }
      return api.post(`/purchases/${id}/payments`, payload, idempotencyKey)
    },
  }
}
