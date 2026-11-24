<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'

definePageMeta({ title: 'Customer groups' })

const resource = useCustomerGroupsResource()
const columns: CrudColumn[] = [
  { key: 'name', label: 'Group' },
  { key: 'discountPct', label: 'Discount', align: 'right', format: 'percent' },
  { key: 'members', label: 'Members', align: 'right', format: 'number' },
  { key: 'notes', label: 'Notes' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Group name', type: 'text', required: true, span: 2, placeholder: 'e.g. Senior citizen' },
  { key: 'discountPct', label: 'Discount %', type: 'number', required: true, hint: 'Applied automatically at the counter, within role limits' },
  { key: 'notes', label: 'Notes', type: 'textarea', span: 2, placeholder: 'Who qualifies and how it is verified' },
]
</script>

<template>
  <UiCrudPage
    title="Customer groups" description="Price groups that give members an automatic discount. The API caps it by the cashier's role limit."
    :resource="resource" :columns="columns" :fields="fields" noun="Group" create-permission="customers.manage"
    :defaults="() => ({ name: '', discountPct: 0, members: 0, notes: '' })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
    <template #cell-discountPct="{ row }"><UiBadge tone="brand">{{ row.discountPct }}% off</UiBadge></template>
    <template #cell-notes="{ row }"><span class="block max-w-md truncate text-slate-500">{{ row.notes || '—' }}</span></template>
  </UiCrudPage>
</template>
