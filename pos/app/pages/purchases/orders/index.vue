<script setup lang="ts">
import { gsap } from 'gsap'
import { Wand2, RefreshCw, Save, ListChecks, Sparkles, Building2, Loader2, CheckCircle2 } from 'lucide-vue-next'
import type { Company, OrderRow, AiRun } from '~/types/purchase-orders'
import type { DateRange } from '~/types/domain'

/**
 * Purchase orders by company: replaces the legacy "Product Order Report"
 * (report/product_order_quantity_alert). Review sales vs stock with an AI
 * 7-day forecast, fill box / strip / piece quantities, save a draft order.
 */
definePageMeta({ title: 'Purchase orders' })
const api = usePurchaseOrdersApi()
const toast = useToast()
const route = useRoute()

const iso = (d: Date) => d.toISOString().slice(0, 10)
const range = ref<DateRange>({ from: iso(new Date(Date.now() - 6 * 864e5)), to: iso(new Date()) })
const companies = ref<Company[]>([])
const companyId = ref<number | null>(null)
const companyQuery = ref('')
const rows = ref<OrderRow[]>([])
const loading = ref(false)
const forecastLoading = ref(false)
const saving = ref(false)
const onlyOrdered = ref(false)
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  companies.value = await api.companies()
  companyId.value = Number(route.query.company) || companies.value[0]?.id || null
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.06 })
})
const company = computed(() => companies.value.find(c => c.id === companyId.value))
const filteredCompanies = computed(() => companies.value.filter(c => c.name.toLowerCase().includes(companyQuery.value.toLowerCase())))

async function load() {
  if (!companyId.value) return
  loading.value = true
  try {
    rows.value = await api.report(companyId.value, range.value.from, range.value.to)
    await nextTick()
    revealStagger(root.value?.querySelectorAll('tbody tr') ?? [], { y: 6, stagger: 0.02, duration: 0.3 })
    loadForecast(false)
  }
  catch (e: any) { toast.error('Could not load products', e?.message) }
  finally { loading.value = false }
}
watch([companyId, () => range.value.from, () => range.value.to], load)

async function loadForecast(refresh: boolean) {
  if (!rows.value.length) return
  forecastLoading.value = true
  if (refresh) rows.value.forEach(r => (r.forecast = null))
  try {
    const f = await api.forecast(rows.value.map(r => r.productId), refresh)
    rows.value.forEach(r => (r.forecast = f[r.productId] ?? null))
    if (refresh) toast.success('AI forecast refreshed')
  }
  catch { toast.warning('AI forecast unavailable', 'You can still enter quantities manually.') }
  finally { forecastLoading.value = false }
}

/** Legacy "AI Generate Order": boxes from the period shortage (sold − in stock). */
function generateFromShortage() {
  let n = 0
  for (const r of rows.value) { const b = boxesFromShortage(r); setBoxQty(r, b); if (b) n++ }
  toast.info(n ? `Quantities filled for ${n} products` : 'No shortage in this period', 'Boxes = (sold − in stock) ÷ pieces per box, rounded up. Adjust any line before saving.')
  pulseQty()
}
function generateFromForecast() {
  let n = 0
  for (const r of rows.value) {
    const need = r.forecast?.recommended ?? 0
    const perBox = r.piecesPerStrip * r.stripsPerBox
    setBoxQty(r, need > 0 ? Math.ceil(need / perBox) : 0)
    if (need > 0) n++
  }
  toast.info(n ? `Forecast applied to ${n} products` : 'Forecast says no order is needed')
  pulseQty()
}
function pulseQty() {
  nextTick(() => {
    if (prefersReducedMotion()) return
    gsap.fromTo(root.value?.querySelectorAll('tbody input') ?? [], { backgroundColor: '#c9f5ec' }, { backgroundColor: '#ffffff', duration: 1.1, stagger: 0.004 })
  })
}
function clearQty() { rows.value.forEach(r => setBoxQty(r, 0)) }

const ordered = computed(() => rows.value.filter(r => r.orderQty > 0))
async function saveDraft() {
  if (!companyId.value || !ordered.value.length) { toast.warning('Nothing to save', 'Enter an order quantity for at least one product.'); return }
  saving.value = true
  try {
    const d = await api.saveDraft(companyId.value, range.value.to, rows.value, rows.value.some(r => r.forecast) ? 'ai' : 'manual', crypto.randomUUID())
    toast.success('Draft order saved', `${d.number} · ${d.lines} lines · ${num(d.orderQty)} pcs`)
    await navigateTo(`/purchases/orders/${d.id}`)
  }
  catch (e: any) { toast.error('Draft not saved', e?.message) }
  finally { saving.value = false }
}

// ---- Generate drafts for every company (legacy global AI draft run) ----
const runOpen = ref(false)
const run = ref<AiRun | null>(null)
const bar = ref<HTMLElement | null>(null)
async function generateAll() {
  runOpen.value = true
  run.value = await api.startAiRun()
  while (run.value && run.value.status !== 'done') {
    run.value = await api.processAiRun(run.value.id)
    if (bar.value) gsap.to(bar.value, { width: `${(run.value.processed / run.value.total) * 100}%`, duration: 0.5, ease: 'power2.out' })
  }
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Purchase orders" description="Plan what to buy from each company: sales vs stock, AI 7-day forecast, and box / strip / piece quantities. Saved drafts become purchase orders for a supplier.">
      <NuxtLink to="/purchases/orders/drafts"><UiButton :icon="ListChecks">Draft orders</UiButton></NuxtLink>
      <UiButton variant="soft" :icon="Sparkles" @click="generateAll">AI drafts for all companies</UiButton>
    </UiPageHeader>

    <div class="card mb-5 p-4" data-reveal>
      <div class="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[auto_minmax(0,320px)_auto] lg:items-end">
        <div>
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Sales period</p>
          <UiDateRange v-model="range" />
        </div>
        <div>
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Company</p>
          <div class="relative">
            <Building2 class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <select v-model="companyId" class="h-10 w-full appearance-none rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm font-semibold focus:border-brand-400 focus:outline-none" aria-label="Company">
              <option v-for="c in filteredCompanies" :key="c.id" :value="c.id">{{ c.name }} ({{ c.products }})</option>
            </select>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 lg:justify-end">
          <UiButton :icon="RefreshCw" :loading="forecastLoading" :disabled="!rows.length" @click="loadForecast(true)">Refresh AI forecast</UiButton>
          <UiButton variant="soft" :icon="Wand2" :disabled="!rows.length" @click="generateFromShortage">AI generate order</UiButton>
        </div>
      </div>
    </div>

    <div class="card overflow-hidden" data-reveal>
      <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
        <div>
          <p class="font-display text-[15px] font-bold">{{ company?.name ?? 'Choose a company' }}</p>
          <p class="text-xs text-slate-500">{{ rows.length }} products · sold between {{ date(range.from) }} and {{ date(range.to) }}</p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
          <label class="flex items-center gap-2 text-[13px] text-slate-600"><input v-model="onlyOrdered" type="checkbox" class="size-4 accent-brand-600">Only lines with a quantity</label>
          <UiButton size="sm" variant="ghost" :disabled="!rows.length || forecastLoading" @click="generateFromForecast">Use AI forecast</UiButton>
          <UiButton size="sm" variant="ghost" :disabled="!ordered.length" @click="clearQty">Clear</UiButton>
        </div>
      </div>
      <div v-if="loading" class="space-y-2 p-4"><div v-for="i in 7" :key="i" class="skeleton h-11" /></div>
      <PurchasingOrderTable v-else :rows="rows" :forecast-loading="forecastLoading" :only-ordered="onlyOrdered" />
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-4 py-3">
        <p class="text-[13px] text-slate-500">Saving again for the same company and date updates that draft.</p>
        <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="!ordered.length" magnetic @click="saveDraft">Save draft order</UiButton>
      </div>
    </div>

    <UiModal v-model="runOpen" title="AI drafts for all companies" description="Forecasts each company's products and saves a draft where stock will not last 21 days." size="sm" :dismissible="run?.status === 'done'">
      <div class="py-2">
        <div class="flex items-center gap-3">
          <component :is="run?.status === 'done' ? CheckCircle2 : Loader2" class="size-6 text-brand-600" :class="run?.status !== 'done' && 'animate-spin'" />
          <p class="text-sm font-semibold">{{ run?.status === 'done' ? 'Finished' : 'Analysing companies…' }}</p>
          <span class="ml-auto text-sm text-slate-500 tabular">{{ run?.processed ?? 0 }} / {{ run?.total ?? 0 }}</span>
        </div>
        <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100"><div ref="bar" class="h-full w-0 rounded-full bg-gradient-to-r from-brand-400 to-brand-600" /></div>
        <p v-if="run?.status === 'done'" class="mt-4 text-sm text-slate-600">{{ run.created }} draft orders created or updated. Review them before turning them into purchases.</p>
      </div>
      <template #footer>
        <UiButton variant="ghost" :disabled="run?.status !== 'done'" @click="runOpen = false">Close</UiButton>
        <NuxtLink to="/purchases/orders/drafts"><UiButton variant="primary" :disabled="run?.status !== 'done'">View drafts</UiButton></NuxtLink>
      </template>
    </UiModal>
  </div>
</template>
