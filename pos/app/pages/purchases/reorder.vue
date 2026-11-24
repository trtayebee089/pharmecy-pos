<script setup lang="ts">
import { gsap } from 'gsap'
import { Sparkles, Truck, CheckCircle2, Trash2, TrendingUp, PackageMinus, Brain, FileText } from 'lucide-vue-next'
import type { ReorderLine } from '~/types/inventory'

definePageMeta({ title: 'Reorder drafts' })
const api = useReorderApi()
const toast = useToast()
const { ask } = useConfirm()

const lines = ref<ReorderLine[]>([])
const generating = ref(false)
const generated = ref(false)
const converting = ref(false)
const days = ref(30)
const cover = ref(21)
const drafts = ref<{ supplier: string; number: string }[]>([])
const root = ref<HTMLElement | null>(null)
const ring = ref<SVGCircleElement | null>(null)
const pctEl = ref<HTMLElement | null>(null)
const stepIdx = ref(0)

const steps = ['Reading sales history', 'Excluding expired & near-expiry batches', 'Forecasting demand per product', 'Rounding to box / strip packs', 'Grouping by supplier']
const R = 52
const C = 2 * Math.PI * R

async function generate() {
  generating.value = true
  generated.value = false
  drafts.value = []
  stepIdx.value = 0
  await nextTick()
  const state = { p: 0 }
  const tl = gsap.timeline()
  if (!prefersReducedMotion()) {
    tl.to(state, {
      p: 92, duration: 1.5, ease: 'power1.inOut',
      onUpdate: () => {
        if (ring.value) ring.value.style.strokeDashoffset = String(C * (1 - state.p / 100))
        if (pctEl.value) pctEl.value.textContent = `${Math.round(state.p)}%`
        stepIdx.value = Math.min(steps.length - 1, Math.floor(state.p / (92 / steps.length)))
      },
    })
    gsap.to('[data-orbit]', { rotate: 360, duration: 3, ease: 'none', repeat: -1, transformOrigin: '50% 50%' })
  }
  try {
    lines.value = await api.generate({ days: days.value, coverDays: cover.value })
    tl.kill()
    if (ring.value) ring.value.style.strokeDashoffset = '0'
    if (pctEl.value) pctEl.value.textContent = '100%'
    stepIdx.value = steps.length
    await new Promise(r => setTimeout(r, prefersReducedMotion() ? 0 : 350))
    generating.value = false
    generated.value = true
    await nextTick()
    revealStagger(root.value?.querySelectorAll('[data-group]') ?? [], { stagger: 0.08 })
  }
  catch (e: any) {
    generating.value = false
    toast.error('Suggestions failed', e?.message)
  }
}

const groups = computed(() => {
  const map = new Map<number, { supplierId: number; supplierName: string; lines: ReorderLine[] }>()
  for (const l of lines.value) {
    if (!map.has(l.supplierId)) map.set(l.supplierId, { supplierId: l.supplierId, supplierName: l.supplierName, lines: [] })
    map.get(l.supplierId)!.lines.push(l)
  }
  return [...map.values()]
})
const factor = (l: ReorderLine) => l.unit === 'box' ? l.boxFactor : l.unit === 'strip' ? l.stripFactor : 1
const pieces = (l: ReorderLine) => (Number(l.finalQty) || 0) * factor(l)
const selected = computed(() => lines.value.filter(l => l.selected && l.finalQty > 0))
const coverDays = (l: ReorderLine) => Math.round(l.inStock / Math.max(0.1, l.soldQty / days.value))
const groupAll = (g: { lines: ReorderLine[] }) => g.lines.every(l => l.selected)
function toggleGroup(g: { lines: ReorderLine[] }) { const v = !groupAll(g); g.lines.forEach(l => (l.selected = v)) }
function changeUnit(l: ReorderLine) { l.finalQty = Math.max(1, Math.ceil(l.suggestedBase / factor(l))) }

async function convert() {
  if (!selected.value.length) return
  converting.value = true
  try {
    const r = await api.convert(selected.value.map(l => ({ productId: l.productId, supplierId: l.supplierId, unit: l.unit, qty: Number(l.finalQty) })), crypto.randomUUID())
    drafts.value = r.drafts
    const done = new Set(selected.value.map(l => l.key))
    lines.value = lines.value.filter(l => !done.has(l.key))
    toast.success('Draft purchases created', `${r.drafts.length} draft(s), one per supplier`)
  }
  catch (e: any) { toast.error('Could not create drafts', e?.message) }
  finally { converting.value = false }
}
async function discard() {
  const { ok } = await ask({ title: 'Discard these suggestions?', description: 'Nothing is ordered. You can generate fresh suggestions any time.', confirmLabel: 'Discard' })
  if (ok) { lines.value = []; generated.value = false }
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Reorder drafts" description="AI forecast plus low-stock rules suggest what to buy, rounded to supplier packs. Nothing is ordered until you convert it to a draft purchase and receive it.">
      <UiButton v-if="generated && lines.length" variant="ghost" :icon="Trash2" @click="discard">Discard</UiButton>
      <UiButton variant="primary" :icon="Sparkles" :loading="generating" magnetic @click="generate">{{ generated ? 'Regenerate' : 'Generate suggestions' }}</UiButton>
    </UiPageHeader>

    <div class="card mb-5 flex flex-wrap items-center gap-6 p-4">
      <label class="flex items-center gap-2 text-sm text-slate-600">Analyse last
        <select v-model.number="days" class="h-9 rounded-lg border border-slate-200 bg-white px-2 font-semibold focus:outline-none" aria-label="Analysis period"><option :value="14">14</option><option :value="30">30</option><option :value="60">60</option><option :value="90">90</option></select> days of sales
      </label>
      <label class="flex items-center gap-2 text-sm text-slate-600">Target cover
        <select v-model.number="cover" class="h-9 rounded-lg border border-slate-200 bg-white px-2 font-semibold focus:outline-none" aria-label="Target cover days"><option :value="14">14</option><option :value="21">21</option><option :value="30">30</option></select> days
      </label>
      <span class="ml-auto flex items-center gap-1.5 text-xs text-slate-500"><Brain class="size-3.5 text-violet-500" />Forecast runs on the server; usage is logged per run</span>
    </div>

    <!-- Generating -->
    <div v-if="generating" class="card relative grid place-items-center overflow-hidden px-6 py-14 text-center">
      <div class="pointer-events-none absolute -top-20 left-1/2 size-80 -translate-x-1/2 rounded-full bg-violet-400/15 blur-3xl" />
      <div class="relative size-36">
        <svg viewBox="0 0 120 120" class="size-full -rotate-90">
          <circle cx="60" cy="60" :r="R" fill="none" stroke="#f1f5f9" stroke-width="8" />
          <circle ref="ring" cx="60" cy="60" :r="R" fill="none" stroke="url(#reorderGrad)" stroke-width="8" stroke-linecap="round" :stroke-dasharray="C" :stroke-dashoffset="C" />
          <defs><linearGradient id="reorderGrad" x1="0" x2="1"><stop offset="0%" stop-color="#8b5cf6" /><stop offset="100%" stop-color="#0e9f8f" /></linearGradient></defs>
        </svg>
        <div data-orbit class="absolute inset-0"><span class="absolute top-1 left-1/2 size-2.5 -translate-x-1/2 rounded-full bg-violet-500 shadow-[0_0_12px_#8b5cf6]" /></div>
        <div class="absolute inset-0 grid place-items-center"><span ref="pctEl" class="font-display text-2xl font-extrabold tabular">0%</span></div>
      </div>
      <p class="relative mt-6 font-display text-lg font-bold">Building reorder suggestions</p>
      <ul class="relative mt-4 space-y-1.5 text-left text-sm">
        <li v-for="(s, i) in steps" :key="s" class="flex items-center gap-2 transition-colors" :class="i < stepIdx ? 'text-emerald-600' : i === stepIdx ? 'font-semibold text-ink' : 'text-slate-400'">
          <CheckCircle2 v-if="i < stepIdx" class="size-4" /><span v-else class="grid size-4 place-items-center"><span class="size-1.5 rounded-full" :class="i === stepIdx ? 'animate-ping bg-violet-500' : 'bg-slate-300'" /></span>{{ s }}
        </li>
      </ul>
    </div>

    <!-- Results -->
    <template v-else-if="generated">
      <div v-if="drafts.length" class="card mb-5 flex flex-wrap items-center gap-3 border-emerald-200 bg-emerald-50/60 p-4">
        <CheckCircle2 class="size-5 text-emerald-600" />
        <span class="text-sm font-semibold text-emerald-800">Created {{ drafts.length }} draft purchase(s):</span>
        <span v-for="d in drafts" :key="d.number" class="rounded-lg bg-white px-2 py-1 font-mono text-xs text-slate-700 ring-1 ring-emerald-200">{{ d.number }} · {{ d.supplier }}</span>
        <NuxtLink to="/purchases/new" class="ml-auto"><UiButton size="sm" variant="primary" :icon="FileText">Receive against draft</UiButton></NuxtLink>
      </div>

      <div v-for="g in groups" :key="g.supplierId" data-group class="card mb-4 overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3.5">
          <input type="checkbox" class="size-4 accent-brand-600" :checked="groupAll(g)" :aria-label="`Select all lines for ${g.supplierName}`" @change="toggleGroup(g)">
          <Truck class="size-4 text-brand-600" />
          <p class="font-display text-[15px] font-bold">{{ g.supplierName }}</p>
          <UiBadge tone="neutral">{{ g.lines.length }} products</UiBadge>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[900px] text-sm">
            <thead><tr class="bg-slate-50/70"><th class="w-10" /><th class="label h-9 px-3 text-left">Product</th><th class="label px-3 text-right">Sold ({{ days }}d)</th><th class="label px-3 text-right">In stock</th><th class="label px-3 text-left">Order</th><th class="label px-3 text-left">Why</th></tr></thead>
            <tbody>
              <tr v-for="l in g.lines" :key="l.key" class="border-t border-slate-50 align-top transition-colors" :class="!l.selected && 'opacity-50'">
                <td class="px-3 py-3"><input v-model="l.selected" type="checkbox" class="size-4 accent-brand-600" :aria-label="`Include ${l.productName}`"></td>
                <td class="px-3 py-3">
                  <p class="font-semibold">{{ l.productName }}</p>
                  <UiBadge :tone="l.source === 'ai' ? 'violet' : 'warning'" class="mt-1"><component :is="l.source === 'ai' ? Sparkles : PackageMinus" class="size-3" />{{ l.source === 'ai' ? 'AI forecast' : 'Below reorder level' }}</UiBadge>
                </td>
                <td class="px-3 py-3 text-right tabular"><span class="inline-flex items-center gap-1"><TrendingUp class="size-3 text-slate-400" />{{ num(l.soldQty) }}</span></td>
                <td class="px-3 py-3 text-right tabular"><span :class="l.inStock <= l.reorderLevel ? 'font-semibold text-amber-600' : ''">{{ num(l.inStock) }}</span><p class="text-[11px] text-slate-400">~{{ coverDays(l) }} days</p></td>
                <td class="px-3 py-3">
                  <div class="flex items-center gap-1.5">
                    <input v-model.number="l.finalQty" type="number" min="0" class="h-9 w-16 rounded-lg border border-slate-200 px-2 text-right font-semibold tabular focus:border-brand-400 focus:outline-none" :aria-label="`Order quantity for ${l.productName}`">
                    <select v-model="l.unit" class="h-9 rounded-lg border border-slate-200 bg-white px-1.5 text-[13px] focus:outline-none" aria-label="Order unit" @change="changeUnit(l)">
                      <option v-if="l.boxFactor > l.stripFactor" value="box">box ({{ l.boxFactor }})</option>
                      <option v-if="l.stripFactor > 1" value="strip">strip ({{ l.stripFactor }})</option>
                      <option value="piece">piece</option>
                    </select>
                  </div>
                  <p class="mt-1 text-[11px] text-slate-400 tabular">= {{ num(pieces(l)) }} pcs · suggested {{ num(l.suggestedBase) }}</p>
                </td>
                <td class="max-w-sm px-3 py-3 text-[12.5px] leading-snug text-slate-600">{{ l.rationale }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <UiEmptyState v-if="!lines.length" :icon="CheckCircle2" title="Nothing left to order" description="All suggestions were converted or discarded." />

      <div v-if="lines.length" class="sticky bottom-4 z-10 mt-5 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white/90 p-3 shadow-[var(--shadow-lift)] backdrop-blur-xl">
        <span class="text-sm text-slate-600"><strong class="text-ink tabular">{{ selected.length }}</strong> lines selected across <strong class="text-ink tabular">{{ new Set(selected.map(l => l.supplierId)).size }}</strong> suppliers</span>
        <UiButton class="ml-auto" variant="primary" :icon="FileText" :disabled="!selected.length" :loading="converting" @click="convert">Create draft purchases</UiButton>
      </div>
    </template>

    <UiEmptyState v-else :icon="Sparkles" title="No suggestions yet" description="Generate suggestions from recent sales, current batch stock and reorder levels.">
      <UiButton variant="primary" :icon="Sparkles" @click="generate">Generate suggestions</UiButton>
    </UiEmptyState>
  </div>
</template>
