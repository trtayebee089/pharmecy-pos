<script setup lang="ts">
import { gsap } from 'gsap'
import { MapPin, Phone, Bike, Layers, ArrowRight, Ban, Globe, Smartphone, Info, Truck, ExternalLink, RefreshCw, Printer, Wallet, History, PackageOpen } from 'lucide-vue-next'
import type { OnlineOrder, OrderStatus, Rider } from '~/types/orders'

/** The order page (/orders/[id]): everything about one online order and its next step. */
const props = defineProps<{ order: OnlineOrder; riders: Rider[] }>()
const emit = defineEmits<{ changed: [order: OnlineOrder] }>()

const api = useOnlineOrdersApi()
const toast = useToast()
const { ask } = useConfirm()
const busy = ref(false)
const riderId = ref<number | null>(null)
const timeline = ref<HTMLElement | null>(null)

watch(() => [props.order.id, props.order.riderId], () => { riderId.value = props.order.riderId ?? null }, { immediate: true })
const gatewayLabel = computed(() => (props.order.paymentMethod === 'bkash' ? 'bKash' : 'SSLCommerz'))
const print = () => window.print()

// ---------------------------------------------------------------- courier (Steadfast / Pathao)
const available = ref<{ steadfast: boolean; pathao: boolean }>({ steadfast: false, pathao: false })
const courier = ref<'steadfast' | 'pathao'>('steadfast')
const courierNote = ref('')
const pathao = reactive<{ cityId: number | null; zoneId: number | null; areaId: number | null }>({ cityId: null, zoneId: null, areaId: null })
const pathaoCities = ref<{ id: number; name: string }[]>([])
const pathaoZones = ref<{ id: number; name: string }[]>([])
const pathaoAreas = ref<{ id: number; name: string }[]>([])
const courierLabel = (c: string) => (c === 'pathao' ? 'Pathao' : 'Steadfast')
const courierOptions = computed(() => (['steadfast', 'pathao'] as const).filter(c => available.value[c]).map(c => ({ value: c, label: courierLabel(c) })))
const canSend = computed(() => !!props.order && ['confirmed', 'packed'].includes(props.order.status) && props.order.paymentStatus !== 'pending' && courierOptions.value.length > 0)

onMounted(async () => {
  try { available.value = await api.couriers() } catch { /* couriers stay hidden */ }
  if (!available.value.steadfast && available.value.pathao) courier.value = 'pathao'
})
watch([courier, () => props.order?.id], async () => {
  if (courier.value !== 'pathao' || !props.order) return
  const place = props.order.pathaoPlace
  Object.assign(pathao, { cityId: place?.cityId ?? null, zoneId: place?.zoneId ?? null, areaId: place?.areaId ?? null })
  try {
    if (!pathaoCities.value.length) pathaoCities.value = await api.pathaoPlaces()
    pathaoZones.value = pathao.cityId ? await api.pathaoPlaces({ city_id: pathao.cityId }) : []
    pathaoAreas.value = pathao.zoneId ? await api.pathaoPlaces({ zone_id: pathao.zoneId }) : []
  } catch (e: any) { toast.error('Pathao places not loaded', e?.message) }
})
async function loadZones() {
  pathao.zoneId = pathao.areaId = null
  pathaoAreas.value = []
  pathaoZones.value = pathao.cityId ? await api.pathaoPlaces({ city_id: pathao.cityId }) : []
}
async function loadAreas() {
  pathao.areaId = null
  pathaoAreas.value = pathao.zoneId ? await api.pathaoPlaces({ zone_id: pathao.zoneId }) : []
}
async function sendToCourier() {
  if (!props.order) return
  busy.value = true
  try {
    const o = await api.sendToCourier(props.order.id, {
      courier: courier.value, note: courierNote.value || undefined,
      ...(courier.value === 'pathao' ? { city_id: pathao.cityId, zone_id: pathao.zoneId, area_id: pathao.areaId } : {}),
    }, crypto.randomUUID())
    toast.success(`Sent with ${courierLabel(courier.value)}`, `${o.number} · ${o.courier?.consignmentId ?? ''}`)
    courierNote.value = ''
    emit('changed', o)
  }
  catch (e: any) { toast.error('Not sent to the courier', e?.message) }
  finally { busy.value = false }
}
async function refreshCourier() {
  if (!props.order) return
  busy.value = true
  try { emit('changed', await api.refreshCourier(props.order.id)) }
  catch (e: any) { toast.error('Status not refreshed', e?.message) }
  finally { busy.value = false }
}
onMounted(async () => {
  await nextTick()
  if (timeline.value && !prefersReducedMotion()) {
    gsap.fromTo(timeline.value.querySelectorAll('[data-step]'), { opacity: 0, x: -10 }, { opacity: 1, x: 0, stagger: 0.07, delay: 0.15, duration: 0.35 })
  }
})

const next = computed(() => props.order ? nextOrderStatus(props.order.status) : null)
const closed = computed(() => props.order?.status === 'delivered' || props.order?.status === 'cancelled')
const activeRiders = computed(() => props.riders.filter(r => r.status === 'active'))
const nextHint: Partial<Record<OrderStatus, string>> = {
  confirmed: 'Confirming allocates stock from the earliest-expiry batches, exactly once.',
  packed: 'Packing locks the allocated batches to this order.',
  in_transit: 'Hand-over to the rider (assign one first), or use Send to courier above.',
  delivered: 'Delivery records the cash-on-delivery payment and completes the sale.',
}

async function advance() {
  if (!props.order || !next.value) return
  if (next.value === 'in_transit' && !props.order.riderId && !props.order.courier) { toast.warning('Assign a rider first', 'Or send the order with a courier.'); return }
  busy.value = true
  try {
    const o = await api.transition(props.order.id, next.value, '', crypto.randomUUID())
    toast.success(`Order ${ORDER_STATUS_LABEL[o.status].toLowerCase()}`, o.number)
    emit('changed', o)
  }
  catch (e: any) { toast.error('Status not changed', e?.message) }
  finally { busy.value = false }
}

async function cancel() {
  if (!props.order) return
  const { ok, reason } = await ask({
    title: `Cancel order ${props.order.number}?`,
    description: props.order.status === 'pending' ? 'No stock was allocated yet.' : 'Allocated stock is released back to the same batches. The order stays on record as cancelled.',
    confirmLabel: 'Cancel order', requireReason: true,
  })
  if (!ok) return
  busy.value = true
  try {
    const o = await api.transition(props.order.id, 'cancelled', reason, crypto.randomUUID())
    toast.success('Order cancelled', o.number)
    emit('changed', o)
  }
  catch (e: any) { toast.error('Not cancelled', e?.message) }
  finally { busy.value = false }
}

async function assign() {
  const rider = props.riders.find(r => r.id === riderId.value)
  if (!props.order || !rider) return
  busy.value = true
  try {
    const o = await api.assignRider(props.order.id, rider, crypto.randomUUID())
    toast.success('Rider assigned', `${rider.name} → ${o.number}`)
    emit('changed', o)
  }
  catch (e: any) { toast.error('Rider not assigned', e?.message) }
  finally { busy.value = false }
}
</script>

<template>
  <div>
    <!-- Header: number, status, channel, and the next step -->
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
          <h1 class="font-mono text-2xl font-bold text-ink">{{ order.number }}</h1>
          <UiBadge :tone="ORDER_STATUS_TONE[order.status]" dot>{{ ORDER_STATUS_LABEL[order.status] }}</UiBadge>
          <span v-if="order.paymentStatus === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Awaiting payment</span>
          <span v-else-if="order.paymentStatus === 'paid'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">Paid online</span>
          <span v-else-if="order.paymentStatus === 'refund_due'" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700">Refund due</span>
        </div>
        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
          <component :is="order.channel === 'app' ? Smartphone : order.channel === 'phone' ? Phone : Globe" class="size-3.5" />
          {{ order.channel === 'app' ? 'Android app' : order.channel === 'phone' ? 'Phone order' : 'Website' }} · placed {{ dateTime(order.placedAt) }}
          <template v-if="order.legacyRef"> · legacy {{ order.legacyRef }}</template>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 print:hidden">
        <UiButton variant="ghost" :icon="Printer" @click="print">Print slip</UiButton>
        <UiButton v-if="!closed" variant="ghost" :icon="Ban" class="hover:!text-rose-600" :disabled="busy" @click="cancel">Cancel order</UiButton>
        <UiButton v-if="next" variant="primary" :icon-right="ArrowRight" :loading="busy" @click="advance">Mark {{ ORDER_STATUS_LABEL[next].toLowerCase() }}</UiButton>
      </div>
    </div>
    <p v-if="next && nextHint[next]" class="mb-5 flex items-start gap-2 rounded-xl bg-brand-50 p-3 text-[13px] text-brand-800 print:hidden"><Info class="mt-0.5 size-4 shrink-0" />{{ nextHint[next] }}</p>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
      <!-- Left: items, totals, history -->
      <div class="min-w-0 space-y-5">
        <section class="card p-5">
          <p class="label mb-3 flex items-center gap-1.5"><PackageOpen class="size-3.5" />Items ({{ order.lines.length }})</p>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[480px] text-sm">
              <thead class="text-left text-xs text-slate-500">
                <tr><th class="pb-2 font-medium">Medicine</th><th class="pb-2 font-medium">Batch</th><th class="pb-2 text-right font-medium">Qty</th><th class="pb-2 text-right font-medium">Price</th><th class="pb-2 text-right font-medium">Total</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(l, i) in order.lines" :key="i">
                  <td class="py-2.5 pr-3 font-semibold">{{ l.productName }}</td>
                  <td class="py-2.5 pr-3 text-xs" :class="l.batchNo ? 'text-slate-500' : 'text-amber-600'"><span class="inline-flex items-center gap-1"><Layers class="size-3" />{{ l.batchNo || 'Not allocated yet' }}</span></td>
                  <td class="py-2.5 text-right tabular">{{ l.qty }} {{ l.unitLabel }}</td>
                  <td class="py-2.5 text-right tabular">{{ money(l.unitPrice) }}</td>
                  <td class="py-2.5 text-right font-semibold tabular">{{ money(l.lineTotal) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <dl class="mt-4 ml-auto max-w-sm space-y-1.5 border-t border-slate-100 pt-3 text-sm">
            <div class="flex justify-between text-slate-500"><dt>Subtotal</dt><dd class="tabular">{{ money(order.subtotal) }}</dd></div>
            <div class="flex justify-between text-slate-500"><dt>Delivery ({{ order.zone }})</dt><dd class="tabular">{{ order.shipping ? money(order.shipping) : 'Free' }}</dd></div>
            <div v-if="order.pointsValue" class="flex justify-between text-slate-500"><dt>Reward points</dt><dd class="tabular">−{{ money(order.pointsValue) }}</dd></div>
            <div v-if="order.paidOnline" class="flex justify-between text-emerald-700"><dt>Paid online ({{ gatewayLabel }})</dt><dd class="tabular">−{{ money(order.paidOnline) }}</dd></div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-display text-lg font-extrabold"><dt>Cash on delivery</dt><dd class="tabular">{{ money(order.codAmount) }}</dd></div>
          </dl>
        </section>

        <section class="card p-5">
          <p class="label mb-3 flex items-center gap-1.5"><History class="size-3.5" />Status history</p>
          <ol ref="timeline" class="relative ml-2 space-y-4 border-l-2 border-slate-100 pl-5">
            <li v-for="(h, i) in order.history" :key="i" data-step class="relative">
              <span class="absolute top-1 -left-[27px] size-3 rounded-full ring-4 ring-white" :class="i === order.history.length - 1 ? 'bg-brand-500' : 'bg-slate-300'" />
              <p class="text-sm font-semibold">{{ ORDER_STATUS_LABEL[h.status] }}</p>
              <p class="text-xs text-slate-500">{{ dateTime(h.at) }} · {{ h.by }}</p>
              <p v-if="h.note" class="mt-1 text-[13px] text-slate-600">“{{ h.note }}”</p>
            </li>
          </ol>
        </section>
      </div>

      <!-- Right: customer, payment, courier / rider -->
      <aside class="space-y-5">
        <section class="card p-5 text-sm">
          <p class="label mb-2">Customer</p>
          <p class="font-semibold">{{ order.customer }}</p>
          <a :href="`tel:${order.phone}`" class="mt-1 inline-flex items-center gap-1.5 text-brand-700 tabular hover:underline"><Phone class="size-3.5" />{{ order.phone }}</a>
          <p class="label mt-4 mb-2">Delivery address</p>
          <p class="flex items-start gap-1.5"><MapPin class="mt-0.5 size-3.5 shrink-0 text-slate-400" /><span>{{ order.address }}, {{ order.thana }}, {{ order.district }}, {{ order.division }}</span></p>
          <p class="mt-2 text-xs text-slate-500">Tracking code <span class="font-mono font-semibold text-slate-700">{{ order.trackingCode }}</span></p>
        </section>

        <section class="card p-5 text-sm">
          <p class="label mb-2 flex items-center gap-1.5"><Wallet class="size-3.5" />Payment</p>
          <p class="font-semibold">{{ order.paymentMethod && order.paymentMethod !== 'cod' ? gatewayLabel : 'Cash on delivery' }}</p>
          <p v-if="order.paymentStatus === 'pending'" class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-[13px] text-amber-800">Waiting for the customer's online payment. Confirm it once paid, or after the customer switches to cash on delivery.</p>
          <p v-else-if="order.paymentStatus === 'refund_due'" class="mt-2 rounded-xl bg-rose-50 px-3 py-2 text-[13px] text-rose-700">Paid online but cancelled: refund {{ money(order.paidOnline) }} from the {{ gatewayLabel }} merchant panel.</p>
          <p v-else class="mt-1 text-slate-500">To collect on delivery: <span class="font-semibold text-ink tabular">{{ money(order.codAmount) }}</span></p>
        </section>

        <!-- Courier: booked parcel, or "Send to courier" once confirmed -->
        <section v-if="order.courier" class="card bg-sky-50/60 p-5 text-sm print:hidden">
          <p class="label mb-2 flex items-center gap-1.5"><Truck class="size-3.5" />Courier</p>
          <p class="font-semibold">{{ courierLabel(order.courier.name) }} · {{ order.courier.consignmentId }}</p>
          <p class="mt-0.5 text-xs text-slate-600">
            Status: <span class="font-semibold capitalize">{{ (order.courier.status || 'booked').replaceAll('_', ' ') }}</span>
            <template v-if="order.courier.statusAt"> · {{ dateTime(order.courier.statusAt) }}</template>
            <template v-if="order.courier.fee != null"> · charge {{ money(order.courier.fee) }}</template>
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <a v-if="order.courier.trackingUrl" :href="order.courier.trackingUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-sky-800 ring-1 ring-sky-200 hover:bg-sky-100">Track parcel <ExternalLink class="size-3" /></a>
            <UiButton v-if="!closed" size="sm" variant="ghost" :icon="RefreshCw" :loading="busy" @click="refreshCourier">Refresh status</UiButton>
          </div>
        </section>
        <section v-else-if="canSend" class="card p-5 print:hidden">
          <p class="label mb-2 flex items-center gap-1.5"><Truck class="size-3.5" />Send to courier</p>
          <UiSegmented v-model="courier" :options="courierOptions" />
          <div v-if="courier === 'pathao'" class="mt-3 grid gap-2">
            <UiSelect v-model="pathao.cityId" placeholder="City" aria-label="Pathao city" :options="pathaoCities.map(c => ({ value: c.id, label: c.name }))" @change="loadZones" />
            <UiSelect v-model="pathao.zoneId" placeholder="Zone" aria-label="Pathao zone" :disabled="!pathao.cityId" :options="pathaoZones.map(z => ({ value: z.id, label: z.name }))" @change="loadAreas" />
            <UiSelect v-model="pathao.areaId" placeholder="Area (optional)" aria-label="Pathao area" :disabled="!pathao.zoneId" :options="[{ value: null, label: 'No area' }, ...pathaoAreas.map(a => ({ value: a.id, label: a.name }))]" />
          </div>
          <input v-model="courierNote" maxlength="250" placeholder="Note for the courier (optional)" class="mt-3 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-brand-400 focus:outline-none" aria-label="Note for the courier">
          <p class="mt-3 text-xs text-slate-500">The courier collects {{ money(order.codAmount) }}.</p>
          <UiButton class="mt-2 w-full" variant="primary" :icon="Truck" :loading="busy" :disabled="courier === 'pathao' && (!pathao.cityId || !pathao.zoneId)" @click="sendToCourier">Send with {{ courierLabel(courier) }}</UiButton>
        </section>

        <section v-if="!closed && !order.courier" class="card p-5 print:hidden">
          <p class="label mb-2 flex items-center gap-1.5"><Bike class="size-3.5" />Rider</p>
          <p v-if="order.riderName" class="mb-2 text-sm">Assigned: <span class="font-semibold">{{ order.riderName }}</span></p>
          <div class="flex gap-2">
            <UiSelect v-model="riderId" class="min-w-0 flex-1" placeholder="Choose a rider" aria-label="Rider" :options="activeRiders.map(r => ({ value: r.id, label: r.name, hint: `${r.assignedOrders} active orders · ${r.phone}` }))" />
            <UiButton :disabled="!riderId || riderId === order.riderId || busy" @click="assign">{{ order.riderId ? 'Change' : 'Assign' }}</UiButton>
          </div>
        </section>
        <section v-else-if="order.riderName" class="card p-5 text-sm">
          <p class="label mb-2 flex items-center gap-1.5"><Bike class="size-3.5" />Rider</p>
          <p class="font-semibold">{{ order.riderName }}</p>
        </section>
      </aside>
    </div>
  </div>
</template>
