<script setup lang="ts">
import { Download, Printer, ScanBarcode, Globe, Store, Undo2, MessageSquare, HandCoins } from 'lucide-vue-next'
import type { Column, RowAction, SaleSummary } from '~/types/domain'

definePageMeta({ title: 'Sales' })
const api = useSalesApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const actionsApi = useRowActionsApi()
const { search, page, filters, result, loading, reload } = useList<SaleSummary>(q => api.list(q), { status: '' })
const status = computed({ get: () => filters.value.status || 'all', set: v => (filters.value.status = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Invoice' },
  { key: 'date', label: 'Date' },
  { key: 'customer', label: 'Customer' },
  { key: 'cashier', label: 'Cashier' },
  { key: 'grandTotal', label: 'Total', align: 'right' },
  { key: 'dueTotal', label: 'Due', align: 'right' },
  { key: 'paymentStatus', label: 'Payment' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const payTone = { paid: 'success', partial: 'warning', unpaid: 'danger' } as const
async function voidSale(row: SaleSummary) {
  const { ok, reason } = await ask({ title: `Void ${row.number}?`, description: 'Stock goes back to its batches and payments are reversed. The invoice stays on record as void. Use a return instead for part of a sale.', confirmLabel: 'Void sale', requireReason: true })
  if (!ok) return
  try { await actionsApi.voidSale(row.id, reason); toast.success('Sale voided', row.number); reload() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
async function sms(row: SaleSummary) {
  try { await actionsApi.smsReceipt(row.id); toast.success('Receipt SMS queued', `${row.number} · ${row.customer}`) }
  catch (e: any) { toast.error('SMS not sent', e?.message) }
}
const rowItems = (row: SaleSummary): RowAction[] => [
  { label: 'Print invoice', icon: Printer, to: `/sales/${row.id}?print=1` },
  { label: 'Return items', icon: Undo2, to: `/sales/${row.id}`, hidden: row.status === 'void' || !auth.can('returns.create') },
  { label: 'Collect due', icon: HandCoins, to: '/customers/due', hidden: !row.dueTotal || row.status === 'void' },
  { label: 'Send SMS receipt', icon: MessageSquare, onClick: () => sms(row), hidden: row.status === 'void' || row.customer === 'Walk-in customer' },
]
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
        <UiRowActions
          :label="row.number" :view="`/sales/${row.id}`" remove-kind="void"
          :remove="row.status !== 'void' && auth.can('sales.void') ? () => voidSale(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>
  </div>
</template>
