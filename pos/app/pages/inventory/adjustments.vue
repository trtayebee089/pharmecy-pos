<script setup lang="ts">
import { Plus, Minus, ClipboardEdit, Trash2, ArrowDown, ArrowUp } from 'lucide-vue-next'
import type { AdjustmentSummary, Column, Product } from '~/types/domain'

definePageMeta({ title: 'Stock adjustments' })
const api = useAdjustmentsApi()
const auth = useAuthStore()
const toast = useToast()
const { search, page, filters, result, loading, reload } = useList<AdjustmentSummary>(q => api.list(q), { reason: '' })
const reasonFilter = computed({ get: () => filters.value.reason || 'all', set: v => (filters.value.reason = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Adjustment' },
  { key: 'date', label: 'Date' },
  { key: 'reason', label: 'Reason' },
  { key: 'lines', label: 'Lines', align: 'right' },
  { key: 'qty', label: 'Qty in / out', align: 'right' },
  { key: 'value', label: 'Value at cost', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'createdBy', label: 'By' },
]
const reasonTone = { damage: 'danger', expiry: 'warning', correction: 'info', lost: 'danger', found: 'success' } as const
const reasonOptions = [
  { value: 'damage', label: 'Damaged / broken' },
  { value: 'expiry', label: 'Expired write-off' },
  { value: 'lost', label: 'Lost / missing' },
  { value: 'found', label: 'Found (stock in)' },
  { value: 'correction', label: 'Count correction' },
]

// ---- New adjustment ----
interface AdjLine { key: string; product: Product; batchId: number; direction: 'in' | 'out'; qty: number }
const open = ref(false)
const reason = ref<string>('damage')
const note = ref('')
const lines = ref<AdjLine[]>([])
const posting = ref(false)
let idemKey = ''

function start() { lines.value = []; note.value = ''; reason.value = 'damage'; idemKey = crypto.randomUUID(); open.value = true }
const defaultDirection = computed<'in' | 'out'>(() => reason.value === 'found' ? 'in' : 'out')
watch(reason, () => { if (reason.value !== 'correction') lines.value.forEach(l => (l.direction = defaultDirection.value)) })

function addLine(p: Product) {
  // Expiry write-offs default to the expired batch; otherwise the FEFO batch.
  const batch = reason.value === 'expiry'
    ? (p.batches.find(b => daysUntil(b.expiryDate) < 0) ?? p.batches[0])
    : (p.batches.find(b => daysUntil(b.expiryDate) >= 0) ?? p.batches[0])
  if (!batch) { toast.warning(`${p.name} has no batches`, 'Receive stock first.'); return }
  lines.value.push({ key: `${p.id}-${Date.now()}`, product: p, batchId: batch.batchId, direction: defaultDirection.value, qty: 1 })
}
const batchOf = (l: AdjLine) => l.product.batches.find(b => b.batchId === l.batchId)!
const after = (l: AdjLine) => batchOf(l).qtyBase + (l.direction === 'in' ? l.qty : -l.qty)
const invalid = computed(() => lines.value.some(l => l.qty <= 0 || after(l) < 0))

// Deep link from Batches & expiry: /inventory/adjustments?reason=expiry&product=ID&batch=ID
const route = useRoute()
const catalog = useCatalogApi()
onMounted(async () => {
  if (!route.query.reason) return
  start()
  reason.value = String(route.query.reason)
  const p = route.query.product ? await catalog.product(Number(route.query.product)) : null
  if (p) {
    addLine(p)
    const b = p.batches.find(x => x.batchId === Number(route.query.batch))
    if (b && lines.value[0]) { lines.value[0].batchId = b.batchId; lines.value[0].qty = b.qtyBase }
    note.value = 'Expired stock written off'
  }
})

async function post() {
  if (!lines.value.length || invalid.value) return
  if (!note.value.trim()) { toast.warning('Add a note', 'Every adjustment needs a short explanation for audit.'); return }
  posting.value = true
  try {
    const res = await api.post({ warehouseId: auth.warehouseId, reason: reason.value, note: note.value, items: lines.value.map(l => ({ productId: l.product.id, batchId: l.batchId, qtyBase: l.direction === 'in' ? l.qty : -l.qty })) }, idemKey)
    toast.success('Adjustment posted', res.number)
    open.value = false
    reload()
  }
  catch (e: any) { toast.error('Adjustment not posted', e?.message) }
  finally { posting.value = false }
}
</script>

<template>
  <div>
    <UiPageHeader title="Stock adjustments" description="Damage, expiry write-offs, losses and corrections. Each one is posted to the stock ledger against a specific batch.">
      <UiButton variant="primary" :icon="ClipboardEdit" @click="start">New adjustment</UiButton>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Number, reason, user…">
      <template #filters>
        <UiSegmented v-model="reasonFilter" :options="[{ value: 'all', label: 'All' }, { value: 'damage', label: 'Damage' }, { value: 'expiry', label: 'Expiry' }, { value: 'correction', label: 'Correction' }]" />
      </template>
      <template #cell-number="{ row }"><span class="font-mono text-[12.5px] font-semibold">{{ row.number }}</span></template>
      <template #cell-date="{ row }"><span class="text-slate-600">{{ dateTime(row.date) }}</span></template>
      <template #cell-reason="{ row }"><UiBadge :tone="reasonTone[row.reason as keyof typeof reasonTone]" class="capitalize">{{ row.reason }}</UiBadge></template>
      <template #cell-qty="{ row }">
        <span class="tabular"><span v-if="row.qtyIn" class="font-semibold text-emerald-600">+{{ row.qtyIn }}</span><span v-if="row.qtyOut" class="font-semibold text-rose-600">−{{ row.qtyOut }}</span> pcs</span>
      </template>
      <template #cell-value="{ row }"><span class="tabular">{{ money(row.value) }}</span></template>
      <template #cell-status="{ row }"><UiBadge :tone="row.status === 'posted' ? 'success' : 'info'" dot class="capitalize">{{ row.status }}</UiBadge></template>
      <template #cell-createdBy="{ row }"><span class="text-slate-500">{{ row.createdBy }}</span></template>
    </UiDataTable>

    <UiModal v-model="open" title="New stock adjustment" description="Quantities are in pieces. A batch can never go below zero." size="xl" :dismissible="!posting">
      <div class="grid gap-4 sm:grid-cols-[240px_1fr]">
        <UiSelect v-model="reason" label="Reason" :options="reasonOptions" />
        <UiInput v-model="note" label="Note (required)" placeholder="What happened? e.g. 2 strips crushed during unloading" />
      </div>
      <div class="mt-4"><UiProductPicker placeholder="Search the product to adjust…" @pick="addLine" /></div>

      <div v-if="lines.length" class="mt-4 space-y-2">
        <div v-for="(l, i) in lines" :key="l.key" class="grid items-center gap-3 rounded-2xl border border-slate-200 p-3 md:grid-cols-[1.4fr_1.4fr_auto_110px_110px_36px]">
          <div><p class="text-sm font-semibold">{{ l.product.name }} <span class="font-medium text-slate-500">{{ l.product.strength }}</span></p><p class="text-xs text-slate-500">{{ l.product.manufacturer }}</p></div>
          <select v-model="l.batchId" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-[13px] focus:border-brand-400 focus:outline-none" aria-label="Batch">
            <option v-for="b in l.product.batches" :key="b.batchId" :value="b.batchId">{{ b.batchNo }} · exp {{ date(b.expiryDate, { month: 'short', year: '2-digit' }) }}{{ daysUntil(b.expiryDate) < 0 ? ' (expired)' : '' }} · {{ b.qtyBase }} pcs</option>
          </select>
          <div class="flex rounded-lg bg-slate-100 p-0.5">
            <button type="button" class="flex h-8 items-center gap-1 rounded-md px-2.5 text-xs font-semibold" :class="l.direction === 'out' ? 'bg-white text-rose-600 shadow-sm' : 'text-slate-500'" :disabled="reason !== 'correction'" @click="l.direction = 'out'"><Minus class="size-3" />Out</button>
            <button type="button" class="flex h-8 items-center gap-1 rounded-md px-2.5 text-xs font-semibold" :class="l.direction === 'in' ? 'bg-white text-emerald-600 shadow-sm' : 'text-slate-500'" :disabled="reason !== 'correction'" @click="l.direction = 'in'"><Plus class="size-3" />In</button>
          </div>
          <input v-model.number="l.qty" type="number" min="1" class="h-9 rounded-lg border px-2 text-right font-semibold tabular focus:outline-none" :class="after(l) < 0 ? 'border-rose-300 bg-rose-50' : 'border-slate-200 focus:border-brand-400'" aria-label="Quantity in pieces">
          <p class="flex items-center gap-1 text-xs tabular" :class="after(l) < 0 ? 'font-semibold text-rose-600' : 'text-slate-500'">
            <component :is="l.direction === 'in' ? ArrowUp : ArrowDown" class="size-3" />{{ batchOf(l).qtyBase }} → {{ after(l) }}
          </p>
          <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-300 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
        </div>
      </div>
      <UiEmptyState v-else :icon="ClipboardEdit" title="No lines yet" description="Search a product above. Expiry write-offs pick the expired batch automatically." />

      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="posting" :disabled="!lines.length || invalid" @click="post">Post adjustment</UiButton>
      </template>
    </UiModal>
  </div>
</template>
