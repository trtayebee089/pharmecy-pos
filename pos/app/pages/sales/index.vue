<script setup lang="ts">
import { Download, Eye, Printer, ScanBarcode, Globe, Store } from 'lucide-vue-next'
import type { Column, SaleSummary } from '~/types/domain'

definePageMeta({ title: 'Sales' })
const api = useSalesApi()
const { search, page, filters, result, loading } = useList<SaleSummary>(q => api.list(q), { status: '' })
const status = computed({ get: () => filters.value.status || 'all', set: v => (filters.value.status = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Invoice' },
  { key: 'date', label: 'Date' },
  { key: 'customer', label: 'Customer' },
  { key: 'cashier', label: 'Cashier' },
  { key: 'grandTotal', label: 'Total', align: 'right' },
  { key: 'dueTotal', label: 'Due', align: 'right' },
  { key: 'paymentStatus', label: 'Payment' },
  { key: 'actions', label: '', align: 'right' },
]
const payTone = { paid: 'success', partial: 'warning', unpaid: 'danger' } as const
function exportCsv() { downloadCsv('sales.csv', columns.filter(c => c.key !== 'actions'), result.value?.data ?? []) }
</script>

<template>
  <div>
    <UiPageHeader title="Sales" description="Completed, voided and online sales. Completed invoices are immutable: correct them with a return or void.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <NuxtLink to="/pos"><UiButton variant="primary" :icon="ScanBarcode">New sale</UiButton></NuxtLink>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Invoice no, customer, cashier…">
      <template #filters>
        <UiSegmented v-model="status" :options="[{ value: 'all', label: 'All' }, { value: 'paid', label: 'Paid' }, { value: 'partial', label: 'Partial' }, { value: 'unpaid', label: 'Unpaid' }, { value: 'void', label: 'Void' }]" />
      </template>
      <template #cell-number="{ row }">
        <div class="flex items-center gap-2">
          <component :is="row.channel === 'website' ? Globe : Store" class="size-4" :class="row.channel === 'website' ? 'text-sky-500' : 'text-slate-400'" />
          <NuxtLink :to="`/sales/${row.id}`" class="font-mono text-[12.5px] font-semibold hover:text-brand-700 hover:underline" :class="row.status === 'void' ? 'text-slate-400 line-through' : 'text-ink'">{{ row.number }}</NuxtLink>
        </div>
      </template>
      <template #cell-date="{ row }"><span class="text-slate-600">{{ dateTime(row.date) }}</span></template>
      <template #cell-customer="{ row }"><span class="font-medium">{{ row.customer }}</span></template>
      <template #cell-cashier="{ row }"><span class="text-slate-500">{{ row.cashier }}</span></template>
      <template #cell-grandTotal="{ row }"><span class="font-semibold tabular">{{ money(row.grandTotal) }}</span></template>
      <template #cell-dueTotal="{ row }"><span class="tabular" :class="row.dueTotal ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ row.dueTotal ? money(row.dueTotal) : '—' }}</span></template>
      <template #cell-paymentStatus="{ row }">
        <UiBadge v-if="row.status === 'void'" tone="neutral">Void</UiBadge>
        <UiBadge v-else :tone="payTone[row.paymentStatus as keyof typeof payTone]" dot class="capitalize">{{ row.paymentStatus }}</UiBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1 opacity-0 transition group-hover:opacity-100">
          <NuxtLink :to="`/sales/${row.id}`"><UiButton size="sm" variant="ghost" :icon="Eye" aria-label="View" /></NuxtLink>
          <NuxtLink :to="`/sales/${row.id}?print=1`"><UiButton size="sm" variant="ghost" :icon="Printer" aria-label="Reprint" /></NuxtLink>
        </div>
      </template>
    </UiDataTable>
  </div>
</template>
