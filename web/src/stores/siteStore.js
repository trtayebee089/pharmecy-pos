import { defineStore } from 'pinia'
import api, { unwrap } from '../services/api'

/** Shown until the shop sets its own name in the POS. */
export const DEFAULT_BRAND = 'Your Brand'

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
  getters: {
    /** The shop's name (POS: Administration › Integrations › Website › Site name). */
    brand: (state) => String(state.shop.siteName || '').trim() || DEFAULT_BRAND,
  },
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
