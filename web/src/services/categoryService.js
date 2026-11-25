import api, { normalizeList } from './api'
import { normalizeProduct } from './productService'

export function normalizeCategory(category = {}) {
  return {
    id: category.id || category.slug,
    name: category.name || 'Category',
    slug: category.slug || category.id,
    image: category.image || '',
    product_count: category.product_count || 0,
    description: category.description || '',
    raw: category,
  }
}

export const categoryService = {
  async list(params = {}) {
    try {
      const normalized = normalizeList((await api.get('/v1/categories', { params })).data)
      return { ...normalized, data: normalized.data.map(normalizeCategory) }
    } catch {
      return { data: [], meta: { total: 0, current_page: 1, last_page: 1 }, offline: true }
    }
  },

  /**
   * Products belonging to one category (and its sub-categories).
   * GET /v1/categories/{slug} returns the category on `data` and its products on
   * a sibling `products` paginator. Returns an empty list rather than throwing so
   * a single failing shelf can never take the page down.
   */
  async products(slug, params = {}) {
    if (!slug) return { category: null, data: [] }
    try {
      const payload = (await api.get(`/v1/categories/${encodeURIComponent(slug)}`, { params })).data || {}
      const list = Array.isArray(payload.products) ? payload.products : payload.products?.data || []
      return { category: payload.data ? normalizeCategory(payload.data) : null, data: list.map(normalizeProduct) }
    } catch {
      return { category: null, data: [] }
    }
  },
}
