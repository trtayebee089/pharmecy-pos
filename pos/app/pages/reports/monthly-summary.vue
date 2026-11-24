<script setup lang="ts">
import { ChevronLeft, ChevronRight, Printer, Download, TrendingUp, Receipt, HandCoins, Wallet } from 'lucide-vue-next'

/** Legacy "Summary Report" per month: sales → net sales → gross profit → net profit, payments by method, daily table. */
definePageMeta({ title: 'Monthly summary' })
const api = useApi('reports')

interface Day { date: string; invoices: number; sales: number; returns: number; discounts: number; netSales: number; cogs: number; grossProfit: number; purchases: number; expenses: number; income: number; netProfit: number; cash: number; bkash: number; card: number; nagad: number }
const now = new Date()
const ym = ref({ y: now.getFullYear(), m: now.getMonth() })
const days = ref<Day[]>([])
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

const label = computed(() => new Date(ym.value.y, ym.value.m, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }))
const isCurrent = computed(() => ym.value.y === now.getFullYear() && ym.value.m === now.getMonth())
function shift(d: number) {
  const dt = new Date(ym.value.y, ym.value.m + d, 1)
  if (dt > now) return
  ym.value = { y: dt.getFullYear(), m: dt.getMonth() }
}

async function load() {
  loading.value = true
  if (api.useMocks) { await mockDelay(450); days.value = (await import('~/mocks/reports-extra')).monthlySummary(ym.value.y, ym.value.m) }
  else days.value = await api.get('/reports/monthly-summary', { year: ym.value.y, month: ym.value.m + 1, warehouse_id: useAuthStore().warehouseId })
  loading.value = false
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.05 })
  root.value?.querySelectorAll<HTMLElement>('[data-count]').forEach(el => countUp(el, Number(el.dataset.count), n => money(n)))
}
watch(ym, load, { deep: true })
onMounted(load)

const sum = (k: keyof Day) => days.value.reduce((s, d) => s + (d[k] as number), 0)
const t = computed(() => ({
  invoices: sum('invoices'), sales: sum('sales'), returns: sum('returns'), discounts: sum('discounts'), netSales: sum('netSales'),
  cogs: sum('cogs'), gross: sum('grossProfit'), purchases: sum('purchases'), expenses: sum('expenses'), income: sum('income'), net: sum('netProfit'),
  cash: sum('cash'), bkash: sum('bkash'), card: sum('card'), nagad: sum('nagad'),
}))
const margin = computed(() => t.value.netSales ? (t.value.gross / t.value.netSales) * 100 : 0)
const series = computed(() => days.value.map(d => ({ label: date(d.date, { day: '2-digit', month: 'short' }), value: d.netSales })))
const payMix = computed(() => {
  const total = t.value.cash + t.value.bkash + t.value.card + t.value.nagad || 1
  return [
    { method: 'Cash', value: Math.round((t.value.cash / total) * 100) },
    { method: 'bKash', value: Math.round((t.value.bkash / total) * 100) },
    { method: 'Card', value: Math.round((t.value.card / total) * 100) },
    { method: 'Nagad', value: Math.round((t.value.nagad / total) * 100) },
  ]
})
const statement = computed(() => [
  { label: 'Gross sales', value: t.value.sales },
  { label: 'Less: returns', value: -t.value.returns },
  { label: 'Less: discounts', value: -t.value.discounts },
  { label: 'Net sales', value: t.value.netSales, strong: true },
  { label: 'Less: cost of goods sold (batch cost)', value: -t.value.cogs },
  { label: 'Gross profit', value: t.value.gross, strong: true },
  { label: 'Less: expenses', value: -t.value.expenses },
  { label: 'Add: other income', value: t.value.income },
  { label: 'Net profit', value: t.value.net, strong: true, total: true },
])
const columns = [
  { key: 'date', label: 'Date' }, { key: 'invoices', label: 'Invoices' }, { key: 'sales', label: 'Sales' }, { key: 'returns', label: 'Returns' },
  { key: 'netSales', label: 'Net sales' }, { key: 'grossProfit', label: 'Gross profit' }, { key: 'purchases', label: 'Purchases' },
  { key: 'expenses', label: 'Expenses' }, { key: 'netProfit', label: 'Net profit' },
]
function exportCsv() { downloadCsv(`monthly-summary-${ym.value.y}-${String(ym.value.m + 1).padStart(2, '0')}.csv`, columns, days.value) }
function printPage() { window.print() }
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Monthly summary" description="The month at a glance: sales to net profit, how customers paid, and every day's figures.">
      <UiButton :icon="Printer" @click="printPage">Print</UiButton>
      <UiButton variant="primary" :icon="Download" :disabled="!days.length" @click="exportCsv">Export CSV</UiButton>
    </UiPageHeader>

    <div class="mb-5 flex items-center gap-2" data-noprint>
      <UiButton :icon="ChevronLeft" aria-label="Previous month" @click="shift(-1)" />
      <div class="card flex h-10 min-w-48 items-center justify-center px-4 font-display text-[15px] font-bold">{{ label }}</div>
      <UiButton :icon="ChevronRight" aria-label="Next month" :disabled="isCurrent" @click="shift(1)" />
      <span v-if="isCurrent" class="ml-2 text-xs text-slate-500">Month to date</span>
    </div>

    <div v-if="loading" class="space-y-4"><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><div v-for="i in 4" :key="i" class="skeleton h-28 rounded-2xl" /></div><div class="skeleton h-72 rounded-2xl" /></div>
    <template v-else>
      <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="k in [{ l: 'Net sales', v: t.netSales, i: TrendingUp, h: `${num(t.invoices)} invoices` }, { l: 'Gross profit', v: t.gross, i: HandCoins, h: `${margin.toFixed(1)}% margin` }, { l: 'Expenses', v: t.expenses, i: Receipt, h: `Purchases ${money(t.purchases)}` }, { l: 'Net profit', v: t.net, i: Wallet, h: 'after expenses and other income' }]" :key="k.l" data-reveal class="card p-5">
          <div class="flex items-start justify-between"><p class="label">{{ k.l }}</p><component :is="k.i" class="size-[18px] text-brand-600" /></div>
          <p :data-count="k.v" class="mt-2 font-display text-2xl font-extrabold tracking-tight tabular" :class="k.v < 0 && 'text-rose-600'">{{ money(0) }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ k.h }}</p>
        </div>
      </div>

      <div class="mb-5 grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div class="card p-5" data-reveal>
          <p class="label mb-2">Net sales per day</p>
          <ChartsAreaChart :series="series" :height="220" />
        </div>
        <div class="card p-5" data-reveal>
          <p class="label mb-3">Profit statement</p>
          <dl class="text-sm">
            <div v-for="s in statement" :key="s.label" class="flex justify-between py-1.5" :class="[s.strong && 'font-semibold', s.total && 'mt-1 border-t-2 border-slate-200 pt-2.5 font-display text-base font-extrabold']">
              <dt :class="!s.strong && 'pl-3 text-slate-500'">{{ s.label }}</dt>
              <dd class="tabular" :class="s.value < 0 ? 'text-slate-500' : ''">{{ money(s.value) }}</dd>
            </div>
          </dl>
          <p class="label mt-5 mb-3">How customers paid</p>
          <ChartsDonutChart :items="payMix" />
        </div>
      </div>

      <div class="card overflow-hidden" data-reveal>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[960px] text-sm">
            <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th v-for="c in columns" :key="c.key" class="label h-10 px-4" :class="c.key === 'date' ? 'text-left' : 'text-right'">{{ c.label }}</th></tr></thead>
            <tbody>
              <tr v-for="d in days" :key="d.date" class="border-b border-slate-50 hover:bg-brand-50/40">
                <td class="px-4 py-2.5 text-slate-600">{{ date(d.date, { weekday: 'short', day: '2-digit', month: 'short' }) }}</td>
                <td class="px-4 text-right tabular">{{ num(d.invoices) }}</td>
                <td class="px-4 text-right tabular">{{ money(d.sales) }}</td>
                <td class="px-4 text-right text-slate-500 tabular">{{ money(d.returns) }}</td>
                <td class="px-4 text-right font-semibold tabular">{{ money(d.netSales) }}</td>
                <td class="px-4 text-right tabular">{{ money(d.grossProfit) }}</td>
                <td class="px-4 text-right text-slate-500 tabular">{{ d.purchases ? money(d.purchases) : '—' }}</td>
                <td class="px-4 text-right text-slate-500 tabular">{{ money(d.expenses) }}</td>
                <td class="px-4 text-right font-semibold tabular" :class="d.netProfit < 0 ? 'text-rose-600' : 'text-emerald-700'">{{ money(d.netProfit) }}</td>
              </tr>
            </tbody>
            <tfoot><tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
              <td class="px-4 py-3">Total</td><td class="px-4 text-right tabular">{{ num(t.invoices) }}</td><td class="px-4 text-right tabular">{{ money(t.sales) }}</td>
              <td class="px-4 text-right tabular">{{ money(t.returns) }}</td><td class="px-4 text-right tabular">{{ money(t.netSales) }}</td><td class="px-4 text-right tabular">{{ money(t.gross) }}</td>
              <td class="px-4 text-right tabular">{{ money(t.purchases) }}</td><td class="px-4 text-right tabular">{{ money(t.expenses) }}</td><td class="px-4 text-right tabular">{{ money(t.net) }}</td>
            </tr></tfoot>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
