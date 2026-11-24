<script setup lang="ts">
import { PauseCircle, ScanBarcode, MonitorSmartphone, Store } from 'lucide-vue-next'
import type { HeldSaleSummary } from '~/types/orders'

definePageMeta({ title: 'Held sales' })

const cart = useCartStore()
const api = useHeldSalesApi()
const toast = useToast()
const { ask } = useConfirm()

const auth = useAuthStore()
const live = !useApi('sales').useMocks
const mockServer = ref<HeldSaleSummary[]>([])
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

/** Held carts in the cart store: server-held ones of this warehouse when live, this browser's in mock mode. */
const entries = computed<HeldSaleSummary[]>(() => cart.held.map(h => ({
  id: h.id, heldAt: h.at, heldBy: h.heldBy ?? auth.user?.name ?? 'You', heldById: h.heldById, register: h.register ?? 'This counter',
  customer: h.customerName ?? null, items: h.items, total: h.total, source: h.server ? 'server' : 'this_counter',
})))
const mine = (h: HeldSaleSummary) => !h.heldById || h.heldById === auth.user?.id
const local = computed(() => entries.value.filter(mine))
const server = computed(() => [...entries.value.filter(h => !mine(h)), ...mockServer.value])

async function load() {
  loading.value = true
  try {
    if (live) await cart.loadHeld()
    else mockServer.value = await api.list()
  }
  catch (e: any) { toast.error('Could not load held sales', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-held]') ?? [], { stagger: 0.05 })
}
onMounted(load)

const age = (iso: string) => {
  const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  return m < 60 ? `${m} min ago` : `${Math.floor(m / 60)}h ${m % 60}m ago`
}

async function resume(h: HeldSaleSummary) {
  if (cart.lines.length) {
    const { ok } = await ask({ title: 'Park the current cart?', description: 'The cart on the counter is not empty. It will be held so you can resume this sale.', confirmLabel: 'Hold & resume', tone: 'primary' })
    if (!ok) return
  }
  if (!cart.held.some(x => x.id === h.id)) { toast.info('Demo data', 'Mock held sales from other counters cannot be resumed.'); return }
  try {
    const skipped = await cart.resume(h.id)
    skipped.length
      ? toast.warning('Sale resumed without some items', `${skipped.join(', ')} can no longer be sold.`)
      : toast.success('Sale resumed', `${h.id} is back on the counter.`)
    await navigateTo('/pos')
  }
  catch (e: any) { toast.error('Could not resume', e?.message); load() }
}

async function discard(h: HeldSaleSummary) {
  const { ok } = await ask({ title: `Discard held sale ${h.id}?`, description: 'Nothing was sold and no stock was reserved, so discarding has no stock or money effect.', confirmLabel: 'Discard' })
  if (!ok) return
  try {
    if (cart.held.some(x => x.id === h.id)) await cart.discardHeld(h.id)
    else { await api.discard(h.id); mockServer.value = mockServer.value.filter(x => x.id !== h.id) }
  }
  catch (e: any) { toast.error('Could not discard', e?.message); load(); return }
  toast.success('Held sale discarded', h.id)
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Held sales" description="Carts parked with F8. Holding reserves no stock; prices and batches are re-checked when the sale is completed.">
      <NuxtLink to="/pos"><UiButton variant="primary" :icon="ScanBarcode">Back to counter</UiButton></NuxtLink>
    </UiPageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-6">
      <section>
        <h2 class="label mb-3 flex items-center gap-1.5"><Store class="size-3.5" />{{ live ? 'Held by you' : 'This counter' }} · {{ local.length }}</h2>
        <div v-if="local.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <OrdersHeldCard v-for="h in local" :key="h.id" :held="h" :age="age(h.heldAt)" can-resume @resume="resume(h)" @discard="discard(h)" />
        </div>
        <div v-else class="card"><UiEmptyState :icon="PauseCircle" title="Nothing held on this counter" description="Press F8 at the POS to park the current cart." /></div>
      </section>

      <section>
        <h2 class="label mb-3 flex items-center gap-1.5"><MonitorSmartphone class="size-3.5" />Other counters · {{ server.length }}</h2>
        <div v-if="loading" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><div v-for="i in 3" :key="i" class="skeleton h-40 rounded-2xl" /></div>
        <div v-else-if="server.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <OrdersHeldCard v-for="h in server" :key="h.id" :held="h" :age="age(h.heldAt)" :can-resume="live" @resume="resume(h)" @discard="discard(h)" />
        </div>
        <div v-else class="card"><UiEmptyState :icon="PauseCircle" title="No held sales on other counters" /></div>
        <p class="mt-3 text-xs text-slate-500">Any counter in this warehouse can resume a held sale. The first one to resume it takes it, so two cashiers never complete the same cart.</p>
      </section>
    </div>
  </div>
</template>

