<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowLeft, ScanBarcode, CheckCircle2, XCircle, Save, Equal, Search } from 'lucide-vue-next'
import type { Product } from '~/types/domain'
import type { CountLine, CountSession } from '~/types/inventory'

definePageMeta({ title: 'Stock count' })
const route = useRoute()
const api = useCountsApi()
const toast = useToast()
const { ask } = useConfirm()

const session = ref<CountSession | null>(null)
const lines = ref<CountLine[]>([])
const loading = ref(true)
const saving = ref(false)
const filter = ref<'all' | 'pending' | 'variance'>('all')
const q = ref('')
const root = ref<HTMLElement | null>(null)
const bar = ref<HTMLElement | null>(null)
const varianceEl = ref<HTMLElement | null>(null)

onMounted(async () => {
  const r = await api.get(Number(route.params.id))
  if (r) { session.value = r.session; lines.value = r.lines }
  loading.value = false
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.06 })
})

const editable = computed(() => session.value?.status === 'counting' || session.value?.status === 'open')
const counted = computed(() => lines.value.filter(l => l.countedQty !== null && l.countedQty !== undefined && String(l.countedQty) !== '').length)
const pct = computed(() => lines.value.length ? Math.round((counted.value / lines.value.length) * 100) : 0)
const diff = (l: CountLine) => (l.countedQty === null || String(l.countedQty) === '') ? null : Number(l.countedQty) - l.systemQty
const varianceQty = computed(() => lines.value.reduce((s, l) => s + (diff(l) ?? 0), 0))
const varianceValue = computed(() => Math.round(lines.value.reduce((s, l) => s + (diff(l) ?? 0) * l.unitCost, 0) * 100) / 100)
const shortLines = computed(() => lines.value.filter(l => (diff(l) ?? 0) < 0).length)
const overLines = computed(() => lines.value.filter(l => (diff(l) ?? 0) > 0).length)

watch(pct, (p) => {
  if (!bar.value) return
  prefersReducedMotion() ? gsap.set(bar.value, { width: `${p}%` }) : gsap.to(bar.value, { width: `${p}%`, duration: 0.6, ease: 'power3.out' })
}, { flush: 'post' })
watch(varianceValue, () => pop(varianceEl.value))
onMounted(() => { if (bar.value) gsap.set(bar.value, { width: `${pct.value}%` }) })

const visible = computed(() => {
  const s = q.value.trim().toLowerCase()
  return lines.value.filter(l =>
    (filter.value === 'all' || (filter.value === 'pending' ? diff(l) === null : (diff(l) ?? 0) !== 0))
    && (!s || `${l.productName} ${l.batchNo} ${l.manufacturer}`.toLowerCase().includes(s)))
})

/** Scanning a product jumps to its first uncounted batch row. */
function jumpTo(p: Product) {
  const line = lines.value.find(l => l.productId === p.id && diff(l) === null) ?? lines.value.find(l => l.productId === p.id)
  if (!line) { toast.warning(`${p.name} is not in this count's scope`); return }
  filter.value = 'all'
  q.value = ''
  nextTick(() => {
    const input = root.value?.querySelector<HTMLInputElement>(`[data-line="${line.key}"]`)
    input?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    input?.focus()
    input?.select()
    const row = input?.closest('tr')
    if (row && !prefersReducedMotion()) gsap.fromTo(row, { backgroundColor: '#c9f5ec' }, { backgroundColor: 'rgba(0,0,0,0)', duration: 1.2 })
  })
}
function matchSystem(l: CountLine) { l.countedQty = l.systemQty }

async function save() {
  if (!session.value) return
  saving.value = true
  await api.saveCounts(session.value.id, lines.value.map(l => ({ batchId: l.batchId, countedQty: l.countedQty === null || String(l.countedQty) === '' ? null : Number(l.countedQty) })))
  saving.value = false
  toast.success('Counts saved', `${counted.value} of ${lines.value.length} batches counted`)
}

async function post() {
  if (!session.value) return
  if (counted.value < lines.value.length) {
    const { ok } = await ask({ title: 'Some batches are not counted', description: `${lines.value.length - counted.value} batches have no count. Uncounted batches are left unchanged. Post anyway?`, confirmLabel: 'Post anyway', tone: 'primary' })
    if (!ok) return
  }
  const { ok } = await ask({ title: 'Post this stock count?', description: `Variance ${varianceQty.value > 0 ? '+' : ''}${varianceQty.value} pcs (${money(varianceValue.value)}) will be posted as count movements. This cannot be undone.`, confirmLabel: 'Post count', tone: 'primary' })
  if (!ok) return
  saving.value = true
  try {
    await save()
    await api.post(session.value.id, crypto.randomUUID())
    session.value.status = 'posted'
    toast.success('Count posted', 'Stock now matches the counted quantities.')
  }
  catch (e: any) { toast.error('Count not posted', e?.message) }
  finally { saving.value = false }
}
async function cancel() {
  if (!session.value) return
  const { ok, reason } = await ask({ title: 'Cancel this count?', description: 'No stock changes are posted. The session stays on record as cancelled.', confirmLabel: 'Cancel count', requireReason: true })
  if (!ok) return
  await api.cancel(session.value.id, reason)
  session.value.status = 'cancelled'
  toast.info('Count cancelled')
}
</script>

<template>
  <div ref="root" class="mx-auto max-w-7xl">
    <div v-if="loading" class="space-y-4"><div class="skeleton h-10 w-80" /><div class="skeleton h-28" /><div class="skeleton h-96" /></div>
    <UiEmptyState v-else-if="!session" title="Count not found" />
    <template v-else>
      <div data-reveal class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <NuxtLink to="/inventory/counts"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back to counts" /></NuxtLink>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="font-display text-[24px] font-extrabold tracking-tight">{{ session.name }}</h1>
              <UiBadge :tone="session.status === 'posted' ? 'success' : session.status === 'cancelled' ? 'neutral' : 'brand'" dot class="capitalize">{{ session.status === 'counting' ? 'In progress' : session.status }}</UiBadge>
            </div>
            <p class="text-sm text-slate-500"><span class="font-mono">{{ session.number }}</span> · {{ session.scope }} · started {{ dateTime(session.startedAt) }} by {{ session.startedBy }}</p>
          </div>
        </div>
        <div v-if="editable" class="flex gap-2">
          <UiButton variant="ghost" :icon="XCircle" class="text-rose-600" @click="cancel">Cancel count</UiButton>
          <UiButton :icon="Save" :loading="saving" @click="save">Save progress</UiButton>
          <UiButton variant="primary" :icon="CheckCircle2" :disabled="!counted || saving" magnetic @click="post">Post count</UiButton>
        </div>
      </div>

      <div data-reveal class="mb-5 grid gap-4 md:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))]">
        <div class="card p-5">
          <div class="flex items-center justify-between"><p class="label">Progress</p><span class="text-sm font-bold text-brand-700 tabular">{{ pct }}%</span></div>
          <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100"><div ref="bar" class="h-full rounded-full bg-gradient-to-r from-brand-400 to-brand-600" style="width: 0" /></div>
          <p class="mt-2 text-xs text-slate-500 tabular">{{ counted }} of {{ lines.length }} batches counted</p>
        </div>
        <div class="card p-5"><p class="label">Short</p><p class="mt-1 font-display text-2xl font-extrabold text-rose-600 tabular">{{ shortLines }}</p><p class="text-xs text-slate-500">batches</p></div>
        <div class="card p-5"><p class="label">Over</p><p class="mt-1 font-display text-2xl font-extrabold text-emerald-600 tabular">{{ overLines }}</p><p class="text-xs text-slate-500">batches</p></div>
        <div class="card p-5"><p class="label">Variance</p><p ref="varianceEl" class="mt-1 font-display text-2xl font-extrabold tabular" :class="varianceValue < 0 ? 'text-rose-600' : varianceValue > 0 ? 'text-emerald-600' : 'text-ink'">{{ money(varianceValue) }}</p><p class="text-xs text-slate-500 tabular">{{ varianceQty > 0 ? '+' : '' }}{{ varianceQty }} pcs at cost</p></div>
      </div>

      <div data-reveal class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-3">
          <div v-if="editable" class="w-full md:w-96"><UiProductPicker placeholder="Scan a pack to jump to its batch…" @pick="jumpTo" /></div>
          <div class="w-full md:w-60"><UiInput v-model="q" :icon="Search" placeholder="Filter rows…" /></div>
          <UiSegmented v-model="filter" :options="[{ value: 'all', label: 'All', count: lines.length }, { value: 'pending', label: 'Not counted', count: lines.length - counted }, { value: 'variance', label: 'Variance', count: shortLines + overLines }]" />
        </div>
        <div class="max-h-[62vh] overflow-auto">
          <table class="w-full min-w-[760px] text-sm">
            <thead class="sticky top-0 z-10 bg-slate-50">
              <tr class="border-b border-slate-100">
                <th class="label h-10 px-4 text-left">Product</th>
                <th class="label px-3 text-left">Batch</th>
                <th class="label px-3 text-right">System</th>
                <th class="label px-3 text-center">Counted</th>
                <th class="label px-3 text-right">Variance</th>
                <th class="label px-4 text-right">Value</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in visible" :key="l.key" class="border-b border-slate-50" :class="(diff(l) ?? 0) < 0 ? 'bg-rose-50/40' : (diff(l) ?? 0) > 0 ? 'bg-emerald-50/40' : ''">
                <td class="px-4 py-2.5"><p class="font-semibold">{{ l.productName }}</p><p class="text-xs text-slate-500">{{ l.manufacturer }}</p></td>
                <td class="px-3 py-2.5"><span class="font-mono text-xs font-semibold">{{ l.batchNo }}</span><div class="mt-1"><UiExpiryBadge :date="l.expiryDate" /></div></td>
                <td class="px-3 py-2.5 text-right text-slate-500 tabular">{{ num(l.systemQty) }}</td>
                <td class="px-3 py-2.5">
                  <div class="flex items-center justify-center gap-1">
                    <input
                      v-model.number="l.countedQty" :data-line="l.key" type="number" min="0" :disabled="!editable" placeholder="—"
                      class="h-9 w-24 rounded-lg border px-2 text-right font-semibold tabular focus:outline-none disabled:bg-transparent disabled:text-slate-600"
                      :class="diff(l) === null ? 'border-slate-200 focus:border-brand-400' : (diff(l) ?? 0) === 0 ? 'border-emerald-300 bg-emerald-50/50' : 'border-amber-300 bg-amber-50/50'"
                      :aria-label="`Counted pieces for batch ${l.batchNo}`"
                    >
                    <button v-if="editable" class="rounded-md p-1.5 text-slate-300 hover:bg-brand-50 hover:text-brand-700" :aria-label="`Count equals system for ${l.batchNo}`" title="Matches system" @click="matchSystem(l)"><Equal class="size-3.5" /></button>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-right font-semibold tabular" :class="(diff(l) ?? 0) < 0 ? 'text-rose-600' : (diff(l) ?? 0) > 0 ? 'text-emerald-600' : 'text-slate-300'">{{ diff(l) === null ? '—' : `${(diff(l) ?? 0) > 0 ? '+' : ''}${diff(l)}` }}</td>
                <td class="px-4 py-2.5 text-right tabular" :class="(diff(l) ?? 0) !== 0 ? 'font-semibold' : 'text-slate-300'">{{ diff(l) ? money((diff(l) ?? 0) * l.unitCost) : '—' }}</td>
              </tr>
            </tbody>
          </table>
          <UiEmptyState v-if="!visible.length" :icon="ScanBarcode" title="No rows" description="Change the filter or scan a product." />
        </div>
      </div>
    </template>
  </div>
</template>
