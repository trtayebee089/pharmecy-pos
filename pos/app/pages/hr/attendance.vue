<script setup lang="ts">
import { CheckCheck, ChevronLeft, ChevronRight, Save, CalendarDays } from 'lucide-vue-next'
import type { AttendanceRow, AttendanceStatus, Employee } from '~/types/hr'

definePageMeta({ title: 'Attendance' })
const api = useAttendanceApi()
const employeesRes = useEmployeesResource()
const holidaysRes = useHolidaysResource()
const toast = useToast()

const view = ref<'daily' | 'monthly'>('daily')
const day = ref(new Date().toISOString().slice(0, 10))
const rows = ref<AttendanceRow[]>([])
const loading = ref(false)
const saving = ref(false)
const dirty = ref(false)
const holidayMap = ref<Record<string, string>>({})
const table = ref<HTMLElement | null>(null)

const statuses: { value: AttendanceStatus; label: string; on: string }[] = [
  { value: 'present', label: 'Present', on: 'bg-emerald-500 text-white' },
  { value: 'late', label: 'Late', on: 'bg-amber-400 text-amber-950' },
  { value: 'leave', label: 'Leave', on: 'bg-sky-500 text-white' },
  { value: 'absent', label: 'Absent', on: 'bg-rose-500 text-white' },
]

async function load() {
  loading.value = true
  rows.value = await api.day(day.value)
  loading.value = false
  dirty.value = false
  await nextTick()
  revealStagger(table.value?.querySelectorAll('tbody tr') ?? [], { y: 6, stagger: 0.025, duration: 0.3 })
}
watch(day, load)
onMounted(async () => {
  load()
  holidayMap.value = Object.fromEntries((await holidaysRes.all()).map(h => [h.date, h.name]))
  employees.value = (await employeesRes.all()).filter(e => e.isActive)
  employeeId.value = employees.value[0]?.id ?? null
})

const isFriday = computed(() => new Date(day.value).getDay() === 5)
const holiday = computed(() => holidayMap.value[day.value])
const future = computed(() => day.value > new Date().toISOString().slice(0, 10))
const counts = computed(() => Object.fromEntries(statuses.map(s => [s.value, rows.value.filter(r => r.status === s.value).length])) as Record<AttendanceStatus, number>)
const unmarked = computed(() => rows.value.filter(r => !r.status).length)

function set(r: AttendanceRow, s: AttendanceStatus) {
  r.status = s
  if ((s === 'present' || s === 'late') && !r.checkIn) r.checkIn = s === 'late' ? '09:45' : '09:00'
  if (s === 'absent' || s === 'leave') { r.checkIn = ''; r.checkOut = '' }
  dirty.value = true
}
function markAllPresent() {
  for (const r of rows.value) if (!r.status) set(r, 'present')
  toast.info('Unmarked employees set to present')
}
function shiftDay(d: number) {
  const x = new Date(day.value); x.setDate(x.getDate() + d); day.value = x.toISOString().slice(0, 10)
}
async function save() {
  if (unmarked.value) { toast.warning(`${unmarked.value} employees not marked`, 'Mark everyone before saving.'); return }
  saving.value = true
  try {
    await api.save(day.value, rows.value, crypto.randomUUID())
    dirty.value = false
    toast.success('Attendance saved', date(day.value))
  }
  catch (e: any) { toast.error('Not saved', e?.message) }
  finally { saving.value = false }
}

// ---- Monthly view ----
const employees = ref<Employee[]>([])
const employeeId = ref<number | null>(null)
const cursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const monthDays = ref<Record<string, AttendanceStatus | null>>({})
async function loadMonth() {
  if (!employeeId.value) return
  monthDays.value = await api.month(employeeId.value, cursor.value.getFullYear(), cursor.value.getMonth())
}
watch([employeeId, cursor, view], () => view.value === 'monthly' && loadMonth())
function shiftMonth(d: number) { cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + d, 1) }
</script>

<template>
  <div>
    <UiPageHeader title="Attendance" description="Daily register used by payroll. Fridays are the weekly off; holidays come from the holiday list.">
      <UiSegmented v-model="view" :options="[{ value: 'daily', label: 'Daily sheet' }, { value: 'monthly', label: 'Monthly view' }]" />
    </UiPageHeader>

    <template v-if="view === 'daily'">
      <div class="card mb-5 flex flex-wrap items-center gap-3 p-3">
        <UiButton size="sm" variant="ghost" :icon="ChevronLeft" aria-label="Previous day" @click="shiftDay(-1)" />
        <div class="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm">
          <CalendarDays class="size-4 text-slate-400" />
          <input v-model="day" type="date" class="bg-transparent focus:outline-none" aria-label="Attendance date">
        </div>
        <UiButton size="sm" variant="ghost" :icon="ChevronRight" aria-label="Next day" @click="shiftDay(1)" />
        <span class="text-sm font-semibold text-ink">{{ new Date(day).toLocaleDateString('en-GB', { weekday: 'long' }) }}</span>
        <UiBadge v-if="holiday" tone="violet">Holiday · {{ holiday }}</UiBadge>
        <UiBadge v-else-if="isFriday" tone="neutral">Weekly off</UiBadge>
        <div class="ml-auto flex flex-wrap items-center gap-2">
          <UiBadge v-for="s in statuses" :key="s.value" :tone="s.value === 'present' ? 'success' : s.value === 'late' ? 'warning' : s.value === 'leave' ? 'info' : 'danger'">{{ s.label }} {{ counts[s.value] }}</UiBadge>
          <UiBadge v-if="unmarked" tone="neutral">Unmarked {{ unmarked }}</UiBadge>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
          <p class="text-sm text-slate-500">{{ rows.length }} active employees</p>
          <div class="ml-auto flex gap-2">
            <UiButton :icon="CheckCheck" :disabled="future || !unmarked" @click="markAllPresent">Mark unmarked present</UiButton>
            <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="future || !dirty" @click="save">Save attendance</UiButton>
          </div>
        </div>
        <p v-if="future" class="border-b border-amber-100 bg-amber-50 px-4 py-2 text-sm text-amber-700">Attendance cannot be recorded for a future date.</p>
        <div ref="table" class="overflow-x-auto">
          <table class="w-full min-w-[720px] text-sm">
            <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Employee</th><th class="label px-3 text-left">Status</th><th class="label px-3 text-left">Check-in</th><th class="label px-3 text-left">Check-out</th></tr></thead>
            <tbody v-if="loading"><tr v-for="i in 6" :key="i"><td colspan="4" class="px-4 py-3"><div class="skeleton h-8" /></td></tr></tbody>
            <tbody v-else>
              <tr v-for="r in rows" :key="r.employeeId" class="border-b border-slate-50 hover:bg-brand-50/30">
                <td class="px-4 py-2.5">
                  <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xs font-bold text-slate-600">{{ initials(r.name) }}</span>
                    <div><p class="font-semibold text-ink">{{ r.name }}</p><p class="text-xs text-slate-500">{{ r.designation }}</p></div>
                  </div>
                </td>
                <td class="px-3 py-2.5">
                  <div class="inline-flex rounded-xl bg-slate-100 p-0.5" role="radiogroup" :aria-label="`Status for ${r.name}`">
                    <button
                      v-for="s in statuses" :key="s.value" type="button" role="radio" :aria-checked="r.status === s.value" :disabled="future"
                      class="h-8 rounded-lg px-2.5 text-xs font-semibold transition disabled:opacity-50"
                      :class="r.status === s.value ? `${s.on} shadow-sm` : 'text-slate-500 hover:text-slate-800'"
                      @click="set(r, s.value)"
                    >{{ s.label }}</button>
                  </div>
                </td>
                <td class="px-3 py-2.5"><input v-model="r.checkIn" type="time" :disabled="future || r.status === 'absent' || r.status === 'leave'" class="h-9 rounded-lg border border-slate-200 px-2 text-sm tabular focus:border-brand-400 focus:outline-none disabled:bg-slate-50 disabled:text-slate-300" :aria-label="`Check-in for ${r.name}`" @input="dirty = true"></td>
                <td class="px-3 py-2.5"><input v-model="r.checkOut" type="time" :disabled="future || r.status === 'absent' || r.status === 'leave'" class="h-9 rounded-lg border border-slate-200 px-2 text-sm tabular focus:border-brand-400 focus:outline-none disabled:bg-slate-50 disabled:text-slate-300" :aria-label="`Check-out for ${r.name}`" @input="dirty = true"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
      <div class="card h-fit overflow-hidden">
        <button
          v-for="e in employees" :key="e.id"
          class="flex w-full items-center gap-3 border-b border-slate-100 px-4 py-3 text-left transition-colors last:border-0"
          :class="e.id === employeeId ? 'bg-brand-50/70' : 'hover:bg-slate-50'"
          @click="employeeId = e.id"
        >
          <span class="grid size-8 place-items-center rounded-lg text-[11px] font-bold" :class="e.id === employeeId ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500'">{{ initials(e.name) }}</span>
          <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ e.name }}</span><span class="block truncate text-xs text-slate-500">{{ e.designation }}</span></span>
        </button>
      </div>
      <div class="card p-5">
        <div class="mb-4 flex items-center justify-between">
          <div>
            <p class="label">{{ employees.find(e => e.id === employeeId)?.name }}</p>
            <p class="font-display text-lg font-bold">{{ cursor.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) }}</p>
          </div>
          <div class="flex gap-1">
            <UiButton size="sm" variant="ghost" :icon="ChevronLeft" aria-label="Previous month" @click="shiftMonth(-1)" />
            <UiButton size="sm" variant="ghost" :icon="ChevronRight" aria-label="Next month" @click="shiftMonth(1)" />
          </div>
        </div>
        <div class="max-w-xl"><HrMonthHeatmap :year="cursor.getFullYear()" :month="cursor.getMonth()" :days="monthDays" :holidays="holidayMap" /></div>
      </div>
    </div>
  </div>
</template>
