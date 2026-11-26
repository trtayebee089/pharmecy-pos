<script setup lang="ts">
import { Printer, Scale, CheckCircle2, AlertTriangle } from 'lucide-vue-next'
import type { BalanceSheet } from '~/types/finance'
const { brand } = useBrand()

definePageMeta({ title: 'Balance sheet' })
const api = useFinanceApi()
const auth = useAuthStore()
const asOf = ref(new Date().toISOString().slice(0, 10))
const data = ref<BalanceSheet | null>(null)
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { data.value = await api.balanceSheet(asOf.value) }
  catch (e: any) { useToast().error('Could not load balance sheet', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.08 })
  root.value?.querySelectorAll<HTMLElement>('[data-total]').forEach(el => countUp(el, Number(el.dataset.total), money, 0.9))
}
watch(asOf, load)
onMounted(load)

const sum = (xs?: { amount: number }[]) => (xs ?? []).reduce((s, x) => s + x.amount, 0)
const assets = computed(() => sum(data.value?.assets))
const liabilities = computed(() => sum(data.value?.liabilities))
const equity = computed(() => sum(data.value?.equity))
const balanced = computed(() => Math.abs(assets.value - liabilities.value - equity.value) < 0.01)
const pnl = computed(() => {
  const p = data.value?.pnl
  if (!p) return null
  const netSales = p.sales - p.returns
  const gross = netSales - p.cogs
  return { ...p, netSales, gross, margin: netSales ? (gross / netSales) * 100 : 0, net: gross - p.expenses + p.income }
})
function printPage() { window.print() }
</script>

<template>
  <div ref="root" class="mx-auto max-w-5xl">
    <UiPageHeader title="Balance sheet" description="Position as of a date, built from account, stock and party ledgers. Read-only: every figure traces to posted transactions.">
      <label class="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm" data-noprint>
        <span class="text-slate-500">As of</span>
        <input v-model="asOf" type="date" class="bg-transparent font-medium focus:outline-none" aria-label="As of date">
      </label>
      <UiButton :icon="Printer" :disabled="!data" @click="printPage">Print</UiButton>
    </UiPageHeader>

    <div v-if="loading && !data" class="grid gap-5 md:grid-cols-2"><div class="skeleton h-96 rounded-2xl" /><div class="skeleton h-96 rounded-2xl" /></div>

    <template v-else-if="data">
      <div class="card mb-5 flex flex-wrap items-center gap-4 p-5" data-reveal>
        <span class="grid size-11 place-items-center rounded-2xl" :class="balanced ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'"><component :is="balanced ? CheckCircle2 : AlertTriangle" class="size-5" /></span>
        <div class="min-w-0 flex-1">
          <p class="font-display text-base font-bold">{{ brand }} · {{ auth.warehouse?.name }}</p>
          <p class="text-sm text-slate-500">Statement of financial position as of {{ date(asOf, { day: '2-digit', month: 'long', year: 'numeric' }) }}</p>
        </div>
        <UiBadge :tone="balanced ? 'success' : 'danger'" dot>{{ balanced ? 'Assets = Liabilities + Equity' : 'Out of balance' }}</UiBadge>
      </div>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 md:grid-cols-2">
        <section class="card overflow-hidden" data-reveal>
          <header class="border-b border-slate-100 bg-slate-50/70 px-5 py-3"><h2 class="label">Assets</h2></header>
          <dl class="divide-y divide-slate-100">
            <div v-for="a in data.assets" :key="a.label" class="flex items-start justify-between gap-4 px-5 py-3">
              <dt><p class="text-sm font-medium">{{ a.label }}</p><p v-if="a.note" class="text-xs text-slate-400">{{ a.note }}</p></dt>
              <dd class="text-sm tabular">{{ money(a.amount) }}</dd>
            </div>
          </dl>
          <div class="flex justify-between border-t-2 border-double border-slate-300 bg-brand-50/40 px-5 py-3.5 font-display font-extrabold">
            <span>Total assets</span><span :data-total="assets" class="tabular">{{ money(assets) }}</span>
          </div>
        </section>

        <div class="space-y-5">
          <section class="card overflow-hidden" data-reveal>
            <header class="border-b border-slate-100 bg-slate-50/70 px-5 py-3"><h2 class="label">Liabilities</h2></header>
            <dl class="divide-y divide-slate-100">
              <div v-for="l in data.liabilities" :key="l.label" class="flex items-start justify-between gap-4 px-5 py-3">
                <dt><p class="text-sm font-medium">{{ l.label }}</p><p v-if="l.note" class="text-xs text-slate-400">{{ l.note }}</p></dt>
                <dd class="text-sm tabular">{{ money(l.amount) }}</dd>
              </div>
            </dl>
            <div class="flex justify-between border-t border-slate-200 px-5 py-3 font-semibold"><span>Total liabilities</span><span class="tabular">{{ money(liabilities) }}</span></div>
          </section>
          <section class="card overflow-hidden" data-reveal>
            <header class="border-b border-slate-100 bg-slate-50/70 px-5 py-3"><h2 class="label">Equity</h2></header>
            <dl class="divide-y divide-slate-100">
              <div v-for="e in data.equity" :key="e.label" class="flex items-start justify-between gap-4 px-5 py-3">
                <dt><p class="text-sm font-medium">{{ e.label }}</p></dt>
                <dd class="text-sm tabular">{{ money(e.amount) }}</dd>
              </div>
            </dl>
            <div class="flex justify-between border-t border-slate-200 px-5 py-3 font-semibold"><span>Total equity</span><span class="tabular">{{ money(equity) }}</span></div>
          </section>
          <div class="flex justify-between rounded-2xl border-2 border-double border-slate-300 bg-white px-5 py-3.5 font-display font-extrabold" data-reveal>
            <span>Liabilities + equity</span><span :data-total="liabilities + equity" class="tabular">{{ money(liabilities + equity) }}</span>
          </div>
        </div>
      </div>

      <section v-if="pnl" class="card mt-5 overflow-hidden" data-reveal>
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
          <div class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-xl bg-brand-50 text-brand-700"><Scale class="size-[18px]" /></span>
            <div><h2 class="font-display text-[15px] font-bold">Profit & loss</h2><p class="text-xs text-slate-500">{{ date(pnl.from) }} → {{ date(pnl.to) }} · cost of goods from batch cost</p></div>
          </div>
          <UiBadge :tone="pnl.net >= 0 ? 'success' : 'danger'">{{ pnl.net >= 0 ? 'Profit' : 'Loss' }} {{ money(pnl.net) }}</UiBadge>
        </header>
        <dl class="mx-auto max-w-xl divide-y divide-slate-100 px-5 py-2 text-sm">
          <div class="flex justify-between py-2.5"><dt>Sales</dt><dd class="tabular">{{ money(pnl.sales) }}</dd></div>
          <div class="flex justify-between py-2.5 text-slate-500"><dt class="pl-4">Less sales returns</dt><dd class="tabular">({{ money(pnl.returns) }})</dd></div>
          <div class="flex justify-between py-2.5 font-semibold"><dt>Net sales</dt><dd class="tabular">{{ money(pnl.netSales) }}</dd></div>
          <div class="flex justify-between py-2.5 text-slate-500"><dt class="pl-4">Cost of goods sold</dt><dd class="tabular">({{ money(pnl.cogs) }})</dd></div>
          <div class="flex justify-between py-2.5 font-semibold"><dt>Gross profit <span class="ml-1 text-xs font-normal text-slate-500">{{ pnl.margin.toFixed(1) }}% margin</span></dt><dd class="tabular">{{ money(pnl.gross) }}</dd></div>
          <div class="flex justify-between py-2.5 text-slate-500"><dt class="pl-4">Operating expenses</dt><dd class="tabular">({{ money(pnl.expenses) }})</dd></div>
          <div class="flex justify-between py-2.5 text-slate-500"><dt class="pl-4">Other income</dt><dd class="tabular">{{ money(pnl.income) }}</dd></div>
          <div class="flex justify-between border-t-2 border-double border-slate-300 py-3 font-display text-base font-extrabold" :class="pnl.net >= 0 ? 'text-emerald-700' : 'text-rose-600'"><dt>Net profit</dt><dd class="tabular">{{ money(pnl.net) }}</dd></div>
        </dl>
      </section>
    </template>
  </div>
</template>
