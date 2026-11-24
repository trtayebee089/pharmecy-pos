<script setup lang="ts">
import { ArrowLeft, Printer, Undo2, Wallet, Truck, Layers, CircleDollarSign } from 'lucide-vue-next'
import type { PurchaseDetail } from '~/mocks/purchase-detail'

definePageMeta({ title: 'Purchase detail' })
const route = useRoute()
const api = usePurchaseDetailApi()
const toast = useToast()
const p = ref<PurchaseDetail | null>(null)
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  p.value = await api.get(Number(route.params.id))
  loading.value = false
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
})

const payOpen = ref(false)
const pay = reactive({ amount: 0, method: 'bank', reference: '' })
const paying = ref(false)
let idemKey = ''
function startPay() { pay.amount = p.value?.dueTotal ?? 0; pay.reference = ''; idemKey = crypto.randomUUID(); payOpen.value = true }
async function submitPay() {
  if (!p.value || pay.amount <= 0 || pay.amount > p.value.dueTotal) return
  paying.value = true
  try {
    const res = await api.pay(p.value.id, { ...pay }, idemKey)
    p.value.payments.push({ number: res.number, date: new Date().toISOString(), method: pay.method, amount: pay.amount, reference: pay.reference })
    p.value.paidTotal = Math.round((p.value.paidTotal + pay.amount) * 100) / 100
    p.value.dueTotal = Math.round((p.value.dueTotal - pay.amount) * 100) / 100
    payOpen.value = false
    toast.success('Supplier payment recorded', `${res.number} · ${money(pay.amount)}`)
  }
  catch (e: any) { toast.error('Payment not recorded', e?.message) }
  finally { paying.value = false }
}
function printDoc() { window.print() }
const pieces = computed(() => p.value?.lines.reduce((s, l) => s + l.pieces, 0) ?? 0)
</script>

<template>
  <div ref="root" class="mx-auto max-w-6xl">
    <div v-if="loading" class="space-y-4"><div class="skeleton h-10 w-80" /><div class="skeleton h-96" /></div>
    <UiEmptyState v-else-if="!p" title="Purchase not found" />
    <template v-else>
      <div class="mb-6 flex flex-wrap items-center justify-between gap-4" data-reveal>
        <div class="flex items-center gap-3">
          <NuxtLink to="/purchases"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back" /></NuxtLink>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="font-mono text-xl font-bold text-ink">{{ p.number }}</h1>
              <UiBadge :tone="p.status === 'received' ? 'success' : p.status === 'draft' ? 'info' : 'neutral'" dot>{{ p.status === 'draft' ? 'Ordered' : p.status === 'received' ? 'Received' : 'Void' }}</UiBadge>
              <UiBadge :tone="p.dueTotal ? 'warning' : 'success'">{{ p.dueTotal ? 'Due' : 'Paid' }}</UiBadge>
            </div>
            <p class="text-sm text-slate-500">Received {{ date(p.receivedAt) }} by {{ p.receivedBy }} · supplier invoice {{ p.supplierInvoiceNo }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <NuxtLink v-if="p.status === 'draft'" to="/purchases/new"><UiButton variant="primary" :icon="Truck">Receive goods</UiButton></NuxtLink>
          <UiButton :icon="Printer" @click="printDoc">{{ p.status === 'draft' ? 'Print order' : 'Print GRN' }}</UiButton>
          <NuxtLink to="/purchases/returns"><UiButton :icon="Undo2">Return to supplier</UiButton></NuxtLink>
          <UiButton v-if="p.dueTotal > 0" variant="primary" :icon="Wallet" @click="startPay">Pay supplier</UiButton>
        </div>
      </div>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="card overflow-hidden" data-reveal>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
              <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-5 text-left">Item & batch</th><th class="label px-3 text-right">Qty</th><th class="label px-3 text-right">Pieces</th><th class="label px-3 text-right">Cost</th><th class="label px-5 text-right">Total</th></tr></thead>
              <tbody>
                <tr v-for="l in p.lines" :key="l.id" class="border-b border-slate-50 align-top">
                  <td class="px-5 py-3.5">
                    <p class="font-semibold text-ink">{{ l.product }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500"><Layers class="size-3" /><span class="font-mono">{{ l.batchNo }}</span><UiExpiryBadge :date="l.expiryDate" /></p>
                  </td>
                  <td class="px-3 py-3.5 text-right tabular">{{ l.qty }} × {{ l.unitLabel }}<span v-if="l.freeQty" class="block text-xs text-emerald-600">+{{ l.freeQty }} free</span></td>
                  <td class="px-3 py-3.5 text-right tabular">{{ num(l.pieces) }}</td>
                  <td class="px-3 py-3.5 text-right tabular">{{ money(l.unitCost) }}<span v-if="l.discountPct" class="block text-xs text-slate-400">−{{ l.discountPct }}%</span></td>
                  <td class="px-5 py-3.5 text-right font-semibold tabular">{{ money(l.lineTotal) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <dl class="ml-auto max-w-xs space-y-2 p-5 text-sm">
            <div class="flex justify-between text-slate-500"><dt>Items ({{ num(pieces) }} pcs)</dt><dd class="tabular">{{ money(p.subtotal) }}</dd></div>
            <div class="flex justify-between text-slate-500"><dt>Other charges</dt><dd class="tabular">{{ money(p.otherCharges) }}</dd></div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-display text-lg font-extrabold"><dt>Total</dt><dd class="tabular">{{ money(p.grandTotal) }}</dd></div>
            <div class="flex justify-between text-slate-500"><dt>Paid</dt><dd class="tabular">{{ money(p.paidTotal) }}</dd></div>
            <div v-if="p.dueTotal" class="flex justify-between font-semibold text-amber-600"><dt>Supplier due</dt><dd class="tabular">{{ money(p.dueTotal) }}</dd></div>
          </dl>
        </div>

        <div class="space-y-5">
          <div class="card p-5" data-reveal>
            <p class="label mb-3 flex items-center gap-1.5"><Truck class="size-3.5" />Supplier</p>
            <p class="text-sm font-semibold">{{ p.supplier }}</p>
            <p class="mt-1 text-xs text-slate-500">Into {{ p.warehouse }}</p>
          </div>
          <div class="card p-5" data-reveal>
            <p class="label mb-3 flex items-center gap-1.5"><CircleDollarSign class="size-3.5" />Payments</p>
            <ul class="space-y-2 text-sm">
              <li v-for="pm in p.payments" :key="pm.number" class="flex justify-between gap-3"><span>{{ pm.method }} <span class="block text-xs text-slate-400">{{ date(pm.date) }} · {{ pm.reference || pm.number }}</span></span><span class="font-semibold tabular">{{ money(pm.amount) }}</span></li>
              <li v-if="!p.payments.length" class="text-slate-500">No payments yet</li>
            </ul>
          </div>
        </div>
      </div>

      <UiModal v-model="payOpen" title="Pay supplier" :description="`${p.supplier} · due ${money(p.dueTotal)}`" size="sm" :dismissible="!paying">
        <div class="space-y-4">
          <UiInput v-model.number="pay.amount" type="number" label="Amount (৳)" :error="pay.amount > p.dueTotal ? 'More than the amount due' : undefined" />
          <UiSelect v-model="pay.method" label="Paid from" :options="[{ value: 'cash', label: 'Cash' }, { value: 'bank', label: 'Bank transfer' }, { value: 'bkash', label: 'bKash' }]" />
          <UiInput v-model="pay.reference" label="Reference" placeholder="Cheque / transaction no." />
        </div>
        <template #footer>
          <UiButton variant="ghost" @click="payOpen = false">Cancel</UiButton>
          <UiButton variant="primary" :loading="paying" :disabled="pay.amount <= 0 || pay.amount > p.dueTotal" @click="submitPay">Record payment</UiButton>
        </template>
      </UiModal>
    </template>
  </div>
</template>
