<script setup lang="ts">
import { Plus, Download, Upload, FileWarning, Barcode, ClipboardEdit, Copy, Layers, Pencil } from 'lucide-vue-next'
import type { Column, Product, RowAction } from '~/types/domain'

definePageMeta({ title: 'Products' })
const api = useProductsApi()
const importOpen = ref(false)
const { search, page, filters, result, loading, reload } = useList<Product>(q => api.list(q), { stock: '' })
const stockFilter = computed({ get: () => filters.value.stock || 'all', set: v => (filters.value.stock = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'name', label: 'Product' },
  { key: 'generic', label: 'Generic' },
  { key: 'manufacturer', label: 'Manufacturer' },
  { key: 'units', label: 'Units & price' },
  { key: 'stock', label: 'Sellable stock', align: 'right' },
  { key: 'expiry', label: 'Next expiry' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const actionsApi = useRowActionsApi()
const viewing = ref<Product | null>(null)
const viewOpen = computed({ get: () => !!viewing.value, set: v => { if (!v) viewing.value = null } })
async function deactivate(p: Product) {
  const { ok } = await ask({ title: `Deactivate ${p.name} ${p.strength ?? ''}?`, description: 'Products are never deleted, because past sales and purchases reference them. It disappears from the counter and search; you can reactivate it from Edit.', confirmLabel: 'Deactivate' })
  if (!ok) return
  try { await actionsApi.deactivateProduct(p.id); toast.success('Product deactivated', p.name); reload() }
  catch (e: any) { toast.error('Not deactivated', e?.message) }
}
async function duplicate(p: Product) {
  try { const c = await actionsApi.duplicateProduct(p.id); toast.success('Product duplicated', 'Opening the copy so you can adjust it'); await navigateTo(`/products/${c.id}`) }
  catch (e: any) { toast.error('Not duplicated', e?.message) }
}
const rowItems = (p: Product): RowAction[] => [
  { label: 'Print barcode labels', icon: Barcode, to: '/catalog/labels', hidden: !auth.can('products.print_barcode') },
  { label: 'Stock & batches', icon: Layers, to: '/inventory/stock' },
  { label: 'Adjust stock', icon: ClipboardEdit, to: `/inventory/adjustments?reason=correction&product=${p.id}`, hidden: !auth.can('inventory.adjust') },
  { label: 'Duplicate', icon: Copy, onClick: () => duplicate(p), hidden: !auth.can('products.create') },
]
const nextExpiry = (p: Product) => p.batches.find(b => daysUntil(b.expiryDate) >= 0)?.expiryDate
function exportCsv() {
  const rows = (result.value?.data ?? []).map(p => ({
    name: `${p.name} ${p.strength ?? ''}`.trim(), generic: p.generic, manufacturer: p.manufacturer,
    units: p.units.map(u => `${u.code}=${u.price}`).join(' | '), stock: p.stockBase, expiry: nextExpiry(p) ?? '', status: p.isActive ? 'Active' : 'Inactive',
  }))
  downloadCsv('products.csv', columns.filter(c => c.key !== 'actions'), rows)
}
</script>

<template>
  <div>
    <UiPageHeader title="Products" description="Medicines, unit ladders (box · strip · piece), prices and batch stock.">
      <UiButton :icon="Upload" @click="importOpen = true">Import</UiButton>
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
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
            <p class="flex items-center gap-1.5 font-semibold text-ink"><NuxtLink :to="`/products/${row.id}`" class="hover:text-brand-700 hover:underline">{{ row.name }}</NuxtLink> <span class="font-medium text-slate-500">{{ row.strength }}</span>
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
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="`${row.name} ${row.strength ?? ''}`" :view="() => (viewing = row)"
          :edit="auth.can('products.edit') ? `/products/${row.id}` : undefined"
          :remove="row.isActive && auth.can('products.edit') ? () => deactivate(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>

    <UiDrawer v-model="viewOpen" :title="viewing ? `${viewing.name} ${viewing.strength ?? ''}` : ''" :description="viewing ? `${viewing.generic ?? 'No generic'} · ${viewing.manufacturer}` : ''" width="lg">
      <template v-if="viewing">
        <dl class="grid grid-cols-2 gap-3 text-sm" data-stagger>
          <div class="rounded-xl bg-slate-50 p-3"><dt class="label">SKU</dt><dd class="mt-1 font-mono font-semibold">{{ viewing.sku }}</dd></div>
          <div class="rounded-xl bg-slate-50 p-3"><dt class="label">Dosage form</dt><dd class="mt-1 font-semibold">{{ viewing.dosageForm }}</dd></div>
          <div class="rounded-xl bg-slate-50 p-3"><dt class="label">Sellable stock</dt><dd class="mt-1 font-semibold tabular">{{ unitBreakdown(viewing.stockBase, viewing.units) }}</dd></div>
          <div class="rounded-xl bg-slate-50 p-3"><dt class="label">Reorder level</dt><dd class="mt-1 font-semibold tabular">{{ num(viewing.reorderLevelBase) }} pcs</dd></div>
        </dl>
        <p class="label mt-6 mb-2" data-stagger>Units & prices</p>
        <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200" data-stagger>
          <li v-for="u in viewing.units" :key="u.id" class="flex justify-between px-4 py-2.5 text-sm"><span>{{ u.label }} <span class="text-slate-400">· {{ u.factor }} pc</span></span><span class="font-semibold tabular">{{ money(u.price) }}</span></li>
        </ul>
        <p class="label mt-6 mb-2" data-stagger>Batches (earliest expiry first)</p>
        <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200" data-stagger>
          <li v-for="b in viewing.batches" :key="b.batchId" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
            <span class="font-mono">{{ b.batchNo }}</span><UiExpiryBadge :date="b.expiryDate" /><span class="font-semibold tabular">{{ num(b.qtyBase) }} pcs</span>
          </li>
          <li v-if="!viewing.batches.length" class="px-4 py-3 text-sm text-slate-500">No batches yet.</li>
        </ul>
      </template>
      <template #footer>
        <UiButton variant="ghost" @click="viewing = null">Close</UiButton>
        <NuxtLink v-if="viewing && auth.can('products.edit')" :to="`/products/${viewing.id}`"><UiButton variant="primary" :icon="Pencil">Edit product</UiButton></NuxtLink>
      </template>
    </UiDrawer>
    <CatalogProductImport v-model="importOpen" @imported="reload" />
  </div>
</template>
