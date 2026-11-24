<script setup lang="ts">
import { gsap } from 'gsap'
import { Flip } from 'gsap/Flip'
import { ArrowRight, Bike, MapPin, RefreshCw, ListOrdered, GripVertical, PackageCheck, Package, Truck, CircleCheckBig } from 'lucide-vue-next'
import type { OnlineOrder, OrderStatus, Rider } from '~/types/orders'

definePageMeta({ title: 'Fulfilment board' })
gsap.registerPlugin(Flip)

const api = useOnlineOrdersApi()
const ridersRes = useRidersResource()
const toast = useToast()

const orders = ref<OnlineOrder[]>([])
const riders = ref<Rider[]>([])
const loading = ref(true)
const busyId = ref<number | null>(null)
const board = ref<HTMLElement | null>(null)

const columns: { status: OrderStatus; title: string; icon: any; accent: string }[] = [
  { status: 'confirmed', title: 'Confirmed', icon: PackageCheck, accent: 'bg-sky-500' },
  { status: 'packed', title: 'Packed', icon: Package, accent: 'bg-violet-500' },
  { status: 'in_transit', title: 'In transit', icon: Truck, accent: 'bg-brand-500' },
  { status: 'delivered', title: 'Delivered today', icon: CircleCheckBig, accent: 'bg-emerald-500' },
]

async function load() {
  loading.value = true
  try { [orders.value, riders.value] = await Promise.all([api.all(), ridersRes.all()]) }
  catch (e: any) { toast.error('Could not load the board', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(board.value?.querySelectorAll('[data-card]') ?? [], { y: 12, stagger: 0.03, duration: 0.4 })
}
onMounted(load)

const isToday = (iso: string) => new Date(iso).toDateString() === new Date().toDateString()
function cards(status: OrderStatus) {
  return orders.value.filter(o => o.status === status && (status !== 'delivered' || isToday(o.history[o.history.length - 1]!.at)))
}
const codOf = (status: OrderStatus) => cards(status).reduce((s, o) => s + o.codAmount, 0)
const pendingCount = computed(() => orders.value.filter(o => o.status === 'pending').length)

/** Advance one step with a FLIP animation of the moving card. */
async function advance(o: OnlineOrder) {
  const to = nextOrderStatus(o.status)
  if (!to || busyId.value) return
  if (to === 'in_transit' && !o.riderId) {
    toast.warning('Assign a rider first', `${o.number} needs a rider before it leaves the store.`)
    selectedId.value = o.id
    return
  }
  busyId.value = o.id
  try {
    const updated = await api.transition(o.id, to, '', crypto.randomUUID())
    const state = Flip.getState(board.value?.querySelectorAll('[data-card]') ?? [])
    const i = orders.value.findIndex(x => x.id === o.id)
    orders.value[i] = { ...updated }
    await nextTick()
    if (!prefersReducedMotion()) {
      Flip.from(state, {
        duration: 0.6, ease: 'power3.inOut', absolute: true, nested: true,
        onEnter: els => gsap.fromTo(els, { opacity: 0, scale: 0.9 }, { opacity: 1, scale: 1, duration: 0.4 }),
      })
      const moved = board.value?.querySelector(`[data-card="${o.id}"]`)
      if (moved) gsap.fromTo(moved, { boxShadow: '0 0 0 3px rgb(14 159 143 / .45)' }, { boxShadow: '0 0 0 0px rgb(14 159 143 / 0)', duration: 1.2, delay: 0.5 })
    }
    toast.success(`${updated.number} → ${ORDER_STATUS_LABEL[to]}`)
  }
  catch (e: any) { toast.error('Could not move the order', e?.message) }
  finally { busyId.value = null }
}

// ---- Drag and drop (forward one step only) ----
const dragId = ref<number | null>(null)
const overCol = ref<OrderStatus | null>(null)
const dragged = computed(() => orders.value.find(o => o.id === dragId.value) ?? null)
const canDrop = (status: OrderStatus) => !!dragged.value && nextOrderStatus(dragged.value.status) === status
function onDragStart(e: DragEvent, o: OnlineOrder) {
  dragId.value = o.id
  e.dataTransfer?.setData('text/plain', String(o.id))
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}
function onDragOver(e: DragEvent, status: OrderStatus) {
  if (!canDrop(status)) return
  e.preventDefault()
  overCol.value = status
}
function onDrop(status: OrderStatus) {
  const o = dragged.value
  overCol.value = null
  dragId.value = null
  if (o && nextOrderStatus(o.status) === status) advance(o)
}
function onDragEnd() { dragId.value = null; overCol.value = null }

const selectedId = ref<number | null>(null)
const selected = computed(() => orders.value.find(o => o.id === selectedId.value) ?? null)
const drawer = computed({ get: () => selectedId.value !== null, set: v => { if (!v) selectedId.value = null } })
function onChanged(o: OnlineOrder) {
  const i = orders.value.findIndex(x => x.id === o.id)
  if (i >= 0) orders.value[i] = { ...o }
}
</script>

<template>
  <div>
    <UiPageHeader title="Fulfilment board" description="Drag a card to the next column or use its arrow. Stock was allocated when the order was confirmed.">
      <UiButton :icon="RefreshCw" :loading="loading" @click="load">Refresh</UiButton>
      <NuxtLink to="/orders"><UiButton variant="primary" :icon="ListOrdered">All orders<UiBadge v-if="pendingCount" tone="warning" class="ml-1">{{ pendingCount }} pending</UiBadge></UiButton></NuxtLink>
    </UiPageHeader>

    <div v-if="loading && !orders.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div v-for="i in 4" :key="i" class="skeleton h-96 rounded-2xl" />
    </div>

    <div v-else ref="board" class="grid grid-cols-[minmax(0,1fr)] gap-4 md:grid-cols-2 xl:grid-cols-4">
      <section
        v-for="col in columns" :key="col.status"
        class="flex min-h-[320px] flex-col rounded-2xl border bg-slate-100/60 p-3 transition-colors"
        :class="overCol === col.status ? 'border-brand-400 bg-brand-50/70' : canDrop(col.status) ? 'border-dashed border-brand-300' : 'border-transparent'"
        :aria-label="`${col.title} column`"
        @dragover="onDragOver($event, col.status)" @dragleave="overCol = null" @drop.prevent="onDrop(col.status)"
      >
        <header class="mb-3 flex items-center gap-2 px-1">
          <span class="size-2 rounded-full" :class="col.accent" />
          <component :is="col.icon" class="size-4 text-slate-500" />
          <h2 class="text-sm font-bold text-ink">{{ col.title }}</h2>
          <span class="rounded-md bg-white px-1.5 text-[11px] font-semibold text-slate-500 tabular">{{ cards(col.status).length }}</span>
          <span class="ml-auto text-[11.5px] font-semibold text-slate-500 tabular">{{ money(codOf(col.status)) }}</span>
        </header>

        <div class="flex flex-1 flex-col gap-2.5">
          <article
            v-for="o in cards(col.status)" :key="o.id" :data-card="o.id"
            :draggable="col.status !== 'delivered'"
            class="group relative cursor-grab rounded-xl border border-slate-200/80 bg-white p-3 shadow-[var(--shadow-card)] transition-[box-shadow,opacity] hover:shadow-[var(--shadow-lift)] active:cursor-grabbing"
            :class="[dragId === o.id && 'opacity-40', busyId === o.id && 'pointer-events-none opacity-70']"
            @dragstart="onDragStart($event, o)" @dragend="onDragEnd"
          >
            <div class="flex items-start gap-2">
              <GripVertical v-if="col.status !== 'delivered'" class="mt-0.5 size-4 shrink-0 text-slate-300" />
              <button class="min-w-0 flex-1 text-left" @click="selectedId = o.id">
                <p class="font-mono text-[12.5px] font-bold text-ink hover:text-brand-700">{{ o.number }}</p>
                <p class="truncate text-sm font-medium">{{ o.customer }}</p>
                <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-slate-500"><MapPin class="size-3 shrink-0" />{{ o.zone }}</p>
              </button>
              <p class="shrink-0 text-sm font-bold tabular">{{ money(o.codAmount) }}</p>
            </div>
            <div class="mt-3 flex items-center gap-2 border-t border-slate-100 pt-2.5">
              <span v-if="o.riderName" class="inline-flex min-w-0 items-center gap-1.5 text-xs text-slate-600">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-300 to-brand-600 text-[10px] font-bold text-white">{{ initials(o.riderName) }}</span>
                <span class="truncate">{{ o.riderName }}</span>
              </span>
              <span v-else class="inline-flex items-center gap-1 text-xs font-semibold text-amber-600"><Bike class="size-3.5" />No rider</span>
              <span class="ml-auto text-[11px] text-slate-400">{{ o.items }} items</span>
              <button
                v-if="col.status !== 'delivered'"
                class="grid size-7 place-items-center rounded-lg bg-brand-50 text-brand-700 transition hover:bg-brand-600 hover:text-white"
                :aria-label="`Move ${o.number} to ${ORDER_STATUS_LABEL[nextOrderStatus(o.status)!]}`"
                @click="advance(o)"
              ><ArrowRight class="size-3.5" /></button>
            </div>
          </article>
          <p v-if="!cards(col.status).length" class="grid flex-1 place-items-center rounded-xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-400">No orders</p>
        </div>
      </section>
    </div>

    <OrdersOrderDrawer v-model="drawer" :order="selected" :riders="riders" @changed="onChanged" />
  </div>
</template>
