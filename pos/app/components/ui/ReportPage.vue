<script setup lang="ts">
import { Download, Printer, Search, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import type { DateRange, ReportResult, RowAction } from '~/types/domain'

/**
 * Standard report layout: date range + filters → KPI strip, optional trend
 * chart, and a searchable, paginated table with totals and CSV export.
 * Figures come from the API (server-side queries); this page only renders them.
 */
const props = defineProps<{
  title: string
  description?: string
  filters?: { key: string; label: string; options: { value: string; label: string }[] }[]
  load: (params: { range: DateRange; filters: Record<string, string> }) => Promise<ReportResult>
  /** When rows are documents: link View to the document and add extra actions. Return null for no actions on that row. */
  rowActions?: (row: Record<string, any>) => { view?: string | (() => void); items?: RowAction[] } | null
}>()

const iso = (d: Date) => d.toISOString().slice(0, 10)
const range = ref<DateRange>({ from: iso(new Date(Date.now() - 29 * 864e5)), to: iso(new Date()) })
// Filters can be preset from the URL, e.g. /reports/customer?customer=12
const route = useRoute()
const filterValues = ref<Record<string, string>>(Object.fromEntries((props.filters ?? []).map(f => [f.key, typeof route.query[f.key] === 'string' ? String(route.query[f.key]) : ''])))
const data = ref<ReportResult | null>(null)
const loading = ref(false)
const q = ref('')
const page = ref(1)
const perPage = 15
const root = ref<HTMLElement | null>(null)
let seq = 0

async function run() {
  const my = ++seq
  loading.value = true
  try {
    const r = await props.load({ range: range.value, filters: filterValues.value })
    if (my !== seq) return
    data.value = r
    page.value = 1
    await nextTick()
    revealStagger(root.value?.querySelectorAll('[data-kpi]') ?? [], { stagger: 0.05 })
    root.value?.querySelectorAll<HTMLElement>('[data-count]').forEach((el, i) => {
      const k = r.kpis[i]
      if (k) countUp(el, k.value, n => fmtKpi(n, k.format))
    })
  }
  catch (e: any) { useToast().error('Report failed', e?.message) }
  finally { if (my === seq) loading.value = false }
}
watch([range, filterValues], run, { deep: true })
onMounted(run)

function fmtKpi(n: number, f?: string) {
  return f === 'money' ? money(n) : f === 'percent' ? `${n.toFixed(1)}%` : num(Math.round(n))
}
const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  const rows = data.value?.rows ?? []
  return s ? rows.filter(r => Object.values(r).some(v => String(v).toLowerCase().includes(s))) : rows
})
const pageRows = computed(() => filtered.value.slice((page.value - 1) * perPage, page.value * perPage))
const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / perPage)))
watch(q, () => (page.value = 1))
const align = (a?: string) => a === 'right' ? 'text-right' : a === 'center' ? 'text-center' : 'text-left'

function printPage() { window.print() }
function exportCsv() {
  if (!data.value) return
  downloadCsv(`${props.title.toLowerCase().replace(/\s+/g, '-')}_${range.value.from}_${range.value.to}.csv`, data.value.columns, filtered.value)
}
</script>

<template>
  <div ref="root">
    <UiPageHeader :title="title" :description="description">
      <UiButton :icon="Printer" @click="printPage">Print</UiButton>
      <UiButton variant="primary" :icon="Download" :disabled="!data" @click="exportCsv">Export CSV</UiButton>
    </UiPageHeader>

    <div class="card mb-5 flex flex-wrap items-center gap-3 p-3">
      <UiDateRange v-model="range" />
      <UiSelect
        v-for="f in filters" :key="f.key" v-model="filterValues[f.key]" class="w-52" :aria-label="f.label"
        :options="[{ value: '', label: `All ${f.label.toLowerCase()}` }, ...f.options.map(o => ({ value: o.value, label: o.label }))]"
      />
      <span v-if="loading" class="ml-auto text-xs text-slate-400">Updating…</span>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <template v-if="data">
        <div v-for="k in data.kpis" :key="k.label" data-kpi class="card p-5">
          <p class="label">{{ k.label }}</p>
          <p data-count class="mt-2 font-display text-2xl font-extrabold tracking-tight text-ink tabular">{{ fmtKpi(k.value, k.format) }}</p>
          <p v-if="k.hint" class="mt-1 text-xs text-slate-500">{{ k.hint }}</p>
        </div>
      </template>
      <template v-else><div v-for="i in 4" :key="i" class="skeleton h-[104px] rounded-2xl" /></template>
    </div>

    <div v-if="data?.series?.length" class="card mb-5 p-5">
      <p class="label mb-2">{{ data.seriesTitle ?? 'Trend' }}</p>
      <ChartsAreaChart :series="data.series" :height="220" />
    </div>

    <div class="card overflow-hidden">
      <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
        <div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Filter rows…" /></div>
        <span class="ml-auto text-[13px] text-slate-500 tabular">{{ num(filtered.length) }} rows</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 bg-slate-50/70">
              <th v-for="c in data?.columns ?? []" :key="c.key" class="label h-10 px-4 whitespace-nowrap" :class="align(c.align)">{{ c.label }}</th>
              <th v-if="rowActions" class="label h-10 px-4 text-right" data-noprint>Actions</th>
            </tr>
          </thead>
          <tbody v-if="!data"><tr v-for="i in 6" :key="i"><td class="px-4 py-3"><div class="skeleton h-4 w-full" /></td></tr></tbody>
          <tbody v-else>
            <tr v-for="(r, i) in pageRows" :key="i" class="border-b border-slate-50 transition-colors hover:bg-brand-50/40">
              <td v-for="c in data.columns" :key="c.key" class="px-4 py-2.5 whitespace-nowrap" :class="align(c.align)"><UiCell :value="r[c.key]" :column="c" /></td>
              <td v-if="rowActions" class="px-3 py-1" data-noprint>
                <UiRowActions v-if="rowActions(r)" :view="rowActions(r)?.view" :items="rowActions(r)?.items ?? []" />
              </td>
            </tr>
          </tbody>
          <tfoot v-if="data?.totals">
            <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
              <td v-for="(c, ci) in data.columns" :key="c.key" class="px-4 py-3 whitespace-nowrap" :class="align(c.align)">
                <template v-if="ci === 0">Total</template>
                <UiCell v-else-if="data.totals[c.key] !== undefined" :value="data.totals[c.key]" :column="c" />
              </td>
              <td v-if="rowActions" />
            </tr>
          </tfoot>
        </table>
        <UiEmptyState v-if="data && !filtered.length" title="No data for this period" description="Change the date range or filters." />
      </div>
      <div class="flex items-center justify-end gap-1 border-t border-slate-100 px-4 py-3 text-[13px] text-slate-500">
        <UiButton size="sm" variant="ghost" :icon="ChevronLeft" :disabled="page <= 1" aria-label="Previous page" @click="page--" />
        <span class="px-2 tabular">Page {{ page }} / {{ lastPage }}</span>
        <UiButton size="sm" variant="ghost" :icon="ChevronRight" :disabled="page >= lastPage" aria-label="Next page" @click="page++" />
      </div>
    </div>
  </div>
</template>
