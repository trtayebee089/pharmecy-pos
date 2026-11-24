<script setup lang="ts">
import { Printer } from 'lucide-vue-next'
import type { DateRange, ReportResult } from '~/types/domain'

definePageMeta({ title: 'Custom product sales' })
const api = useApi()

async function load({ range, filters }: { range: DateRange; filters: Record<string, string> }): Promise<ReportResult> {
  if (!api.useMocks) return api.get('/reports/custom-products', { ...range, ...filters })
  await mockDelay(400)
  const all = (await import('~/mocks/reports-extra')).customProductRows()
  const rows = all.filter(r => !filters.product || r.productName === filters.product)
  const total = rows.reduce((s, r) => s + r.total, 0)
  const byName = new Map<string, number>()
  for (const r of rows) byName.set(r.productName, (byName.get(r.productName) ?? 0) + r.total)
  const top = [...byName.entries()].sort((a, b) => b[1] - a[1])
  return {
    kpis: [
      { label: 'Custom item sales', value: total, format: 'money' },
      { label: 'Lines', value: rows.length, format: 'number' },
      { label: 'Quantity sold', value: rows.reduce((s, r) => s + r.qty, 0), format: 'number' },
      { label: 'Distinct items', value: byName.size, format: 'number', hint: top[0] ? `Most sold: ${top[0][0]}` : undefined },
    ],
    series: top.slice(0, 8).map(([label, value]) => ({ label: label.split(' ').slice(0, 2).join(' '), value })),
    seriesTitle: 'Top custom items by value',
    columns: [
      { key: 'date', label: 'Date', format: 'datetime' },
      { key: 'number', label: 'Invoice', format: 'mono' },
      { key: 'productName', label: 'Item (typed at counter)' },
      { key: 'productCode', label: 'Code', format: 'mono' },
      { key: 'qty', label: 'Qty', align: 'right', format: 'number' },
      { key: 'unitPrice', label: 'Unit price', align: 'right', format: 'money' },
      { key: 'total', label: 'Total', align: 'right', format: 'money' },
      { key: 'cashier', label: 'Cashier' },
      { key: 'customer', label: 'Customer' },
    ],
    rows,
    totals: { qty: rows.reduce((s, r) => s + r.qty, 0), total },
  }
}
const productOptions = ref<{ value: string; label: string }[]>([])
onMounted(async () => {
  if (!api.useMocks) return
  const names = [...new Set((await import('~/mocks/reports-extra')).customProductRows().map(r => r.productName))].sort()
  productOptions.value = names.map(n => ({ value: n, label: n }))
})
function saleActions(r: Record<string, any>) {
  const id = r.saleId
  if (!id) return null
  return { view: `/sales/${id}`, items: [{ label: 'Print invoice', icon: Printer, to: `/sales/${id}?print=1` }] }
}
</script>

<template>
  <UiReportPage
    title="Custom product sales" description="Items sold as free-text lines for products not in the catalogue. They carry no stock; frequent ones are candidates to add as real products."
    :filters="[{ key: 'product', label: 'Items', options: productOptions }]" :load="load" :row-actions="saleActions"
  />
</template>
