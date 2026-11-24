<script setup lang="ts">
import { gsap } from 'gsap'
import { Banknote, Landmark, Smartphone, Plus, FileText, Star, Printer, ArrowRightLeft, Power } from 'lucide-vue-next'
import type { Account, AccountType, StatementLine } from '~/types/finance'
import type { DateRange } from '~/types/domain'

definePageMeta({ title: 'Accounts' })
const api = useFinanceApi()
const auth = useAuthStore()
const toast = useToast()
const accounts = ref<Account[]>([])
const loading = ref(true)
const grid = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { accounts.value = await api.accounts() }
  catch (e: any) { toast.error('Could not load accounts', e?.message) }
  finally { loading.value = false }
  await nextTick()
  if (grid.value && !prefersReducedMotion()) gsap.fromTo(grid.value.children, { opacity: 0, y: 18 }, { opacity: 1, y: 0, stagger: 0.07, duration: 0.5, ease: 'power3.out' })
  grid.value?.querySelectorAll<HTMLElement>('[data-balance]').forEach((el) => countUp(el, Number(el.dataset.balance), money))
}
onMounted(load)

const icons: Record<AccountType, any> = { cash: Banknote, bank: Landmark, mobile_wallet: Smartphone }
const typeLabel: Record<AccountType, string> = { cash: 'Cash', bank: 'Bank', mobile_wallet: 'Mobile wallet' }
const gradients: Record<AccountType, string> = {
  cash: 'from-emerald-500/15 to-transparent text-emerald-700',
  bank: 'from-sky-500/15 to-transparent text-sky-700',
  mobile_wallet: 'from-pink-500/15 to-transparent text-pink-700',
}
const total = computed(() => accounts.value.filter(a => a.isActive).reduce((s, a) => s + a.balance, 0))
const byType = computed(() => (['cash', 'bank', 'mobile_wallet'] as AccountType[]).map(t => ({ t, sum: accounts.value.filter(a => a.type === t).reduce((s, a) => s + a.balance, 0) })))

// ---- Statement ----
const drawer = ref(false)
const current = ref<Account | null>(null)
const lines = ref<StatementLine[]>([])
const stLoading = ref(false)
const iso = (d: Date) => d.toISOString().slice(0, 10)
const range = ref<DateRange>({ from: iso(new Date(Date.now() - 29 * 864e5)), to: iso(new Date()) })
async function openStatement(a: Account) {
  current.value = a
  drawer.value = true
  await loadStatement()
}
async function loadStatement() {
  if (!current.value) return
  stLoading.value = true
  lines.value = await api.statement(current.value.id, range.value.from, range.value.to)
  stLoading.value = false
}
watch(range, () => drawer.value && loadStatement(), { deep: true })
const totIn = computed(() => lines.value.reduce((s, l) => s + l.inAmount, 0))
const totOut = computed(() => lines.value.reduce((s, l) => s + l.outAmount, 0))
function exportStatement() {
  downloadCsv(`statement-${current.value?.code}.csv`, [
    { key: 'date', label: 'Date' }, { key: 'description', label: 'Description' }, { key: 'ref', label: 'Reference' },
    { key: 'inAmount', label: 'In' }, { key: 'outAmount', label: 'Out' }, { key: 'balance', label: 'Balance' },
  ], lines.value)
}

// ---- Row actions ----
const { ask } = useConfirm()
const canManage = computed(() => auth.can('accounts.view'))
async function deactivate(a: Account) {
  const { ok } = await ask({ title: `Deactivate ${a.name}?`, description: 'It disappears from payment and transfer choices. Its history and statement stay available.', confirmLabel: 'Deactivate' })
  if (!ok) return
  try { await api.setAccountActive(a.id, false); toast.success('Account deactivated', a.name); load() }
  catch (e: any) { toast.error('Cannot deactivate', e?.message) }
}
async function activate(a: Account) {
  try { await api.setAccountActive(a.id, true); toast.success('Account activated', a.name); load() }
  catch (e: any) { toast.error('Could not activate', e?.message) }
}
async function makeDefault(a: Account) {
  try { await api.setDefaultAccount(a.id); toast.success('Default account changed', a.name); load() }
  catch (e: any) { toast.error('Could not change default', e?.message) }
}
function accountActions(a: Account) {
  return [
    { label: 'Statement', icon: FileText, onClick: () => openStatement(a) },
    { label: 'Transfer money', icon: ArrowRightLeft, to: `/finance/transfers?from=${a.id}`, hidden: !auth.can('accounts.transfer') || !a.isActive },
    { label: 'Set as default', icon: Star, onClick: () => makeDefault(a), hidden: !canManage.value || a.isDefault || !a.isActive },
    { label: 'Activate', icon: Power, onClick: () => activate(a), hidden: !canManage.value || a.isActive },
  ]
}

// ---- Create / edit ----
const formOpen = ref(false)
const form = ref<Partial<Account>>({})
const saving = ref(false)
function startForm(a?: Account) {
  form.value = a ? { ...a } : { name: '', code: '', type: 'bank', accountNo: '', openingBalance: 0 }
  formOpen.value = true
}
async function save() {
  const f = form.value
  if (!f.name?.trim() || !f.code?.trim()) { toast.warning('Name and code are required'); return }
  saving.value = true
  try {
    await api.saveAccount({ ...f, openingBalance: Number(f.openingBalance) || 0 })
    toast.success(f.id ? 'Account updated' : 'Account created', f.name)
    formOpen.value = false
    load()
  }
  catch (e: any) { toast.error('Could not save', e?.message) }
  finally { saving.value = false }
}
</script>

<template>
  <div>
    <UiPageHeader title="Accounts" description="Cash, bank and mobile-wallet accounts. Balances are calculated from posted transactions and cannot be edited.">
      <UiButton v-if="auth.can('accounts.view')" variant="primary" :icon="Plus" @click="startForm()">New account</UiButton>
    </UiPageHeader>

    <div class="mb-5 card flex flex-wrap items-center gap-6 p-5">
      <div class="min-w-48 flex-1">
        <p class="label">Total funds</p>
        <p class="font-display text-3xl font-extrabold tracking-tight tabular">{{ money(total) }}</p>
      </div>
      <div v-for="b in byType" :key="b.t" class="flex items-center gap-3">
        <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br" :class="gradients[b.t]"><component :is="icons[b.t]" class="size-5" /></span>
        <div><p class="text-xs text-slate-500">{{ typeLabel[b.t] }}</p><p class="font-semibold tabular">{{ money(b.sum) }}</p></div>
      </div>
    </div>

    <div v-if="loading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><div v-for="i in 5" :key="i" class="skeleton h-44 rounded-2xl" /></div>
    <div v-else ref="grid" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="a in accounts" :key="a.id" class="card group relative overflow-hidden p-5 transition-shadow hover:shadow-[var(--shadow-lift)]">
        <div class="pointer-events-none absolute -top-12 -right-12 size-36 rounded-full bg-gradient-to-br opacity-60 blur-2xl transition-transform duration-700 group-hover:scale-125" :class="gradients[a.type]" />
        <div class="relative flex items-start justify-between">
          <span class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br" :class="gradients[a.type]"><component :is="icons[a.type]" class="size-5" /></span>
          <div class="flex items-center gap-1">
            <UiBadge v-if="a.isDefault" tone="brand"><Star class="size-3" />Default</UiBadge>
            <UiBadge v-if="!a.isActive" tone="neutral">Inactive</UiBadge>
          </div>
        </div>
        <p class="relative mt-4 font-semibold text-ink">{{ a.name }}</p>
        <p class="relative text-xs text-slate-500">{{ typeLabel[a.type] }} · {{ a.code }} · {{ a.accountNo }}</p>
        <p class="relative mt-3 font-display text-2xl font-extrabold tracking-tight tabular" :data-balance="a.balance">{{ money(0) }}</p>
        <div class="relative mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
          <span class="label">Actions</span>
          <UiRowActions
            :label="a.name" :view="() => openStatement(a)"
            :edit="canManage ? () => startForm(a) : undefined"
            :remove="canManage && a.isActive && !a.isDefault ? () => deactivate(a) : undefined"
            :items="accountActions(a)"
          />
        </div>
      </div>
    </div>

    <UiDrawer v-model="drawer" :title="current?.name" :description="current ? `${typeLabel[current.type]} · ${current.accountNo}` : ''" width="xl">
      <div class="mb-4" data-stagger><UiDateRange v-model="range" /></div>
      <div class="mb-4 grid grid-cols-3 gap-3" data-stagger>
        <div class="rounded-2xl bg-emerald-50 p-3"><p class="label text-emerald-700">In</p><p class="font-semibold text-emerald-700 tabular">{{ money(totIn) }}</p></div>
        <div class="rounded-2xl bg-rose-50 p-3"><p class="label text-rose-700">Out</p><p class="font-semibold text-rose-700 tabular">{{ money(totOut) }}</p></div>
        <div class="rounded-2xl bg-slate-50 p-3"><p class="label">Closing</p><p class="font-semibold tabular">{{ money(lines[lines.length - 1]?.balance) }}</p></div>
      </div>
      <div v-if="stLoading" class="space-y-2"><div v-for="i in 8" :key="i" class="skeleton h-9" /></div>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-sm">
          <thead><tr class="border-b border-slate-100"><th class="label h-9 text-left">Date</th><th class="label text-left">Description</th><th class="label text-right">In</th><th class="label text-right">Out</th><th class="label text-right">Balance</th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in lines" :key="i" class="border-b border-slate-50" :class="i === 0 && 'bg-slate-50/70'">
              <td class="py-2 pr-2 text-xs whitespace-nowrap text-slate-500">{{ date(l.date) }}</td>
              <td class="py-2"><p class="font-medium">{{ l.description }}</p><p class="font-mono text-[11px] text-slate-400">{{ l.ref }}</p></td>
              <td class="py-2 text-right text-emerald-700 tabular">{{ l.inAmount ? money(l.inAmount) : '' }}</td>
              <td class="py-2 text-right text-rose-600 tabular">{{ l.outAmount ? money(l.outAmount) : '' }}</td>
              <td class="py-2 text-right font-semibold tabular">{{ money(l.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer>
        <UiButton :icon="Printer" @click="exportStatement">Export CSV</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="formOpen" :title="form.id ? 'Edit account' : 'New account'" :description="form.id ? 'The balance comes from transactions and cannot be changed here.' : undefined" :dismissible="!saving">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><UiInput v-model="form.name" label="Account name *" placeholder="e.g. Dutch-Bangla Bank" /></div>
        <UiInput v-model="form.code" label="Code *" placeholder="BANK-DBBL" />
        <UiSelect v-model="form.type" label="Type" :options="[{ value: 'cash', label: 'Cash' }, { value: 'bank', label: 'Bank' }, { value: 'mobile_wallet', label: 'Mobile wallet' }]" />
        <UiInput v-model="form.accountNo" label="Account / wallet no." />
        <UiInput v-model.number="form.openingBalance" type="number" label="Opening balance (৳)" :hint="form.id ? 'Locked after creation' : 'Posted once as the opening entry'" />
        <div v-if="form.id" class="sm:col-span-2"><UiSwitch v-model="form.isActive" label="Active" description="Inactive accounts cannot receive new transactions" /></div>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="formOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="saving" @click="save">{{ form.id ? 'Save changes' : 'Create account' }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
