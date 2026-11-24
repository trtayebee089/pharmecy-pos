<script setup lang="ts">
import { Download, Undo2, Trash } from 'lucide-vue-next'
import type { BatchRow, Column } from '~/types/domain'

definePageMeta({ title: 'Batches & expiry' })
const api = useInventoryApi()
const { search, page, filters, result, loading } = useList<BatchRow>(q => api.batches(q), { within: 90 })
const within = computed({ get: () => filters.value.within, set: v => (filters.value.within = v) })

const columns: Column[] = [
  { key: 'productName', label: 'Product' },
  { key: 'batchNo', label: 'Batch' },
  { key: 'expiryDate', label: 'Expiry' },
  { key: 'life', label: 'Shelf life left', width: '180px' },
  { key: 'qtyBase', label: 'Qty (pcs)', align: 'right' },
  { key: 'value', label: 'Value at cost', align: 'right' },
  { key: 'actions', label: '', align: 'right' },
]
// Visual shelf-life bar: 0–365 days
const lifePct = (d: string) => Math.max(0, Math.min(100, (daysUntil(d) / 365) * 100))
function exportCsv() { downloadCsv('batches.csv', columns.filter(c => c.key !== 'actions'), result.value?.data ?? []) }
</script>

<template>
  <div>
    <UiPageHeader title="Batches & expiry" description="Every unit of stock sits in a batch. Expired batches are blocked from sale automatically.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="batchId" search-placeholder="Product, batch no, manufacturer…" empty-title="No batches in this window">
      <template #filters>
        <UiSegmented v-model="within" :options="[{ value: 'expired', label: 'Expired' }, { value: 30, label: '≤ 30 days' }, { value: 90, label: '≤ 90 days' }, { value: 0, label: 'All' }]" />
      </template>
      <template #cell-productName="{ row }">
        <p class="font-semibold text-ink">{{ row.productName }}</p>
        <p class="text-xs text-slate-500">{{ row.manufacturer }}</p>
      </template>
      <template #cell-batchNo="{ row }"><span class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-700">{{ row.batchNo }}</span></template>
      <template #cell-expiryDate="{ row }"><UiExpiryBadge :date="row.expiryDate" /></template>
      <template #cell-life="{ row }">
        <div class="h-1.5 w-36 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full transition-[width] duration-700" :style="{ width: `${lifePct(row.expiryDate)}%` }" :class="{ 'bg-rose-500': expiryState(row.expiryDate) !== 'ok' && expiryState(row.expiryDate) !== 'soon', 'bg-amber-500': expiryState(row.expiryDate) === 'soon', 'bg-emerald-500': expiryState(row.expiryDate) === 'ok' }" />
        </div>
      </template>
      <template #cell-qtyBase="{ row }"><span class="font-semibold tabular">{{ num(row.qtyBase) }}</span></template>
      <template #cell-value="{ row }"><span class="tabular">{{ money(row.value) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1 opacity-0 transition group-hover:opacity-100">
          <NuxtLink to="/purchases/returns"><UiButton size="sm" variant="ghost" :icon="Undo2" title="Return to supplier">Return</UiButton></NuxtLink>
          <NuxtLink v-if="daysUntil(row.expiryDate) < 0" :to="`/inventory/adjustments?reason=expiry&product=${row.productId}&batch=${row.batchId}`"><UiButton size="sm" variant="ghost" :icon="Trash" title="Write off expired stock">Write off</UiButton></NuxtLink>
        </div>
      </template>
    </UiDataTable>
  </div>
</template>
