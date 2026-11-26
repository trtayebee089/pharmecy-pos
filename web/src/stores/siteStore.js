import { defineStore } from 'pinia'
import api, { unwrap } from '../services/api'

let pending = null

/**
 * Site-wide settings from GET /v1/site-config (Administration › Integrations and
 * Website › Banners in the POS): shop details, public tracking ids, payment
 * options, the home page banners and Google Maps. Loaded once per visit.
 */
export const useSiteStore = defineStore('site', {
  state: () => ({
    loaded: false,
    shop: {},
    tracking: {},
    payments: [{ code: 'cod', label: 'Cash on delivery' }],
    banners: { hero: [], tiles: [] },
    // Google Maps (delivery pin at checkout, live rider map). The browser key is public by design.
    maps: { enabled: false, browser_key: null, default_center: { lat: 23.8103, lng: 90.4125 } },
  }),
  getters: {
    mapsEnabled: (state) => Boolean(state.maps.enabled && state.maps.browser_key),
  },
  actions: {
    /** Pages may call this before App.vue's call has finished: they share one request. */
    load() {
      if (this.loaded) return Promise.resolve(this)
      if (!pending) pending = this.fetchConfig().finally(() => { pending = null })
      return pending
    },
    async fetchConfig() {
      try {
        const data = unwrap(await api.get('/v1/site-config')) || {}
        this.shop = data.shop || {}
        this.tracking = data.tracking || {}
        if (Array.isArray(data.payments) && data.payments.length) this.payments = data.payments
        this.banners = { hero: data.banners?.hero || [], tiles: data.banners?.tiles || [] }
        if (data.maps) {
          const c = data.maps.default_center
          this.maps = {
            enabled: Boolean(data.maps.enabled),
            browser_key: data.maps.browser_key || null,
            default_center: c && Number.isFinite(Number(c.lat)) && Number.isFinite(Number(c.lng))
              ? { lat: Number(c.lat), lng: Number(c.lng) }
              : this.maps.default_center,
          }
        }
      } catch {
        // The shop still works on defaults (cash on delivery, built-in hero, no maps) when this fails.
      } finally {
        this.loaded = true
      }
      return this
    },
  },
})
