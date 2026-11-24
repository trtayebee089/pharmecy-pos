<script setup lang="ts">
import { Printer } from 'lucide-vue-next'
import type { ReportResult } from '~/types/domain'

// Reference implementation of the report template (UiReportPage).
definePageMeta({ title: 'Sales report' })
const api = useApi('reports')

async function load({ range, filters }: { range: { from: string; to: string }; filters: Record<string, string> }): Promise<ReportResult> {
  if (!api.useMocks) return api.get('/reports/sales', { ...range, ...filters, warehouse_id: useAuthStore().warehouseId })
  await mockDelay(450)
  const { sales } = await import('~/mocks/seed')
  const rows = sales
    .filter(s => s.status === 'completed' && (!filters.channel || s.channel === filters.channel) && (!filters.payment || s.paymentStatus === filters.payment))
    .map(s => ({ id: s.id, number: s.number, date: s.date, customer: s.customer, cashier: s.cashier, channel: s.channel, items: s.items, grandTotal: s.grandTotal, paidTotal: s.paidTotal, dueTotal: s.dueTotal }))
  const total = rows.reduce((a, r) => a + r.grandTotal, 0)
  const byDay = new Map<string, number>()
  for (const r of rows) { const k = date(r.date, { day: '2-digit', month: 'short' }); byDay.set(k, (byDay.get(k) ?? 0) + r.grandTotal) }
  return {
    kpis: [
      { label: 'Net sales', value: total, format: 'money' },
      { label: 'Invoices', value: rows.length, format: 'number' },
      { label: 'Average basket', value: rows.length ? total / rows.length : 0, format: 'money' },
      { label: 'Outstanding due', value: rows.reduce((a, r) => a + r.dueTotal, 0), format: 'money' },
    ],
    series: [...byDay.entries()].reverse().map(([label, value]) => ({ label, value })),
    seriesTitle: 'Net sales per day',
    columns: [
      { key: 'number', label: 'Invoice', format: 'mono' },
      { key: 'date', label: 'Date', format: 'datetime' },
      { key: 'customer', label: 'Customer' },
      { key: 'cashier', label: 'Cashier' },
      { key: 'channel', label: 'Channel', format: 'badge', tones: { pos: 'brand', website: 'info' } },
      { key: 'items', label: 'Items', align: 'right', format: 'number' },
      { key: 'grandTotal', label: 'Total', align: 'right', format: 'money' },
      { key: 'paidTotal', label: 'Paid', align: 'right', format: 'money' },
      { key: 'dueTotal', label: 'Due', align: 'right', format: 'money' },
    ],
    rows,
    totals: { items: rows.reduce((a, r) => a + r.items, 0), grandTotal: total, paidTotal: rows.reduce((a, r) => a + r.paidTotal, 0), dueTotal: rows.reduce((a, r) => a + r.dueTotal, 0) },
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
    title="Sales report" description="Completed invoices in the period. Voided sales are excluded; returns appear in the returns report."
    :filters="[{ key: 'channel', label: 'Channels', options: [{ value: 'pos', label: 'POS' }, { value: 'website', label: 'Website' }] }, { key: 'payment', label: 'Payment states', options: [{ value: 'paid', label: 'Paid' }, { value: 'partial', label: 'Partial' }, { value: 'unpaid', label: 'Unpaid' }] }]"
    :load="load" :row-actions="saleActions"
  />
</template>
