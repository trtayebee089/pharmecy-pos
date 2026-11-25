import { defineStore } from 'pinia'
import api, { unwrap } from '../services/api'

/**
 * Site-wide settings from GET /v1/site-config (Administration › Integrations and
 * Website › Banners in the POS): shop details, public tracking ids, payment
 * options and the home page banners. Loaded once per visit.
 */
export const useSiteStore = defineStore('site', {
  state: () => ({
    loaded: false,
    shop: {},
    tracking: {},
    payments: [{ code: 'cod', label: 'Cash on delivery' }],
    banners: { hero: [], tiles: [] },
  }),
  actions: {
    async load() {
      if (this.loaded) return this
      try {
        const data = unwrap(await api.get('/v1/site-config')) || {}
        this.shop = data.shop || {}
        this.tracking = data.tracking || {}
        if (Array.isArray(data.payments) && data.payments.length) this.payments = data.payments
        this.banners = { hero: data.banners?.hero || [], tiles: data.banners?.tiles || [] }
      } catch {
        // The shop still works on defaults (cash on delivery, built-in hero) when this fails.
      } finally {
        this.loaded = true
      }
      return this
    },
  },
})
