<template>
  <!-- On the way: ETA, rider and the live map -->
  <section v-if="current?.status === 'in_transit'" class="overflow-hidden rounded-3xl bg-white shadow-soft" aria-live="polite">
    <div class="p-5 sm:p-6">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-accent-700">
            <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-accent-500"></span></span>
            On the way
          </p>
          <p class="mt-1.5 text-2xl font-black text-ink sm:text-3xl">
            <template v-if="eta">Arriving in ~{{ eta.minutes }} min</template>
            <template v-else>Your order is on its way</template>
          </p>
          <p class="mt-0.5 text-sm text-slate-500">
            <template v-if="eta">Around {{ clock(eta.at) }}<span v-if="eta.distance_m"> · {{ distance(eta.distance_m) }} away</span></template>
            <template v-else>We will show the arrival time as soon as the rider's position comes in.</template>
          </p>
        </div>
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-accent-50 text-accent-700"><Bike class="h-6 w-6" /></span>
      </div>

      <div v-if="current.rider" class="mt-5 flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white font-black text-ink shadow-card">{{ initial }}</span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-bold text-ink">{{ current.rider.name }}</p>
          <p class="text-xs text-slate-500">{{ riderStatus }}</p>
        </div>
        <a v-if="current.rider.phone" :href="`tel:${current.rider.phone}`" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-accent-600 px-4 py-2 text-sm font-bold text-white hover:bg-accent-700">
          <Phone class="h-4 w-4" /> Call
        </a>
      </div>
    </div>

    <DeliveryMap
      v-if="showMap"
      class="h-64 sm:h-80"
      :store="current.store"
      :customer="current.customer"
      :rider="riderPoint"
      @unavailable="mapFailed = true"
    />
    <div v-if="showMap" class="flex flex-wrap gap-x-4 gap-y-1 px-5 py-3 text-xs text-slate-500 sm:px-6">
      <span v-if="current.store" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-tealbrand"></span>Pharmacy</span>
      <span v-if="current.customer" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary-600"></span>Your location</span>
      <span v-if="riderPoint" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-accent-600"></span>Rider</span>
    </div>
  </section>

  <!-- Delivered: how long the trip took -->
  <section v-else-if="current?.status === 'delivered' && current.delivery_minutes != null" class="flex items-center gap-4 rounded-3xl bg-white p-5 shadow-soft sm:p-6">
    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-accent-50 text-accent-700"><Timer class="h-6 w-6" /></span>
    <div class="min-w-0">
      <p class="text-lg font-black text-ink">Delivered in {{ current.delivery_minutes }} min</p>
      <p class="text-sm text-slate-500">From pickup at the pharmacy to your door<span v-if="current.delivered_at"> · {{ clock(current.delivered_at) }}</span>.</p>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Bike, Phone, Timer } from 'lucide-vue-next'
import DeliveryMap from './DeliveryMap.vue'
import { useSiteStore } from '../../stores/siteStore'

/**
 * Live delivery card for an order's `live` block. While the parcel is in transit it
 * polls `fetchLive()` every `poll_seconds` (only while the tab is visible) and stops
 * when the server returns `poll_seconds: null` or the card unmounts.
 */
const props = defineProps({
  live: { type: Object, default: null },
  /** async () => Live (orderService.live(number) or orderService.trackLive(code)) */
  fetchLive: { type: Function, required: true },
})
const emit = defineEmits(['status-change'])

const site = useSiteStore()
const current = ref(props.live)
const mapFailed = ref(false)
let timer = null
let waitingForVisible = false
let stopped = false

watch(() => props.live, (v) => { if (v) { current.value = v; schedule() } })

const eta = computed(() => current.value?.eta || null)
const riderPoint = computed(() => {
  const p = current.value?.rider?.position
  return p ? { ...p, name: current.value.rider.name } : null
})
const showMap = computed(() => site.mapsEnabled && !mapFailed.value && Boolean(current.value?.store || current.value?.customer || riderPoint.value))
const initial = computed(() => (current.value?.rider?.name || 'R').trim().charAt(0).toUpperCase())
const riderStatus = computed(() => {
  const p = current.value?.rider?.position
  if (!p) return 'Your rider'
  if (p.is_live) return 'Your rider · live location'
  return p.updated_at ? `Your rider · last seen ${clock(p.updated_at)}` : 'Your rider'
})

const clock = (v) => (v ? new Intl.DateTimeFormat('en-BD', { hour: 'numeric', minute: '2-digit' }).format(new Date(v)) : '')
const distance = (m) => (m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`)

function schedule() {
  clearTimeout(timer)
  timer = null
  const seconds = Number(current.value?.poll_seconds)
  if (stopped || !seconds || seconds <= 0) return
  timer = setTimeout(poll, seconds * 1000)
}

async function poll() {
  timer = null
  if (document.hidden) { waitingForVisible = true; return } // resumes on visibilitychange
  const before = current.value?.status
  try {
    const fresh = await props.fetchLive()
    if (fresh) {
      current.value = fresh
      if (fresh.status !== before) emit('status-change', fresh)
    }
  } catch { /* network blip: keep the last view and try again next round */ }
  schedule()
}

function onVisibility() {
  if (!document.hidden && waitingForVisible) { waitingForVisible = false; poll() }
}

onMounted(() => {
  document.addEventListener('visibilitychange', onVisibility)
  site.load()
  schedule()
})
onBeforeUnmount(() => {
  stopped = true
  clearTimeout(timer)
  timer = null
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>
