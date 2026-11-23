<script setup lang="ts">
import { Plus, Download, Upload, FileWarning } from 'lucide-vue-next'
import type { Column, Product } from '~/types/domain'

definePageMeta({ title: 'Products' })
const api = useProductsApi()
const { search, page, filters, result, loading } = useList<Product>(q => api.list(q), { stock: '' })
const stockFilter = computed({ get: () => filters.value.stock || 'all', set: v => (filters.value.stock = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'name', label: 'Product' },
  { key: 'generic', label: 'Generic' },
  { key: 'manufacturer', label: 'Manufacturer' },
  { key: 'units', label: 'Units & price' },
  { key: 'stock', label: 'Sellable stock', align: 'right' },
  { key: 'expiry', label: 'Next expiry' },
  { key: 'status', label: 'Status' },
]
const nextExpiry = (p: Product) => p.batches.find(b => daysUntil(b.expiryDate) >= 0)?.expiryDate
</script>

<template>
  <div>
    <UiPageHeader title="Products" description="Medicines, unit ladders (box · strip · piece), prices and batch stock.">
      <UiButton :icon="Upload">Import</UiButton>
      <UiButton :icon="Download">Export</UiButton>
      <NuxtLink to="/products/new"><UiButton variant="primary" :icon="Plus">Add product</UiButton></NuxtLink>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Brand, generic, SKU, manufacturer…">
      <template #filters>
        <UiSegmented v-model="stockFilter" :options="[{ value: 'all', label: 'All' }, { value: 'low', label: 'Low stock' }]" />
      </template>
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-50 to-brand-100 text-xs font-bold text-brand-700">{{ initials(row.name) }}</span>
          <div>
            <p class="flex items-center gap-1.5 font-semibold text-ink">{{ row.name }} <span class="font-medium text-slate-500">{{ row.strength }}</span>
              <FileWarning v-if="row.requiresPrescription" class="size-3.5 text-violet-600" />
            </p>
            <p class="text-xs text-slate-500">SKU {{ row.sku }} · {{ row.dosageForm }}</p>
          </div>
        </div>
      </template>
      <template #cell-generic="{ row }"><span class="text-slate-600">{{ row.generic ?? '—' }}</span></template>
      <template #cell-manufacturer="{ row }"><span class="text-slate-600">{{ row.manufacturer }}</span></template>
      <template #cell-units="{ row }">
        <div class="flex flex-wrap gap-1">
          <span v-for="u in row.units" :key="u.id" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600 tabular">{{ u.code }} {{ money(u.price, false) }}</span>
        </div>
      </template>
      <template #cell-stock="{ row }">
        <span class="font-semibold tabular" :class="row.stockBase <= 0 ? 'text-rose-600' : row.stockBase <= row.reorderLevelBase ? 'text-amber-600' : 'text-ink'">{{ unitBreakdown(row.stockBase, row.units) }}</span>
      </template>
      <template #cell-expiry="{ row }">
        <UiExpiryBadge v-if="nextExpiry(row)" :date="nextExpiry(row)!" />
        <UiBadge v-else tone="danger">No valid batch</UiBadge>
      </template>
      <template #cell-status="{ row }">
        <UiBadge :tone="row.isActive ? 'success' : 'neutral'" dot>{{ row.isActive ? 'Active' : 'Inactive' }}</UiBadge>
      </template>
    </UiDataTable>
  </div>
</template>
