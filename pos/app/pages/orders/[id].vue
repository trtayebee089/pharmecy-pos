<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next'
import type { OnlineOrder, Rider } from '~/types/orders'

definePageMeta({ title: 'Online order' })
const route = useRoute()
const api = useOnlineOrdersApi()
const ridersRes = useRidersResource()
const toast = useToast()

const order = ref<OnlineOrder | null>(null)
const riders = ref<Rider[]>([])
const error = ref('')

async function load() {
  error.value = ''
  try { [order.value, riders.value] = await Promise.all([api.one(Number(route.params.id)), ridersRes.all()]) }
  catch (e: any) { error.value = e?.status === 404 ? 'This order does not exist.' : (e?.message ?? 'Could not load the order.'); return }
  // "Print order slip" from the list opens the page with ?print=1.
  if (route.query.print && import.meta.client) nextTick(() => setTimeout(() => window.print(), 400))
}
watch(() => route.params.id, load, { immediate: true })

// While the parcel is on its way, refresh quietly so the arrival time stays current.
const visibility = useDocumentVisibility()
const etaTimer = setInterval(async () => {
  if (order.value?.status !== 'in_transit' || visibility.value !== 'visible') return
  try { order.value = await api.one(order.value.id) } catch { /* keep the shown order */ }
}, 30000)
onBeforeUnmount(() => clearInterval(etaTimer))

function onChanged(o: OnlineOrder) {
  order.value = o
  ridersRes.all().then(r => (riders.value = r)).catch(() => toast.warning('Rider list not refreshed'))
}
</script>

<template>
  <div>
    <NuxtLink to="/orders" class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-700 print:hidden">
      <ArrowLeft class="size-4" /> Online orders
    </NuxtLink>
    <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
    <div v-else-if="!order" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]"><div class="skeleton h-80" /><div class="skeleton h-80" /></div>
    <OrdersOrderDetail v-else :order="order" :riders="riders" @changed="onChanged" />
  </div>
</template>
