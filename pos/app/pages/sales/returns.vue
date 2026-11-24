<script setup lang="ts">
import { Download, Layers, PackageCheck, PackageX, Undo2, Printer, Receipt } from 'lucide-vue-next'
import type { Column, RowAction } from '~/types/domain'
import type { SaleReturnSummary } from '~/types/orders'

definePageMeta({ title: 'Sale returns' })
const api = useSaleReturnsApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const actionsApi = useRowActionsApi()
const voided = ref(new Set<number>())
const { search, page, filters, result, loading, reload } = useList<SaleReturnSummary>(q => api.list(q), { refundMethod: '' })
const method = computed({ get: () => filters.value.refundMethod || 'all', set: v => (filters.value.refundMethod = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Return' },
  { key: 'date', label: 'Date' },
  { key: 'saleNumber', label: 'Original invoice' },
  { key: 'customer', label: 'Customer' },
  { key: 'items', label: 'Lines', align: 'right' },
  { key: 'stock', label: 'Restocked / written off', align: 'right' },
  { key: 'refundMethod', label: 'Refund' },
  { key: 'total', label: 'Refund total', align: 'right' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const methodLabel: Record<string, string> = { cash: 'Cash', credit: 'Customer credit', bkash: 'bKash' }
const methodTone = { cash: 'success', credit: 'info', bkash: 'violet' } as const

const selected = ref<SaleReturnSummary | null>(null)
const drawer = computed({ get: () => !!selected.value, set: v => { if (!v) selected.value = null } })

async function voidReturn(row: SaleReturnSummary) {
  const { ok, reason } = await ask({ title: `Void return ${row.number}?`, description: 'Restocked pieces are taken back out of their batches and the refund is reversed. The return stays on record as void.', confirmLabel: 'Void return', requireReason: true })
  if (!ok) return
  try { await actionsApi.voidDocument('sale-returns', row.id, reason); voided.value.add(row.id); toast.success('Return voided', row.number); reload() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
function printReturn(row: SaleReturnSummary) { selected.value = row; nextTick(() => setTimeout(() => window.print(), 400)) }
const rowItems = (row: SaleReturnSummary): RowAction[] => [
  { label: 'Open original invoice', icon: Receipt, to: `/sales/${row.saleId}` },
  { label: 'Print return note', icon: Printer, onClick: () => printReturn(row) },
]
function exportCsv() {
  const rows = (result.value?.data ?? []).map(r => ({ ...r, stock: `${r.restockedQty} / ${r.writtenOffQty}`, refundMethod: methodLabel[r.refundMethod] }))
  downloadCsv('sale-returns.csv', columns.filter(c => c.key !== 'actions'), rows)
}
</script>

<template>
  <div>
    <UiPageHeader title="Sale returns" description="Returns are separate documents linked to the original invoice. The invoice itself never changes.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <NuxtLink to="/sales"><UiButton variant="primary" :icon="Undo2">Return from an invoice</UiButton></NuxtLink>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Return no, invoice, customer, reason…" empty-title="No returns found">
      <template #filters>
        <UiSegmented v-model="method" :options="[{ value: 'all', label: 'All' }, { value: 'cash', label: 'Cash' }, { value: 'credit', label: 'Credit' }, { value: 'bkash', label: 'bKash' }]" />
      </template>
      <template #cell-number="{ row }">
        <button class="font-mono text-[12.5px] font-semibold text-ink hover:text-brand-700 hover:underline" @click="selected = row">{{ row.number }}</button>
      </template>
      <template #cell-date="{ row }"><span class="text-slate-600">{{ dateTime(row.date) }}</span></template>
      <template #cell-saleNumber="{ row }">
        <NuxtLink :to="`/sales/${row.saleId}`" class="font-mono text-[12.5px] text-brand-700 hover:underline">{{ row.saleNumber }}</NuxtLink>
      </template>
      <template #cell-customer="{ row }"><span class="font-medium">{{ row.customer }}</span></template>
      <template #cell-items="{ row }"><span class="tabular">{{ row.items }}</span></template>
      <template #cell-stock="{ row }">
        <span class="tabular"><span class="font-semibold text-emerald-600">{{ row.restockedQty }}</span><span class="text-slate-300"> / </span><span :class="row.writtenOffQty ? 'font-semibold text-rose-600' : 'text-slate-400'">{{ row.writtenOffQty }}</span> pcs</span>
      </template>
      <template #cell-refundMethod="{ row }"><UiBadge :tone="methodTone[row.refundMethod as keyof typeof methodTone]">{{ methodLabel[row.refundMethod] }}</UiBadge></template>
      <template #cell-total="{ row }"><span class="font-semibold tabular">{{ money(row.total) }}</span></template>
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="row.number" :view="() => (selected = row)" remove-kind="void"
          :remove="!voided.has(row.id) && auth.can('returns.create') ? () => voidReturn(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>

    <UiDrawer v-model="drawer" width="lg">
      <template #header>
        <p class="label">Sale return</p>
        <h2 class="font-mono text-lg font-bold text-ink">{{ selected?.number }}</h2>
        <p class="text-sm text-slate-500">{{ dateTime(selected?.date) }} · by {{ selected?.createdBy }}</p>
      </template>
      <div v-if="selected" class="space-y-5">
        <div data-stagger class="grid grid-cols-2 gap-3">
          <div class="rounded-2xl bg-slate-50 p-4">
            <p class="label">Original invoice</p>
            <NuxtLink :to="`/sales/${selected.saleId}`" class="mt-1 block font-mono text-sm font-semibold text-brand-700 hover:underline">{{ selected.saleNumber }}</NuxtLink>
            <p class="text-xs text-slate-500">{{ selected.customer }}</p>
          </div>
          <div class="rounded-2xl bg-brand-50 p-4">
            <p class="label text-brand-700">Refunded · {{ methodLabel[selected.refundMethod] }}</p>
            <p class="mt-1 font-display text-xl font-extrabold text-brand-800 tabular">{{ money(selected.total) }}</p>
          </div>
        </div>
        <div data-stagger class="rounded-2xl border border-slate-200 p-4">
          <p class="label mb-1">Reason</p>
          <p class="text-sm text-slate-700">{{ selected.reason }}</p>
        </div>
        <div data-stagger>
          <p class="label mb-2">Lines (per batch)</p>
          <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
            <li v-for="(l, i) in selected.lines" :key="i" class="flex items-start gap-3 p-3.5">
              <span class="grid size-9 shrink-0 place-items-center rounded-xl" :class="l.restock ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'">
                <component :is="l.restock ? PackageCheck : PackageX" class="size-4" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ l.productName }}</p>
                <p class="mt-0.5 flex items-center gap-1 text-xs text-slate-500"><Layers class="size-3" /><span class="font-mono">{{ l.batchNo }}</span> · exp {{ date(l.expiryDate, { month: 'short', year: 'numeric' }) }}</p>
                <p class="mt-1 text-xs" :class="l.restock ? 'text-emerald-700' : 'text-rose-700'">{{ l.restock ? 'Returned to the same batch (sellable)' : 'Written off as damaged' }}</p>
              </div>
              <div class="text-right">
                <p class="text-sm font-semibold tabular">{{ money(l.lineTotal) }}</p>
                <p class="text-xs text-slate-500 tabular">{{ l.qtyBase }} pcs × {{ money(l.unitPrice) }}</p>
              </div>
            </li>
          </ul>
        </div>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="drawer = false">Close</UiButton>
        <NuxtLink v-if="selected" :to="`/sales/${selected.saleId}`"><UiButton variant="primary">Open invoice</UiButton></NuxtLink>
      </template>
    </UiDrawer>
  </div>
</template>
