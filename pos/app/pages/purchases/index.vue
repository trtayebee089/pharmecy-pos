<script setup lang="ts">
import { Download, PackagePlus, ClipboardList, Truck, Wallet, Printer, Undo2, Copy } from 'lucide-vue-next'
import type { Column, PurchaseSummary, RowAction } from '~/types/domain'

definePageMeta({ title: 'Purchases' })
const api = usePurchasesApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const actionsApi = useRowActionsApi()
const { search, page, result, loading, reload } = useList<PurchaseSummary>(q => api.list(q))

async function voidPurchase(row: PurchaseSummary) {
  const ordered = row.status === 'draft'
  const { ok, reason } = await ask({
    title: `${ordered ? 'Cancel order' : 'Void'} ${row.number}?`,
    description: ordered ? 'Nothing was received yet, so no stock or supplier due changes.' : 'Received pieces are removed from their batches (only if still in stock) and the supplier due is reversed. The purchase stays on record as void.',
    confirmLabel: ordered ? 'Cancel order' : 'Void purchase', requireReason: true,
  })
  if (!ok) return
  try { await actionsApi.voidPurchase(row.id, reason); toast.success(ordered ? 'Order cancelled' : 'Purchase voided', row.number); reload() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
async function duplicate(row: PurchaseSummary) {
  try { const c = await actionsApi.duplicatePurchase(row.id); toast.success('Purchase duplicated', `${c.number} created as an order`); reload() }
  catch (e: any) { toast.error('Not duplicated', e?.message) }
}
const rowItems = (row: PurchaseSummary): RowAction[] => [
  { label: 'Receive goods', icon: Truck, to: '/purchases/new', hidden: row.status !== 'draft' || !auth.can('purchases.create') },
  { label: 'Pay supplier', icon: Wallet, to: `/purchases/${row.id}`, hidden: !row.dueTotal || row.status === 'void' || !auth.can('payments.pay_supplier') },
  { label: row.status === 'draft' ? 'Print order' : 'Print GRN', icon: Printer, to: `/purchases/${row.id}` },
  { label: 'Return to supplier', icon: Undo2, to: '/purchases/returns', hidden: row.status !== 'received' },
  { label: 'Duplicate', icon: Copy, onClick: () => duplicate(row), hidden: !auth.can('purchases.create') },
]

const columns: Column[] = [
  { key: 'number', label: 'Purchase' },
  { key: 'date', label: 'Received' },
  { key: 'supplier', label: 'Supplier' },
  { key: 'items', label: 'Lines', align: 'right' },
  { key: 'grandTotal', label: 'Total', align: 'right' },
  { key: 'dueTotal', label: 'Supplier due', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
function exportCsv() { downloadCsv('purchases.csv', columns.filter(c => c.key !== 'actions'), result.value?.data ?? []) }
</script>

<template>
  <div>
    <UiPageHeader title="Purchases" description="Goods received with batch numbers and expiry dates. Receiving posts stock and supplier dues.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <NuxtLink to="/purchases/orders"><UiButton :icon="ClipboardList">Purchase orders</UiButton></NuxtLink>
      <NuxtLink to="/purchases/new"><UiButton variant="primary" :icon="PackagePlus">New purchase</UiButton></NuxtLink>
    </UiPageHeader>
    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Purchase no, supplier, invoice no…">
      <template #cell-number="{ row }">
        <NuxtLink :to="`/purchases/${row.id}`" class="font-mono text-[12.5px] font-semibold text-ink hover:text-brand-700 hover:underline">{{ row.number }}</NuxtLink>
        <p class="text-xs text-slate-500">Supplier inv. {{ row.supplierInvoiceNo }}</p>
      </template>
      <template #cell-date="{ row }"><span class="text-slate-600">{{ date(row.date) }}</span></template>
      <template #cell-supplier="{ row }"><span class="font-medium">{{ row.supplier }}</span></template>
      <template #cell-items="{ row }"><span class="tabular">{{ row.items }}</span></template>
      <template #cell-grandTotal="{ row }"><span class="font-semibold tabular">{{ money(row.grandTotal) }}</span></template>
      <template #cell-dueTotal="{ row }"><span class="tabular" :class="row.dueTotal ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ row.dueTotal ? money(row.dueTotal) : '—' }}</span></template>
      <template #cell-status="{ row }"><UiBadge :tone="row.status === 'received' ? 'success' : row.status === 'draft' ? 'info' : 'neutral'" dot>{{ row.status === 'draft' ? 'Ordered' : row.status === 'received' ? 'Received' : 'Void' }}</UiBadge></template>
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="row.number" :view="`/purchases/${row.id}`" remove-kind="void"
          :remove="row.status !== 'void' && auth.can('purchases.create') ? () => voidPurchase(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>
  </div>
</template>
