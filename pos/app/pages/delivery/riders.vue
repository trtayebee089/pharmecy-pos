<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'

definePageMeta({ title: 'Riders' })
const resource = useRidersResource()

const columns: CrudColumn[] = [
  { key: 'name', label: 'Rider' },
  { key: 'phone', label: 'Phone' },
  { key: 'nid', label: 'NID', format: 'mono' },
  { key: 'assignedOrders', label: 'Active orders', align: 'right', format: 'number' },
  { key: 'status', label: 'Status', format: 'badge', tones: { active: 'success', on_leave: 'warning', inactive: 'neutral' } },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Full name', type: 'text', required: true, span: 2 },
  { key: 'phone', label: 'Mobile', type: 'tel', required: true, placeholder: '01XXXXXXXXX', hint: 'Must be unique' },
  { key: 'nid', label: 'National ID', type: 'text', required: true },
  { key: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Active' }, { value: 'on_leave', label: 'On leave' }, { value: 'inactive', label: 'Inactive' }] },
]
</script>

<template>
  <UiCrudPage
    title="Riders" description="Own delivery riders. Only active riders can be assigned to orders; phone numbers are unique."
    :resource="resource" :columns="columns" :fields="fields" noun="Rider" create-permission="riders.manage" :deletable="false"
    :segments="{ key: 'status', options: [{ value: 'active', label: 'Active' }, { value: 'on_leave', label: 'On leave' }, { value: 'inactive', label: 'Inactive' }] }"
    :defaults="() => ({ name: '', phone: '', nid: '', status: 'active' as const, assignedOrders: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }">
      <span class="inline-flex items-center gap-2.5">
        <span class="grid size-8 place-items-center rounded-full bg-gradient-to-br from-brand-300 to-brand-600 text-[11px] font-bold text-white">{{ initials(row.name) }}</span>
        <span class="font-semibold text-ink">{{ row.name }}</span>
      </span>
    </template>
    <template #cell-phone="{ row }"><a :href="`tel:${row.phone}`" class="text-brand-700 tabular hover:underline">{{ row.phone }}</a></template>
  </UiCrudPage>
</template>
