<script setup lang="ts">
import { Download, Eye, Globe, Smartphone, Kanban, RefreshCw, Info } from 'lucide-vue-next'
import type { Column, Paginated } from '~/types/domain'
import type { OnlineOrder, OrderStatus, Rider } from '~/types/orders'

definePageMeta({ title: 'Online orders' })
const api = useOnlineOrdersApi()
const ridersRes = useRidersResource()
const toast = useToast()

const orders = ref<OnlineOrder[]>([])
const riders = ref<Rider[]>([])
const loading = ref(true)
const tab = ref<OrderStatus | 'all'>('pending')
const search = ref('')
const page = ref(1)
const perPage = 15

async function load() {
  loading.value = true
  try { [orders.value, riders.value] = await Promise.all([api.all(), ridersRes.all()]) }
  catch (e: any) { toast.error('Could not load orders', e?.message) }
  finally { loading.value = false }
}
onMounted(load)

const statuses: OrderStatus[] = ['pending', 'confirmed', 'packed', 'in_transit', 'delivered', 'cancelled']
const tabs = computed(() => [
  ...statuses.map(s => ({ value: s, label: ORDER_STATUS_LABEL[s], count: orders.value.filter(o => o.status === s).length })),
  { value: 'all' as const, label: 'All', count: orders.value.length },
])
const filtered = computed(() => {
  const s = search.value.trim().toLowerCase()
  return orders.value
    .filter(o => tab.value === 'all' || o.status === tab.value)
    .filter(o => !s || `${o.number} ${o.legacyRef} ${o.customer} ${o.phone} ${o.zone} ${o.trackingCode}`.toLowerCase().includes(s))
})
watch([tab, search], () => (page.value = 1))
const result = computed<Paginated<OnlineOrder> | null>(() => loading.value && !orders.value.length ? null : {
  data: filtered.value.slice((page.value - 1) * perPage, page.value * perPage),
  meta: { page: page.value, perPage, total: filtered.value.length, lastPage: Math.max(1, Math.ceil(filtered.value.length / perPage)) },
})

const columns: Column[] = [
  { key: 'number', label: 'Order' },
  { key: 'placedAt', label: 'Placed' },
  { key: 'customer', label: 'Customer' },
  { key: 'zone', label: 'Zone' },
  { key: 'items', label: 'Items', align: 'right' },
  { key: 'codAmount', label: 'COD', align: 'right' },
  { key: 'rider', label: 'Rider' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right' },
]

const selectedId = ref<number | null>(null)
const selected = computed(() => orders.value.find(o => o.id === selectedId.value) ?? null)
const drawer = computed({ get: () => selectedId.value !== null, set: v => { if (!v) selectedId.value = null } })
function onChanged(o: OnlineOrder) {
  const i = orders.value.findIndex(x => x.id === o.id)
  if (i >= 0) orders.value[i] = { ...o }
}
const pendingCod = computed(() => orders.value.filter(o => !['delivered', 'cancelled'].includes(o.status)).reduce((s, o) => s + o.codAmount, 0))

function exportCsv() {
  downloadCsv('online-orders.csv', columns.filter(c => c.key !== 'actions'), filtered.value.map(o => ({ ...o, rider: o.riderName ?? '', status: ORDER_STATUS_LABEL[o.status] })))
}
</script>

<template>
  <div>
    <UiPageHeader title="Online orders" description="Orders from the website and Android app. They arrive from the legacy storefront through the bridge about once a minute.">
      <UiButton :icon="RefreshCw" :loading="loading" @click="load">Refresh</UiButton>
      <UiButton :icon="Download" :disabled="!filtered.length" @click="exportCsv">Export</UiButton>
      <NuxtLink to="/delivery"><UiButton variant="primary" :icon="Kanban">Fulfilment board</UiButton></NuxtLink>
    </UiPageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-sky-100 bg-sky-50/60 px-4 py-3 text-[13px] text-sky-900">
      <Info class="size-4 shrink-0 text-sky-600" />
      <span class="min-w-0 flex-1">Confirming an order allocates stock from the earliest-expiry batches exactly once; cancelling releases it. Open COD across active orders: <strong class="tabular">{{ money(pendingCod) }}</strong></span>
    </div>

    <div class="mb-4"><UiTabs v-model="tab" :tabs="tabs" /></div>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Order no, legacy ref, customer, phone, zone…" :empty-title="`No ${tab === 'all' ? '' : ORDER_STATUS_LABEL[tab].toLowerCase()} orders`">
      <template #cell-number="{ row }">
        <div class="flex items-center gap-2">
          <component :is="row.channel === 'app' ? Smartphone : Globe" class="size-4 text-sky-500" :aria-label="row.channel" />
          <div>
            <button class="font-mono text-[12.5px] font-semibold text-ink hover:text-brand-700 hover:underline" @click="selectedId = row.id">{{ row.number }}</button>
            <p class="text-[11px] text-slate-400">legacy {{ row.legacyRef }}</p>
          </div>
        </div>
      </template>
      <template #cell-placedAt="{ row }"><span class="text-slate-600">{{ dateTime(row.placedAt) }}</span></template>
      <template #cell-customer="{ row }">
        <p class="font-medium">{{ row.customer }}</p>
        <p class="text-xs text-slate-500 tabular">{{ row.phone }}</p>
      </template>
      <template #cell-zone="{ row }"><span class="text-slate-600">{{ row.zone }}</span></template>
      <template #cell-items="{ row }"><span class="tabular">{{ row.items }}</span></template>
      <template #cell-codAmount="{ row }"><span class="font-semibold tabular">{{ money(row.codAmount) }}</span></template>
      <template #cell-rider="{ row }">
        <span v-if="row.riderName" class="inline-flex items-center gap-2"><span class="grid size-6 place-items-center rounded-full bg-brand-100 text-[10px] font-bold text-brand-700">{{ initials(row.riderName) }}</span><span class="text-sm">{{ row.riderName }}</span></span>
        <span v-else class="text-slate-400">—</span>
      </template>
      <template #cell-status="{ row }"><UiBadge :tone="ORDER_STATUS_TONE[row.status as OrderStatus]" dot>{{ ORDER_STATUS_LABEL[row.status as OrderStatus] }}</UiBadge></template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end opacity-0 transition group-hover:opacity-100">
          <UiButton size="sm" variant="ghost" :icon="Eye" :aria-label="`Open order ${row.number}`" @click="selectedId = row.id" />
        </div>
      </template>
    </UiDataTable>

    <OrdersOrderDrawer v-model="drawer" :order="selected" :riders="riders" @changed="onChanged" />
  </div>
</template>
