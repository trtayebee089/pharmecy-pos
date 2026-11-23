<script setup lang="ts">
import { Download, Eye, PackagePlus } from 'lucide-vue-next'
import type { Column, PurchaseSummary } from '~/types/domain'

definePageMeta({ title: 'Purchases' })
const api = usePurchasesApi()
const { search, page, result, loading } = useList<PurchaseSummary>(q => api.list(q))

const columns: Column[] = [
  { key: 'number', label: 'Purchase' },
  { key: 'date', label: 'Received' },
  { key: 'supplier', label: 'Supplier' },
  { key: 'items', label: 'Lines', align: 'right' },
  { key: 'grandTotal', label: 'Total', align: 'right' },
  { key: 'dueTotal', label: 'Supplier due', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right' },
]
</script>

<template>
  <div>
    <UiPageHeader title="Purchases" description="Goods received with batch numbers and expiry dates. Receiving posts stock and supplier dues.">
      <UiButton :icon="Download">Export</UiButton>
      <NuxtLink to="/purchases/new"><UiButton variant="primary" :icon="PackagePlus">Receive purchase</UiButton></NuxtLink>
    </UiPageHeader>
    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Purchase no, supplier, invoice no…">
      <template #cell-number="{ row }">
        <p class="font-mono text-[12.5px] font-semibold text-ink">{{ row.number }}</p>
        <p class="text-xs text-slate-500">Supplier inv. {{ row.supplierInvoiceNo }}</p>
      </template>
      <template #cell-date="{ row }"><span class="text-slate-600">{{ date(row.date) }}</span></template>
      <template #cell-supplier="{ row }"><span class="font-medium">{{ row.supplier }}</span></template>
      <template #cell-items="{ row }"><span class="tabular">{{ row.items }}</span></template>
      <template #cell-grandTotal="{ row }"><span class="font-semibold tabular">{{ money(row.grandTotal) }}</span></template>
      <template #cell-dueTotal="{ row }"><span class="tabular" :class="row.dueTotal ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ row.dueTotal ? money(row.dueTotal) : '—' }}</span></template>
      <template #cell-status="{ row }"><UiBadge :tone="row.status === 'received' ? 'success' : row.status === 'draft' ? 'info' : 'neutral'" dot class="capitalize">{{ row.status }}</UiBadge></template>
      <template #cell-actions><div class="flex justify-end opacity-0 transition group-hover:opacity-100"><UiButton size="sm" variant="ghost" :icon="Eye" aria-label="View" /></div></template>
    </UiDataTable>
  </div>
</template>
