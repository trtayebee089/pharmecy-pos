<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowLeft, Truck, PackagePlus, Trash2, CalendarX, AlertTriangle, CheckCircle2, Receipt } from 'lucide-vue-next'
import type { Product, PurchaseLineInput, Supplier } from '~/types/domain'

definePageMeta({ title: 'New purchase' })

const auth = useAuthStore()
const suppliersApi = useSuppliersApi()
const receiving = useReceivingApi()
const toast = useToast()

const suppliers = ref<Supplier[]>([])
const header = reactive({ supplierId: null as number | null, supplierInvoiceNo: '', invoiceDate: new Date().toISOString().slice(0, 10), otherCharges: 0, paidAmount: 0, paymentMethod: 'cash', note: '' })
const lines = ref<PurchaseLineInput[]>([])
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const done = ref<string | null>(null)
const root = ref<HTMLElement | null>(null)
const tbody = ref<HTMLElement | null>(null)
let idemKey = crypto.randomUUID()
const route = useRoute()
/** received = goods are here now (batch + expiry required, stock added); ordered = purchase order, receive later. */
const mode = ref<'received' | 'ordered'>(route.query.status === 'ordered' ? 'ordered' : 'received')
const fromDraft = ref<string | null>(null)

onMounted(async () => {
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
  suppliers.value = await suppliersApi.lookup()
  if (route.query.supplier) header.supplierId = Number(route.query.supplier)
  // Pre-fill from a draft purchase order (/purchases/new?draft=ID)
  if (route.query.draft) {
    const draft = await usePurchaseOrdersApi().draft(Number(route.query.draft))
    if (draft) {
      fromDraft.value = draft.number
      const catalog = useCatalogApi()
      for (const r of draft.rows.filter(x => x.orderQty > 0)) {
        const p = await catalog.product(r.productId)
        if (!p) continue
        addLine(p)
        const l = lines.value[lines.value.length - 1]!
        const box = p.units.find(u => u.code === 'box')
        const strip = p.units.find(u => u.code === 'strip')
        if (box && Number.isInteger(r.boxQty) && r.boxQty > 0) { l.unitId = box.id; l.qty = r.boxQty }
        else if (strip) { l.unitId = strip.id; l.qty = Math.ceil(r.orderQty / strip.factor) }
        else { l.unitId = p.units[0]!.id; l.qty = r.orderQty }
      }
      header.note = `From draft order ${draft.number}`
    }
  }
})
const supplier = computed(() => suppliers.value.find(s => s.id === header.supplierId))

function addLine(p: Product) {
  const existing = lines.value.find(l => l.product.id === p.id && !l.batchNo)
  if (existing) { existing.qty++; return }
  const buyUnit = [...p.units].sort((a, b) => b.factor - a.factor)[0]!
  const lastCost = p.batches[p.batches.length - 1]?.unitCost ?? buyUnit.price / buyUnit.factor * 0.82
  lines.value.push({
    key: `${p.id}-${Date.now()}`, product: p, unitId: buyUnit.id, qty: 1, freeQty: 0, batchNo: '', expiryDate: '',
    unitCost: Math.round(lastCost * buyUnit.factor * 100) / 100, discountPct: 0, updateRetailPrice: null,
  })
  nextTick(() => {
    const row = tbody.value?.lastElementChild as HTMLElement | null
    if (row && !prefersReducedMotion()) gsap.fromTo(row, { opacity: 0, x: -12, backgroundColor: '#effcf9' }, { opacity: 1, x: 0, backgroundColor: '#fff', duration: 0.6, ease: 'power2.out' })
    row?.querySelector<HTMLInputElement>('[data-batch]')?.focus()
  })
}
function removeLine(key: string) { lines.value = lines.value.filter(l => l.key !== key) }

const unitOf = (l: PurchaseLineInput) => l.product.units.find(u => u.id === l.unitId) ?? l.product.units[0]!
const lineTotal = (l: PurchaseLineInput) => Math.round(l.qty * l.unitCost * (1 - l.discountPct / 100) * 100) / 100
const pieces = (l: PurchaseLineInput) => (l.qty + l.freeQty) * unitOf(l).factor
const subtotal = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0))
const grand = computed(() => Math.round((subtotal.value + (Number(header.otherCharges) || 0)) * 100) / 100)
const due = computed(() => Math.max(0, Math.round((grand.value - (Number(header.paidAmount) || 0)) * 100) / 100))
const totalPieces = computed(() => lines.value.reduce((s, l) => s + pieces(l), 0))

function expiryIssue(l: PurchaseLineInput): 'missing' | 'expired' | 'short' | null {
  if (!l.expiryDate) return 'missing'
  const d = daysUntil(l.expiryDate)
  if (d < 0) return 'expired'
  if (d < 180) return 'short'
  return null
}
const knownBatches = (l: PurchaseLineInput) => l.product.batches.filter(b => daysUntil(b.expiryDate) >= 0)
function useBatch(l: PurchaseLineInput, batchNo: string, expiry: string) { l.batchNo = batchNo; l.expiryDate = expiry }

function validate(): boolean {
  errors.value = {}
  if (!header.supplierId) errors.value.supplier = 'Choose the supplier'
  if (mode.value === 'received' && !header.supplierInvoiceNo.trim()) errors.value.invoice = 'Supplier invoice number is required'
  if (!lines.value.length) errors.value.lines = 'Add at least one product'
  for (const l of lines.value) {
    const ei = expiryIssue(l)
    if (mode.value === 'received') {
      if (!l.batchNo.trim()) errors.value[`${l.key}.batch`] = 'Batch no required'
      if (ei === 'missing') errors.value[`${l.key}.expiry`] = 'Expiry required'
    }
    if (ei === 'expired' && l.expiryDate) errors.value[`${l.key}.expiry`] = 'Already expired: cannot be received'
    if (l.qty <= 0) errors.value[`${l.key}.qty`] = 'Qty must be above 0'
    if (l.unitCost < 0) errors.value[`${l.key}.cost`] = 'Invalid cost'
  }
  if ((Number(header.paidAmount) || 0) > grand.value) errors.value.paid = 'Paid amount exceeds the total'
  return Object.keys(errors.value).length === 0
}

async function post() {
  if (!validate()) {
    shake(root.value?.querySelector('[data-post]') ?? null)
    toast.error('Check the purchase', Object.values(errors.value)[0])
    return
  }
  saving.value = true
  try {
    const res = await receiving.receive({
      status: mode.value, warehouseId: auth.warehouseId, supplierId: header.supplierId!, supplierInvoiceNo: header.supplierInvoiceNo, invoiceDate: header.invoiceDate,
      otherCharges: Number(header.otherCharges) || 0, paidAmount: Number(header.paidAmount) || 0, paymentMethod: header.paymentMethod, note: header.note,
      lines: lines.value.map(l => ({ productId: l.product.id, unitId: l.unitId, qty: l.qty, freeQty: l.freeQty, batchNo: l.batchNo.trim(), expiryDate: l.expiryDate, unitCost: l.unitCost, discountPct: l.discountPct, updateRetailPrice: l.updateRetailPrice })),
    }, idemKey)
    done.value = res.number
    idemKey = crypto.randomUUID()
  }
  catch (e: any) { toast.error('Purchase not posted', e?.message) }
  finally { saving.value = false }
}
function another() { done.value = null; lines.value = []; header.supplierInvoiceNo = ''; header.paidAmount = 0; header.otherCharges = 0 }
</script>

<template>
  <div ref="root" class="mx-auto max-w-7xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4" data-reveal>
      <div class="flex items-center gap-3">
        <NuxtLink to="/purchases"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back" /></NuxtLink>
        <div>
          <h1 class="font-display text-[26px] font-extrabold tracking-tight text-ink">New purchase</h1>
          <p class="text-sm text-slate-500">
            <template v-if="mode === 'received'">Goods are here: each line needs the batch number and expiry printed on the pack. Posting adds stock and the supplier due.</template>
            <template v-else>Purchase order to the supplier: no stock is added until it is received with batch and expiry.</template>
            <span v-if="fromDraft" class="ml-1 font-semibold text-brand-700">Pre-filled from {{ fromDraft }}.</span>
          </p>
        </div>
      </div>
      <UiSegmented v-model="mode" :options="[{ value: 'received', label: 'Goods received now' }, { value: 'ordered', label: 'Order only (receive later)' }]" />
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
      <div class="space-y-5">
        <UiSection title="Supplier & invoice" :icon="Truck">
          <div class="grid gap-4 sm:grid-cols-3">
            <UiSelect v-model="header.supplierId" label="Supplier" :options="suppliers.map(s => ({ value: s.id, label: s.name }))" placeholder="Choose supplier" :error="errors.supplier" :hint="supplier ? `Current due ${money(supplier.dueBalance)}` : undefined" />
            <UiInput v-model="header.supplierInvoiceNo" label="Supplier invoice no." placeholder="INV-…" :error="errors.invoice" />
            <UiInput v-model="header.invoiceDate" type="date" label="Invoice date" />
          </div>
        </UiSection>

        <UiSection :title="mode === 'received' ? 'Items received' : 'Items to order'" :description="mode === 'received' ? `${lines.length} lines · ${num(totalPieces)} pieces into stock` : `${lines.length} lines · ${num(totalPieces)} pieces ordered`" :icon="PackagePlus">
          <UiProductPicker placeholder="Scan the pack barcode or search a product…" @pick="addLine" />
          <p v-if="errors.lines" class="mt-3 text-sm font-medium text-rose-600">{{ errors.lines }}</p>

          <div v-if="lines.length" class="-mx-5 mt-4 overflow-x-auto">
            <table class="w-full min-w-[1000px] text-sm">
              <thead>
                <tr class="border-y border-slate-100 bg-slate-50/70">
                  <th class="label h-9 px-3 text-left">Product</th>
                  <th class="label px-2 text-left">Unit</th>
                  <th class="label px-2 text-right">Qty</th>
                  <th class="label px-2 text-right">Free</th>
                  <th class="label px-2 text-left">Batch no.</th>
                  <th class="label px-2 text-left">Expiry</th>
                  <th class="label px-2 text-right">Cost / unit</th>
                  <th class="label px-2 text-right">Disc %</th>
                  <th class="label px-3 text-right">Line total</th>
                  <th />
                </tr>
              </thead>
              <tbody ref="tbody">
                <tr v-for="l in lines" :key="l.key" class="border-b border-slate-50 align-top">
                  <td class="px-3 py-3">
                    <p class="font-semibold text-ink">{{ l.product.name }} <span class="font-medium text-slate-500">{{ l.product.strength }}</span></p>
                    <p class="text-xs text-slate-500 tabular">= {{ num(pieces(l)) }} pcs<template v-if="l.freeQty"> (incl. free)</template></p>
                    <div v-if="knownBatches(l).length" class="mt-1.5 flex flex-wrap gap-1">
                      <button v-for="b in knownBatches(l)" :key="b.batchId" type="button" class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10.5px] text-slate-600 hover:bg-brand-100 hover:text-brand-800" :title="`Use existing batch ${b.batchNo}`" @click="useBatch(l, b.batchNo, b.expiryDate)">{{ b.batchNo }}</button>
                    </div>
                  </td>
                  <td class="px-2 py-3">
                    <select v-model="l.unitId" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-[13px] font-semibold focus:border-brand-400 focus:outline-none">
                      <option v-for="u in l.product.units" :key="u.id" :value="u.id">{{ u.label }}</option>
                    </select>
                  </td>
                  <td class="px-2 py-3"><input v-model.number="l.qty" type="number" min="1" class="h-9 w-16 rounded-lg border px-2 text-right tabular focus:outline-none" :class="errors[`${l.key}.qty`] ? 'border-rose-300' : 'border-slate-200 focus:border-brand-400'"></td>
                  <td class="px-2 py-3"><input v-model.number="l.freeQty" type="number" min="0" class="h-9 w-14 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none"></td>
                  <td class="px-2 py-3">
                    <input v-model="l.batchNo" data-batch placeholder="e.g. NP2611A" class="h-9 w-32 rounded-lg border px-2 font-mono text-[13px] uppercase focus:outline-none" :class="errors[`${l.key}.batch`] ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200 focus:border-brand-400'">
                  </td>
                  <td class="px-2 py-3">
                    <input v-model="l.expiryDate" type="date" class="h-9 rounded-lg border px-2 text-[13px] focus:outline-none" :class="expiryIssue(l) === 'expired' || errors[`${l.key}.expiry`] ? 'border-rose-300 bg-rose-50/50' : expiryIssue(l) === 'short' ? 'border-amber-300 bg-amber-50/50' : 'border-slate-200 focus:border-brand-400'">
                    <p v-if="expiryIssue(l) === 'expired'" class="mt-1 flex items-center gap-1 text-[11px] font-semibold text-rose-600"><CalendarX class="size-3" />Expired</p>
                    <p v-else-if="expiryIssue(l) === 'short'" class="mt-1 flex items-center gap-1 text-[11px] font-semibold text-amber-600"><AlertTriangle class="size-3" />Short expiry ({{ daysUntil(l.expiryDate) }}d)</p>
                  </td>
                  <td class="px-2 py-3"><input v-model.number="l.unitCost" type="number" min="0" step="0.01" class="h-9 w-24 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none"></td>
                  <td class="px-2 py-3"><input v-model.number="l.discountPct" type="number" min="0" max="100" class="h-9 w-14 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none"></td>
                  <td class="px-3 py-3 text-right font-semibold tabular">{{ money(lineTotal(l)) }}</td>
                  <td class="py-3 pr-3"><button type="button" class="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="removeLine(l.key)"><Trash2 class="size-4" /></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <UiEmptyState v-else :icon="PackagePlus" title="No items yet" description="Scan each pack or search by name. Existing batch numbers can be picked with one click." />
        </UiSection>
      </div>

      <div class="space-y-5">
        <div class="card sticky top-20 overflow-hidden" data-reveal>
          <div class="bg-gradient-to-br from-brand-900 to-brand-950 p-5 text-white">
            <p class="text-[11px] font-semibold tracking-wider text-brand-200/70 uppercase">Purchase total</p>
            <p class="mt-1 font-display text-3xl font-extrabold whitespace-nowrap tabular">{{ money(grand) }}</p>
            <p class="mt-1 text-sm text-brand-100/70">{{ supplier?.name ?? 'No supplier selected' }}</p>
          </div>
          <div class="space-y-3 p-5 text-sm">
            <div class="flex justify-between text-slate-500"><span>Items subtotal</span><span class="tabular">{{ money(subtotal) }}</span></div>
            <div class="flex items-center justify-between text-slate-500"><span>Other charges</span><input v-model.number="header.otherCharges" type="number" min="0" class="h-8 w-24 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none"></div>
            <div class="border-t border-dashed border-slate-200 pt-3">
              <div class="flex items-center justify-between gap-2">
                <select v-model="header.paymentMethod" class="h-8 rounded-lg border border-slate-200 bg-white px-2 text-[13px] font-semibold focus:outline-none">
                  <option value="cash">Paid cash</option><option value="bank">Paid bank</option><option value="bkash">Paid bKash</option>
                </select>
                <input v-model.number="header.paidAmount" type="number" min="0" class="h-8 w-28 rounded-lg border px-2 text-right tabular focus:outline-none" :class="errors.paid ? 'border-rose-300' : 'border-slate-200 focus:border-brand-400'">
              </div>
              <button type="button" class="mt-1.5 text-xs font-semibold text-brand-700 hover:underline" @click="header.paidAmount = grand">Pay in full</button>
            </div>
            <div class="flex justify-between rounded-xl bg-amber-50 px-3 py-2.5 font-semibold" :class="due ? 'text-amber-700' : 'text-slate-400'"><span>Supplier due</span><span class="tabular">{{ money(due) }}</span></div>
            <UiTextarea v-model="header.note" placeholder="Note (optional)" :rows="2" />
            <UiButton data-post variant="primary" size="lg" block :icon="Receipt" :loading="saving" magnetic @click="post">{{ mode === 'received' ? 'Post purchase' : 'Save purchase order' }}</UiButton>
            <p class="text-center text-[11px] text-slate-400">{{ mode === 'received' ? 'Posting is final. Corrections are made with a purchase return.' : 'The order can be edited until it is received.' }}</p>
          </div>
        </div>
      </div>
    </div>

    <UiModal :model-value="!!done" size="sm" :dismissible="false">
      <div class="py-4 text-center">
        <div class="mx-auto grid size-16 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 text-white shadow-[var(--shadow-glow)]"><CheckCircle2 class="size-8" /></div>
        <h3 class="mt-4 font-display text-xl font-extrabold">{{ mode === 'received' ? 'Purchase posted' : 'Purchase order saved' }}</h3>
        <p class="mt-1 font-mono text-sm text-slate-500">{{ done }}</p>
        <p class="mt-3 text-sm text-slate-600">{{ num(totalPieces) }} {{ mode === 'received' ? `pieces added to stock across ${lines.length} batches.` : `pieces ordered on ${lines.length} lines. Receive it when the goods arrive.` }}</p>
        <div class="mt-6 flex justify-center gap-2">
          <NuxtLink to="/purchases"><UiButton>All purchases</UiButton></NuxtLink>
          <UiButton variant="primary" @click="another">Receive another</UiButton>
        </div>
      </div>
    </UiModal>
  </div>
</template>
