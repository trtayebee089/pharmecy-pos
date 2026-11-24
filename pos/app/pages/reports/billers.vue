<script setup lang="ts">
import { Printer } from 'lucide-vue-next'
import type { DateRange, ReportResult } from '~/types/domain'

definePageMeta({ title: 'Biller report' })
const api = useApi('reports')
const billerOptions = ref<{ value: string; label: string }[]>([])
onMounted(async () => {
  if (api.useMocks) billerOptions.value = (await import('~/mocks/reports-extra')).billers.map(b => ({ value: String(b.id), label: b.name }))
  else billerOptions.value = (await api.get<{ id: number; name: string }[]>('/billers')).map(b => ({ value: String(b.id), label: b.name }))
})

async function load({ range, filters }: { range: DateRange; filters: Record<string, string> }): Promise<ReportResult> {
  if (!api.useMocks) return api.get('/reports/billers', { ...range, ...filters, warehouse_id: useAuthStore().warehouseId })
  await mockDelay(400)
  const m = await import('~/mocks/reports-extra')
  const rows = m.billerRows().filter(r => !filters.biller || String(r.billerId) === filters.biller)
  const total = rows.reduce((s, r) => s + r.grandTotal, 0)
  const byBiller = m.billers.map(b => ({ b, v: rows.filter(r => r.billerId === b.id).reduce((s, r) => s + r.grandTotal, 0) })).filter(x => x.v > 0)
  const top = byBiller.sort((a, b) => b.v - a.v)[0]
  return {
    kpis: [
      { label: 'Sales', value: total, format: 'money' },
      { label: 'Invoices', value: rows.length, format: 'number' },
      { label: 'Collected', value: rows.reduce((s, r) => s + r.paidTotal, 0), format: 'money' },
      { label: 'Outstanding due', value: rows.reduce((s, r) => s + r.dueTotal, 0), format: 'money', hint: top ? `Top biller: ${top.b.name}` : undefined },
    ],
    series: byBiller.map(x => ({ label: x.b.name.replace('Red Pharma ', ''), value: x.v })),
    seriesTitle: 'Sales by biller',
    columns: [
      { key: 'biller', label: 'Biller' },
      { key: 'number', label: 'Invoice', format: 'mono' },
      { key: 'date', label: 'Date', format: 'datetime' },
      { key: 'customer', label: 'Customer' },
      { key: 'cashier', label: 'Cashier' },
      { key: 'items', label: 'Items', align: 'right', format: 'number' },
      { key: 'grandTotal', label: 'Total', align: 'right', format: 'money' },
      { key: 'paidTotal', label: 'Paid', align: 'right', format: 'money' },
      { key: 'dueTotal', label: 'Due', align: 'right', format: 'money' },
    ],
    rows,
    totals: { items: rows.reduce((s, r) => s + r.items, 0), grandTotal: total, paidTotal: rows.reduce((s, r) => s + r.paidTotal, 0), dueTotal: rows.reduce((s, r) => s + r.dueTotal, 0) },
  }
}
function saleActions(r: Record<string, any>) {
  const id = r.id
  if (!id) return null
  return { view: `/sales/${id}`, items: [{ label: 'Print invoice', icon: Printer, to: `/sales/${id}?print=1` }] }
}
</script>

<template>
  <UiReportPage
    title="Biller report" description="Sales, collections and dues per biller (the billing identity printed on the invoice)."
    :filters="[{ key: 'biller', label: 'Billers', options: billerOptions }]" :load="load" :row-actions="saleActions"
  />
</template>
