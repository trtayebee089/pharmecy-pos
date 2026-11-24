<script setup lang="ts">
import { Users, FileText } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { CustomerGroup } from '~/types/people'

definePageMeta({ title: 'Customer groups' })

const resource = useCustomerGroupsResource()
const rowActions = (g: CustomerGroup): RowAction[] => [
  { label: 'View members', icon: Users, to: `/customers?search=${encodeURIComponent(g.name)}` },
  { label: 'Customer report', icon: FileText, to: '/reports/customer' },
]
const columns: CrudColumn[] = [
  { key: 'name', label: 'Group' },
  { key: 'discountPct', label: 'Discount', align: 'right', format: 'percent' },
  { key: 'members', label: 'Members', align: 'right', format: 'number' },
  { key: 'notes', label: 'Notes' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Group name', type: 'text', required: true, span: 2, placeholder: 'e.g. Senior citizen' },
  { key: 'discountPct', label: 'Discount %', type: 'number', required: true, hint: 'Applied to every catalogue line at the counter. A larger manual line discount replaces it (that part is checked against the cashier’s limit).' },
  { key: 'notes', label: 'Notes', type: 'textarea', span: 2, placeholder: 'Who qualifies and how it is verified' },
]
</script>

<template>
  <UiCrudPage
    title="Customer groups" description="Groups that give members an automatic line discount at the counter. The larger of the group discount and a manual line discount applies; custom items are not discounted."
    :resource="resource" :columns="columns" :fields="fields" noun="Group" create-permission="customer_groups.manage" :row-actions="rowActions"
    :defaults="() => ({ name: '', discountPct: 0, members: 0, notes: '' })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
    <template #cell-discountPct="{ row }"><UiBadge tone="brand">{{ row.discountPct }}% off</UiBadge></template>
    <template #cell-notes="{ row }"><span class="block max-w-md truncate text-slate-500">{{ row.notes || '—' }}</span></template>
  </UiCrudPage>
</template>
