<script setup lang="ts">
import { Boxes, PackageX, PackageMinus, CalendarClock, Download, Layers, ClipboardEdit, ArrowRightLeft } from 'lucide-vue-next'
import type { Column } from '~/types/domain'
import type { StockRow } from '~/types/inventory'

definePageMeta({ title: 'Stock overview' })
const api = useStockApi()
const { search, page, filters, result, loading } = useList<StockRow>(q => api.list(q), { status: '' })
const status = computed({ get: () => filters.value.status || 'all', set: v => (filters.value.status = v === 'all' ? '' : v) })

const totals = ref<{ value: number; out: number; low: number; expiring: number; products: number } | null>(null)
onMounted(async () => { totals.value = await api.totals() })

const columns: Column[] = [
  { key: 'name', label: 'Product' },
  { key: 'sellableBase', label: 'Sellable stock', align: 'right' },
  { key: 'reorderLevelBase', label: 'Reorder level', align: 'right' },
  { key: 'valueAtCost', label: 'Value at cost', align: 'right' },
  { key: 'expiringSoon', label: 'Expiring ≤ 90d', align: 'center' },
  { key: 'status', label: 'Status' },
]
const tone = { out: 'danger', low: 'warning', ok: 'success' } as const
const label = { out: 'Out of stock', low: 'Low', ok: 'In stock' } as const

const selected = ref<StockRow | null>(null)
const drawer = computed({ get: () => !!selected.value, set: v => { if (!v) selected.value = null } })
const levelPct = (r: StockRow) => Math.min(100, (r.sellableBase / Math.max(1, r.reorderLevelBase * 3)) * 100)

function exportCsv() {
  downloadCsv('stock-overview.csv', columns, (result.value?.data ?? []).map(r => ({ ...r, name: `${r.name} ${r.strength ?? ''}`.trim(), status: label[r.status] })))
}
</script>

<template>
  <div>
    <UiPageHeader title="Stock overview" description="Sellable stock excludes expired batches. Value is at batch cost.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <NuxtLink to="/inventory/counts"><UiButton :icon="ClipboardEdit">Stock count</UiButton></NuxtLink>
    </UiPageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <UiStatCard label="Stock value" :value="totals?.value ?? 0" hint="at cost, sellable only" :icon="Boxes" :loading="!totals" />
      <UiStatCard label="Out of stock" :value="totals?.out ?? 0" format="number" hint="products" :icon="PackageX" tone="rose" :loading="!totals" />
      <UiStatCard label="Low stock" :value="totals?.low ?? 0" format="number" hint="at or below reorder level" :icon="PackageMinus" tone="amber" :loading="!totals" />
      <UiStatCard label="Expiring soon" :value="totals?.expiring ?? 0" format="number" hint="products with a batch ≤ 90 days" :icon="CalendarClock" tone="violet" :loading="!totals" />
    </div>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Product, manufacturer, SKU…">
      <template #filters>
        <UiSegmented v-model="status" :options="[{ value: 'all', label: 'All' }, { value: 'low', label: 'Low' }, { value: 'out', label: 'Out' }, { value: 'expiring', label: 'Expiring' }]" />
      </template>
      <template #cell-name="{ row }">
        <button class="text-left" @click="selected = row">
          <p class="font-semibold text-ink hover:text-brand-700">{{ row.name }} <span class="font-medium text-slate-500">{{ row.strength }}</span></p>
          <p class="text-xs text-slate-500">{{ row.manufacturer }} · {{ row.dosageForm }} · {{ row.batches.length }} batches</p>
        </button>
      </template>
      <template #cell-sellableBase="{ row }">
        <p class="font-semibold tabular" :class="row.status === 'out' ? 'text-rose-600' : row.status === 'low' ? 'text-amber-600' : 'text-ink'">{{ unitBreakdown(row.sellableBase, row.units) }}</p>
        <div class="mt-1 ml-auto h-1 w-24 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full transition-[width] duration-700" :class="row.status === 'ok' ? 'bg-emerald-500' : row.status === 'low' ? 'bg-amber-500' : 'bg-rose-500'" :style="{ width: `${levelPct(row)}%` }" />
        </div>
      </template>
      <template #cell-reorderLevelBase="{ row }"><span class="text-slate-500 tabular">{{ num(row.reorderLevelBase) }} pcs</span></template>
      <template #cell-valueAtCost="{ row }"><span class="tabular">{{ money(row.valueAtCost) }}</span></template>
      <template #cell-expiringSoon="{ row }">
        <UiBadge v-if="row.expiringSoon" tone="warning">{{ row.expiringSoon }}</UiBadge>
        <span v-else class="text-slate-300">—</span>
      </template>
      <template #cell-status="{ row }"><UiBadge :tone="tone[row.status as keyof typeof tone]" dot>{{ label[row.status as keyof typeof label] }}</UiBadge></template>
    </UiDataTable>

    <UiDrawer v-model="drawer" :title="selected ? `${selected.name} ${selected.strength ?? ''}` : ''" :description="selected ? `${selected.manufacturer} · SKU ${selected.sku}` : ''">
      <template v-if="selected">
        <div data-stagger class="grid grid-cols-3 gap-3">
          <div class="rounded-2xl bg-slate-50 p-3"><p class="label">Sellable</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ num(selected.sellableBase) }}</p><p class="text-[11px] text-slate-500">pcs</p></div>
          <div class="rounded-2xl bg-rose-50 p-3"><p class="label text-rose-700">Expired</p><p class="mt-1 font-display text-lg font-extrabold text-rose-700 tabular">{{ num(selected.expiredBase) }}</p><p class="text-[11px] text-rose-600">blocked</p></div>
          <div class="rounded-2xl bg-brand-50 p-3"><p class="label text-brand-700">Value</p><p class="mt-1 font-display text-lg font-extrabold text-brand-800 tabular">{{ compactMoney(selected.valueAtCost) }}</p><p class="text-[11px] text-brand-700">at cost</p></div>
        </div>
        <p data-stagger class="label mt-6 mb-2 flex items-center gap-1.5"><Layers class="size-3.5" />Batches (FEFO order)</p>
        <ul data-stagger class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
          <li v-for="(b, i) in selected.batches" :key="b.batchId" class="flex items-center gap-3 px-4 py-3">
            <span class="grid size-7 place-items-center rounded-lg bg-slate-100 text-[11px] font-bold text-slate-500">{{ i + 1 }}</span>
            <div class="min-w-0 flex-1">
              <p class="font-mono text-[13px] font-semibold">{{ b.batchNo }}</p>
              <p class="text-xs text-slate-500">cost {{ money(b.unitCost) }}/pc</p>
            </div>
            <UiExpiryBadge :date="b.expiryDate" />
            <span class="w-16 text-right text-sm font-semibold tabular" :class="daysUntil(b.expiryDate) < 0 && 'text-rose-600 line-through'">{{ num(b.qtyBase) }}</span>
          </li>
        </ul>
        <p data-stagger class="mt-3 text-xs text-slate-500">Expired batches stay on record until written off or returned to the supplier; the counter can never sell them.</p>
      </template>
      <template #footer>
        <NuxtLink to="/inventory/adjustments"><UiButton :icon="ClipboardEdit">Adjust</UiButton></NuxtLink>
        <NuxtLink to="/inventory/transfers"><UiButton :icon="ArrowRightLeft">Transfer</UiButton></NuxtLink>
      </template>
    </UiDrawer>
  </div>
</template>
