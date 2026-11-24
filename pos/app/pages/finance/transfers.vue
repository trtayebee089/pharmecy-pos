<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowRightLeft, Search, ArrowRight, AlertTriangle, Download } from 'lucide-vue-next'
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
onMounted(load)
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
          <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Transfer</th><th class="label text-left">Route</th><th class="label text-right">Amount</th><th class="label text-left pl-6">Note</th><th class="label px-4 text-left">By</th></tr></thead>
          <tbody v-if="loading"><tr v-for="i in 6" :key="i"><td colspan="5" class="px-4 py-2"><div class="skeleton h-8" /></td></tr></tbody>
          <tbody v-else ref="tbody">
            <tr v-for="t in filtered" :key="t.id" class="border-b border-slate-50 hover:bg-brand-50/40">
              <td class="px-4 py-3"><p class="font-mono text-[12.5px] font-semibold">{{ t.number }}</p><p class="text-xs text-slate-500">{{ dateTime(t.date) }}</p></td>
              <td><span class="inline-flex items-center gap-2 text-[13px]"><span class="font-medium">{{ t.fromAccount }}</span><ArrowRight class="size-3.5 text-brand-600" /><span class="font-medium">{{ t.toAccount }}</span></span></td>
              <td class="text-right font-semibold tabular">{{ money(t.amount) }}</td>
              <td class="pl-6 text-slate-500">{{ t.note || '—' }}</td>
              <td class="px-4 text-slate-500">{{ t.createdBy }}</td>
            </tr>
          </tbody>
        </table>
        <UiEmptyState v-if="!loading && !filtered.length" :icon="ArrowRightLeft" title="No transfers found" />
      </div>
    </div>

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
