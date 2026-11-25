<script setup lang="ts">
import { Undo2, Trash2, PackageX, CalendarX, AlertTriangle, Printer, Truck } from 'lucide-vue-next'
import type { Column, Product, RowAction, Supplier } from '~/types/domain'
import type { PurchaseReturnSummary } from '~/types/inventory'

definePageMeta({ title: 'Purchase returns' })
const api = usePurchaseReturnsApi()
const suppliersApi = useSuppliersApi()
const toast = useToast()
const { ask } = useConfirm()
const { search, page, result, loading, reload } = useList<PurchaseReturnSummary>(q => api.list(q))

const columns: Column[] = [
  { key: 'number', label: 'Return' },
  { key: 'supplier', label: 'Supplier' },
  { key: 'reason', label: 'Reason' },
  { key: 'qty', label: 'Pieces', align: 'right' },
  { key: 'total', label: 'Credit', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const auth = useAuthStore()
const viewing = ref<PurchaseReturnSummary | null>(null)
const viewOpen = computed({ get: () => !!viewing.value, set: v => { if (!v) viewing.value = null } })
async function voidReturn(r: PurchaseReturnSummary) {
  const { ok, reason } = await ask({ title: `Void ${r.number}?`, description: 'The pieces go back into their batches and the supplier credit is reversed. The return stays on record as void.', confirmLabel: 'Void return', requireReason: true })
  if (!ok) return
  try { await api.void(r.id, reason); toast.success('Return voided', r.number); reload() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
function printNote(r: PurchaseReturnSummary) { viewing.value = r; nextTick(() => setTimeout(() => window.print(), 500)) }
const rowItems = (r: PurchaseReturnSummary): RowAction[] => [
  { label: 'Print return note', icon: Printer, onClick: () => printNote(r) },
  { label: 'Open supplier', icon: Truck, to: '/suppliers' },
]
const viewRows = computed(() => viewing.value ? [
  ['Date', dateTime(viewing.value.date)], ['Supplier', viewing.value.supplier], ['Credit note', viewing.value.creditNote || '—'],
  ['Reason', viewing.value.reason], ['Lines', String(viewing.value.lines)], ['Pieces returned', num(viewing.value.qty)],
  ['Credit value', money(viewing.value.total)], ['Status', viewing.value.status === 'posted' ? 'Posted' : 'Void'],
] : [])

// ---- New return ----
interface RLine { key: string; product: Product; batchId: number; qty: number; unitCost: number }
const open = ref(false)
const suppliers = ref<Supplier[]>([])
const form = reactive({ supplierId: null as number | null, creditNote: '', reason: 'Expired', settlement: 'reduce_due' as 'reduce_due' | 'refund' })
const lines = ref<RLine[]>([])
const posting = ref(false)
let idemKey = ''
const reasons = ['Expired', 'Near expiry', 'Damaged in transit', 'Wrong item supplied', 'Recalled by manufacturer'].map(r => ({ value: r, label: r }))

async function startNew() {
  Object.assign(form, { supplierId: null, creditNote: '', reason: 'Expired', settlement: 'reduce_due' })
  lines.value = []
  idemKey = crypto.randomUUID()
  open.value = true
  if (!suppliers.value.length) suppliers.value = await suppliersApi.lookup()
}
/** Expired first, then nearest expiry: those are what is usually sent back. */
const orderedBatches = (p: Product) => [...p.batches].sort((a, b) => a.expiryDate.localeCompare(b.expiryDate))
function add(p: Product) {
  const b = orderedBatches(p)[0]
  if (!b) { toast.warning(`${p.name} has no stock to return`); return }
  lines.value.push({ key: `${p.id}-${Date.now()}`, product: p, batchId: b.batchId, qty: b.qtyBase, unitCost: b.unitCost })
}
const batchOf = (l: RLine) => l.product.batches.find(b => b.batchId === l.batchId)!
function changeBatch(l: RLine) { const b = batchOf(l); l.unitCost = b.unitCost; l.qty = Math.min(l.qty, b.qtyBase) }
const lineError = (l: RLine) => l.qty <= 0 ? 'Qty must be above 0' : l.qty > batchOf(l).qtyBase ? `Only ${batchOf(l).qtyBase} pcs in batch` : ''
const total = computed(() => Math.round(lines.value.reduce((s, l) => s + (Number(l.qty) || 0) * l.unitCost, 0) * 100) / 100)
const supplier = computed(() => suppliers.value.find(s => s.id === form.supplierId))
const invalid = computed(() => !form.supplierId || !lines.value.length || lines.value.some(l => lineError(l)))

async function post() {
  if (invalid.value || !supplier.value) return
  const { ok } = await ask({ title: 'Post purchase return?', description: `${num(lines.value.reduce((s, l) => s + l.qty, 0))} pcs leave stock and ${money(total.value)} is credited against ${supplier.value.name}.`, confirmLabel: 'Post return', tone: 'primary' })
  if (!ok) return
  posting.value = true
  try {
    const r = await api.create({
      warehouseId: auth.warehouseId, supplierId: supplier.value.id, creditNote: form.creditNote, reason: form.reason, settlement: form.settlement,
      lines: lines.value.map(l => ({ productId: l.product.id, batchId: l.batchId, qtyBase: Number(l.qty), unitCost: l.unitCost })),
    }, supplier.value.name, idemKey)
    toast.success('Purchase return posted', `${r.number} · credit ${money(r.total)}`)
    open.value = false
    reload()
  }
  catch (e: any) { toast.error('Return not posted', e?.message) }
  finally { posting.value = false }
}
</script>

<template>
  <div>
    <UiPageHeader title="Purchase returns" description="Send expired, damaged or wrong stock back to the supplier. Posting removes the batch quantity and credits the supplier account.">
      <UiButton v-if="auth.can('purchase_returns.create')" variant="primary" :icon="Undo2" @click="startNew">New return</UiButton>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Return no, supplier, credit note…">
      <template #cell-number="{ row }">
        <p class="font-mono text-[12.5px] font-semibold" :class="row.status === 'void' && 'line-through opacity-60'">{{ row.number }}</p>
        <p class="text-xs text-slate-500">{{ date(row.date) }} · CN {{ row.creditNote || '—' }}</p>
      </template>
      <template #cell-supplier="{ row }"><span class="font-medium">{{ row.supplier }}</span></template>
      <template #cell-reason="{ row }"><UiBadge :tone="row.reason === 'Expired' ? 'danger' : row.reason === 'Near expiry' ? 'warning' : 'neutral'">{{ row.reason }}</UiBadge></template>
      <template #cell-qty="{ row }"><span class="tabular">{{ num(row.qty) }}</span><p class="text-xs text-slate-400">{{ row.lines }} lines</p></template>
      <template #cell-total="{ row }"><span class="font-semibold tabular">{{ money(row.total) }}</span></template>
      <template #cell-status="{ row }"><UiBadge :tone="row.status === 'posted' ? 'success' : 'neutral'" dot class="capitalize">{{ row.status }}</UiBadge></template>
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="row.number" :view="() => (viewing = row)" remove-kind="void"
          :remove="row.status !== 'void' && auth.can('purchase_returns.create') ? () => voidReturn(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>

    <UiDrawer v-model="viewOpen" title="Purchase return" :description="viewing?.number" width="md">
      <dl class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in viewRows" :key="k" data-stagger class="flex justify-between gap-6 py-3">
          <dt class="text-slate-500">{{ k }}</dt><dd class="text-right font-medium">{{ v }}</dd>
        </div>
      </dl>
    </UiDrawer>

    <UiModal v-model="open" title="Return to supplier" description="Expired and nearest-expiry batches are listed first." size="xl" :dismissible="!posting">
      <div class="grid gap-4 sm:grid-cols-3">
        <UiSelect v-model="form.supplierId" label="Supplier" :options="suppliers.map(s => ({ value: s.id, label: s.name }))" placeholder="Choose supplier" :hint="supplier ? `Current due ${money(supplier.dueBalance)}` : undefined" />
        <UiSelect v-model="form.reason" label="Reason" :options="reasons" />
        <UiInput v-model="form.creditNote" label="Supplier credit note no." placeholder="Optional" />
      </div>
      <div class="mt-4"><UiProductPicker include-expired placeholder="Search the product being returned…" @pick="add" /></div>

      <div v-if="lines.length" class="mt-4 space-y-2">
        <div v-for="(l, i) in lines" :key="l.key" class="grid grid-cols-[minmax(0,1fr)] items-center gap-3 rounded-2xl border border-slate-200 p-3 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1.6fr)_100px_110px_36px]">
          <div><p class="text-sm font-semibold">{{ l.product.name }} <span class="font-medium text-slate-500">{{ l.product.strength }}</span></p><p class="text-xs text-slate-500">{{ l.product.manufacturer }}</p></div>
          <div>
            <UiSelect
              v-model="l.batchId" size="sm" aria-label="Batch"
              :options="orderedBatches(l.product).map(b => ({ value: b.batchId, label: `${daysUntil(b.expiryDate) < 0 ? '⚠ EXPIRED · ' : ''}${b.batchNo} · exp ${date(b.expiryDate, { month: 'short', year: '2-digit' })} · ${b.qtyBase} pcs` }))"
              @change="changeBatch(l)"
            />
            <p v-if="expiryState(batchOf(l).expiryDate) === 'expired'" class="mt-1 flex items-center gap-1 text-[11px] font-semibold text-rose-600"><CalendarX class="size-3" />Expired batch</p>
            <p v-else-if="expiryState(batchOf(l).expiryDate) !== 'ok'" class="mt-1 flex items-center gap-1 text-[11px] font-semibold text-amber-600"><AlertTriangle class="size-3" />Expires in {{ daysUntil(batchOf(l).expiryDate) }} days</p>
          </div>
          <div>
            <input v-model.number="l.qty" type="number" min="1" class="h-9 w-full rounded-lg border px-2 text-right font-semibold tabular focus:outline-none" :class="lineError(l) ? 'border-rose-300 bg-rose-50' : 'border-slate-200 focus:border-brand-400'" aria-label="Pieces to return">
            <p v-if="lineError(l)" class="mt-1 text-[11px] font-semibold text-rose-600">{{ lineError(l) }}</p>
          </div>
          <p class="text-right text-sm font-semibold tabular">{{ money((Number(l.qty) || 0) * l.unitCost) }}<span class="block text-[11px] font-normal text-slate-400">@ {{ money(l.unitCost) }}/pc</span></p>
          <button class="grid size-9 place-items-center rounded-lg text-slate-300 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
        </div>
      </div>
      <UiEmptyState v-else :icon="PackageX" title="Nothing to return yet" description="Search a product; its expired or nearest-expiry batch is preselected." />

      <div class="mt-4">
        <p class="mb-2 text-[13px] font-medium text-slate-700">Settlement</p>
        <UiSegmented v-model="form.settlement" :options="[{ value: 'reduce_due', label: 'Reduce supplier due' }, { value: 'refund', label: 'Supplier refunds cash' }]" />
      </div>
      <template #footer>
        <span class="mr-auto text-sm text-slate-500">Credit <strong class="font-display text-lg text-ink tabular">{{ money(total) }}</strong></span>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Undo2" :disabled="invalid" :loading="posting" @click="post">Post return</UiButton>
      </template>
    </UiModal>
  </div>
</template>
