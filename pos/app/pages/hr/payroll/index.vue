<script setup lang="ts">
import { Plus, Banknote, CheckCircle2, Printer, HandCoins, UserRound } from 'lucide-vue-next'
import type { RowAction } from '~/types/domain'
import type { Employee, PayrollRun, SalaryAdvance } from '~/types/hr'

definePageMeta({ title: 'Payroll' })
const api = usePayrollApi()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const { ask } = useConfirm()
const tab = ref<'runs' | 'advances'>(route.query.tab === 'advances' ? 'advances' : 'runs')
const runs = ref<PayrollRun[]>([])
const advances = ref<SalaryAdvance[]>([])
const loading = ref(true)
const list = ref<HTMLElement | null>(null)
const canPayroll = computed(() => auth.can('hr.payroll'))

async function load() {
  loading.value = true
  ;[runs.value, advances.value] = await Promise.all([api.runs(), api.advances()])
  loading.value = false
  await nextTick()
  revealStagger(list.value?.children ?? [], { y: 8, stagger: 0.05 })
}
onMounted(async () => {
  await load()
  // Deep link from Employees › Salary advance: /hr/payroll?tab=advances&employee=ID
  if (route.query.employee && canPayroll.value) startAdvance(Number(route.query.employee))
})

const tones = { draft: 'info', approved: 'warning', paid: 'success' } as const
const periodLabel = (p: string) => new Date(`${p}-01`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
const latestPaid = computed(() => runs.value.find(r => r.status === 'paid'))
const openAdvances = computed(() => advances.value.filter(a => a.status === 'open'))

const newOpen = ref(false)
const newPeriod = ref('')
const creating = ref(false)
function startNew() {
  const d = new Date()
  newPeriod.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
  newOpen.value = true
}
async function create() {
  creating.value = true
  try {
    const run = await api.create(newPeriod.value, crypto.randomUUID())
    toast.success('Payroll run created', `${periodLabel(run.period)} · ${run.employees} payslips`)
    newOpen.value = false
    await navigateTo(`/hr/payroll/${run.id}`)
  }
  catch (e: any) { toast.error('Run not created', e?.message) }
  finally { creating.value = false }
}

// ---- Run actions ----
async function approveRun(r: PayrollRun) {
  const { ok } = await ask({ title: `Approve ${periodLabel(r.period)} payroll?`, description: `${r.employees} payslips, net ${money(r.net)}. Payslips can no longer be edited after approval.`, confirmLabel: 'Approve', tone: 'primary' })
  if (!ok) return
  await api.approve(r.id)
  toast.success('Payroll approved', periodLabel(r.period))
  load()
}
async function deleteRun(r: PayrollRun) {
  const { ok } = await ask({ title: `Delete the ${periodLabel(r.period)} draft run?`, description: 'Draft payslips are discarded. Nothing was paid yet.', confirmLabel: 'Delete draft' })
  if (!ok) return
  try { await api.deleteRun(r.id); toast.success('Draft run deleted'); load() }
  catch (e: any) { toast.error('Could not delete', e?.message) }
}
function runActions(r: PayrollRun): RowAction[] {
  return [
    { label: 'Approve', icon: CheckCircle2, onClick: () => approveRun(r), hidden: !canPayroll.value || r.status !== 'draft' },
    { label: 'Pay salaries', icon: Banknote, to: `/hr/payroll/${r.id}?action=pay`, hidden: !canPayroll.value || r.status !== 'approved' },
    { label: 'Print payslips', icon: Printer, to: `/hr/payroll/${r.id}?action=print` },
  ]
}

// ---- Salary advances ----
const employees = ref<Employee[]>([])
const advOpen = ref(false)
const advForm = reactive({ employeeId: null as number | null, amount: 0, account: '' })
const payoutAccounts = ref<string[]>([])
const advSaving = ref(false)
let advKey = ''
async function startAdvance(employeeId?: number) {
  tab.value = 'advances'
  if (!employees.value.length) employees.value = (await useEmployeesResource().all()).filter(e => e.isActive)
  payoutAccounts.value = await api.accounts()
  Object.assign(advForm, { employeeId: employeeId ?? null, amount: 0, account: payoutAccounts.value[0] ?? '' })
  advKey = crypto.randomUUID()
  advOpen.value = true
}
async function giveAdvance() {
  const emp = employees.value.find(e => e.id === advForm.employeeId)
  if (!emp || !(advForm.amount > 0) || !advForm.account) return
  if (advForm.amount > emp.basicSalary) { toast.warning('Advance exceeds one month of basic salary'); return }
  advSaving.value = true
  try {
    await api.giveAdvance({ employeeId: emp.id, employee: emp.name, amount: Number(advForm.amount), account: advForm.account }, advKey)
    toast.success('Advance given', `${emp.name} · ${money(advForm.amount)}`)
    advOpen.value = false
    load()
  }
  catch (e: any) { toast.error('Advance not given', e?.message) }
  finally { advSaving.value = false }
}
const viewing = ref<SalaryAdvance | null>(null)
const viewOpen = ref(false)
const recoverOpen = ref(false)
const recoverTarget = ref<SalaryAdvance | null>(null)
const recoverAmount = ref(0)
function startRecover(a: SalaryAdvance) { recoverTarget.value = a; recoverAmount.value = a.amount - a.recovered; recoverOpen.value = true }
async function recover() {
  const a = recoverTarget.value
  if (!a || recoverAmount.value <= 0 || recoverAmount.value > a.amount - a.recovered) return
  await api.recordRecovery(a.id, Number(recoverAmount.value), crypto.randomUUID())
  toast.success('Repayment recorded', `${a.employee} · ${money(recoverAmount.value)}`)
  recoverOpen.value = false
  load()
}
async function voidAdvance(a: SalaryAdvance) {
  const { ok, reason } = await ask({ title: `Void the advance to ${a.employee}?`, description: `${money(a.amount)} given ${date(a.givenAt)}. The payment out is reversed.`, confirmLabel: 'Void advance', requireReason: true })
  if (!ok) return
  try { await api.voidAdvance(a.id, reason); toast.success('Advance voided'); load() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}
function advanceActions(a: SalaryAdvance): RowAction[] {
  return [
    { label: 'Record repayment', icon: HandCoins, onClick: () => startRecover(a), hidden: !canPayroll.value || a.status !== 'open' },
    { label: 'Employee', icon: UserRound, to: '/hr/employees' },
  ]
}
</script>

<template>
  <div>
    <UiPageHeader title="Payroll" description="Monthly runs built from basic salary, attendance and advance recovery. A paid run is final.">
      <UiButton v-if="canPayroll" :icon="HandCoins" @click="startAdvance()">Give advance</UiButton>
      <UiButton v-if="canPayroll" variant="primary" :icon="Plus" @click="startNew">New payroll run</UiButton>
    </UiPageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
      <UiStatCard label="Last net payout" :value="latestPaid?.net ?? 0" :hint="latestPaid ? periodLabel(latestPaid.period) : 'No paid run'" :icon="Banknote" :loading="loading" />
      <UiStatCard label="Employees on payroll" :value="runs[0]?.employees ?? 0" format="number" tone="sky" :loading="loading" />
      <UiStatCard label="Open salary advances" :value="openAdvances.reduce((s, a) => s + a.amount - a.recovered, 0)" :hint="`${openAdvances.length} employees`" tone="amber" :loading="loading" />
    </div>

    <div class="mb-4"><UiTabs v-model="tab" :tabs="[{ value: 'runs', label: 'Payroll runs', count: runs.length }, { value: 'advances', label: 'Salary advances', count: advances.length }]" /></div>

    <div v-if="loading" class="space-y-3"><div v-for="i in 4" :key="i" class="skeleton h-20 rounded-2xl" /></div>

    <div v-else-if="tab === 'runs'" ref="list" class="space-y-3">
      <div v-for="r in runs" :key="r.id" class="card flex flex-wrap items-center gap-4 p-4 transition-shadow hover:shadow-[var(--shadow-lift)]">
        <span class="grid size-12 place-items-center rounded-2xl bg-brand-50 font-display text-sm font-extrabold text-brand-700">{{ r.period.slice(5) }}</span>
        <div class="min-w-40 flex-1">
          <NuxtLink :to="`/hr/payroll/${r.id}`" class="font-display font-bold text-ink hover:text-brand-700 hover:underline">{{ periodLabel(r.period) }}</NuxtLink>
          <p class="text-xs text-slate-500">{{ r.employees }} employees<template v-if="r.paidAt"> · paid {{ date(r.paidAt) }} from {{ r.paidFrom }}</template></p>
        </div>
        <div class="text-right text-sm"><p class="label">Gross</p><p class="tabular">{{ money(r.gross) }}</p></div>
        <div class="text-right text-sm"><p class="label">Deductions</p><p class="text-rose-600 tabular">−{{ money(r.deductions) }}</p></div>
        <div class="text-right text-sm"><p class="label">Net</p><p class="font-display font-extrabold tabular">{{ money(r.net) }}</p></div>
        <UiBadge :tone="tones[r.status]" dot class="capitalize">{{ r.status }}</UiBadge>
        <UiRowActions
          :label="periodLabel(r.period)" :view="`/hr/payroll/${r.id}`"
          :edit="canPayroll && r.status === 'draft' ? `/hr/payroll/${r.id}` : undefined"
          :remove="canPayroll && r.status === 'draft' ? () => deleteRun(r) : undefined"
          :items="runActions(r)"
        />
      </div>
      <UiEmptyState v-if="!runs.length" title="No payroll runs" description="Create the first monthly run." />
    </div>

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
          <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Employee</th><th class="label px-4 text-left">Given</th><th class="label px-4 text-right">Amount</th><th class="label px-4 text-right">Recovered</th><th class="label px-4 text-right">Outstanding</th><th class="label px-4 text-left">Status</th><th class="label px-4 text-right">Actions</th></tr></thead>
          <tbody>
            <tr v-for="a in advances" :key="a.id" class="border-b border-slate-50 hover:bg-brand-50/40">
              <td class="px-4 py-3 font-semibold">{{ a.employee }}</td>
              <td class="px-4 py-3 text-slate-600">{{ date(a.givenAt) }}</td>
              <td class="px-4 py-3 text-right tabular">{{ money(a.amount) }}</td>
              <td class="px-4 py-3 text-right tabular">{{ money(a.recovered) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular" :class="a.amount - a.recovered ? 'text-amber-600' : 'text-slate-400'">{{ money(a.amount - a.recovered) }}</td>
              <td class="px-4 py-3"><UiBadge :tone="a.status === 'open' ? 'warning' : 'success'" dot class="capitalize">{{ a.status }}</UiBadge></td>
              <td class="px-3">
                <UiRowActions
                  :label="a.employee" :view="() => { viewing = a; viewOpen = true }" remove-kind="void"
                  :remove="canPayroll && a.recovered === 0 ? () => voidAdvance(a) : undefined"
                  :items="advanceActions(a)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">Advances are recovered automatically from the next payroll runs until settled.</p>
    </div>

    <UiModal v-model="newOpen" title="New payroll run" description="Payslips are generated from basic salary, attendance (absent days) and open advances." size="sm" :dismissible="!creating">
      <label class="block">
        <span class="mb-1.5 block text-[13px] font-medium text-slate-700">Period</span>
        <input v-model="newPeriod" type="month" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-brand-400 focus:outline-none">
      </label>
      <template #footer>
        <UiButton variant="ghost" @click="newOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="creating" :disabled="!newPeriod" @click="create">Create run</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="advOpen" title="Give salary advance" description="Paid out now and recovered from the next payroll runs." size="sm" :dismissible="!advSaving">
      <div class="space-y-4">
        <UiSelect v-model="advForm.employeeId" label="Employee" :options="employees.map(e => ({ value: e.id, label: `${e.name} · basic ${money(e.basicSalary)}` }))" placeholder="Choose employee" />
        <UiInput v-model.number="advForm.amount" type="number" label="Amount (৳)" />
        <UiSelect v-model="advForm.account" label="Paid from" :options="payoutAccounts.map(a => ({ value: a, label: a }))" />
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="advOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="advSaving" :disabled="!advForm.employeeId || !(advForm.amount > 0) || !advForm.account" @click="giveAdvance">Give advance</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="recoverOpen" :title="recoverTarget ? `Repayment · ${recoverTarget.employee}` : ''" description="Cash repaid outside payroll." size="sm">
      <UiInput v-model.number="recoverAmount" type="number" label="Amount (৳)" :hint="recoverTarget ? `Outstanding ${money(recoverTarget.amount - recoverTarget.recovered)}` : ''" />
      <template #footer>
        <UiButton variant="ghost" @click="recoverOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :disabled="!recoverTarget || recoverAmount <= 0 || recoverAmount > recoverTarget.amount - recoverTarget.recovered" @click="recover">Record repayment</UiButton>
      </template>
    </UiModal>

    <UiDrawer v-model="viewOpen" :title="viewing?.employee" description="Salary advance" width="md">
      <dl v-if="viewing" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Given', date(viewing.givenAt)], ['Amount', money(viewing.amount)], ['Recovered', money(viewing.recovered)], ['Outstanding', money(viewing.amount - viewing.recovered)], ['Status', viewing.status]]" :key="k" data-stagger class="flex justify-between gap-6 py-3"><dt class="text-slate-500">{{ k }}</dt><dd class="font-medium capitalize">{{ v }}</dd></div>
      </dl>
    </UiDrawer>
  </div>
</template>
