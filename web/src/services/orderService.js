import api, { normalizeList, unwrap } from './api'

/** A key per checkout attempt: a retried request returns the first order instead of a second one. */
function idempotencyKey() {
  return globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

export const orderService = {
  async list(params = {}) {
    return normalizeList((await api.get('/v1/orders', { params })).data)
  },
  async find(number) {
    return unwrap(await api.get(`/v1/orders/${encodeURIComponent(number)}`))
  },
  /** Server prices, zone shipping and discounts; the response carries the final totals. */
  async checkout(payload, key = idempotencyKey()) {
    return unwrap(await api.post('/v1/checkout', payload, { headers: { 'Idempotency-Key': key } }))
  },
  async reorder(number) {
    return unwrap(await api.post(`/v1/orders/${encodeURIComponent(number)}/reorder`))
  },
  async track(code) {
    return unwrap(await api.get(`/v1/tracking/${encodeURIComponent(code.trim())}`))
  },
  async zones() {
    return unwrap(await api.get('/v1/shipping-zones'))
  },
  newKey: idempotencyKey,
}
