/**
 * Google Maps for the delivery screens (Branches map picker, Delivery › Live map,
 * the online order's route). The Maps JavaScript API is loaded lazily, once per
 * session, with the public browser key from Administration › Integrations › Maps
 * (restrict that key to the POS and website addresses in Google Cloud).
 * Nothing here calls a paid server API; arrival times come from the Laravel API.
 */
declare global {
  interface Window { google?: any; __rpMapsReady?: () => void }
}

/** Integrations › Maps as the POS API returns it (the server key is never sent back). */
export interface MapsConfig {
  enabled: boolean
  browserKey: string
  defaultLat: number
  defaultLng: number
  averageSpeedKmh: number
  serverKeySet?: boolean
}

export interface LatLng { lat: number; lng: number }

let loading: Promise<any> | null = null
let loadedKey = ''

/** Loads the Maps JavaScript API (once) and resolves with `google.maps`. */
export function loadGoogleMaps(key: string): Promise<any> {
  if (!import.meta.client) return Promise.reject(new Error('Maps load in the browser only.'))
  if (window.google?.maps?.Map) return Promise.resolve(window.google.maps)
  if (loading && loadedKey === key) return loading
  loadedKey = key
  loading = new Promise((resolve, reject) => {
    window.__rpMapsReady = async () => {
      try {
        const maps = window.google.maps
        // With loading=async the classes arrive through importLibrary.
        if (typeof maps.importLibrary === 'function') await Promise.all([maps.importLibrary('maps'), maps.importLibrary('marker'), maps.importLibrary('geometry')])
        resolve(window.google.maps)
      }
      catch (e) { loading = null; reject(e) }
    }
    const s = document.createElement('script')
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&loading=async&callback=__rpMapsReady`
    s.async = true
    s.onerror = () => { loading = null; s.remove(); reject(new Error('Google Maps could not load. Check the browser key and its allowed websites.')) }
    document.head.appendChild(s)
  })
  return loading
}

/** True when maps are switched on and have a browser key. */
export const mapsUsable = (c: MapsConfig | null | undefined): c is MapsConfig => !!c?.enabled && !!c.browserKey

/** A link that opens a position in Google Maps (new tab). */
export const googleMapsLink = (p: LatLng) => `https://www.google.com/maps/search/?api=1&query=${p.lat},${p.lng}`

export const hasPosition = (lat: number | null | undefined, lng: number | null | undefined) =>
  lat !== null && lat !== undefined && lng !== null && lng !== undefined && !Number.isNaN(Number(lat)) && !Number.isNaN(Number(lng))

/** Brand colours of the map pins, shared by every delivery map. */
export const PIN_COLORS = {
  store: '#0b655e',
  customer: '#e11d48',
  riderLive: '#16a34a',
  riderStale: '#94a3b8',
  confirmed: '#0ea5e9',
  packed: '#8b5cf6',
  in_transit: '#0e9f8f',
} as const

/**
 * Map helpers for one page: create a map in an element, add coloured pins and
 * polylines, fit the view. Every object made here is removed on unmount.
 */
export function useGoogleMaps() {
  let g: any = null
  const objects: any[] = []
  const error = ref('')
  const ready = ref(false)

  async function init(config: MapsConfig): Promise<any | null> {
    error.value = ''
    if (!mapsUsable(config)) return null
    try { g = await loadGoogleMaps(config.browserKey); ready.value = true; return g }
    catch (e: any) { error.value = e?.message ?? 'Google Maps could not load.'; return null }
  }

  function createMap(el: HTMLElement, center: LatLng, zoom = 13, extra: Record<string, any> = {}) {
    return new g.Map(el, {
      center, zoom, clickableIcons: false, streetViewControl: false, mapTypeControl: false, fullscreenControl: true,
      gestureHandling: 'cooperative', ...extra,
    })
  }

  /** A round pin: filled circle with a white ring; `label` is one or two characters. */
  function pin(map: any, at: LatLng, opts: { color: string; title?: string; label?: string; scale?: number; opacity?: number; zIndex?: number; draggable?: boolean }) {
    const marker = new g.Marker({
      map, position: at, title: opts.title, draggable: !!opts.draggable, zIndex: opts.zIndex,
      label: opts.label ? { text: opts.label, color: '#fff', fontSize: '11px', fontWeight: '700' } : undefined,
      icon: {
        path: g.SymbolPath.CIRCLE, scale: opts.scale ?? 11, fillColor: opts.color, fillOpacity: opts.opacity ?? 1,
        strokeColor: '#ffffff', strokeWeight: 2.5,
      },
    })
    objects.push(marker)
    return marker
  }

  /** A classic drop pin (for "pick on map"). */
  function dropPin(map: any, at: LatLng, opts: { title?: string; draggable?: boolean } = {}) {
    const marker = new g.Marker({ map, position: at, title: opts.title, draggable: !!opts.draggable })
    objects.push(marker)
    return marker
  }

  function line(map: any, path: LatLng[], opts: { color?: string; weight?: number; dashed?: boolean } = {}) {
    const poly = new g.Polyline({
      map, path, geodesic: true,
      strokeColor: opts.color ?? PIN_COLORS.in_transit, strokeOpacity: opts.dashed ? 0 : 0.9, strokeWeight: opts.weight ?? 4,
      icons: opts.dashed ? [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.8, strokeColor: opts.color ?? '#64748b', scale: 3 }, offset: '0', repeat: '14px' }] : undefined,
    })
    objects.push(poly)
    return poly
  }

  function infoWindow() {
    const w = new g.InfoWindow()
    objects.push(w)
    return w
  }

  /** Zooms to show every point; one point → centre on it. */
  function fit(map: any, points: LatLng[], fallback?: LatLng) {
    if (!points.length) { if (fallback) map.setCenter(fallback); return }
    if (points.length === 1) { map.setCenter(points[0]); map.setZoom(15); return }
    const b = new g.LatLngBounds()
    points.forEach(p => b.extend(p))
    map.fitBounds(b, 48)
  }

  function clear(list?: any[]) {
    const all = list ?? objects
    for (const o of all) { try { o.setMap?.(null); o.close?.() } catch { /* already gone */ } }
    if (!list) objects.length = 0
    else for (const o of list) { const i = objects.indexOf(o); if (i >= 0) objects.splice(i, 1) }
  }

  onBeforeUnmount(() => clear())

  return { init, createMap, pin, dropPin, line, infoWindow, fit, clear, error, ready, maps: () => g }
}
