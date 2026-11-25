<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowRightLeft, ArrowRight, PackageCheck, Ban, Trash2, Truck, Printer } from 'lucide-vue-next'
import type { Column, Product, RowAction } from '~/types/domain'
import type { TransferLine, TransferSummary } from '~/types/inventory'

definePageMeta({ title: 'Transfers' })
const api = useTransfersApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const { search, page, filters, result, loading, reload } = useList<TransferSummary>(q => api.list(q), { status: '' })
const status = computed({ get: () => filters.value.status || 'all', set: v => (filters.value.status = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Transfer' },
  { key: 'route', label: 'From → To' },
  { key: 'qty', label: 'Pieces', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'createdBy', label: 'By' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const tone = { draft: 'info', sent: 'warning', received: 'success', void: 'neutral' } as const
const statusLabel = { draft: 'Draft', sent: 'In transit', received: 'Received', void: 'Void' } as const
const warehouses = computed(() => auth.user?.warehouses ?? [])

// ---- Detail ----
const detail = ref<TransferSummary | null>(null)
const detailOpen = computed({ get: () => !!detail.value, set: v => { if (!v) detail.value = null } })

async function receive(t: TransferSummary) {
  const { ok } = await ask({ title: `Receive ${t.number}?`, description: `${num(t.qty)} pieces in ${t.lines.length} batches will be added to ${t.to}. Check the physical goods first.`, confirmLabel: 'Receive goods', tone: 'primary' })
  if (!ok) return
  await api.receive(t.id, crypto.randomUUID())
  t.status = 'received'
  toast.success('Transfer received', `${num(t.qty)} pcs added to ${t.to}`)
  reload()
}
async function voidTransfer(t: TransferSummary) {
  const { ok, reason } = await ask({ title: `Void ${t.number}?`, description: 'Stock in transit returns to the source warehouse. The transfer stays on record as void.', confirmLabel: 'Void transfer', requireReason: true })
  if (!ok) return
  await api.void(t.id, reason)
  toast.success('Transfer voided')
  detail.value = null
  reload()
}

function printTransfer(t: TransferSummary) { detail.value = t; nextTick(() => setTimeout(() => window.print(), 500)) }
const rowItems = (t: TransferSummary): RowAction[] => [
  { label: 'Receive goods', icon: PackageCheck, onClick: () => receive(t), hidden: t.status !== 'sent' || !auth.can('inventory.transfer') },
  { label: 'Print transfer note', icon: Printer, onClick: () => printTransfer(t) },
]

// ---- New transfer ----
interface Draft { key: string; product: Product; batchId: number; qty: number }
const open = ref(false)
const form = reactive({ from: null as number | null, to: null as number | null, note: '' })
const nameOf = (id: number | null) => warehouses.value.find(w => w.id === id)?.name ?? ''
const draft = ref<Draft[]>([])
const sending = ref(false)
let idemKey = ''
function startNew() {
  form.from = auth.warehouse?.id ?? warehouses.value[0]?.id ?? null
  form.to = warehouses.value.find(w => w.id !== form.from)?.id ?? null
  form.note = ''
  draft.value = []
  idemKey = crypto.randomUUID()
  open.value = true
}
const sellable = (p: Product) => p.batches.filter(b => daysUntil(b.expiryDate) >= 0)
function add(p: Product) {
  const b = sellable(p)[0]
  if (!b) { toast.warning(`${p.name} has no sellable batch`, 'Expired stock cannot be transferred for sale.'); return }
  draft.value.push({ key: `${p.id}-${Date.now()}`, product: p, batchId: b.batchId, qty: Math.min(b.qtyBase, 10) })
}
const batchOf = (d: Draft) => d.product.batches.find(b => b.batchId === d.batchId)!
const lineError = (d: Draft) => d.qty <= 0 ? 'Qty must be above 0' : d.qty > batchOf(d).qtyBase ? `Only ${batchOf(d).qtyBase} pcs in batch` : ''
const invalid = computed(() => !form.from || !form.to || form.from === form.to || !draft.value.length || draft.value.some(d => lineError(d)))
const totalQty = computed(() => draft.value.reduce((s, d) => s + (Number(d.qty) || 0), 0))

const arrow = ref<HTMLElement | null>(null)
watch(() => [form.from, form.to], () => {
  if (arrow.value && !prefersReducedMotion()) gsap.fromTo(arrow.value, { x: -6, opacity: 0.4 }, { x: 0, opacity: 1, duration: 0.4, ease: 'back.out(2)' })
})

async function send() {
  if (invalid.value) return
  sending.value = true
  try {
    const lines: TransferLine[] = draft.value.map(d => ({ productId: d.product.id, productName: `${d.product.name} ${d.product.strength ?? ''}`.trim(), batchId: d.batchId, batchNo: batchOf(d).batchNo, expiryDate: batchOf(d).expiryDate, qtyBase: Number(d.qty) }))
    const t = await api.send(
      { fromWarehouseId: form.from!, toWarehouseId: form.to!, note: form.note, lines: lines.map(l => ({ productId: l.productId, batchId: l.batchId, qtyBase: l.qtyBase })) },
      idemKey, { from: nameOf(form.from), to: nameOf(form.to), lines },
    )
    toast.success('Transfer sent', `${t.number} · ${num(t.qty)} pcs in transit`)
    open.value = false
    reload()
  }
  catch (e: any) { toast.error('Transfer not sent', e?.message) }
  finally { sending.value = false }
}
</script>

<template>
  <div>
    <UiPageHeader title="Transfers" description="Move stock between warehouses batch by batch. Stock is in transit from sending until the destination receives it.">
      <UiButton variant="primary" :icon="ArrowRightLeft" :disabled="warehouses.length < 2" @click="startNew">New transfer</UiButton>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Transfer no, warehouse, user…">
      <template #filters>
        <UiSegmented v-model="status" :options="[{ value: 'all', label: 'All' }, { value: 'sent', label: 'In transit' }, { value: 'received', label: 'Received' }, { value: 'void', label: 'Void' }]" />
      </template>
      <template #cell-number="{ row }">
        <button class="text-left" @click="detail = row"><p class="font-mono text-[12.5px] font-semibold hover:text-brand-700" :class="row.status === 'void' && 'line-through opacity-60'">{{ row.number }}</p><p class="text-xs text-slate-500">{{ dateTime(row.date) }}</p></button>
      </template>
      <template #cell-route="{ row }">
        <span class="flex items-center gap-2 text-slate-700"><span class="truncate">{{ row.from.replace('Red Pharma · ', '') }}</span><ArrowRight class="size-3.5 text-brand-500" /><span class="truncate font-semibold">{{ row.to.replace('Red Pharma · ', '') }}</span></span>
      </template>
      <template #cell-qty="{ row }"><span class="font-semibold tabular">{{ num(row.qty) }}</span><p class="text-xs text-slate-400">{{ row.lines.length }} batches</p></template>
      <template #cell-status="{ row }"><UiBadge :tone="tone[row.status as keyof typeof tone]" dot>{{ statusLabel[row.status as keyof typeof statusLabel] }}</UiBadge></template>
      <template #cell-createdBy="{ row }"><span class="text-slate-500">{{ row.createdBy }}</span></template>
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="row.number" :view="() => (detail = row)" remove-kind="void"
          :remove="(row.status === 'draft' || row.status === 'sent') && auth.can('inventory.transfer') ? () => voidTransfer(row) : undefined"
          :items="rowItems(row)"
        >
          <UiButton v-if="row.status === 'sent' && auth.can('inventory.transfer')" size="sm" variant="soft" :icon="PackageCheck" @click="receive(row)">Receive</UiButton>
        </UiRowActions>
      </template>
    </UiDataTable>

    <UiDrawer v-model="detailOpen" :title="detail?.number" :description="detail ? `${detail.from} → ${detail.to}` : ''">
      <template v-if="detail">
        <div data-stagger class="flex items-center gap-2">
          <UiBadge :tone="tone[detail.status]" dot>{{ statusLabel[detail.status] }}</UiBadge>
          <span class="text-sm text-slate-500">{{ dateTime(detail.date) }} · by {{ detail.createdBy }}</span>
        </div>
        <p v-if="detail.note" data-stagger class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ detail.note }}</p>
        <ul data-stagger class="mt-5 divide-y divide-slate-100 rounded-2xl border border-slate-200">
          <li v-for="l in detail.lines" :key="l.batchId" class="flex items-center gap-3 px-4 py-3">
            <div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ l.productName }}</p><p class="font-mono text-xs text-slate-500">{{ l.batchNo }}</p></div>
            <UiExpiryBadge :date="l.expiryDate" />
            <span class="w-16 text-right font-semibold tabular">{{ num(l.qtyBase) }}</span>
          </li>
        </ul>
        <p data-stagger class="mt-3 text-right text-sm text-slate-500">Total <strong class="text-ink tabular">{{ num(detail.qty) }} pcs</strong></p>
      </template>
      <template #footer>
        <UiButton v-if="detail?.status === 'sent'" variant="ghost" :icon="Ban" class="text-rose-600" @click="voidTransfer(detail!)">Void</UiButton>
        <UiButton v-if="detail?.status === 'sent'" variant="primary" :icon="PackageCheck" @click="receive(detail!)">Receive goods</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="open" title="New transfer" description="Choose the exact batch for each product. Expired batches cannot be transferred." size="xl" :dismissible="!sending">
      <div class="grid grid-cols-[minmax(0,1fr)] items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
        <UiSelect v-model="form.from" label="From" :options="warehouses.map(w => ({ value: w.id, label: w.name }))" @update:model-value="draft = []" />
        <span ref="arrow" class="hidden h-10 place-items-center text-brand-600 sm:grid"><ArrowRight class="size-5" /></span>
        <UiSelect v-model="form.to" label="To" :options="warehouses.filter(w => w.id !== form.from).map(w => ({ value: w.id, label: w.name }))" placeholder="Destination" :error="form.from && form.from === form.to ? 'Choose a different warehouse' : undefined" />
      </div>
      <div class="mt-4"><UiProductPicker :warehouse-id="form.from ?? undefined" placeholder="Search a product to transfer…" @pick="add" /></div>
      <div v-if="draft.length" class="mt-4 space-y-2">
        <div v-for="(d, i) in draft" :key="d.key" class="grid grid-cols-[minmax(0,1fr)] items-center gap-3 rounded-2xl border border-slate-200 p-3 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_110px_36px]">
          <div><p class="text-sm font-semibold">{{ d.product.name }} <span class="font-medium text-slate-500">{{ d.product.strength }}</span></p><p class="text-xs text-slate-500">{{ d.product.manufacturer }}</p></div>
          <UiSelect
            v-model="d.batchId" size="sm" aria-label="Batch"
            :options="sellable(d.product).map(b => ({ value: b.batchId, label: `${b.batchNo} · exp ${date(b.expiryDate, { month: 'short', year: '2-digit' })} · ${b.qtyBase} pcs` }))"
          />
          <div>
            <input v-model.number="d.qty" type="number" min="1" class="h-9 w-full rounded-lg border px-2 text-right font-semibold tabular focus:outline-none" :class="lineError(d) ? 'border-rose-300 bg-rose-50' : 'border-slate-200 focus:border-brand-400'" aria-label="Pieces to transfer">
            <p v-if="lineError(d)" class="mt-1 text-[11px] font-semibold text-rose-600">{{ lineError(d) }}</p>
          </div>
          <button class="grid size-9 place-items-center rounded-lg text-slate-300 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="draft.splice(i, 1)"><Trash2 class="size-4" /></button>
        </div>
      </div>
      <UiEmptyState v-else :icon="Truck" title="No products yet" description="Search products above; the earliest-expiry sellable batch is chosen by default." />
      <div class="mt-4"><UiInput v-model="form.note" label="Note" placeholder="Optional, e.g. weekend restock" /></div>
      <template #footer>
        <span class="mr-auto text-sm text-slate-500">Sending <strong class="text-ink tabular">{{ num(totalQty) }} pcs</strong></span>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Truck" :disabled="invalid" :loading="sending" @click="send">Send transfer</UiButton>
      </template>
    </UiModal>
  </div>
</template>
