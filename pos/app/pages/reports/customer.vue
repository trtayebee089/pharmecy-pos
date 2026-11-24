<script setup lang="ts">
import { Printer, HandCoins } from 'lucide-vue-next'
import type { DateRange, ReportResult } from '~/types/domain'

/**
 * Legacy "Customer Report": choose a customer to see their statement
 * (sales, payments, returns with running balance). Without a customer the
 * report summarises every customer.
 */
definePageMeta({ title: 'Customer report' })
const api = useApi()
const customerOptions = ref<{ value: string; label: string }[]>([])
onMounted(async () => {
  const list = await useCustomersApi().list({ perPage: 500 })
  customerOptions.value = list.data.filter(c => !c.isWalkIn).map(c => ({ value: String(c.id), label: `${c.name} · ${c.phone ?? ''}` }))
})

async function load({ range, filters }: { range: DateRange; filters: Record<string, string> }): Promise<ReportResult> {
  if (!api.useMocks) return api.get('/reports/customer', { ...range, ...filters })
  await mockDelay(400)
  const m = await import('~/mocks/reports-extra')
  const { customers } = await import('~/mocks/seed')
  if (filters.customer) {
    const rows = m.customerStatement(Number(filters.customer))
    const c = customers.find(x => x.id === Number(filters.customer))
    const sum = (t: string) => rows.filter(r => r.type === t).reduce((s, r) => s + r.debit + r.credit, 0)
    return {
      kpis: [
        { label: 'Purchases', value: sum('sale'), format: 'money' },
        { label: 'Paid', value: sum('payment'), format: 'money' },
        { label: 'Returns', value: sum('return'), format: 'money' },
        { label: 'Balance due', value: rows[rows.length - 1]?.balance ?? 0, format: 'money', hint: c ? `${c.points} points · ${c.membership ?? 'no membership'}` : undefined },
      ],
      columns: [
        { key: 'date', label: 'Date', format: 'datetime' },
        { key: 'type', label: 'Type', format: 'badge', tones: { sale: 'brand', payment: 'success', return: 'warning' } },
        { key: 'reference', label: 'Reference', format: 'mono' },
        { key: 'debit', label: 'Debit', align: 'right', format: 'money' },
        { key: 'credit', label: 'Credit', align: 'right', format: 'money' },
        { key: 'balance', label: 'Balance', align: 'right', format: 'money' },
      ],
      rows,
      totals: { debit: rows.reduce((s, r) => s + r.debit, 0), credit: rows.reduce((s, r) => s + r.credit, 0), balance: rows[rows.length - 1]?.balance ?? 0 },
    }
  }
  const rows = customers.filter(c => !c.isWalkIn).map((c) => {
    const st = m.customerStatement(c.id)
    return {
      customerId: c.id, name: c.name, phone: c.phone, membership: c.membership ?? '', invoices: st.filter(r => r.type === 'sale').length,
      purchases: st.filter(r => r.type === 'sale').reduce((s, r) => s + r.debit, 0), paid: st.filter(r => r.type === 'payment').reduce((s, r) => s + r.credit, 0),
      returns: st.filter(r => r.type === 'return').reduce((s, r) => s + r.credit, 0), due: Math.max(0, st[st.length - 1]?.balance ?? 0), points: c.points,
    }
  }).sort((a, b) => b.purchases - a.purchases)
  return {
    kpis: [
      { label: 'Customers', value: rows.length, format: 'number' },
      { label: 'Purchases', value: rows.reduce((s, r) => s + r.purchases, 0), format: 'money' },
      { label: 'Returns', value: rows.reduce((s, r) => s + r.returns, 0), format: 'money' },
      { label: 'Total due', value: rows.reduce((s, r) => s + r.due, 0), format: 'money', hint: 'Choose a customer for their statement' },
    ],
    columns: [
      { key: 'name', label: 'Customer' }, { key: 'phone', label: 'Phone' }, { key: 'membership', label: 'Membership' },
      { key: 'invoices', label: 'Invoices', align: 'right', format: 'number' }, { key: 'purchases', label: 'Purchases', align: 'right', format: 'money' },
      { key: 'paid', label: 'Paid', align: 'right', format: 'money' }, { key: 'returns', label: 'Returns', align: 'right', format: 'money' },
      { key: 'due', label: 'Due', align: 'right', format: 'money' }, { key: 'points', label: 'Points', align: 'right', format: 'number' },
    ],
    rows,
    totals: { invoices: rows.reduce((s, r) => s + r.invoices, 0), purchases: rows.reduce((s, r) => s + r.purchases, 0), paid: rows.reduce((s, r) => s + r.paid, 0), returns: rows.reduce((s, r) => s + r.returns, 0), due: rows.reduce((s, r) => s + r.due, 0) },
  }
}
function statementActions(r: Record<string, any>) {
  if (r.saleId) return { view: `/sales/${r.saleId}`, items: [{ label: 'Print invoice', icon: Printer, to: `/sales/${r.saleId}?print=1`, hidden: r.type !== 'sale' }] }
  if (r.customerId) return { view: `/customers/${r.customerId}`, items: [{ label: 'Collect due', icon: HandCoins, to: '/customers/due', hidden: !(r.due > 0) }] }
  return null
}
</script>

<template>
  <UiReportPage
    title="Customer report" description="Pick a customer for their statement: every sale, payment and return with the running balance."
    :filters="[{ key: 'customer', label: 'Customers', options: customerOptions }]" :load="load" :row-actions="statementActions"
  />
</template>
