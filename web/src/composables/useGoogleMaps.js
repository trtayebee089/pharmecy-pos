/**
 * Loads the Google Maps JavaScript API on demand (only the pages with a map call it),
 * once per visit, with the public browser key from site-config. Resolves with
 * `{ Map, Marker, LatLngBounds, Point, Size }`; rejects when the script cannot load
 * (offline, blocked, bad key), so callers can hide the map and carry on.
 */
let loading = null

const CALLBACK = '__redpharmaMapsReady'

export function loadGoogleMaps(key) {
  if (typeof window === 'undefined') return Promise.reject(new Error('No browser'))
  if (!key) return Promise.reject(new Error('Maps are not configured'))
  if (loading) return loading

  loading = new Promise((resolve, reject) => {
    if (window.google?.maps?.importLibrary) return resolve()
    window[CALLBACK] = () => { delete window[CALLBACK]; resolve() }
    // Google calls this on an invalid / restricted key; the map area then shows its own error.
    window.gm_authFailure = () => reject(new Error('Google Maps key was rejected'))
    const s = document.createElement('script')
    s.async = true
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&loading=async&callback=${CALLBACK}`
    s.onerror = () => reject(new Error('Google Maps could not be loaded'))
    document.head.appendChild(s)
  })
    .then(async () => {
      const [{ Map }, { Marker }, core] = await Promise.all([
        window.google.maps.importLibrary('maps'),
        window.google.maps.importLibrary('marker'),
        window.google.maps.importLibrary('core'),
      ])
      return { Map, Marker, LatLngBounds: core.LatLngBounds, Point: core.Point, Size: core.Size }
    })
    .catch((e) => {
      loading = null // allow a retry on the next page that needs a map
      throw e
    })

  return loading
}

/** Shared map options: clean, mobile friendly, no Street View / map-type clutter. */
export const BASE_MAP_OPTIONS = {
  disableDefaultUI: true,
  zoomControl: true,
  clickableIcons: false,
  gestureHandling: 'greedy',
  styles: [
    { featureType: 'poi.business', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  ],
}

/** The API accepts a pin inside Bangladesh only (checkout validation). */
export function inBangladesh(p) {
  return p && p.lat >= 20.3 && p.lat <= 26.8 && p.lng >= 88.0 && p.lng <= 92.8
}
