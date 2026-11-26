<script setup lang="ts">
import { RefreshCw, Bike, MapPin, Store, Phone, Timer, Navigation, MapPinOff, Settings2 } from 'lucide-vue-next'
import type { DeliveryLive, OrderStatus } from '~/types/orders'

/**
 * Delivery › Live map: branches, riders (last reported position) and open own-rider
 * orders (customer pin, coloured by status), refreshed every 15 seconds.
 */
definePageMeta({ title: 'Live map' })
const api = useDeliveryTrackingApi()
const auth = useAuthStore()
const gm = useGoogleMaps()

const REFRESH_SECONDS = 15
const data = ref<DeliveryLive | null>(null)
const error = ref('')
const loading = ref(false)
const updatedAt = ref<number | null>(null)
const now = ref(Date.now())

const mapEl = ref<HTMLElement | null>(null)
let map: any = null
let info: any = null
let drawn: any[] = []
let fitted = false
const riderMarkers = new Map<number, any>()
const orderMarkers = new Map<number, any>()

const usable = computed(() => mapsUsable(data.value?.maps))
const ORDER_COLOR: Partial<Record<OrderStatus, string>> = { confirmed: PIN_COLORS.confirmed, packed: PIN_COLORS.packed, in_transit: PIN_COLORS.in_transit }

async function load() {
  if (loading.value) return
  loading.value = true
  try {
    data.value = await api.live()
    error.value = ''
    updatedAt.value = Date.now()
    await draw()
  }
  catch (e: any) { error.value = e?.message ?? 'Could not load the live map.' }
  finally { loading.value = false }
}

// ---- refresh every 15 s while the tab is visible ----
let timer: ReturnType<typeof setInterval> | null = null
const visibility = useDocumentVisibility()
onMounted(() => {
  load()
  timer = setInterval(() => { now.value = Date.now(); if (visibility.value === 'visible') load() }, REFRESH_SECONDS * 1000)
})
watch(visibility, (v) => { if (v === 'visible' && updatedAt.value && Date.now() - updatedAt.value > REFRESH_SECONDS * 1000) load() })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
const clock = setInterval(() => (now.value = Date.now()), 5000)
onBeforeUnmount(() => clearInterval(clock))

// ---- map drawing ----
const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
function riderColor(r: DeliveryLive['riders'][number]) {
  if (!r.onDuty) return PIN_COLORS.riderStale
  return r.position?.is_live ? PIN_COLORS.riderLive : '#f59e0b'
}

async function draw() {
  const d = data.value
  if (!d || !mapsUsable(d.maps)) return
  const g = await gm.init(d.maps)
  await nextTick()
  if (!g || !mapEl.value) return
  if (!map) {
    map = gm.createMap(mapEl.value, { lat: d.maps.defaultLat, lng: d.maps.defaultLng }, 12)
    info = gm.infoWindow()
  }
  gm.clear(drawn)
  drawn = []
  riderMarkers.clear()
  orderMarkers.clear()
  const open = (marker: any, html: string) => marker.addListener('click', () => { info.setContent(html); info.open({ map, anchor: marker }) })

  for (const s of d.stores) {
    const m = gm.pin(map, { lat: s.lat, lng: s.lng }, { color: PIN_COLORS.store, label: 'S', title: s.name, scale: 12, zIndex: 10 })
    open(m, `<div style="font:13px Inter,sans-serif"><b>${esc(s.name)}</b><br>${esc(s.address)}</div>`)
    drawn.push(m)
  }
  for (const o of d.orders) {
    if (!hasPosition(o.lat, o.lng)) continue
    const m = gm.pin(map, { lat: o.lat!, lng: o.lng! }, { color: ORDER_COLOR[o.status] ?? PIN_COLORS.customer, title: `${o.number} · ${ORDER_STATUS_LABEL[o.status]}`, scale: 8, zIndex: 20 })
    open(m, `<div style="font:13px Inter,sans-serif;max-width:240px"><b>${esc(o.number)}</b> · ${esc(ORDER_STATUS_LABEL[o.status])}<br>${esc(o.customer)}<br><span style="color:#64748b">${esc(o.address)}</span>`
      + `${o.riderName ? `<br>Rider: ${esc(o.riderName)}` : ''}${o.eta ? `<br><b>Arrives in ~${o.eta.minutes} min</b>` : ''}<br><a href="/orders/${o.id}">Open order</a></div>`)
    drawn.push(m)
    orderMarkers.set(o.id, m)
  }
  for (const r of d.riders) {
    if (!r.position) continue
    const m = gm.pin(map, { lat: r.position.lat, lng: r.position.lng }, {
      color: riderColor(r), label: initials(r.name).slice(0, 2), title: r.name, scale: 14, zIndex: 30,
      opacity: r.position.is_live ? 1 : 0.75,
    })
    open(m, `<div style="font:13px Inter,sans-serif"><b>${esc(r.name)}</b> · ${r.onDuty ? 'on duty' : 'off duty'}<br>${esc(r.phone)}<br>`
      + `<span style="color:#64748b">Position ${r.position.is_live ? 'live' : 'stale'}, ${esc(timeAgo(r.position.updated_at))}</span>`
      + `${r.orders.length ? `<br>Carrying: ${r.orders.map(esc).join(', ')}` : ''}</div>`)
    drawn.push(m)
    riderMarkers.set(r.id, m)
  }
  if (!fitted) {
    const pts = [
      ...d.stores.map(s => ({ lat: s.lat, lng: s.lng })),
      ...d.riders.filter(r => r.position).map(r => ({ lat: r.position!.lat, lng: r.position!.lng })),
      ...d.orders.filter(o => hasPosition(o.lat, o.lng)).map(o => ({ lat: o.lat!, lng: o.lng! })),
    ]
    gm.fit(map, pts, { lat: d.maps.defaultLat, lng: d.maps.defaultLng })
    fitted = true
  }
}

function focusRider(id: number) {
  const m = riderMarkers.get(id)
  if (!m || !map) return
  map.panTo(m.getPosition()); map.setZoom(Math.max(map.getZoom() ?? 14, 15))
  window.google?.maps?.event?.trigger(m, 'click')
}
function focusOrder(id: number) {
  const m = orderMarkers.get(id)
  if (!m || !map) return
  map.panTo(m.getPosition()); map.setZoom(Math.max(map.getZoom() ?? 14, 15))
  window.google?.maps?.event?.trigger(m, 'click')
}

// ---- side list ----
const ordersOf = (riderId: number) => (data.value?.orders ?? []).filter(o => o.riderId === riderId)
const unassigned = computed(() => (data.value?.orders ?? []).filter(o => !o.riderId))
const riders = computed(() => [...(data.value?.riders ?? [])].sort((a, b) => Number(b.onDuty) - Number(a.onDuty) || ordersOf(b.id).length - ordersOf(a.id).length || a.name.localeCompare(b.name)))
const stats = computed(() => {
  const d = data.value
  return {
    onDuty: d?.riders.filter(r => r.onDuty).length ?? 0,
    live: d?.riders.filter(r => r.position?.is_live).length ?? 0,
    inTransit: d?.orders.filter(o => o.status === 'in_transit').length ?? 0,
    noPin: d?.orders.filter(o => !hasPosition(o.lat, o.lng)).length ?? 0,
  }
})
const updatedLabel = computed(() => (updatedAt.value ? timeAgo(new Date(updatedAt.value).toISOString(), now.value) : ''))
const dotColor = (status: OrderStatus) => ORDER_COLOR[status] ?? PIN_COLORS.customer
</script>

<template>
  <div>
    <UiPageHeader title="Live map" :description="`Riders' last reported positions and open orders with their arrival times. Refreshes every ${REFRESH_SECONDS} seconds.`">
      <span v-if="updatedLabel" class="text-[12.5px] text-slate-500">Updated {{ updatedLabel }}</span>
      <UiButton :icon="RefreshCw" :loading="loading" @click="load">Refresh</UiButton>
    </UiPageHeader>

    <p v-if="error && !data" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
    <div v-else-if="!data" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]"><div class="skeleton h-[560px]" /><div class="skeleton h-[560px]" /></div>

    <template v-else>
      <p v-if="error" class="mb-4 rounded-xl bg-amber-50 px-4 py-2.5 text-[13px] text-amber-800">Last refresh failed: {{ error }}. Showing the previous data.</p>

      <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card px-4 py-3"><p class="label">Riders on duty</p><p class="mt-1 font-display text-2xl font-extrabold tabular">{{ stats.onDuty }}</p></div>
        <div class="card px-4 py-3"><p class="label">Live positions</p><p class="mt-1 font-display text-2xl font-extrabold tabular">{{ stats.live }}</p></div>
        <div class="card px-4 py-3"><p class="label">In transit</p><p class="mt-1 font-display text-2xl font-extrabold tabular">{{ stats.inTransit }}</p></div>
        <div class="card px-4 py-3"><p class="label">Open orders without a pin</p><p class="mt-1 font-display text-2xl font-extrabold tabular">{{ stats.noPin }}</p></div>
      </div>

      <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <!-- Map -->
        <section class="card overflow-hidden">
          <div v-if="usable && !gm.error.value" ref="mapEl" class="h-[60vh] min-h-[420px] w-full bg-slate-100" />
          <div v-else class="grid h-[320px] place-items-center p-6 text-center">
            <div class="max-w-sm">
              <MapPinOff class="mx-auto size-8 text-slate-300" />
              <p class="mt-2 font-semibold text-ink">{{ gm.error.value ? 'The map could not load' : 'Google Maps is off' }}</p>
              <p class="mt-1 text-[13px] text-slate-500">{{ gm.error.value || 'Switch it on and add a browser key in Administration › Integrations › Maps. The list on the right still works.' }}</p>
              <NuxtLink v-if="auth.can('settings.manage')" to="/admin/integrations" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline"><Settings2 class="size-4" />Open Integrations</NuxtLink>
            </div>
          </div>
          <div v-if="usable" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2.5 text-[12px] text-slate-600">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.store }" />Branch</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.riderLive }" />Rider, live</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-amber-500" />Rider, position stale</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.riderStale }" />Rider off duty</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.confirmed }" />Confirmed</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.packed }" />Packed</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" :style="{ background: PIN_COLORS.in_transit }" />In transit</span>
          </div>
        </section>

        <!-- Riders and their orders -->
        <aside class="space-y-3">
          <section v-for="r in riders" :key="r.id" class="card p-4">
            <div class="flex items-start gap-3">
              <button type="button" class="relative shrink-0" :aria-label="`Show ${r.name} on the map`" :disabled="!r.position || !usable" @click="focusRider(r.id)">
                <span class="grid size-9 place-items-center rounded-full bg-gradient-to-br from-brand-300 to-brand-600 text-[11px] font-bold text-white">{{ initials(r.name) }}</span>
                <span class="absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-white" :style="{ background: riderColor(r) }" />
              </button>
              <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2">
                  <span class="truncate font-semibold text-ink">{{ r.name }}</span>
                  <UiBadge :tone="r.onDuty ? (r.position?.is_live ? 'success' : 'warning') : 'neutral'" dot>{{ r.onDuty ? (r.position?.is_live ? 'Live' : 'On duty') : 'Off duty' }}</UiBadge>
                </p>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-slate-500">
                  <a :href="`tel:${r.phone}`" class="inline-flex items-center gap-1 tabular hover:text-brand-700"><Phone class="size-3" />{{ r.phone }}</a>
                  <span class="inline-flex items-center gap-1"><Navigation class="size-3" />{{ r.position?.updated_at ? `seen ${timeAgo(r.position.updated_at, now)}` : 'no position yet' }}</span>
                </p>
              </div>
            </div>
            <ul v-if="ordersOf(r.id).length" class="mt-3 space-y-1.5 border-t border-slate-100 pt-3">
              <li v-for="o in ordersOf(r.id)" :key="o.id" class="flex items-center gap-2 text-[13px]">
                <span class="size-2 shrink-0 rounded-full" :style="{ background: dotColor(o.status) }" />
                <NuxtLink :to="`/orders/${o.id}`" class="font-mono text-[12px] font-bold text-ink hover:text-brand-700">{{ o.number }}</NuxtLink>
                <span class="min-w-0 flex-1 truncate text-slate-500">{{ o.customer }}</span>
                <span v-if="o.eta" class="inline-flex shrink-0 items-center gap-1 font-semibold text-brand-700 tabular" :title="`${o.eta.source === 'google' ? 'Google traffic-aware' : 'Estimate'}, arrives ${dateTime(o.eta.at)}`"><Timer class="size-3" />{{ o.eta.minutes }} min</span>
                <span v-else class="shrink-0 text-[11.5px] text-slate-400">{{ ORDER_STATUS_LABEL[o.status] }}</span>
                <button v-if="usable && hasPosition(o.lat, o.lng)" type="button" class="shrink-0 rounded p-0.5 text-slate-400 hover:text-brand-700" :aria-label="`Show ${o.number} on the map`" @click="focusOrder(o.id)"><MapPin class="size-3.5" /></button>
              </li>
            </ul>
            <p v-else class="mt-2 text-xs text-slate-400">No open orders</p>
          </section>
          <p v-if="!riders.length" class="card p-5 text-center text-sm text-slate-500"><Bike class="mx-auto mb-1 size-5 text-slate-300" />No active riders.</p>

          <section v-if="unassigned.length" class="card p-4">
            <p class="label mb-2 flex items-center gap-1.5"><Store class="size-3.5" />Waiting for a rider ({{ unassigned.length }})</p>
            <ul class="space-y-1.5">
              <li v-for="o in unassigned" :key="o.id" class="flex items-center gap-2 text-[13px]">
                <span class="size-2 shrink-0 rounded-full" :style="{ background: dotColor(o.status) }" />
                <NuxtLink :to="`/orders/${o.id}`" class="font-mono text-[12px] font-bold text-ink hover:text-brand-700">{{ o.number }}</NuxtLink>
                <span class="min-w-0 flex-1 truncate text-slate-500">{{ o.customer }}</span>
                <span v-if="!hasPosition(o.lat, o.lng)" class="shrink-0 text-[11px] text-amber-600">no pin</span>
                <button v-else-if="usable" type="button" class="shrink-0 rounded p-0.5 text-slate-400 hover:text-brand-700" :aria-label="`Show ${o.number} on the map`" @click="focusOrder(o.id)"><MapPin class="size-3.5" /></button>
              </li>
            </ul>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
