import api, { normalizeList, unwrap } from './api'

/** A key per checkout attempt: a retried request returns the first order instead of a second one. */
function idempotencyKey() {
  return globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

export const orderService = {
  /** "Request a medicine": fields + up to 5 prescription photos / PDFs. `onProgress(0..100)` while uploading. */
  async requestMedicine(fields, files = [], onProgress) {
    const form = new FormData()
    Object.entries(fields).forEach(([k, v]) => { if (v) form.append(k, v) })
    files.forEach((f) => form.append('files[]', f))
    return unwrap(await api.post('/v1/medicine-requests', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : 0),
    }))
  },
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
  /** Start or retry an online payment: { redirect_url }. Works for guests (tracking code). */
  async pay(trackingCode, gateway) {
    return unwrap(await api.post('/v1/payments/start', { tracking_code: trackingCode, gateway }))
  },
  async payOnDelivery(trackingCode) {
    return unwrap(await api.post('/v1/payments/cod', { tracking_code: trackingCode }))
  },
  async zones() {
    return unwrap(await api.get('/v1/shipping-zones'))
  },
  newKey: idempotencyKey,
}
