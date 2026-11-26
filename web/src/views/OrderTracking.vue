<template>
  <div class="mx-auto max-w-2xl px-4 py-10">
    <!-- Search -->
    <div class="rounded-3xl bg-white p-6 shadow-soft">
      <h1 class="text-2xl font-black text-ink sm:text-3xl">Track your order</h1>
      <p class="mt-1 text-sm text-slate-500">Enter the tracking code from your order confirmation (it starts with RP).</p>
      <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="track()">
        <label class="sr-only" for="tracking-code">Tracking code</label>
        <input id="tracking-code" v-model.trim="code" required placeholder="RPXXXXXXXX" autocomplete="off" class="min-w-0 flex-1 rounded-2xl bg-slate-50 px-4 py-3 font-semibold uppercase tracking-wider outline-none focus:ring-2 focus:ring-primary-500" />
        <button :disabled="loading" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ loading && !order ? 'Checking…' : 'Track order' }}</button>
      </form>
      <p v-if="error" class="mt-4 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
    </div>

    <div v-if="loading && !order" class="mt-4 space-y-4" aria-hidden="true">
      <div class="h-40 animate-pulse rounded-3xl bg-white"></div>
      <div class="h-72 animate-pulse rounded-3xl bg-white"></div>
    </div>

    <template v-if="order">
      <!-- Summary -->
      <section class="mt-4 rounded-3xl bg-white p-6 shadow-soft" aria-live="polite">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tracking code</p>
            <p class="mt-1 flex items-center gap-2 text-xl font-black tracking-wider text-ink">
              {{ order.tracking_code }}
              <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="copied ? 'Copied' : 'Copy tracking link'" @click="copyLink">
                <Check v-if="copied" class="h-4 w-4 text-primary-600" /><Copy v-else class="h-4 w-4" />
              </button>
            </p>
            <p class="mt-0.5 text-sm text-slate-500">Order {{ order.number }}</p>
          </div>
          <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold" :class="pill">
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ order.status_label }}
          </span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-5 text-sm sm:grid-cols-4">
          <div><dt class="text-xs text-slate-500">Placed</dt><dd class="mt-1 font-bold text-ink">{{ dateTime(order.placed_at) }}</dd></div>
          <div><dt class="text-xs text-slate-500">Items</dt><dd class="mt-1 font-bold text-ink">{{ order.items_count }}</dd></div>
          <div>
            <dt class="text-xs text-slate-500">{{ order.payment_status === 'paid' ? 'Payment' : 'Cash on delivery' }}</dt>
            <dd class="mt-1 font-bold text-ink">{{ order.payment_status === 'paid' ? 'Paid online' : formatPrice(order.cod_amount) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-slate-500">Delivery by</dt>
            <dd class="mt-1 font-bold text-ink">
              <a v-if="order.courier?.tracking_url" :href="order.courier.tracking_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-primary-700 underline">{{ order.courier.name }} <ExternalLink class="h-3.5 w-3.5" /></a>
              <span v-else>{{ order.courier?.name || site.shop.siteName || 'Our rider' }}</span>
            </dd>
          </div>
        </dl>

        <!-- Progress -->
        <div v-if="order.status !== 'cancelled'" class="mt-6">
          <div class="relative">
            <!-- The line runs between the centres of the first and last step (five equal columns). -->
            <div class="absolute left-[10%] right-[10%] top-[17px] h-1 rounded-full bg-slate-100"></div>
            <div class="absolute left-[10%] top-[17px] h-1 rounded-full bg-primary-600 transition-[width] duration-700 ease-out" :style="{ width: `${80 * progressRatio}%` }"></div>
            <ol class="relative grid grid-cols-5">
              <li v-for="(step, i) in STEPS" :key="step.status" class="flex flex-col items-center text-center">
                <button
                  type="button"
                  class="grid h-9 w-9 place-items-center rounded-full ring-4 ring-white transition"
                  :class="i < reached ? 'bg-primary-600 text-white' : i === reached ? 'bg-primary-600 text-white shadow-lg shadow-primary-600/30' : 'bg-slate-100 text-slate-400'"
                  :disabled="!stepEntry(step.status)"
                  :aria-label="`${step.label}${stepEntry(step.status) ? ', ' + dateTime(stepEntry(step.status).at) : ', not yet'}`"
                  @click="focusStep(step.status)"
                >
                  <component :is="step.icon" class="h-4 w-4" />
                </button>
                <span class="mt-2 text-[11px] font-semibold leading-tight sm:text-xs" :class="i <= reached ? 'text-ink' : 'text-slate-400'">{{ step.label }}</span>
              </li>
            </ol>
          </div>
        </div>
        <p v-else class="mt-5 rounded-2xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">This order was cancelled.</p>
      </section>

      <!-- Live delivery: rider on the map and arrival time (in transit), or delivery time (delivered) -->
      <LiveDeliveryCard v-if="order.live" class="mt-4" :live="order.live" :fetch-live="fetchLive" @status-change="track(true)" />

      <!-- Updates -->
      <section class="mt-4 rounded-3xl bg-white p-6 shadow-soft">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-lg font-black text-ink">Tracking updates</h2>
          <button type="button" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-50" :disabled="loading" @click="track(true)">
            <RefreshCw class="h-3.5 w-3.5" :class="loading && 'animate-spin'" /> Refresh
          </button>
        </div>
        <ol class="mt-5">
          <li
            v-for="(entry, i) in visibleUpdates"
            :key="`${entry.status}-${entry.at}`"
            :data-status="entry.status"
            class="relative flex gap-4 pb-6 last:pb-0"
          >
            <span v-if="i < visibleUpdates.length - 1" class="absolute left-[15px] top-8 bottom-0 w-px bg-slate-200" aria-hidden="true"></span>
            <span
              class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full transition"
              :class="[i === 0 ? (order.status === 'cancelled' ? 'bg-red-600 text-white' : 'bg-primary-600 text-white') : 'bg-slate-100 text-slate-500', highlighted === entry.status && 'ring-4 ring-primary-200']"
            >
              <span v-if="i === 0 && !closed" class="absolute inset-0 animate-ping rounded-full bg-primary-400 opacity-30"></span>
              <component :is="i === 0 ? iconFor(entry.status) : Check" class="relative h-4 w-4" />
            </span>
            <div class="min-w-0 pt-1" :class="highlighted === entry.status && 'rounded-xl bg-primary-50/60 -m-2 p-2'">
              <p class="text-sm font-semibold" :class="i === 0 ? 'text-ink' : 'text-slate-700'">{{ describe(entry) }}</p>
              <p class="mt-0.5 text-xs text-slate-500">{{ dateTime(entry.at) }}</p>
            </div>
          </li>
        </ol>
        <button v-if="updates.length > COLLAPSED" type="button" class="mt-4 text-sm font-bold text-primary-700 hover:underline" @click="showAll = !showAll">
          {{ showAll ? 'Show fewer updates' : `Show all ${updates.length} updates` }}
        </button>
      </section>

      <!-- Help -->
      <section v-if="site.shop.phone || site.shop.whatsapp" class="mt-4 rounded-3xl bg-white p-6 shadow-soft">
        <h2 class="text-lg font-black text-ink">Need help with your order?</h2>
        <p class="mt-1 text-sm text-slate-500">Message us on WhatsApp or call the hotline with your tracking code.</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <a v-if="site.shop.whatsapp" :href="`https://wa.me/${waNumber}?text=${encodeURIComponent('Order ' + order.number + ' (' + order.tracking_code + ')')}`" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-bold text-ink hover:border-primary-300 hover:bg-primary-50">
            <MessageCircle class="h-4 w-4 text-primary-600" /> Chat on WhatsApp
          </a>
          <a v-if="site.shop.phone" :href="`tel:${site.shop.phone}`" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100">
            <Phone class="h-4 w-4" /> {{ site.shop.phone }}
          </a>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Ban, Bike, Check, CheckCircle2, ClipboardCheck, Copy, ExternalLink, MessageCircle, Package, Phone, ReceiptText, RefreshCw } from 'lucide-vue-next'
import { orderService } from '../services/orderService'
import LiveDeliveryCard from '../components/maps/LiveDeliveryCard.vue'
import { useSiteStore } from '../stores/siteStore'
import { formatPrice } from '../utils/formatPrice'

const STEPS = [
  { status: 'pending', label: 'Placed', icon: ReceiptText },
  { status: 'confirmed', label: 'Confirmed', icon: ClipboardCheck },
  { status: 'packed', label: 'Packed', icon: Package },
  { status: 'in_transit', label: 'On the way', icon: Bike },
  { status: 'delivered', label: 'Delivered', icon: CheckCircle2 },
]
const COLLAPSED = 6
const REFRESH_MS = 60_000

const route = useRoute()
const router = useRouter()
const site = useSiteStore()
const code = ref(String(route.query.code || ''))
const order = ref(null)
const error = ref('')
const loading = ref(false)
const showAll = ref(false)
const copied = ref(false)
const highlighted = ref(null)
let timer = null

const closed = computed(() => ['delivered', 'cancelled'].includes(order.value?.status))
/** Newest first; back-to-back updates with the same status (e.g. a payment change) show once, at the latest time. */
const updates = computed(() => [...(order.value?.history || [])].reverse().filter((h, i, all) => i === 0 || all[i - 1].status !== h.status))
const visibleUpdates = computed(() => (showAll.value ? updates.value : updates.value.slice(0, COLLAPSED)))
const reached = computed(() => Math.max(0, STEPS.findIndex((s) => s.status === order.value?.status)))
const progressRatio = computed(() => reached.value / (STEPS.length - 1))
const pill = computed(() => ({
  delivered: 'bg-primary-50 text-primary-700',
  cancelled: 'bg-red-50 text-red-700',
}[order.value?.status] || 'bg-sky-50 text-sky-700'))
const waNumber = computed(() => {
  const digits = String(site.shop.whatsapp || '').replace(/\D/g, '')
  return digits.startsWith('880') ? digits : `88${digits}`
})

const dateTime = (v) => (v ? new Intl.DateTimeFormat('en-BD', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : '')
const iconFor = (status) => (status === 'cancelled' ? Ban : STEPS.find((s) => s.status === status)?.icon || Check)
const stepEntry = (status) => order.value?.history?.find((h) => h.status === status)

/** A sentence per update, like a courier's tracking page. */
function describe(entry) {
  return {
    pending: 'Order placed. Our pharmacist will call to confirm it.',
    confirmed: 'Order confirmed and medicines reserved for you.',
    packed: 'Order packed and ready for delivery.',
    in_transit: order.value?.courier ? `Handed to ${order.value.courier.name} for delivery.` : 'Out for delivery with our rider.',
    delivered: 'Delivered. Thank you for ordering with us!',
    cancelled: 'Order cancelled.',
  }[entry.status] || entry.label
}

async function focusStep(status) {
  const index = updates.value.findIndex((h) => h.status === status)
  if (index < 0) return
  if (index >= COLLAPSED) showAll.value = true
  highlighted.value = status
  await nextTick()
  document.querySelector(`[data-status="${status}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  setTimeout(() => { if (highlighted.value === status) highlighted.value = null }, 2500)
}

const fetchLive = () => orderService.trackLive(order.value.tracking_code)

async function copyLink() {
  const url = `${location.origin}/track-order?code=${order.value.tracking_code}`
  try {
    await navigator.clipboard.writeText(url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch { /* clipboard blocked */ }
}

async function track(quiet = false) {
  if (!code.value) return
  loading.value = true
  if (!quiet) { error.value = ''; order.value = null; showAll.value = false }
  try {
    order.value = await orderService.track(code.value)
    if (route.query.code !== code.value.toUpperCase()) router.replace({ query: { code: code.value.toUpperCase() } })
    schedule()
  } catch (e) {
    if (!quiet) error.value = e.status === 404 ? 'No order with that tracking code. Please check the code.' : e.message
  } finally {
    loading.value = false
  }
}

/** Keeps an open order fresh while the page is visible. */
function schedule() {
  clearTimeout(timer)
  if (order.value && !closed.value) timer = setTimeout(() => { if (!document.hidden) track(true); else schedule() }, REFRESH_MS)
}

onMounted(() => {
  site.load()
  if (code.value) track()
})
onBeforeUnmount(() => clearTimeout(timer))
</script>
