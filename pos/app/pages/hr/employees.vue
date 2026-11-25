<script setup lang="ts">
import { Phone, Mail, Building2, CalendarDays, Wallet, UserRound, KeyRound, CalendarCheck, HandCoins, Power, IdCard } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { AttendanceStatus, Department, Designation, Employee } from '~/types/hr'

definePageMeta({ title: 'Employees' })

const tab = ref<'employees' | 'departments' | 'designations'>('employees')
const employees = useEmployeesResource()
const departments = useDepartmentsResource()
const designations = useDesignationsResource()
const attendance = useAttendanceApi()

const deptList = ref<Department[]>([])
const desigList = ref<Designation[]>([])
async function loadLookups() {
  [deptList.value, desigList.value] = await Promise.all([departments.all(), designations.all()])
}
onMounted(loadLookups)

const employeeColumns: CrudColumn[] = [
  { key: 'name', label: 'Employee' },
  { key: 'department', label: 'Department' },
  { key: 'joinDate', label: 'Joined', format: 'date' },
  { key: 'basicSalary', label: 'Basic salary', align: 'right', format: 'money' },
  { key: 'userAccount', label: 'POS login' },
  { key: 'isActive', label: 'Status', format: 'boolean' },
]
const employeeFields = computed<FieldDef[]>(() => [
  { key: 'name', label: 'Full name', type: 'text', required: true, span: 2 },
  { key: 'code', label: 'Employee code', type: 'text', required: true, placeholder: 'EMP-012' },
  { key: 'joinDate', label: 'Join date', type: 'date', required: true },
  { key: 'department', label: 'Department', type: 'select', required: true, options: deptList.value.map(d => ({ value: d.name, label: d.name })) },
  { key: 'designation', label: 'Designation', type: 'select', required: true, options: desigList.value.map(d => ({ value: d.name, label: d.name })) },
  { key: 'phone', label: 'Phone', type: 'tel', required: true },
  { key: 'email', label: 'Email', type: 'email' },
  { key: 'basicSalary', label: 'Basic salary (৳ / month)', type: 'money', required: true, hint: 'Used by payroll; stored, never derived' },
  { key: 'warehouse', label: 'Works at', type: 'select', options: (useAuthStore().user?.warehouses ?? []).map(w => ({ value: w.name, label: w.name })) },
  { key: 'isActive', label: 'Active', type: 'switch', hint: 'Inactive employees are left out of attendance and payroll', span: 2 },
])
const deptColumns: CrudColumn[] = [{ key: 'name', label: 'Department' }, { key: 'employees', label: 'Employees', align: 'right', format: 'number' }]
const deptFields: FieldDef[] = [{ key: 'name', label: 'Department name', type: 'text', required: true, span: 2 }]
const desigColumns = computed<CrudColumn[]>(() => [{ key: 'name', label: 'Designation' }, { key: 'department', label: 'Department' }])
const desigFields = computed<FieldDef[]>(() => [
  { key: 'name', label: 'Designation', type: 'text', required: true },
  { key: 'department', label: 'Department', type: 'select', required: true, options: deptList.value.map(d => ({ value: d.name, label: d.name })) },
])

// ---- Detail drawer ----
const open = ref(false)
const current = ref<Employee | null>(null)
const month = ref<Record<string, AttendanceStatus | null>>({})
const now = new Date()
async function view(e: Employee) {
  current.value = e
  open.value = true
  month.value = await attendance.month(e.id, now.getFullYear(), now.getMonth())
}
// ---- Row actions (employees are deactivated, never deleted: payroll history refers to them) ----
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const crud = ref<{ reload: () => void } | null>(null)
async function setActive(e: Employee, active: boolean) {
  if (!active) {
    const { ok } = await ask({ title: `Deactivate ${e.name}?`, description: 'They are left out of attendance and future payroll runs. History is kept.', confirmLabel: 'Deactivate' })
    if (!ok) return
  }
  try { await employees.save({ id: e.id, isActive: active }); toast.success(active ? 'Employee activated' : 'Employee deactivated', e.name); crud.value?.reload() }
  catch (err: any) { toast.error('Could not update', err?.message) }
}
function employeeActions(e: Employee): RowAction[] {
  const manage = auth.can('hr.manage')
  return [
    { label: 'Profile & attendance', icon: IdCard, onClick: () => view(e) },
    { label: 'Attendance sheet', icon: CalendarCheck, to: '/hr/attendance' },
    { label: 'Salary advance', icon: HandCoins, to: `/hr/payroll?tab=advances&employee=${e.id}`, hidden: !auth.can('hr.payroll') },
    { label: e.isActive ? 'Deactivate' : 'Activate', icon: Power, onClick: () => setActive(e, !e.isActive), danger: e.isActive, hidden: !manage },
  ]
}

const tenure = computed(() => {
  if (!current.value) return ''
  const m = Math.max(0, Math.round((Date.now() - new Date(current.value.joinDate).getTime()) / (30.44 * 864e5)))
  return m >= 12 ? `${Math.floor(m / 12)} yr ${m % 12} mo` : `${m} months`
})
</script>

<template>
  <div>
    <div class="mb-5"><UiTabs v-model="tab" :tabs="[{ value: 'employees', label: 'Employees' }, { value: 'departments', label: 'Departments', count: deptList.length }, { value: 'designations', label: 'Designations', count: desigList.length }]" /></div>

    <UiCrudPage
      v-if="tab === 'employees'"
      ref="crud"
      :row-actions="employeeActions"
      title="Employees" description="Staff records used by attendance and payroll. A POS login is a separate user account linked here."
      :resource="employees" :columns="employeeColumns" :fields="employeeFields" noun="Employee" create-permission="hr.manage" :deletable="false"
      :defaults="() => ({ code: '', name: '', phone: '', email: '', department: deptList[0]?.name ?? '', designation: '', joinDate: new Date().toISOString().slice(0, 10), basicSalary: 0, userAccount: null, warehouse: 'Dhanmondi', isActive: true })"
      :item-label="r => r.name"
    >
      <template #cell-name="{ row }">
        <button class="flex items-center gap-3 text-left" @click="view(row)">
          <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-300 to-brand-700 text-xs font-bold text-white">{{ initials(row.name) }}</span>
          <span><span class="block font-semibold text-ink hover:text-brand-700">{{ row.name }}</span><span class="block text-xs text-slate-500">{{ row.code }} · {{ row.designation }}</span></span>
        </button>
      </template>
      <template #cell-userAccount="{ row }">
        <span v-if="row.userAccount" class="inline-flex items-center gap-1 text-slate-600"><KeyRound class="size-3.5 text-brand-600" />@{{ row.userAccount }}</span>
        <span v-else class="text-slate-400">No login</span>
      </template>
    </UiCrudPage>

    <UiCrudPage
      v-else-if="tab === 'departments'"
      title="Departments" :resource="departments" :columns="deptColumns" :fields="deptFields" noun="Department" create-permission="hr.manage"
      :defaults="() => ({ name: '', employees: 0 })" :item-label="r => r.name" @saved="loadLookups"
    />

    <UiCrudPage
      v-else
      title="Designations" :resource="designations" :columns="desigColumns" :fields="desigFields" noun="Designation" create-permission="hr.manage"
      :defaults="() => ({ name: '', department: deptList[0]?.name ?? '' })" :item-label="r => r.name" @saved="loadLookups"
    />

    <UiDrawer v-model="open" width="lg">
      <template #header>
        <div v-if="current" class="flex items-center gap-3">
          <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-brand-300 to-brand-700 font-bold text-white">{{ initials(current.name) }}</span>
          <div>
            <h2 class="font-display text-lg font-bold">{{ current.name }}</h2>
            <p class="text-sm text-slate-500">{{ current.designation }} · {{ current.code }}</p>
          </div>
        </div>
      </template>
      <div v-if="current" class="space-y-5">
        <div data-stagger class="grid grid-cols-2 gap-3">
          <div class="rounded-2xl bg-slate-50 p-4"><p class="label flex items-center gap-1.5"><Wallet class="size-3.5" />Basic salary</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ money(current.basicSalary) }}</p></div>
          <div class="rounded-2xl bg-slate-50 p-4"><p class="label flex items-center gap-1.5"><CalendarDays class="size-3.5" />Tenure</p><p class="mt-1 font-display text-lg font-extrabold">{{ tenure }}</p></div>
        </div>
        <dl data-stagger class="space-y-2.5 text-sm">
          <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><dt class="w-28 text-slate-500">Department</dt><dd class="font-medium">{{ current.department }}</dd></div>
          <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" /><dt class="w-28 text-slate-500">Joined</dt><dd class="font-medium">{{ date(current.joinDate) }}</dd></div>
          <div class="flex items-center gap-2"><Phone class="size-4 text-slate-400" /><dt class="w-28 text-slate-500">Phone</dt><dd class="font-medium tabular">{{ current.phone }}</dd></div>
          <div class="flex items-center gap-2"><Mail class="size-4 text-slate-400" /><dt class="w-28 text-slate-500">Email</dt><dd class="font-medium">{{ current.email || '—' }}</dd></div>
          <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" /><dt class="w-28 text-slate-500">POS login</dt><dd class="font-medium">{{ current.userAccount ? `@${current.userAccount}` : 'Not linked' }}</dd></div>
        </dl>
        <div data-stagger class="rounded-2xl border border-slate-200 p-4">
          <p class="label mb-3">Attendance · {{ now.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) }}</p>
          <HrMonthHeatmap :year="now.getFullYear()" :month="now.getMonth()" :days="month" />
        </div>
      </div>
      <template #footer>
        <NuxtLink to="/hr/attendance"><UiButton>Attendance</UiButton></NuxtLink>
        <NuxtLink to="/hr/payroll"><UiButton variant="primary">Payroll</UiButton></NuxtLink>
      </template>
    </UiDrawer>
  </div>
</template>
