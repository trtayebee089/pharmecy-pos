/**
 * Conversion tracking for the ids set in the POS (Administration › Integrations ›
 * Tracking): Google Tag Manager, Meta Pixel, GA4 and Google Ads.
 *
 * Every e-commerce step is pushed to `window.dataLayer` in the GA4 e-commerce
 * format (view_item, add_to_cart, remove_from_cart, view_cart, begin_checkout,
 * purchase, search, sign_up, login), so GTM can forward it anywhere. When the
 * Pixel / GA4 / Ads ids are set, the same events also go to them directly.
 * Purchase carries the order's event id, which the server also sends to the
 * Facebook Conversions API, so Facebook counts the sale once.
 *
 * Nothing loads until init() is called with ids, and every call is a no-op
 * without them, so the site works the same with tracking switched off.
 */
const CURRENCY = 'BDT'
let cfg = null

function w() {
  return typeof window === 'undefined' ? null : window
}

function loadScript(src) {
  const s = document.createElement('script')
  s.async = true
  s.src = src
  document.head.appendChild(s)
}

function cookie(name) {
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return m ? decodeURIComponent(m[1]) : null
}

function fbq(...args) {
  if (cfg?.facebook_pixel_id && w()?.fbq) w().fbq(...args)
}

function gtag(...args) {
  if ((cfg?.ga4_measurement_id || cfg?.google_ads_id) && w()?.gtag) w().gtag(...args)
}

function push(event, ecommerce, extra = {}) {
  const win = w()
  if (!win || !cfg) return
  win.dataLayer = win.dataLayer || []
  if (ecommerce) win.dataLayer.push({ ecommerce: null }) // GA4: clear the previous e-commerce object
  win.dataLayer.push({ event, ...(ecommerce ? { ecommerce } : {}), ...extra })
}

/** GA4 item from a website product (normalizeProduct shape) and a quantity. */
function item(product, quantity = 1) {
  return {
    item_id: String(product.id),
    item_name: [product.name, product.strength].filter(Boolean).join(' '),
    item_brand: product.manufacturer || undefined,
    item_category: product.raw?.category?.name || product.category_slug || undefined,
    price: Number(product.discount_price || product.price || 0),
    quantity: Number(quantity || 1),
  }
}

function value(items) {
  return Math.round(items.reduce((sum, i) => sum + i.price * i.quantity, 0) * 100) / 100
}

function fbContents(items) {
  return { content_ids: items.map((i) => i.item_id), contents: items.map((i) => ({ id: i.item_id, quantity: i.quantity })), content_type: 'product', currency: CURRENCY, value: value(items), num_items: items.reduce((n, i) => n + i.quantity, 0) }
}

export function newEventId() {
  return globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

/*
 * A page can fire an event (view_item, begin_checkout, purchase) before the app
 * has loaded the site config. Those calls wait in `queue` and run once init()
 * has the ids; with tracking switched off they are dropped.
 */
let ready = false
const queue = []

const api = {
  /** Loads GTM / Pixel / gtag for the ids that are set, then runs the queued events. Runs once. */
  init(config) {
    const win = w()
    if (!win || ready) return
    ready = true
    const pending = queue.splice(0)
    if (!config || (!config.gtm_id && !config.facebook_pixel_id && !config.ga4_measurement_id && !config.google_ads_id)) return
    cfg = config
    queueMicrotask(() => pending.forEach(([fn, args]) => fn.apply(api, args)))
    win.dataLayer = win.dataLayer || []

    if (cfg.gtm_id) {
      win.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' })
      loadScript(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(cfg.gtm_id)}`)
    }
    if (cfg.facebook_pixel_id) {
      // Meta's standard loader, written out: queue calls until fbevents.js arrives.
      const f = (win.fbq = function (...args) { f.callMethod ? f.callMethod(...args) : f.queue.push(args) })
      if (!win._fbq) win._fbq = f
      Object.assign(f, { push: f, loaded: true, version: '2.0', queue: [] })
      loadScript('https://connect.facebook.net/en_US/fbevents.js')
      win.fbq('init', cfg.facebook_pixel_id)
    }
    if (cfg.ga4_measurement_id || cfg.google_ads_id) {
      win.gtag = function () { win.dataLayer.push(arguments) } // gtag needs the arguments object
      loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(cfg.ga4_measurement_id || cfg.google_ads_id)}`)
      win.gtag('js', new Date())
      // Page views are sent on every route change below (this is a single-page app).
      if (cfg.ga4_measurement_id) win.gtag('config', cfg.ga4_measurement_id, { send_page_view: false })
      if (cfg.google_ads_id) win.gtag('config', cfg.google_ads_id)
    }
    // Keep Facebook's click id for the server-side event (it arrives once, in the landing URL).
    const fbclid = new URLSearchParams(win.location.search).get('fbclid')
    if (fbclid && !cookie('_fbc')) document.cookie = `_fbc=fb.1.${Date.now()}.${fbclid}; path=/; max-age=7776000; SameSite=Lax`
  },

  pageView(path, title) {
    push('page_view', null, { page_path: path, page_title: title })
    fbq('track', 'PageView')
    if (cfg?.ga4_measurement_id) gtag('event', 'page_view', { page_path: path, page_title: title, send_to: cfg.ga4_measurement_id })
  },

  viewItem(product) {
    const items = [item(product)]
    push('view_item', { currency: CURRENCY, value: value(items), items })
    fbq('track', 'ViewContent', fbContents(items))
    gtag('event', 'view_item', { currency: CURRENCY, value: value(items), items })
  },

  addToCart(product, quantity) {
    const items = [item(product, quantity)]
    push('add_to_cart', { currency: CURRENCY, value: value(items), items })
    fbq('track', 'AddToCart', fbContents(items))
    gtag('event', 'add_to_cart', { currency: CURRENCY, value: value(items), items })
  },

  removeFromCart(product, quantity) {
    const items = [item(product, quantity)]
    push('remove_from_cart', { currency: CURRENCY, value: value(items), items })
    gtag('event', 'remove_from_cart', { currency: CURRENCY, value: value(items), items })
  },

  viewCart(lines) {
    const items = lines.map((l) => item(l.product, l.quantity))
    push('view_cart', { currency: CURRENCY, value: value(items), items })
    gtag('event', 'view_cart', { currency: CURRENCY, value: value(items), items })
  },

  beginCheckout(lines) {
    const items = lines.map((l) => item(l.product, l.quantity))
    if (!items.length) return
    push('begin_checkout', { currency: CURRENCY, value: value(items), items })
    fbq('track', 'InitiateCheckout', fbContents(items))
    gtag('event', 'begin_checkout', { currency: CURRENCY, value: value(items), items })
  },

  /** `order` is the API order (number, items, totals); `eventId` is shared with the server. */
  purchase(order, eventId) {
    const items = (order.items || []).map((l) => ({ item_id: String(l.product_id), item_name: l.name, price: Number(l.unit_price), quantity: Number(l.quantity) }))
    const goods = Math.round((Number(order.grand_total) - Number(order.shipping || 0)) * 100) / 100
    push('purchase', { transaction_id: order.number, currency: CURRENCY, value: goods, shipping: Number(order.shipping || 0), items }, { event_id: eventId })
    fbq('track', 'Purchase', { ...fbContents(items), value: goods }, { eventID: eventId })
    gtag('event', 'purchase', { transaction_id: order.number, currency: CURRENCY, value: goods, shipping: Number(order.shipping || 0), items })
    if (cfg?.google_ads_id && cfg?.google_ads_purchase_label) {
      gtag('event', 'conversion', { send_to: `${cfg.google_ads_id}/${cfg.google_ads_purchase_label}`, value: goods, currency: CURRENCY, transaction_id: order.number })
    }
  },

  search(term) {
    if (!term) return
    push('search', null, { search_term: term })
    fbq('track', 'Search', { search_string: term })
    gtag('event', 'search', { search_term: term })
  },

  signUp() {
    push('sign_up', null, { method: 'phone' })
    fbq('track', 'CompleteRegistration')
    gtag('event', 'sign_up', { method: 'phone' })
  },

  login() {
    push('login', null, { method: 'phone' })
    gtag('event', 'login', { method: 'phone' })
  },

  /** Browser ids the server needs for its own copy of the purchase event. */
  serverContext(eventId) {
    const ga = cookie('_ga')?.split('.')
    return {
      event_id: eventId, fbp: cookie('_fbp'), fbc: cookie('_fbc'),
      ga_client_id: ga && ga.length >= 4 ? `${ga[2]}.${ga[3]}` : null,
      page_url: w()?.location?.href?.slice(0, 500) || null,
    }
  },
}

/** Event methods queue until init(); init and serverContext run directly. */
export const tracking = new Proxy(api, {
  get(target, key) {
    const fn = target[key]
    if (typeof fn !== 'function' || key === 'init' || key === 'serverContext') return fn
    return (...args) => (ready ? fn.apply(target, args) : queue.push([fn, args]))
  },
})
