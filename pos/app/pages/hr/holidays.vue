<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import type { CrudColumn, FieldDef } from '~/types/domain'
import type { Holiday } from '~/types/hr'

definePageMeta({ title: 'Holidays' })
const resource = useHolidaysResource()
const all = ref<Holiday[]>([])
const year = ref(new Date().getFullYear())
const view = ref<'list' | 'calendar'>('list')

async function loadAll() { all.value = await resource.all() }
onMounted(loadAll)
const marks = computed(() => Object.fromEntries(all.value.filter(h => h.date.startsWith(String(year.value))).map(h => [h.date, h.name])))
const upcoming = computed(() => all.value.filter(h => daysUntil(h.date) >= 0).sort((a, b) => a.date.localeCompare(b.date))[0] ?? null)

const columns: CrudColumn[] = [
  { key: 'date', label: 'Date', format: 'date' },
  { key: 'day', label: 'Day' },
  { key: 'name', label: 'Holiday' },
  { key: 'warehouse', label: 'Applies to' },
]
const fields: FieldDef[] = [
  { key: 'date', label: 'Date', type: 'date', required: true },
  { key: 'warehouse', label: 'Applies to', type: 'select', required: true, options: [{ value: 'All warehouses', label: 'All warehouses' }, { value: 'Red Pharma · Dhanmondi', label: 'Dhanmondi only' }, { value: 'Red Pharma · Uttara', label: 'Uttara only' }] },
  { key: 'name', label: 'Holiday name', type: 'text', required: true, span: 2 },
]
</script>

<template>
  <UiCrudPage
    title="Holidays" description="Paid holidays are excluded from absent days in attendance and payroll."
    :resource="resource" :columns="columns" :fields="fields" noun="Holiday" create-permission="hr.manage"
    :defaults="() => ({ date: '', name: '', warehouse: 'All warehouses' })" :item-label="r => `${r.name} · ${date(r.date)}`"
    @saved="loadAll"
  >
    <template #above>
      <div class="mb-5 flex flex-wrap items-center gap-3">
        <UiSegmented v-model="view" :options="[{ value: 'list', label: 'List' }, { value: 'calendar', label: 'Year calendar' }]" />
        <p v-if="upcoming" class="text-sm text-slate-500">Next: <span class="font-semibold text-ink">{{ upcoming.name }}</span> · {{ date(upcoming.date) }} ({{ daysUntil(upcoming.date) }} days)</p>
      </div>
      <div v-if="view === 'calendar'" class="card mb-5 p-5">
        <div class="mb-4 flex items-center justify-between">
          <p class="font-display text-lg font-bold">{{ year }}</p>
          <div class="flex gap-1">
            <UiButton size="sm" variant="ghost" :icon="ChevronLeft" aria-label="Previous year" @click="year--" />
            <UiButton size="sm" variant="ghost" :icon="ChevronRight" aria-label="Next year" @click="year++" />
          </div>
        </div>
        <HrYearCalendar :year="year" :marks="marks" />
        <p class="mt-3 text-xs text-slate-500">Friday is the weekly off (red). Highlighted days are holidays; hover for the name.</p>
      </div>
    </template>
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
    <template #cell-day="{ row }"><span class="text-slate-500">{{ new Date(row.date).toLocaleDateString('en-GB', { weekday: 'long' }) }}</span></template>
  </UiCrudPage>
</template>
