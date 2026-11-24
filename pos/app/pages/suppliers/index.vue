<script setup lang="ts">
import { Ban, BookOpen, HandCoins, Phone, Mail, MapPin, Truck, Undo2, PackagePlus } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { SupplierLedgerEntry, SupplierRow } from '~/types/inventory'

definePageMeta({ title: 'Suppliers' })
const book = useSupplierLedgerApi()
const auth = useAuthStore()
const toast = useToast()
const crud = ref<{ reload: () => void } | null>(null)
const supplierActions = (s: SupplierRow): RowAction[] => [
  { label: 'Ledger', icon: BookOpen, onClick: () => openLedger(s) },
  { label: 'Pay supplier', icon: HandCoins, onClick: () => openLedger(s).then(() => startPay(s)), hidden: !(s.dueBalance > 0) || !auth.can('payments.pay_supplier') },
  { label: 'New purchase', icon: PackagePlus, to: `/purchases/new?supplier=${s.id}`, hidden: !auth.can('purchases.create') },
  { label: 'Return to supplier', icon: Undo2, to: '/purchases/returns', hidden: !auth.can('purchase_returns.view') },
  { label: `Call ${s.phone}`, icon: Phone, onClick: () => { window.location.href = `tel:${s.phone}` }, hidden: !s.phone },
]

const columns: CrudColumn[] = [
  { key: 'name', label: 'Supplier' },
  { key: 'phone', label: 'Phone' },
  { key: 'paymentTermsDays', label: 'Terms', align: 'right' },
  { key: 'lastPurchase', label: 'Last purchase', format: 'date' },
  { key: 'dueBalance', label: 'We owe', align: 'right' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Supplier name', type: 'text', required: true, span: 2 },
  { key: 'companyName', label: 'Company', type: 'text' },
  { key: 'phone', label: 'Phone', type: 'tel', required: true, placeholder: '01XXXXXXXXX' },
  { key: 'email', label: 'Email', type: 'email' },
  { key: 'paymentTermsDays', label: 'Payment terms (days)', type: 'number', hint: '0 = cash on delivery' },
  { key: 'address', label: 'Address', type: 'textarea', span: 2 },
  { key: 'isActive', label: 'Active', type: 'switch', span: 2 },
]

// ---- Ledger drawer ----
const selected = ref<SupplierRow | null>(null)
const ledger = ref<SupplierLedgerEntry[]>([])
const ledgerLoading = ref(false)
const tab = ref<'ledger' | 'profile'>('ledger')
const drawer = computed({ get: () => !!selected.value, set: v => { if (!v) selected.value = null } })

async function openLedger(s: SupplierRow) {
  selected.value = s
  tab.value = 'ledger'
  ledgerLoading.value = true
  ledger.value = await book.ledger(s)
  ledgerLoading.value = false
}
const typeMeta = {
  opening: { label: 'Opening', tone: 'neutral', icon: BookOpen },
  invoice: { label: 'Purchase', tone: 'info', icon: PackagePlus },
  payment: { label: 'Payment', tone: 'success', icon: HandCoins },
  return: { label: 'Return', tone: 'warning', icon: Undo2 },
  void: { label: 'Voided purchase', tone: 'neutral', icon: Ban },
} as const

// ---- Pay supplier ----
const payOpen = ref(false)
const pay = reactive({ amount: 0, method: 'cash', reference: '', paidAt: new Date().toISOString().slice(0, 10), note: '' })
const paying = ref(false)
let idemKey = ''
function startPay(s: SupplierRow) {
  selected.value ??= s
  Object.assign(pay, { amount: s.dueBalance, method: 'cash', reference: '', paidAt: new Date().toISOString().slice(0, 10), note: '' })
  idemKey = crypto.randomUUID()
  payOpen.value = true
}
const payError = computed(() => {
  const a = Number(pay.amount)
  if (!a || a <= 0) return 'Enter an amount above 0'
  if (selected.value && a > selected.value.dueBalance) return `More than the due of ${money(selected.value.dueBalance)}; record an advance separately`
  if (pay.method !== 'cash' && !pay.reference.trim()) return 'Reference is required for non-cash payments'
  return ''
})
/** Preview of the oldest-first allocation the API will apply. */
const allocation = computed(() => {
  let left = Number(pay.amount) || 0
  const open: { ref: string; date: string; amount: number }[] = []
  let running = 0
  for (const e of ledger.value) {
    running += e.increase - e.decrease
    if (e.type === 'invoice' || e.type === 'opening') open.push({ ref: e.ref, date: e.date, amount: e.increase })
  }
  // Settle invoices oldest-first, only up to what is still unpaid overall.
  let unpaid = Math.max(0, running)
  const outstanding = [...open].reverse().map((o) => { const amt = Math.min(o.amount, unpaid); unpaid -= amt; return { ...o, amount: amt } }).filter(o => o.amount > 0).reverse()
  return outstanding.map((o) => { const take = Math.min(o.amount, left); left -= take; return { ...o, take } }).filter(o => o.take > 0)
})
async function submitPay() {
  if (payError.value || !selected.value) return
  paying.value = true
  try {
    const r = await book.pay(selected.value.id, { amount: Number(pay.amount), method: pay.method, reference: pay.reference, paidAt: pay.paidAt, note: pay.note }, idemKey)
    const newDue = Math.round((selected.value.dueBalance - Number(pay.amount)) * 100) / 100
    await book.resource.save({ id: selected.value.id, dueBalance: newDue })
    selected.value.dueBalance = newDue
    ledger.value.push({ id: Date.now(), date: new Date().toISOString(), type: 'payment', ref: `${r.number} · ${pay.method}${pay.reference ? ` ${pay.reference}` : ''}`, increase: 0, decrease: Number(pay.amount), balance: newDue })
    toast.success('Supplier paid', `${r.number} · ${money(Number(pay.amount))}`)
    payOpen.value = false
    crud.value?.reload()
  }
  catch (e: any) { toast.error('Payment not recorded', e?.message) }
  finally { paying.value = false }
}
</script>

<template>
  <div>
    <UiCrudPage
      ref="crud"
      title="Suppliers" description="Distributors and depots. The balance is what we owe, from purchases, payments and returns."
      :resource="book.resource" :columns="columns" :fields="fields" noun="Supplier" create-permission="suppliers.manage" :deletable="false" :row-actions="supplierActions"
      :defaults="() => ({ name: '', companyName: '', phone: '', email: '', address: '', paymentTermsDays: 15, dueBalance: 0, purchases: 0, lastPurchase: null, isActive: true })"
      :item-label="r => r.name"
    >
      <template #cell-name="{ row }">
        <button class="flex items-center gap-3 text-left" @click="openLedger(row)">
          <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-50 to-brand-100 text-brand-700"><Truck class="size-4" /></span>
          <span><span class="block font-semibold text-ink hover:text-brand-700">{{ row.name }}</span><span class="text-xs text-slate-500">{{ row.companyName }} · {{ row.purchases }} purchases</span></span>
        </button>
      </template>
      <template #cell-phone="{ row }"><span class="text-slate-600 tabular">{{ row.phone }}</span></template>
      <template #cell-paymentTermsDays="{ row }"><span class="text-slate-500 tabular">{{ row.paymentTermsDays ? `${row.paymentTermsDays} days` : 'COD' }}</span></template>
      <template #cell-dueBalance="{ row }"><span class="tabular" :class="row.dueBalance ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ row.dueBalance ? money(row.dueBalance) : 'Settled' }}</span></template>

    </UiCrudPage>

    <UiDrawer v-model="drawer" width="xl">
      <template #header>
        <div v-if="selected" class="flex items-center gap-3">
          <span class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-700 text-white"><Truck class="size-5" /></span>
          <div>
            <h2 class="font-display text-lg font-bold">{{ selected.name }}</h2>
            <p class="text-sm text-slate-500">We owe <strong class="tabular" :class="selected.dueBalance ? 'text-amber-600' : 'text-emerald-600'">{{ money(selected.dueBalance) }}</strong></p>
          </div>
        </div>
      </template>
      <template v-if="selected">
        <UiTabs v-model="tab" :tabs="[{ value: 'ledger', label: 'Ledger', count: ledger.length }, { value: 'profile', label: 'Profile' }]" />
        <div v-if="tab === 'ledger'" class="mt-4">
          <div v-if="ledgerLoading" class="space-y-2"><div v-for="i in 6" :key="i" class="skeleton h-12" /></div>
          <div v-else class="overflow-x-auto rounded-2xl border border-slate-200">
            <table class="w-full min-w-[560px] text-sm">
              <thead><tr class="bg-slate-50"><th class="label h-9 px-3 text-left">Date</th><th class="label px-3 text-left">Entry</th><th class="label px-3 text-right">Owed +</th><th class="label px-3 text-right">Paid −</th><th class="label px-3 text-right">Balance</th></tr></thead>
              <tbody>
                <tr v-for="e in ledger" :key="e.id" data-stagger class="border-t border-slate-100">
                  <td class="px-3 py-2.5 text-slate-500">{{ date(e.date, { day: '2-digit', month: 'short', year: '2-digit' }) }}</td>
                  <td class="px-3 py-2.5"><UiBadge :tone="typeMeta[e.type].tone"><component :is="typeMeta[e.type].icon" class="size-3" />{{ typeMeta[e.type].label }}</UiBadge><p class="mt-1 truncate text-xs text-slate-500">{{ e.ref }}</p></td>
                  <td class="px-3 py-2.5 text-right tabular">{{ e.increase ? money(e.increase) : '' }}</td>
                  <td class="px-3 py-2.5 text-right text-emerald-700 tabular">{{ e.decrease ? money(e.decrease) : '' }}</td>
                  <td class="px-3 py-2.5 text-right font-semibold tabular">{{ money(e.balance) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div v-else class="mt-5 space-y-3 text-sm">
          <p class="flex items-center gap-2"><Phone class="size-4 text-slate-400" />{{ selected.phone }}</p>
          <p class="flex items-center gap-2"><Mail class="size-4 text-slate-400" />{{ selected.email || '—' }}</p>
          <p class="flex items-center gap-2"><MapPin class="size-4 text-slate-400" />{{ selected.address || '—' }}</p>
          <p class="text-slate-500">Payment terms: {{ selected.paymentTermsDays ? `${selected.paymentTermsDays} days` : 'cash on delivery' }} · Last purchase {{ date(selected.lastPurchase) }}</p>
        </div>
      </template>
      <template #footer>
        <NuxtLink to="/purchases/returns"><UiButton :icon="Undo2">Return goods</UiButton></NuxtLink>
        <UiButton v-if="selected && selected.dueBalance > 0" variant="primary" :icon="HandCoins" @click="startPay(selected!)">Pay supplier</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="payOpen" title="Pay supplier" :description="selected ? `${selected.name} · due ${money(selected.dueBalance)}` : ''" :dismissible="!paying">
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput v-model.number="pay.amount" type="number" label="Amount (৳)" :error="payError && Number(pay.amount) <= 0 ? payError : undefined" />
        <UiSelect v-model="pay.method" label="Method" :options="[{ value: 'cash', label: 'Cash' }, { value: 'bank', label: 'Bank transfer' }, { value: 'cheque', label: 'Cheque' }, { value: 'bkash', label: 'bKash' }]" />
        <UiInput v-model="pay.reference" label="Reference" placeholder="Txn / cheque no." />
        <UiInput v-model="pay.paidAt" type="date" label="Payment date" />
      </div>
      <p v-if="payError && Number(pay.amount) > 0" class="mt-3 text-sm font-medium text-rose-600">{{ payError }}</p>
      <div v-if="allocation.length" class="mt-4 rounded-2xl bg-slate-50 p-3">
        <p class="label mb-2">Allocated oldest first</p>
        <ul class="space-y-1 text-[13px]">
          <li v-for="a in allocation" :key="a.ref" class="flex justify-between gap-3"><span class="truncate text-slate-600">{{ date(a.date) }} · {{ a.ref }}</span><span class="font-semibold tabular">{{ money(a.take) }}</span></li>
        </ul>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="payOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="HandCoins" :disabled="!!payError" :loading="paying" @click="submitPay">Record payment</UiButton>
      </template>
    </UiModal>
  </div>
</template>
