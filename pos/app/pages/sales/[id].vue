<script setup lang="ts">
import { ArrowLeft, Printer, Undo2, Ban, Layers, CircleDollarSign, UserRound, Store, PackageCheck } from 'lucide-vue-next'
import type { SaleDetail } from '~/types/domain'

definePageMeta({ title: 'Sale detail' })
const route = useRoute()
const api = useSaleDetailApi()
const auth = useAuthStore()
const toast = useToast()
const sale = ref<SaleDetail | null>(null)
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  sale.value = await api.get(Number(route.params.id))
  loading.value = false
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
  if (route.query.print && sale.value) setTimeout(printInvoice, 600)
}
onMounted(load)
function printInvoice() { window.print() }

// ---- Return flow ----
const returnOpen = ref(false)
const qtys = ref<Record<number, number>>({})
const restock = ref<Record<number, boolean>>({})
const refundMethod = ref('cash')
const reason = ref('')
const submitting = ref(false)
let idemKey = ''

function openReturn() {
  qtys.value = {}
  restock.value = {}
  for (const l of sale.value?.lines ?? []) for (const b of l.batches) { qtys.value[b.saleItemBatchId] = 0; restock.value[b.saleItemBatchId] = true }
  reason.value = ''
  idemKey = crypto.randomUUID()
  returnOpen.value = true
}
const returnable = (b: { qtyBase: number; returnedQtyBase: number }) => b.qtyBase - b.returnedQtyBase
const piecePrice = (l: SaleDetail['lines'][number]) => l.lineTotal / l.qtyBase
const refundTotal = computed(() => (sale.value?.lines ?? []).reduce((s, l) => s + l.batches.reduce((a, b) => a + (qtys.value[b.saleItemBatchId] ?? 0) * piecePrice(l), 0), 0))
const anySelected = computed(() => Object.values(qtys.value).some(q => q > 0))

async function submitReturn() {
  if (!sale.value || !anySelected.value) return
  if (!reason.value.trim()) { toast.warning('A reason is required for returns'); return }
  submitting.value = true
  try {
    const items = Object.entries(qtys.value).filter(([, q]) => q > 0).map(([id, q]) => ({ saleItemBatchId: Number(id), qtyBase: q, restock: restock.value[Number(id)] ?? true }))
    const res = await api.createReturn(sale.value.id, { items, refundMethod: refundMethod.value, reason: reason.value }, idemKey)
    // The API's total is authoritative (it includes the share of any order discount).
    const total = res.total ?? Math.round(refundTotal.value * 100) / 100
    toast.success('Return posted', `${res.number} · refund ${money(total)}`)
    sale.value.returns.push({ number: res.number, date: new Date().toISOString(), total })
    for (const l of sale.value.lines) for (const b of l.batches) b.returnedQtyBase += qtys.value[b.saleItemBatchId] ?? 0
    returnOpen.value = false
  }
  catch (e: any) { toast.error('Return not posted', e?.message) }
  finally { submitting.value = false }
}

// ---- Void ----
const voidOpen = ref(false)
const voidReason = ref('')
async function doVoid() {
  if (!sale.value || !voidReason.value.trim()) return
  try {
    await api.void(sale.value.id, voidReason.value)
    sale.value.status = 'void'
    voidOpen.value = false
    toast.success('Sale voided', 'Stock and payments were reversed.')
  }
  catch (e: any) { toast.error('Sale not voided', e?.message) }
}
const payTone = { paid: 'success', partial: 'warning', unpaid: 'danger' } as const
</script>

<template>
  <div ref="root" class="mx-auto max-w-6xl">
    <div v-if="loading" class="space-y-4"><div class="skeleton h-10 w-80" /><div class="skeleton h-96" /></div>
    <UiEmptyState v-else-if="!sale" title="Sale not found" description="Check the invoice number." />
    <template v-else>
      <div class="mb-6 flex flex-wrap items-center justify-between gap-4" data-reveal>
        <div class="flex items-center gap-3">
          <NuxtLink to="/sales"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back" /></NuxtLink>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="font-mono text-xl font-bold text-ink" :class="sale.status === 'void' && 'line-through opacity-60'">{{ sale.number }}</h1>
              <UiBadge v-if="sale.status === 'void'" tone="neutral">Void</UiBadge>
              <UiBadge v-else :tone="payTone[sale.paymentStatus]" dot class="capitalize">{{ sale.paymentStatus }}</UiBadge>
              <UiBadge v-if="sale.returns.length" tone="info">{{ sale.returns.length }} return(s)</UiBadge>
            </div>
            <p class="text-sm text-slate-500">{{ dateTime(sale.date) }} · {{ sale.cashier }} · {{ sale.channel.toUpperCase() }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <UiButton :icon="Printer" @click="printInvoice">Reprint</UiButton>
          <UiButton v-if="sale.status !== 'void' && auth.can('returns.create')" :icon="Undo2" @click="openReturn">Return items</UiButton>
          <UiButton v-if="sale.status !== 'void' && auth.can('sales.void')" variant="ghost" :icon="Ban" class="text-rose-600" @click="voidOpen = true">Void</UiButton>
        </div>
      </div>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="card overflow-hidden" data-reveal>
          <table class="w-full text-sm">
            <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-5 text-left">Item</th><th class="label px-3 text-right">Qty</th><th class="label px-3 text-right">Price</th><th class="label px-5 text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="l in sale.lines" :key="l.id" class="border-b border-slate-50 align-top">
                <td class="px-5 py-3.5">
                  <p class="font-semibold text-ink">{{ l.productName }}</p>
                  <p v-for="b in l.batches" :key="b.saleItemBatchId" class="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                    <Layers class="size-3" /><span class="font-mono">{{ b.batchNo }}</span> · exp {{ date(b.expiryDate, { month: 'short', year: 'numeric' }) }} · {{ b.qtyBase }} pcs
                    <UiBadge v-if="b.returnedQtyBase" tone="info">{{ b.returnedQtyBase }} returned</UiBadge>
                  </p>
                </td>
                <td class="px-3 py-3.5 text-right tabular">{{ l.qty }} × {{ l.unitLabel }}</td>
                <td class="px-3 py-3.5 text-right tabular">{{ money(l.unitPrice) }}</td>
                <td class="px-5 py-3.5 text-right font-semibold tabular">{{ money(l.lineTotal) }}</td>
              </tr>
            </tbody>
          </table>
          <dl class="ml-auto max-w-xs space-y-2 p-5 text-sm">
            <div class="flex justify-between text-slate-500"><dt>Subtotal</dt><dd class="tabular">{{ money(sale.subtotal) }}</dd></div>
            <div class="flex justify-between text-slate-500"><dt>Discount</dt><dd class="tabular">−{{ money(sale.discountTotal) }}</dd></div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-display text-lg font-extrabold"><dt>Total</dt><dd class="tabular">{{ money(sale.grandTotal) }}</dd></div>
            <div class="flex justify-between text-slate-500"><dt>Paid</dt><dd class="tabular">{{ money(sale.paidTotal) }}</dd></div>
            <div v-if="sale.dueTotal" class="flex justify-between font-semibold text-amber-600"><dt>Due</dt><dd class="tabular">{{ money(sale.dueTotal) }}</dd></div>
          </dl>
        </div>

        <div class="space-y-5">
          <div class="card p-5" data-reveal>
            <p class="label mb-3">Customer & store</p>
            <p class="flex items-center gap-2 text-sm font-semibold"><UserRound class="size-4 text-slate-400" />{{ sale.customer }}</p>
            <p class="mt-1 pl-6 text-xs text-slate-500 tabular">{{ sale.customerPhone ?? 'No phone' }}</p>
            <p class="mt-3 flex items-center gap-2 text-sm"><Store class="size-4 text-slate-400" />{{ sale.warehouse }}</p>
          </div>
          <div class="card p-5" data-reveal>
            <p class="label mb-3 flex items-center gap-1.5"><CircleDollarSign class="size-3.5" />Payments</p>
            <ul class="space-y-2 text-sm">
              <li v-for="(p, i) in sale.payments" :key="i" class="flex justify-between"><span>{{ p.method }} <span class="text-xs text-slate-400">{{ dateTime(p.paidAt) }}</span></span><span class="font-semibold tabular">{{ money(p.amount) }}</span></li>
              <li v-if="!sale.payments.length" class="text-slate-500">No payments</li>
            </ul>
          </div>
          <div v-if="sale.returns.length" class="card p-5" data-reveal>
            <p class="label mb-3 flex items-center gap-1.5"><PackageCheck class="size-3.5" />Returns</p>
            <ul class="space-y-2 text-sm">
              <li v-for="r in sale.returns" :key="r.number" class="flex justify-between"><span class="font-mono text-xs">{{ r.number }}</span><span class="font-semibold tabular">{{ money(r.total) }}</span></li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Return modal -->
      <UiModal v-model="returnOpen" title="Return items" description="Pick quantities per batch. Restocked items go back to the same batch; damaged ones are written off." size="lg" :dismissible="!submitting">
        <div class="space-y-3">
          <div v-for="l in sale.lines" :key="l.id" class="rounded-2xl border border-slate-200 p-3">
            <p class="text-sm font-semibold">{{ l.productName }} <span class="text-xs font-normal text-slate-500">· {{ money(piecePrice(l)) }}/pc</span></p>
            <div v-for="b in l.batches" :key="b.saleItemBatchId" class="mt-2 flex flex-wrap items-center gap-3 text-[13px]">
              <span class="font-mono text-xs text-slate-600">{{ b.batchNo }}</span>
              <span class="text-xs text-slate-500">returnable {{ returnable(b) }} pcs</span>
              <input v-model.number="qtys[b.saleItemBatchId]" type="number" min="0" :max="returnable(b)" class="h-9 w-20 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none" :aria-label="`Return quantity for ${b.batchNo}`" @input="qtys[b.saleItemBatchId] = Math.min(returnable(b), Math.max(0, qtys[b.saleItemBatchId] ?? 0))">
              <button type="button" class="text-xs font-semibold text-brand-700 hover:underline" @click="qtys[b.saleItemBatchId] = returnable(b)">All</button>
              <label class="ml-auto flex items-center gap-2 text-xs text-slate-600"><input v-model="restock[b.saleItemBatchId]" type="checkbox" class="size-4 accent-brand-600">Restock (sellable)</label>
            </div>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <UiSelect v-model="refundMethod" label="Refund as" :options="[{ value: 'cash', label: 'Cash from register' }, { value: 'credit', label: 'Customer credit' }, { value: 'bkash', label: 'bKash' }]" />
            <UiInput v-model="reason" label="Reason" placeholder="e.g. wrong strength dispensed" />
          </div>
        </div>
        <template #footer>
          <span class="mr-auto text-sm text-slate-500">Refund <strong class="font-display text-lg text-ink tabular">{{ money(refundTotal) }}</strong></span>
          <UiButton variant="ghost" @click="returnOpen = false">Cancel</UiButton>
          <UiButton variant="primary" :icon="Undo2" :disabled="!anySelected" :loading="submitting" @click="submitReturn">Post return</UiButton>
        </template>
      </UiModal>

      <UiModal v-model="voidOpen" title="Void this sale?" description="Allowed only during the same open register session. Stock and payments are reversed and the invoice stays on record as void." size="sm">
        <UiInput v-model="voidReason" label="Reason (required)" placeholder="e.g. customer cancelled before leaving the counter" />
        <template #footer>
          <UiButton variant="ghost" @click="voidOpen = false">Keep sale</UiButton>
          <UiButton variant="danger" :icon="Ban" :disabled="!voidReason.trim()" @click="doVoid">Void sale</UiButton>
        </template>
      </UiModal>
    </template>
  </div>
</template>
