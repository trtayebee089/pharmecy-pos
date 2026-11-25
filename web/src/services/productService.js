import api, { normalizeList, unwrap } from './api'

export function normalizeProduct(product = {}) {
  const manufacturer =
    product.manufacturer?.title ||
    product.manufacturer?.name ||
    product.brand?.title ||
    product.brand?.name ||
    product.manufacturer ||
    ''

  return {
    id: product.id,
    name: product.name || 'Medicine',
    slug: product.slug || product.id,
    generic_name: product.generic_name || '',
    manufacturer: typeof manufacturer === 'object' ? '' : manufacturer,
    strength: product.strength || '',
    unit: product.unit || '',
    pack: product.pack || '',
    price: Number(product.price || 0),
    discount_price: product.discount_price || null,
    stock: Number(product.stock ?? product.qty ?? 0),
    prescription_required: Boolean(product.prescription_required),
    image: product.image || '',
    description: product.description || '',
    usage: product.usage || '',
    warning: product.warning || '',
    dosage_form: product.dosage_form || '',
    category_slug: product.category_slug || product.category?.slug || '',
    raw: product,
  }
}

const empty = { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }

export const productService = {
  /** Never invents products: when the API is down the list is empty and `offline` is set. */
  async list(params = {}) {
    try {
      const normalized = normalizeList((await api.get('/v1/products', { params })).data)
      return { ...normalized, data: normalized.data.map(normalizeProduct) }
    } catch {
      return { ...empty, offline: true }
    }
  },

  async featured(params = {}) {
    return this.list({ per_page: 24, ...params })
  },

  async find(slug) {
    return normalizeProduct(unwrap(await api.get(`/v1/products/${encodeURIComponent(slug)}`)))
  },

  async search(query) {
    return this.list({ search: query, per_page: 12 })
  },

  async suggestions(query) {
    return unwrap(await api.get('/v1/products/suggestions', { params: { query } }))
  },
}
