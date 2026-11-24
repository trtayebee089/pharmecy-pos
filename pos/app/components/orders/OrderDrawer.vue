<script setup lang="ts">
import { gsap } from 'gsap'
import { MapPin, Phone, Bike, Layers, ArrowRight, Ban, Globe, Smartphone, Info } from 'lucide-vue-next'
import type { OnlineOrder, OrderStatus, Rider } from '~/types/orders'

const open = defineModel<boolean>({ default: false })
const props = defineProps<{ order: OnlineOrder | null; riders: Rider[] }>()
const emit = defineEmits<{ changed: [order: OnlineOrder] }>()

const api = useOnlineOrdersApi()
const toast = useToast()
const { ask } = useConfirm()
const busy = ref(false)
const riderId = ref<number | null>(null)
const timeline = ref<HTMLElement | null>(null)

watch(() => props.order?.id, () => { riderId.value = props.order?.riderId ?? null })
watch(open, async (o) => {
  if (!o) return
  await nextTick()
  if (timeline.value && !prefersReducedMotion()) {
    gsap.fromTo(timeline.value.querySelectorAll('[data-step]'), { opacity: 0, x: -10 }, { opacity: 1, x: 0, stagger: 0.07, delay: 0.25, duration: 0.35 })
  }
})

const next = computed(() => props.order ? nextOrderStatus(props.order.status) : null)
const closed = computed(() => props.order?.status === 'delivered' || props.order?.status === 'cancelled')
const activeRiders = computed(() => props.riders.filter(r => r.status === 'active'))
const nextHint: Partial<Record<OrderStatus, string>> = {
  confirmed: 'Confirming allocates stock from the earliest-expiry batches, exactly once.',
  packed: 'Packing locks the allocated batches to this order.',
  in_transit: 'Hand-over to the rider. A rider must be assigned first.',
  delivered: 'Delivery records the cash-on-delivery payment and completes the sale.',
}

async function advance() {
  if (!props.order || !next.value) return
  if (next.value === 'in_transit' && !props.order.riderId) { toast.warning('Assign a rider first', 'In-transit orders must have a rider.'); return }
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
  <UiDrawer v-model="open" width="lg">
    <template #header>
      <div v-if="order" class="flex flex-wrap items-center gap-2">
        <h2 class="font-mono text-lg font-bold text-ink">{{ order.number }}</h2>
        <UiBadge :tone="ORDER_STATUS_TONE[order.status]" dot>{{ ORDER_STATUS_LABEL[order.status] }}</UiBadge>
      </div>
      <p v-if="order" class="mt-0.5 flex items-center gap-1.5 text-sm text-slate-500">
        <component :is="order.channel === 'app' ? Smartphone : Globe" class="size-3.5" />
        {{ order.channel === 'app' ? 'Android app' : 'Website' }} · placed {{ dateTime(order.placedAt) }} · legacy {{ order.legacyRef }}
      </p>
    </template>

    <div v-if="order" class="space-y-5">
      <div data-stagger class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 p-4">
          <p class="label mb-2">Customer</p>
          <p class="text-sm font-semibold">{{ order.customer }}</p>
          <a :href="`tel:${order.phone}`" class="mt-1 flex items-center gap-1.5 text-sm text-brand-700 tabular hover:underline"><Phone class="size-3.5" />{{ order.phone }}</a>
        </div>
        <div class="rounded-2xl border border-slate-200 p-4">
          <p class="label mb-2">Delivery address</p>
          <p class="flex items-start gap-1.5 text-sm"><MapPin class="mt-0.5 size-3.5 shrink-0 text-slate-400" /><span>{{ order.address }}, {{ order.thana }}, {{ order.district }}, {{ order.division }}</span></p>
          <p class="mt-1 text-xs text-slate-500">Tracking {{ order.trackingCode }}</p>
        </div>
      </div>

      <div data-stagger>
        <p class="label mb-2">Items</p>
        <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
          <li v-for="(l, i) in order.lines" :key="i" class="flex items-start justify-between gap-3 p-3.5 text-sm">
            <div class="min-w-0">
              <p class="font-semibold">{{ l.productName }}</p>
              <p class="text-xs text-slate-500">{{ l.qty }} × {{ l.unitLabel }} @ {{ money(l.unitPrice) }}</p>
              <p class="mt-0.5 flex items-center gap-1 text-xs" :class="l.batchNo ? 'text-slate-500' : 'text-amber-600'">
                <Layers class="size-3" />{{ l.batchNo ? `Batch ${l.batchNo}` : 'Not allocated yet' }}
              </p>
            </div>
            <span class="font-semibold tabular">{{ money(l.lineTotal) }}</span>
          </li>
        </ul>
        <dl class="mt-3 space-y-1.5 text-sm">
          <div class="flex justify-between text-slate-500"><dt>Subtotal</dt><dd class="tabular">{{ money(order.subtotal) }}</dd></div>
          <div class="flex justify-between text-slate-500"><dt>Delivery ({{ order.zone }})</dt><dd class="tabular">{{ order.shipping ? money(order.shipping) : 'Free' }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-2 font-display text-lg font-extrabold"><dt>Cash on delivery</dt><dd class="tabular">{{ money(order.codAmount) }}</dd></div>
        </dl>
      </div>

      <div v-if="!closed" data-stagger class="rounded-2xl bg-slate-50 p-4">
        <p class="label mb-2 flex items-center gap-1.5"><Bike class="size-3.5" />Rider</p>
        <div class="flex gap-2">
          <select v-model="riderId" class="h-10 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-brand-400 focus:outline-none" aria-label="Rider">
            <option :value="null" disabled>Choose a rider</option>
            <option v-for="r in activeRiders" :key="r.id" :value="r.id">{{ r.name }} · {{ r.assignedOrders }} active</option>
          </select>
          <UiButton :disabled="!riderId || riderId === order.riderId || busy" @click="assign">Assign</UiButton>
        </div>
      </div>

      <div data-stagger>
        <p class="label mb-3">Status history</p>
        <ol ref="timeline" class="relative ml-2 space-y-4 border-l-2 border-slate-100 pl-5">
          <li v-for="(h, i) in order.history" :key="i" data-step class="relative">
            <span class="absolute top-1 -left-[27px] size-3 rounded-full ring-4 ring-white" :class="i === order.history.length - 1 ? 'bg-brand-500' : 'bg-slate-300'" />
            <p class="text-sm font-semibold">{{ ORDER_STATUS_LABEL[h.status] }}</p>
            <p class="text-xs text-slate-500">{{ dateTime(h.at) }} · {{ h.by }}</p>
            <p v-if="h.note" class="mt-1 text-xs text-slate-600">“{{ h.note }}”</p>
          </li>
        </ol>
      </div>

      <p v-if="next && nextHint[next]" data-stagger class="flex items-start gap-2 rounded-xl bg-brand-50 p-3 text-xs text-brand-800"><Info class="mt-0.5 size-3.5 shrink-0" />{{ nextHint[next] }}</p>
    </div>

    <template #footer>
      <UiButton v-if="order && !closed" variant="ghost" :icon="Ban" class="mr-auto hover:!text-rose-600" :disabled="busy" @click="cancel">Cancel order</UiButton>
      <UiButton v-if="next" variant="primary" :icon-right="ArrowRight" :loading="busy" @click="advance">Mark {{ ORDER_STATUS_LABEL[next].toLowerCase() }}</UiButton>
      <UiButton v-else variant="ghost" @click="open = false">Close</UiButton>
    </template>
  </UiDrawer>
</template>
