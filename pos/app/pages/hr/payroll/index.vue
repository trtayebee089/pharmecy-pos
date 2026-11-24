<script setup lang="ts">
import { Plus, ArrowRight, Banknote } from 'lucide-vue-next'
import type { PayrollRun, SalaryAdvance } from '~/types/hr'

definePageMeta({ title: 'Payroll' })
const api = usePayrollApi()
const toast = useToast()
const tab = ref<'runs' | 'advances'>('runs')
const runs = ref<PayrollRun[]>([])
const advances = ref<SalaryAdvance[]>([])
const loading = ref(true)
const list = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  ;[runs.value, advances.value] = await Promise.all([api.runs(), api.advances()])
  loading.value = false
  await nextTick()
  revealStagger(list.value?.children ?? [], { y: 8, stagger: 0.05 })
}
onMounted(load)

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
</script>

<template>
  <div>
    <UiPageHeader title="Payroll" description="Monthly runs built from basic salary, attendance and advance recovery. A paid run is final.">
      <UiButton variant="primary" :icon="Plus" @click="startNew">New payroll run</UiButton>
    </UiPageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
      <UiStatCard label="Last net payout" :value="latestPaid?.net ?? 0" :hint="latestPaid ? periodLabel(latestPaid.period) : 'No paid run'" :icon="Banknote" :loading="loading" />
      <UiStatCard label="Employees on payroll" :value="runs[0]?.employees ?? 0" format="number" tone="sky" :loading="loading" />
      <UiStatCard label="Open salary advances" :value="openAdvances.reduce((s, a) => s + a.amount - a.recovered, 0)" :hint="`${openAdvances.length} employees`" tone="amber" :loading="loading" />
    </div>

    <div class="mb-4"><UiTabs v-model="tab" :tabs="[{ value: 'runs', label: 'Payroll runs', count: runs.length }, { value: 'advances', label: 'Salary advances', count: advances.length }]" /></div>

    <div v-if="loading" class="space-y-3"><div v-for="i in 4" :key="i" class="skeleton h-20 rounded-2xl" /></div>

    <div v-else-if="tab === 'runs'" ref="list" class="space-y-3">
      <NuxtLink v-for="r in runs" :key="r.id" :to="`/hr/payroll/${r.id}`" class="card group flex flex-wrap items-center gap-4 p-4 transition-shadow hover:shadow-[var(--shadow-lift)]">
        <span class="grid size-12 place-items-center rounded-2xl bg-brand-50 font-display text-sm font-extrabold text-brand-700">{{ r.period.slice(5) }}</span>
        <div class="min-w-40 flex-1">
          <p class="font-display font-bold text-ink">{{ periodLabel(r.period) }}</p>
          <p class="text-xs text-slate-500">{{ r.employees }} employees<template v-if="r.paidAt"> · paid {{ date(r.paidAt) }} from {{ r.paidFrom }}</template></p>
        </div>
        <div class="text-right text-sm"><p class="label">Gross</p><p class="tabular">{{ money(r.gross) }}</p></div>
        <div class="text-right text-sm"><p class="label">Deductions</p><p class="text-rose-600 tabular">−{{ money(r.deductions) }}</p></div>
        <div class="text-right text-sm"><p class="label">Net</p><p class="font-display font-extrabold tabular">{{ money(r.net) }}</p></div>
        <UiBadge :tone="tones[r.status]" dot class="capitalize">{{ r.status }}</UiBadge>
        <ArrowRight class="size-4 text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600" />
      </NuxtLink>
      <UiEmptyState v-if="!runs.length" title="No payroll runs" description="Create the first monthly run." />
    </div>

    <div v-else class="card overflow-hidden">
      <table class="w-full text-sm">
        <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Employee</th><th class="label px-4 text-left">Given</th><th class="label px-4 text-right">Amount</th><th class="label px-4 text-right">Recovered</th><th class="label px-4 text-right">Outstanding</th><th class="label px-4 text-left">Status</th></tr></thead>
        <tbody>
          <tr v-for="a in advances" :key="a.id" class="border-b border-slate-50">
            <td class="px-4 py-3 font-semibold">{{ a.employee }}</td>
            <td class="px-4 py-3 text-slate-600">{{ date(a.givenAt) }}</td>
            <td class="px-4 py-3 text-right tabular">{{ money(a.amount) }}</td>
            <td class="px-4 py-3 text-right tabular">{{ money(a.recovered) }}</td>
            <td class="px-4 py-3 text-right font-semibold tabular" :class="a.amount - a.recovered ? 'text-amber-600' : 'text-slate-400'">{{ money(a.amount - a.recovered) }}</td>
            <td class="px-4 py-3"><UiBadge :tone="a.status === 'open' ? 'warning' : 'success'" dot class="capitalize">{{ a.status }}</UiBadge></td>
          </tr>
        </tbody>
      </table>
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
  </div>
</template>
