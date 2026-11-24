<script setup lang="ts">
import { HandCoins, Search, Clock, Receipt, CheckCircle2, UserRound, Wand2, FileText, MessageSquare } from 'lucide-vue-next'
import type { DueCustomer, OpenInvoice } from '~/types/people'

definePageMeta({ title: 'Due collection' })
const api = usePeopleApi()
const auth = useAuthStore()
const toast = useToast()

const rows = ref<DueCustomer[]>([])
const loading = ref(true)
const q = ref('')
const aging = ref<'all' | '30' | '60'>('all')
const list = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { rows.value = await api.dueCustomers() }
  catch (e: any) { toast.error('Could not load dues', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(list.value?.children ?? [], { y: 8, stagger: 0.03 })
}
onMounted(load)

const filtered = computed(() => rows.value.filter(r =>
  `${r.name} ${r.phone}`.toLowerCase().includes(q.value.toLowerCase())
  && (aging.value === 'all' || r.oldestDays > Number(aging.value))))
const totalDue = computed(() => rows.value.reduce((s, r) => s + r.dueBalance, 0))
const over60 = computed(() => rows.value.filter(r => r.oldestDays > 60).reduce((s, r) => s + r.dueBalance, 0))
const ageTone = (d: number) => d > 60 ? 'danger' : d > 30 ? 'warning' : 'neutral'

// ---- Customer drawer with open invoices ----
const selected = ref<DueCustomer | null>(null)
const drawer = ref(false)
const invoices = ref<OpenInvoice[]>([])
const invLoading = ref(false)
async function openCustomer(c: DueCustomer) {
  selected.value = c
  drawer.value = true
  invLoading.value = true
  invoices.value = await api.openInvoices(c.id)
  invLoading.value = false
}

// ---- Row actions ----
async function collectFor(c: DueCustomer) { await openCustomer(c); startCollect() }
async function remind(c: DueCustomer) {
  try {
    await api.sendDueReminder(c.id, crypto.randomUUID())
    toast.success('Reminder SMS queued', `${c.name} · due ${money(c.dueBalance)}`)
  }
  catch (e: any) { toast.error('SMS not sent', e?.message) }
}
function dueActions(c: DueCustomer) {
  return [
    { label: 'Collect payment', icon: HandCoins, onClick: () => collectFor(c), hidden: !auth.can('payments.collect') },
    { label: 'Statement', icon: FileText, to: `/reports/customer?customer=${c.id}` },
    { label: 'Send SMS reminder', icon: MessageSquare, onClick: () => remind(c), disabled: !c.phone },
  ]
}

// ---- Collect payment ----
const payOpen = ref(false)
const amount = ref(0)
const method = ref('cash')
const reference = ref('')
const alloc = ref<Record<number, number>>({})
const posting = ref(false)
const receipt = ref<string | null>(null)
const allocatedEl = ref<HTMLElement | null>(null)
let idemKey = ''

function autoAllocate() {
  let rest = Number(amount.value) || 0
  const next: Record<number, number> = {}
  for (const inv of invoices.value) { // oldest first
    const take = Math.min(inv.due, Math.max(0, rest))
    next[inv.saleId] = Math.round(take * 100) / 100
    rest = Math.round((rest - take) * 100) / 100
  }
  alloc.value = next
}
function startCollect() {
  amount.value = selected.value?.dueBalance ?? 0
  method.value = 'cash'
  reference.value = ''
  receipt.value = null
  idemKey = crypto.randomUUID()
  autoAllocate()
  payOpen.value = true
}
watch(amount, autoAllocate)
const allocated = computed(() => Object.values(alloc.value).reduce((s, v) => s + (Number(v) || 0), 0))
const unallocated = computed(() => Math.round(((Number(amount.value) || 0) - allocated.value) * 100) / 100)
watch(allocated, () => pop(allocatedEl.value))
const invalid = computed(() => {
  if (!amount.value || amount.value <= 0) return 'Enter an amount'
  if (amount.value > (selected.value?.dueBalance ?? 0)) return 'Amount is more than the total due'
  if (invoices.value.some(i => (alloc.value[i.saleId] ?? 0) > i.due + 0.001)) return 'An allocation exceeds its invoice due'
  if (Math.abs(unallocated.value) > 0.009) return 'Allocate the full amount to invoices'
  if (method.value !== 'cash' && !reference.value.trim()) return 'Reference / transaction ID is required for non-cash'
  return ''
})

async function post() {
  if (invalid.value || !selected.value) return
  posting.value = true
  try {
    const res = await api.collect(selected.value.id, {
      amount: Number(amount.value), method: method.value, reference: reference.value,
      allocations: Object.entries(alloc.value).filter(([, v]) => v > 0).map(([id, v]) => ({ saleId: Number(id), amount: Number(v) })),
    }, idemKey)
    receipt.value = res.receiptNo
    const paid = Number(amount.value)
    selected.value.dueBalance = Math.round((selected.value.dueBalance - paid) * 100) / 100
    selected.value.lastPaymentAt = new Date().toISOString()
    for (const inv of invoices.value) { const a = alloc.value[inv.saleId] ?? 0; inv.paid += a; inv.due = Math.round((inv.due - a) * 100) / 100 }
    invoices.value = invoices.value.filter(i => i.due > 0)
    selected.value.openInvoices = invoices.value.length
    if (selected.value.dueBalance <= 0) rows.value = rows.value.filter(r => r.id !== selected.value!.id)
    toast.success('Payment received', `${res.receiptNo} · ${money(paid)}`)
  }
  catch (e: any) { toast.error('Payment not posted', e?.message) }
  finally { posting.value = false }
}
const methods = [
  { value: 'cash', label: 'Cash' }, { value: 'bkash', label: 'bKash' }, { value: 'nagad', label: 'Nagad' },
  { value: 'card', label: 'Card' }, { value: 'bank', label: 'Bank transfer' },
]
</script>

<template>
  <div>
    <UiPageHeader title="Due collection" description="Customers who owe money. Payments are allocated to invoices oldest-first and posted to the customer ledger." />

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
      <UiStatCard label="Total receivable" :value="totalDue" :icon="HandCoins" tone="amber" :loading="loading" />
      <UiStatCard label="Customers with due" :value="rows.length" format="number" :icon="UserRound" tone="sky" :loading="loading" />
      <UiStatCard label="Older than 60 days" :value="over60" :icon="Clock" tone="rose" :loading="loading" />
    </div>

    <div class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
        <div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Name or phone…" /></div>
        <UiSegmented v-model="aging" :options="[{ value: 'all', label: 'All' }, { value: '30', label: '> 30 days' }, { value: '60', label: '> 60 days' }]" />
      </div>
      <div v-if="loading" class="space-y-2 p-4"><div v-for="i in 6" :key="i" class="skeleton h-14" /></div>
      <ul v-else ref="list" class="divide-y divide-slate-100">
        <li v-for="c in filtered" :key="c.id" class="flex flex-wrap items-center gap-2 pr-4 transition-colors hover:bg-brand-50/40">
          <button class="group flex min-w-0 flex-1 flex-wrap items-center gap-4 px-5 py-3.5 text-left" @click="openCustomer(c)">
            <span class="grid size-10 place-items-center rounded-xl bg-amber-50 text-sm font-bold text-amber-700">{{ initials(c.name) }}</span>
            <span class="min-w-40 flex-1">
              <span class="block text-sm font-semibold text-ink">{{ c.name }}</span>
              <span class="block text-xs text-slate-500 tabular">{{ c.phone ?? 'No phone' }} · {{ c.openInvoices }} open invoice{{ c.openInvoices === 1 ? '' : 's' }}</span>
            </span>
            <UiBadge :tone="ageTone(c.oldestDays)" dot>Oldest {{ c.oldestDays }}d</UiBadge>
            <span class="w-36 text-xs text-slate-500">{{ c.lastPaymentAt ? `Paid ${date(c.lastPaymentAt)}` : 'No payment yet' }}</span>
            <span class="w-28 text-right font-display text-[15px] font-extrabold text-amber-700 tabular">{{ money(c.dueBalance) }}</span>
          </button>
          <UiRowActions :label="c.name" :view="() => openCustomer(c)" :items="dueActions(c)" />
        </li>
      </ul>
      <UiEmptyState v-if="!loading && !filtered.length" :icon="CheckCircle2" title="No dues here" description="Every customer in this view is fully paid." />
    </div>

    <UiDrawer v-model="drawer" :title="selected?.name" :description="selected ? `${selected.phone ?? 'No phone'} · due ${money(selected.dueBalance)}` : ''">
      <div v-if="invLoading" class="space-y-2"><div v-for="i in 3" :key="i" class="skeleton h-16" /></div>
      <template v-else>
        <p class="label mb-2" data-stagger>Open invoices (oldest first)</p>
        <div v-for="inv in invoices" :key="inv.saleId" data-stagger class="mb-2 flex items-center gap-3 rounded-2xl border border-slate-200 p-3">
          <span class="grid size-9 place-items-center rounded-xl bg-slate-100 text-slate-500"><Receipt class="size-4" /></span>
          <div class="min-w-0 flex-1">
            <NuxtLink :to="`/sales/${inv.saleId}`" class="font-mono text-[12.5px] font-semibold hover:text-brand-700 hover:underline">{{ inv.number }}</NuxtLink>
            <p class="text-xs text-slate-500">{{ date(inv.date) }} · total {{ money(inv.total) }} · paid {{ money(inv.paid) }}</p>
          </div>
          <span class="font-semibold text-amber-700 tabular">{{ money(inv.due) }}</span>
        </div>
        <UiEmptyState v-if="!invoices.length" :icon="CheckCircle2" title="All invoices settled" />
        <div v-if="selected" class="mt-4 rounded-2xl bg-slate-50 p-4" data-stagger>
          <PeopleCustomerDrawerLink :customer-id="selected.id" />
        </div>
      </template>
      <template #footer>
        <UiButton variant="primary" :icon="HandCoins" :disabled="!invoices.length || !auth.can('payments.collect')" magnetic @click="startCollect">Collect payment</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="payOpen" :title="receipt ? undefined : 'Collect payment'" :description="receipt ? undefined : selected?.name" size="lg" :dismissible="!posting">
      <div v-if="receipt" class="py-6 text-center">
        <div class="mx-auto grid size-16 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 text-white shadow-[var(--shadow-glow)]"><CheckCircle2 class="size-8" /></div>
        <h3 class="mt-4 font-display text-xl font-extrabold">Payment received</h3>
        <p class="mt-1 font-mono text-sm text-slate-500">{{ receipt }}</p>
        <p class="mt-3 text-sm text-slate-600">Remaining due: <strong class="tabular">{{ money(selected?.dueBalance) }}</strong></p>
        <div class="mt-6"><UiButton variant="primary" @click="payOpen = false">Done</UiButton></div>
      </div>
      <div v-else class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <div class="space-y-4">
          <UiInput v-model.number="amount" type="number" label="Amount received (৳)" inputmode="decimal" size="lg" />
          <button type="button" class="-mt-2 text-xs font-semibold text-brand-700 hover:underline" @click="amount = selected?.dueBalance ?? 0">Full due {{ money(selected?.dueBalance) }}</button>
          <UiSelect v-model="method" label="Method" :options="methods" />
          <UiInput v-if="method !== 'cash'" v-model="reference" label="Reference / Txn ID" placeholder="e.g. 9F7K2L1Q" />
        </div>
        <div>
          <div class="mb-2 flex items-center justify-between">
            <p class="label">Allocation</p>
            <button type="button" class="flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline" @click="autoAllocate"><Wand2 class="size-3.5" />Oldest first</button>
          </div>
          <div v-for="inv in invoices" :key="inv.saleId" class="mb-2 flex items-center gap-2 rounded-xl border border-slate-200 p-2.5">
            <div class="min-w-0 flex-1">
              <p class="truncate font-mono text-[12px] font-semibold">{{ inv.number }}</p>
              <p class="text-[11px] text-slate-500">due {{ money(inv.due) }} · {{ date(inv.date) }}</p>
            </div>
            <input v-model.number="alloc[inv.saleId]" type="number" min="0" :max="inv.due" step="0.01" class="h-9 w-28 rounded-lg border px-2 text-right text-sm font-semibold tabular focus:outline-none" :class="(alloc[inv.saleId] ?? 0) > inv.due ? 'border-rose-300 bg-rose-50' : 'border-slate-200 focus:border-brand-400'" :aria-label="`Allocate to ${inv.number}`">
          </div>
          <div class="mt-3 flex justify-between rounded-xl bg-slate-50 px-3 py-2 text-sm">
            <span class="text-slate-500">Allocated</span><span ref="allocatedEl" class="font-semibold tabular">{{ money(allocated) }}</span>
          </div>
          <p v-if="unallocated" class="mt-1 text-right text-xs font-semibold tabular" :class="unallocated > 0 ? 'text-amber-600' : 'text-rose-600'">{{ unallocated > 0 ? 'Unallocated' : 'Over-allocated' }} {{ money(Math.abs(unallocated)) }}</p>
        </div>
      </div>
      <template v-if="!receipt" #footer>
        <span v-if="invalid" class="mr-auto text-xs font-medium text-rose-600">{{ invalid }}</span>
        <UiButton variant="ghost" :disabled="posting" @click="payOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="HandCoins" :loading="posting" :disabled="!!invalid" @click="post">Post payment {{ amount ? money(amount) : '' }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
