<script setup lang="ts">
import { Store, MapPin, Timer, Route as RouteIcon, ExternalLink } from 'lucide-vue-next'
import type { OrderRoute } from '~/types/orders'
import type { LatLng, MapsConfig } from '~/composables/useGoogleMaps'

/**
 * The path the rider took for one order (GET orders/{id}/route): store and customer
 * markers with the reported positions drawn as a line between them.
 */
const open = defineModel<boolean>({ default: false })
const props = defineProps<{ orderId: number; number: string }>()

const api = useDeliveryTrackingApi()
const gm = useGoogleMaps()
const route = ref<OrderRoute | null>(null)
const maps = ref<MapsConfig | null>(null)
const error = ref('')
const loading = ref(false)
const el = ref<HTMLElement | null>(null)

/** Distance along the reported points (haversine), in km. */
const km = computed(() => {
  const p = route.value?.points ?? []
  let m = 0
  for (let i = 1; i < p.length; i++) m += haversine(p[i - 1]!, p[i]!)
  return m / 1000
})
function haversine(a: LatLng, b: LatLng) {
  const R = 6371000
  const rad = (d: number) => (d * Math.PI) / 180
  const dLat = rad(b.lat - a.lat)
  const dLng = rad(b.lng - a.lng)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(h))
}

watch(open, async (v) => {
  if (!v) return
  loading.value = true
  error.value = ''
  try { [route.value, maps.value] = await Promise.all([api.route(props.orderId), api.mapsConfig()]) }
  catch (e: any) { error.value = e?.message ?? 'Could not load the route.'; return }
  finally { loading.value = false }
  await draw()
})

async function draw() {
  const r = route.value
  if (!r || !maps.value || !mapsUsable(maps.value)) return
  const g = await gm.init(maps.value)
  await nextTick()
  if (!g || !el.value) return
  gm.clear()
  const map = gm.createMap(el.value, { lat: maps.value.defaultLat, lng: maps.value.defaultLng }, 13)
  const path = r.points.map(p => ({ lat: p.lat, lng: p.lng }))
  if (path.length > 1) gm.line(map, path, { color: PIN_COLORS.in_transit, weight: 5 })
  else if (r.store && r.customer) gm.line(map, [r.store, r.customer], { dashed: true })
  if (r.store) gm.pin(map, r.store, { color: PIN_COLORS.store, label: 'S', title: r.store.name, scale: 12, zIndex: 10 })
  if (r.customer) gm.pin(map, r.customer, { color: PIN_COLORS.customer, label: 'C', title: 'Customer', scale: 12, zIndex: 10 })
  const last = path[path.length - 1]
  if (last && !r.deliveredAt) gm.pin(map, last, { color: PIN_COLORS.riderLive, title: 'Last reported position', scale: 8, zIndex: 20 })
  gm.fit(map, [...(r.store ? [r.store] : []), ...(r.customer ? [r.customer] : []), ...path], { lat: maps.value.defaultLat, lng: maps.value.defaultLng })
}
</script>

<template>
  <UiModal v-model="open" :title="`Route · ${number}`" description="Positions reported by the rider app while the order was on its way." size="xl">
    <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
    <div v-else-if="loading || !route" class="skeleton h-[420px]" />
    <template v-else>
      <div v-if="maps && mapsUsable(maps) && !gm.error.value" ref="el" class="h-[420px] w-full overflow-hidden rounded-xl bg-slate-100" />
      <p v-else class="rounded-xl bg-slate-50 px-4 py-3 text-[13px] text-slate-600">
        {{ gm.error.value || 'Google Maps is off (Administration › Integrations › Maps), so the route is summarised below.' }}
      </p>
      <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
        <div><dt class="label">Picked up</dt><dd class="mt-0.5 font-semibold">{{ route.pickedUpAt ? dateTime(route.pickedUpAt) : 'Not yet' }}</dd></div>
        <div><dt class="label">Delivered</dt><dd class="mt-0.5 font-semibold">{{ route.deliveredAt ? dateTime(route.deliveredAt) : 'Not yet' }}</dd></div>
        <div><dt class="label flex items-center gap-1"><Timer class="size-3" />Delivery time</dt><dd class="mt-0.5 font-semibold tabular">{{ route.deliveryMinutes != null ? `${route.deliveryMinutes} min` : '—' }}</dd></div>
        <div><dt class="label flex items-center gap-1"><RouteIcon class="size-3" />Distance travelled</dt><dd class="mt-0.5 font-semibold tabular">{{ route.points.length > 1 ? `${km.toFixed(1)} km · ${route.points.length} points` : 'No positions reported' }}</dd></div>
      </dl>
      <div class="mt-3 flex flex-wrap gap-3 text-[13px]">
        <a v-if="route.store" :href="googleMapsLink(route.store)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-700 hover:underline"><Store class="size-3.5" />{{ route.store.name }} <ExternalLink class="size-3" /></a>
        <span v-else class="text-amber-700">The branch has no map position (Administration › Branches).</span>
        <a v-if="route.customer" :href="googleMapsLink(route.customer)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-700 hover:underline"><MapPin class="size-3.5" />Customer pin <ExternalLink class="size-3" /></a>
        <span v-else class="text-slate-500">The customer did not drop a pin.</span>
      </div>
    </template>
    <template #footer>
      <UiButton variant="ghost" @click="open = false">Close</UiButton>
    </template>
  </UiModal>
</template>
