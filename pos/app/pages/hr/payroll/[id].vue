<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowLeft, CheckCircle2, Banknote, FileText, Printer, Lock } from 'lucide-vue-next'
import type { PayrollRun, Payslip } from '~/types/hr'

definePageMeta({ title: 'Payroll run' })
const route = useRoute()
const api = usePayrollApi()
const toast = useToast()
const { ask } = useConfirm()

const run = ref<PayrollRun | null>(null)
const slips = ref<Payslip[]>([])
const loading = ref(true)
const missing = ref(false)
const root = ref<HTMLElement | null>(null)
const stepper = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  const r = await api.run(Number(route.params.id))
  loading.value = false
  if (!r) { missing.value = true; return }
  run.value = r.run
  slips.value = r.payslips
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal], tbody tr') ?? [], { y: 8, stagger: 0.025 })
  animateStepper()
}
onMounted(load)

const periodLabel = computed(() => run.value ? new Date(`${run.value.period}-01`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '')
const steps = ['draft', 'approved', 'paid'] as const
const stepIndex = computed(() => run.value ? steps.indexOf(run.value.status) : 0)
function animateStepper() {
  const bar = stepper.value?.querySelector<HTMLElement>('[data-progress]')
  if (!bar) return
  const w = `${(stepIndex.value / (steps.length - 1)) * 100}%`
  prefersReducedMotion() ? gsap.set(bar, { width: w }) : gsap.to(bar, { width: w, duration: 0.8, ease: 'power3.inOut' })
}
watch(stepIndex, () => nextTick(animateStepper))

const totals = computed(() => ({
  basic: slips.value.reduce((s, p) => s + p.basic, 0),
  additions: slips.value.reduce((s, p) => s + p.additions, 0),
  deductions: slips.value.reduce((s, p) => s + p.deductions, 0),
  advanceRecovery: slips.value.reduce((s, p) => s + p.advanceRecovery, 0),
  net: slips.value.reduce((s, p) => s + p.net, 0),
}))

const busy = ref(false)
async function approve() {
  if (!run.value) return
  const { ok } = await ask({ title: `Approve ${periodLabel.value} payroll?`, description: `${slips.value.length} payslips, net ${money(totals.value.net)}. After approval the payslips can no longer be edited.`, confirmLabel: 'Approve', tone: 'primary' })
  if (!ok) return
  busy.value = true
  await api.approve(run.value.id)
  run.value.status = 'approved'
  busy.value = false
  toast.success('Payroll approved', 'It can now be paid.')
}

const payOpen = ref(false)
const accounts = ref<string[]>([])
const account = ref('')
let idemKey = ''
async function startPay() {
  accounts.value = await api.accounts()
  account.value = accounts.value[1] ?? accounts.value[0] ?? ''
  idemKey = crypto.randomUUID()
  payOpen.value = true
}
async function pay() {
  if (!run.value || !account.value) return
  busy.value = true
  try {
    await api.pay(run.value.id, account.value, idemKey)
    Object.assign(run.value, { status: 'paid', paidAt: new Date().toISOString().slice(0, 10), paidFrom: account.value })
    payOpen.value = false
    toast.success('Salaries paid', `${money(totals.value.net)} from ${account.value}`)
  }
  catch (e: any) { toast.error('Payment failed', e?.message) }
  finally { busy.value = false }
}

const slipOpen = ref(false)
const slip = ref<Payslip | null>(null)
function openSlip(p: Payslip) { slip.value = p; slipOpen.value = true }
function printSlip() {
  if (!slip.value || !run.value) return
  const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c]!)
  const p = { ...slip.value, name: esc(slip.value.name), designation: esc(slip.value.designation) }
  const w = window.open('', '_blank', 'width=480,height=640')
  if (!w) return
  const row = (k: string, v: string) => `<tr><td>${k}</td><td style="text-align:right">${v}</td></tr>`
  w.document.write(`<!doctype html><html><head><title>Payslip ${p.name} ${run.value.period}</title><style>body{font:13px/1.5 system-ui,sans-serif;padding:24px;color:#0f1b2d}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px}td{padding:6px 0;border-bottom:1px solid #e2e8f0}.net td{font-weight:700;font-size:15px;border:0;padding-top:12px}</style></head><body>
    <h1>Red Pharma · Payslip</h1><p>${periodLabel.value}<br>${p.name} · ${p.designation}</p>
    <table>${row('Basic salary', money(p.basic))}${row('Additions', money(p.additions))}${row(`Absence deduction (${p.workingDays - p.presentDays} days)`, `−${money(p.deductions)}`)}${row('Advance recovery', `−${money(p.advanceRecovery)}`)}<tr class="net"><td>Net pay</td><td style="text-align:right">${money(p.net)}</td></tr></table>
    <p style="margin-top:32px;color:#64748b">Status: ${run.value.status}${run.value.paidAt ? ` · paid ${date(run.value.paidAt)}` : ''}</p></body></html>`)
  w.document.close()
  w.focus()
  w.print()
}
</script>

<template>
  <div ref="root" class="mx-auto max-w-6xl">
    <div v-if="loading" class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-24" /><div class="skeleton h-80" /></div>
    <UiEmptyState v-else-if="missing" title="Payroll run not found" description="It may have been removed." />
    <template v-else-if="run">
      <div class="mb-6 flex flex-wrap items-center justify-between gap-4" data-reveal>
        <div class="flex items-center gap-3">
          <NuxtLink to="/hr/payroll"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back to payroll" /></NuxtLink>
          <div>
            <h1 class="font-display text-[26px] font-extrabold tracking-tight text-ink">{{ periodLabel }} payroll</h1>
            <p class="text-sm text-slate-500">{{ slips.length }} payslips<template v-if="run.paidAt"> · paid {{ date(run.paidAt) }} from {{ run.paidFrom }}</template></p>
          </div>
        </div>
        <div class="flex gap-2">
          <UiButton v-if="run.status === 'draft'" variant="primary" :icon="CheckCircle2" :loading="busy" @click="approve">Approve</UiButton>
          <UiButton v-else-if="run.status === 'approved'" variant="primary" :icon="Banknote" :loading="busy" magnetic @click="startPay">Pay salaries</UiButton>
          <UiBadge v-else tone="success" size="md"><Lock class="size-3" />Paid · final</UiBadge>
        </div>
      </div>

      <div ref="stepper" class="card mb-5 p-5" data-reveal>
        <div class="relative mx-4 mb-2 h-1 rounded-full bg-slate-100">
          <div data-progress class="absolute inset-y-0 left-0 w-0 rounded-full bg-gradient-to-r from-brand-400 to-brand-600" />
        </div>
        <div class="flex justify-between">
          <div v-for="(s, i) in steps" :key="s" class="flex flex-col items-center gap-1 text-center" :class="i === 0 ? 'items-start' : i === steps.length - 1 ? 'items-end' : ''">
            <span class="grid size-7 place-items-center rounded-full text-xs font-bold transition-colors" :class="i <= stepIndex ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-400'">{{ i + 1 }}</span>
            <span class="text-xs font-semibold capitalize" :class="i <= stepIndex ? 'text-ink' : 'text-slate-400'">{{ s }}</span>
          </div>
        </div>
      </div>

      <div class="mb-5 grid gap-4 sm:grid-cols-4" data-reveal>
        <div class="card p-4"><p class="label">Basic</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ money(totals.basic) }}</p></div>
        <div class="card p-4"><p class="label">Additions</p><p class="mt-1 font-display text-lg font-extrabold text-emerald-600 tabular">+{{ money(totals.additions) }}</p></div>
        <div class="card p-4"><p class="label">Deductions & recovery</p><p class="mt-1 font-display text-lg font-extrabold text-rose-600 tabular">−{{ money(totals.deductions + totals.advanceRecovery) }}</p></div>
        <div class="card bg-gradient-to-br from-brand-800 to-brand-950 p-4 text-white"><p class="text-[11px] font-semibold tracking-wider text-brand-200/80 uppercase">Net payout</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ money(totals.net) }}</p></div>
      </div>

      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] text-sm">
            <thead>
              <tr class="border-b border-slate-100 bg-slate-50/70">
                <th class="label h-10 px-4 text-left">Employee</th><th class="label px-3 text-right">Days</th><th class="label px-3 text-right">Basic</th>
                <th class="label px-3 text-right">Additions</th><th class="label px-3 text-right">Deductions</th><th class="label px-3 text-right">Advance</th>
                <th class="label px-4 text-right">Net</th><th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in slips" :key="p.id" class="group border-b border-slate-50 hover:bg-brand-50/30">
                <td class="px-4 py-3"><p class="font-semibold text-ink">{{ p.name }}</p><p class="text-xs text-slate-500">{{ p.designation }}</p></td>
                <td class="px-3 py-3 text-right tabular" :class="p.presentDays < p.workingDays ? 'text-amber-600' : 'text-slate-500'">{{ p.presentDays }}/{{ p.workingDays }}</td>
                <td class="px-3 py-3 text-right tabular">{{ money(p.basic) }}</td>
                <td class="px-3 py-3 text-right tabular" :class="p.additions ? 'text-emerald-600' : 'text-slate-400'">{{ p.additions ? `+${money(p.additions)}` : '—' }}</td>
                <td class="px-3 py-3 text-right tabular" :class="p.deductions ? 'text-rose-600' : 'text-slate-400'">{{ p.deductions ? `−${money(p.deductions)}` : '—' }}</td>
                <td class="px-3 py-3 text-right tabular" :class="p.advanceRecovery ? 'text-rose-600' : 'text-slate-400'">{{ p.advanceRecovery ? `−${money(p.advanceRecovery)}` : '—' }}</td>
                <td class="px-4 py-3 text-right font-semibold tabular">{{ money(p.net) }}</td>
                <td class="pr-3"><UiButton size="sm" variant="ghost" :icon="FileText" :aria-label="`Payslip for ${p.name}`" @click="openSlip(p)" /></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                <td class="px-4 py-3" colspan="2">Total</td>
                <td class="px-3 py-3 text-right tabular">{{ money(totals.basic) }}</td>
                <td class="px-3 py-3 text-right tabular">{{ money(totals.additions) }}</td>
                <td class="px-3 py-3 text-right tabular">{{ money(totals.deductions) }}</td>
                <td class="px-3 py-3 text-right tabular">{{ money(totals.advanceRecovery) }}</td>
                <td class="px-4 py-3 text-right tabular">{{ money(totals.net) }}</td>
                <td />
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <UiModal v-model="payOpen" title="Pay salaries" :description="`Posts one payment per payslip (${money(totals.net)} total). This cannot be undone.`" size="sm" :dismissible="!busy">
        <UiSelect v-model="account" label="Pay from account" :options="accounts.map(a => ({ value: a, label: a }))" />
        <template #footer>
          <UiButton variant="ghost" @click="payOpen = false">Cancel</UiButton>
          <UiButton variant="primary" :icon="Banknote" :loading="busy" :disabled="!account" @click="pay">Pay {{ money(totals.net) }}</UiButton>
        </template>
      </UiModal>

      <UiModal v-model="slipOpen" :title="slip ? `Payslip · ${slip.name}` : ''" :description="periodLabel" size="sm">
        <dl v-if="slip" class="space-y-2.5 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Designation</dt><dd>{{ slip.designation }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Days present</dt><dd class="tabular">{{ slip.presentDays }} of {{ slip.workingDays }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-2.5"><dt class="text-slate-500">Basic salary</dt><dd class="tabular">{{ money(slip.basic) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Additions</dt><dd class="text-emerald-600 tabular">+{{ money(slip.additions) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Absence deduction</dt><dd class="text-rose-600 tabular">−{{ money(slip.deductions) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Advance recovery</dt><dd class="text-rose-600 tabular">−{{ money(slip.advanceRecovery) }}</dd></div>
          <div class="flex justify-between border-t border-slate-200 pt-2.5 font-display text-lg font-extrabold"><dt>Net pay</dt><dd class="tabular">{{ money(slip.net) }}</dd></div>
        </dl>
        <template #footer>
          <UiButton variant="ghost" @click="slipOpen = false">Close</UiButton>
          <UiButton variant="primary" :icon="Printer" @click="printSlip">Print payslip</UiButton>
        </template>
      </UiModal>
    </template>
  </div>
</template>
