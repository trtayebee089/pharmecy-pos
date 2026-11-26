<template>
  <div class="relative overflow-hidden bg-slate-100">
    <div ref="mapEl" class="absolute inset-0"></div>
    <p v-if="!ready && !failed" class="absolute inset-0 grid place-items-center text-sm font-semibold text-slate-500">Loading map…</p>
    <p v-if="failed" class="absolute inset-0 grid place-items-center p-4 text-center text-sm text-slate-500">The map is unavailable right now.</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useSiteStore } from '../../stores/siteStore'
import { BASE_MAP_OPTIONS, loadGoogleMaps } from '../../composables/useGoogleMaps'

/**
 * Store, customer and rider on one map. Markers are kept and moved on every poll;
 * the view is fitted once, and again only when a marker leaves it.
 */
const props = defineProps({
  store: { type: Object, default: null }, // { lat, lng, name }
  customer: { type: Object, default: null }, // { lat, lng }
  rider: { type: Object, default: null }, // { lat, lng, heading, is_live, name }
})
const emit = defineEmits(['unavailable'])

const site = useSiteStore()
const mapEl = ref(null)
const ready = ref(false)
const failed = ref(false)
const map = shallowRef(null)
let lib = null
const markers = {}

const svg = (body, size = 40) => ({
  url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 40 40">${body}</svg>`)}`,
})
const ICONS = {
  // Teal shop badge
  store: () => ({ ...svg('<circle cx="20" cy="20" r="17" fill="#0f766e" stroke="#fff" stroke-width="3"/><path d="M12 17h16l-1.6-5H13.6zM13 18v9h14v-9M18 27v-5h4v5" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/>'), anchor: new lib.Point(20, 20), scaledSize: new lib.Size(36, 36) }),
  // Red drop pin (the customer's door)
  customer: () => ({ ...svg('<path d="M20 38s13-12.4 13-22a13 13 0 1 0-26 0c0 9.6 13 22 13 22z" fill="#dc2632" stroke="#fff" stroke-width="2.5"/><circle cx="20" cy="16" r="5" fill="#fff"/>'), anchor: new lib.Point(20, 38), scaledSize: new lib.Size(38, 38) }),
  // Green rider dot with a heading arrow; grey when the position is stale
  rider: (live, heading) => ({
    ...svg(`<g transform="rotate(${Number(heading) || 0} 20 20)"><path d="M20 2l5 8h-10z" fill="${live ? '#059669' : '#94a3b8'}" opacity="${heading == null ? 0 : 1}"/></g><circle cx="20" cy="20" r="12" fill="${live ? '#059669' : '#94a3b8'}" stroke="#fff" stroke-width="3"/><circle cx="20" cy="20" r="4" fill="#fff"/>`),
    anchor: new lib.Point(20, 20), scaledSize: new lib.Size(40, 40),
  }),
}

const valid = (p) => p && Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng))
const pos = (p) => ({ lat: Number(p.lat), lng: Number(p.lng) })

function sync() {
  if (!map.value) return
  const points = { store: props.store, customer: props.customer, rider: props.rider }
  let outside = false
  let added = false
  for (const [kind, p] of Object.entries(points)) {
    if (!valid(p)) {
      markers[kind]?.setMap(null)
      delete markers[kind]
      continue
    }
    const icon = kind === 'rider' ? ICONS.rider(p.is_live !== false, p.heading) : ICONS[kind]()
    const title = kind === 'store' ? (p.name || 'Pharmacy') : kind === 'customer' ? 'Delivery location' : (p.name ? `${p.name} (rider)` : 'Rider')
    if (markers[kind]) {
      markers[kind].setPosition(pos(p))
      markers[kind].setIcon(icon)
    } else {
      markers[kind] = new lib.Marker({ map: map.value, position: pos(p), icon, title, zIndex: kind === 'rider' ? 3 : 1 })
      added = true
    }
    const bounds = map.value.getBounds()
    if (bounds && !bounds.contains(pos(p))) outside = true
  }
  if (added || outside) fit()
}

function fit() {
  const list = Object.values(markers)
  if (!list.length) return
  if (list.length === 1) {
    map.value.setCenter(list[0].getPosition())
    map.value.setZoom(15)
    return
  }
  const bounds = new lib.LatLngBounds()
  list.forEach((m) => bounds.extend(m.getPosition()))
  map.value.fitBounds(bounds, 48)
}

onMounted(async () => {
  try {
    const { Map, ...rest } = await loadGoogleMaps(site.maps.browser_key)
    lib = rest
    if (!mapEl.value) return
    map.value = new Map(mapEl.value, { ...BASE_MAP_OPTIONS, center: site.maps.default_center, zoom: 13 })
    ready.value = true
    sync()
  } catch {
    failed.value = true
    emit('unavailable')
  }
})

watch(() => [props.store, props.customer, props.rider], sync, { deep: true })

onBeforeUnmount(() => {
  Object.values(markers).forEach((m) => m.setMap(null))
  map.value = null
})
</script>
