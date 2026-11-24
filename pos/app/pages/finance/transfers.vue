<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowRightLeft, Search, ArrowRight, AlertTriangle, Download, Printer, Undo2 } from 'lucide-vue-next'
import type { Account, MoneyTransfer } from '~/types/finance'

definePageMeta({ title: 'Money transfers' })
const api = useFinanceApi()
const auth = useAuthStore()
const toast = useToast()
const rows = ref<MoneyTransfer[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const q = ref('')
const tbody = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { [rows.value, accounts.value] = await Promise.all([api.transfers(), api.accounts()]) }
  catch (e: any) { toast.error('Could not load transfers', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(tbody.value?.children ?? [], { y: 6, stagger: 0.02 })
}
onMounted(async () => {
  await load()
  // Deep link from Accounts › Transfer money: /finance/transfers?from=ID
  const from = Number(useRoute().query.from)
  if (from && canTransfer.value) { start(); form.fromAccountId = from }
})
const filtered = computed(() => rows.value.filter(r => `${r.number} ${r.fromAccount} ${r.toAccount} ${r.note}`.toLowerCase().includes(q.value.toLowerCase())))
const monthTotal = computed(() => rows.value.filter(r => daysUntil(r.date) > -30).reduce((s, r) => s + r.amount, 0))

// ---- New transfer ----
const open = ref(false)
const form = reactive({ fromAccountId: null as number | null, toAccountId: null as number | null, amount: 0, date: new Date().toISOString().slice(0, 10), note: '' })
const posting = ref(false)
const arrow = ref<HTMLElement | null>(null)
let idemKey = ''
function start() {
  Object.assign(form, { fromAccountId: accounts.value.find(a => a.isDefault)?.id ?? null, toAccountId: null, amount: 0, date: new Date().toISOString().slice(0, 10), note: '' })
  idemKey = crypto.randomUUID()
  open.value = true
}
const from = computed(() => accounts.value.find(a => a.id === form.fromAccountId))
const to = computed(() => accounts.value.find(a => a.id === form.toAccountId))
const opts = computed(() => accounts.value.filter(a => a.isActive).map(a => ({ value: a.id, label: `${a.name} · ${money(a.balance)}` })))
const overBalance = computed(() => !!from.value && form.amount > from.value.balance)
const error = computed(() => {
  if (!form.fromAccountId || !form.toAccountId) return 'Choose both accounts'
  if (form.fromAccountId === form.toAccountId) return 'From and to must be different accounts'
  if (!form.amount || form.amount <= 0) return 'Enter an amount above 0'
  if (overBalance.value) return `Only ${money(from.value?.balance)} available in ${from.value?.name}`
  return ''
})
watch(() => [form.fromAccountId, form.toAccountId], () => {
  if (arrow.value && !prefersReducedMotion()) gsap.fromTo(arrow.value, { x: -8, opacity: 0.3 }, { x: 0, opacity: 1, duration: 0.4, ease: 'back.out(2)' })
})
function swap() { [form.fromAccountId, form.toAccountId] = [form.toAccountId, form.fromAccountId] }
async function post() {
  if (error.value) return
  posting.value = true
  try {
    const t = await api.transfer({ fromAccountId: form.fromAccountId!, toAccountId: form.toAccountId!, amount: Number(form.amount), date: form.date, note: form.note }, idemKey)
    toast.success('Transfer posted', `${t.number} · ${money(t.amount)}`)
    open.value = false
    load()
  }
  catch (e: any) { toast.error('Transfer failed', e?.message) }
  finally { posting.value = false }
}
// ---- Row actions ----
const { ask } = useConfirm()
const voided = api.voidedTransfers
const viewing = ref<MoneyTransfer | null>(null)
const viewOpen = ref(false)
function view(t: MoneyTransfer) { viewing.value = t; viewOpen.value = true }
async function voidTransfer(t: MoneyTransfer) {
  const { ok, reason } = await ask({ title: `Void ${t.number}?`, description: `${money(t.amount)} goes back from ${t.toAccount} to ${t.fromAccount}. The transfer stays on record as void.`, confirmLabel: 'Void transfer', requireReason: true })
  if (!ok) return
  try { await api.voidTransfer(t.id, reason, crypto.randomUUID()); toast.success('Transfer voided', t.number); load() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
function reverse(t: MoneyTransfer) {
  start()
  form.fromAccountId = accounts.value.find(a => a.name === t.toAccount)?.id ?? null
  form.toAccountId = accounts.value.find(a => a.name === t.fromAccount)?.id ?? null
  form.amount = t.amount
  form.note = `Reverse of ${t.number}`
}
function printVoucher(t: MoneyTransfer) {
  const w = window.open('', '_blank', 'width=640,height=640')
  if (!w) { toast.warning('Allow pop-ups to print the voucher'); return }
  w.document.write(`<html><head><title>${t.number}</title><style>body{font:14px system-ui;padding:32px}td{padding:8px;border-bottom:1px solid #e2e8f0}td:first-child{color:#64748b}</style></head><body><h2>Red Pharma · Money transfer</h2><p>${t.number}${voided.value[t.id] ? ' · VOID' : ''}</p><table style="width:100%;border-collapse:collapse"><tr><td>Date</td><td>${dateTime(t.date)}</td></tr><tr><td>From</td><td>${t.fromAccount}</td></tr><tr><td>To</td><td>${t.toAccount}</td></tr><tr><td>Amount</td><td><b>${money(t.amount)}</b></td></tr><tr><td>Note</td><td>${t.note || '—'}</td></tr><tr><td>By</td><td>${t.createdBy}</td></tr></table></body></html>`)
  w.document.close()
  w.print()
}
const canTransfer = computed(() => auth.can('accounts.transfer'))

const columns = [
  { key: 'number', label: 'Number' }, { key: 'date', label: 'Date' }, { key: 'fromAccount', label: 'From' },
  { key: 'toAccount', label: 'To' }, { key: 'amount', label: 'Amount' }, { key: 'note', label: 'Note' }, { key: 'createdBy', label: 'By' },
]
</script>

<template>
  <div>
    <UiPageHeader title="Money transfers" description="Moves between cash, bank and wallet accounts. Each transfer posts one out and one in transaction.">
      <UiButton :icon="Download" :disabled="!filtered.length" @click="downloadCsv('money-transfers.csv', columns, filtered)">Export</UiButton>
      <UiButton v-if="auth.can('accounts.transfer')" variant="primary" :icon="ArrowRightLeft" @click="start">New transfer</UiButton>
    </UiPageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
      <UiStatCard label="Transferred · last 30 days" :value="monthTotal" :icon="ArrowRightLeft" :loading="loading" />
      <UiStatCard label="Transfers" :value="rows.length" format="number" :icon="ArrowRight" tone="sky" :loading="loading" />
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-slate-100 px-4 py-3"><div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Number, account, note…" /></div></div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
          <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Transfer</th><th class="label text-left">Route</th><th class="label text-right">Amount</th><th class="label text-left pl-6">Note</th><th class="label px-4 text-left">By</th><th class="label px-4 text-right">Actions</th></tr></thead>
          <tbody v-if="loading"><tr v-for="i in 6" :key="i"><td colspan="6" class="px-4 py-2"><div class="skeleton h-8" /></td></tr></tbody>
          <tbody v-else ref="tbody">
            <tr v-for="t in filtered" :key="t.id" class="border-b border-slate-50 hover:bg-brand-50/40">
              <td class="px-4 py-3"><p class="flex items-center gap-2 font-mono text-[12.5px] font-semibold" :class="voided[t.id] && 'text-slate-400 line-through'">{{ t.number }}<UiBadge v-if="voided[t.id]" tone="neutral">Void</UiBadge></p><p class="text-xs text-slate-500">{{ dateTime(t.date) }}</p></td>
              <td><span class="inline-flex items-center gap-2 text-[13px]"><span class="font-medium">{{ t.fromAccount }}</span><ArrowRight class="size-3.5 text-brand-600" /><span class="font-medium">{{ t.toAccount }}</span></span></td>
              <td class="text-right font-semibold tabular">{{ money(t.amount) }}</td>
              <td class="pl-6 text-slate-500">{{ t.note || '—' }}</td>
              <td class="px-4 text-slate-500">{{ t.createdBy }}</td>
              <td class="px-3">
                <UiRowActions
                  :label="t.number" :view="() => view(t)" remove-kind="void"
                  :remove="!voided[t.id] && canTransfer ? () => voidTransfer(t) : undefined"
                  :items="[{ label: 'Print voucher', icon: Printer, onClick: () => printVoucher(t) }, { label: 'Reverse transfer', icon: Undo2, onClick: () => reverse(t), hidden: !canTransfer || !!voided[t.id] }]"
                />
              </td>
            </tr>
          </tbody>
        </table>
        <UiEmptyState v-if="!loading && !filtered.length" :icon="ArrowRightLeft" title="No transfers found" />
      </div>
    </div>

    <UiDrawer v-model="viewOpen" :title="viewing?.number" description="Money transfer" width="md">
      <dl v-if="viewing" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Date', dateTime(viewing.date)], ['From', viewing.fromAccount], ['To', viewing.toAccount], ['Amount', money(viewing.amount)], ['Note', viewing.note || '—'], ['Posted by', viewing.createdBy], ['Status', voided[viewing.id] ? `Void · ${voided[viewing.id]}` : 'Posted']]" :key="k" data-stagger class="flex justify-between gap-6 py-3">
          <dt class="text-slate-500">{{ k }}</dt><dd class="text-right font-medium">{{ v }}</dd>
        </div>
      </dl>
      <template #footer>
        <UiButton :icon="Printer" @click="viewing && printVoucher(viewing)">Print voucher</UiButton>
        <UiButton variant="ghost" @click="viewOpen = false">Close</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="open" title="New money transfer" description="Posted immediately; correct a mistake with a reverse transfer." :dismissible="!posting">
      <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
        <UiSelect v-model="form.fromAccountId" label="From" :options="opts" placeholder="Choose account" />
        <button ref="arrow" type="button" class="mb-1 grid size-9 place-items-center justify-self-center rounded-full bg-brand-50 text-brand-700 transition hover:bg-brand-100" aria-label="Swap accounts" @click="swap"><ArrowRightLeft class="size-4" /></button>
        <UiSelect v-model="form.toAccountId" label="To" :options="opts" placeholder="Choose account" />
      </div>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <UiInput v-model.number="form.amount" type="number" label="Amount (৳)" inputmode="decimal" :error="overBalance ? 'More than the available balance' : undefined" />
        <UiInput v-model="form.date" type="date" label="Date" />
        <div class="sm:col-span-2"><UiInput v-model="form.note" label="Note" placeholder="e.g. Daily deposit" /></div>
      </div>
      <div v-if="from && to && form.amount > 0 && !error" class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-xl bg-rose-50 p-3"><p class="label text-rose-700">{{ from.name }}</p><p class="tabular">{{ money(from.balance) }} → <strong>{{ money(from.balance - form.amount) }}</strong></p></div>
        <div class="rounded-xl bg-emerald-50 p-3"><p class="label text-emerald-700">{{ to.name }}</p><p class="tabular">{{ money(to.balance) }} → <strong>{{ money(to.balance + form.amount) }}</strong></p></div>
      </div>
      <p v-if="error && (form.amount || (form.fromAccountId && form.toAccountId))" class="mt-3 flex items-center gap-1.5 text-xs font-medium text-amber-700"><AlertTriangle class="size-3.5" />{{ error }}</p>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="posting" :disabled="!!error" @click="post">Post transfer</UiButton>
      </template>
    </UiModal>
  </div>
</template>
