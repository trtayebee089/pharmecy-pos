<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'
import type { DosageForm } from '~/types/inventory'

definePageMeta({ title: 'Dosage forms' })

const resource = useResource<DosageForm>('catalog/dosage-forms', async () => (await import('~/mocks/inventory')).dosageForms)

const columns: CrudColumn[] = [
  { key: 'name', label: 'Dosage form' },
  { key: 'products', label: 'Products', align: 'right', format: 'number' },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Dosage form', type: 'text', required: true, span: 2, placeholder: 'e.g. Tablet, Syrup, Injection' },
  { key: 'isActive', label: 'Active', type: 'switch', span: 2 },
]
</script>

<template>
  <UiCrudPage
    title="Dosage forms" description="Tablet, capsule, syrup… Shown on product cards and used as POS filter chips. Legacy categories map here."
    :resource="resource" :columns="columns" :fields="fields" noun="Dosage form" create-permission="catalog.manage"
    :defaults="() => ({ name: '', isActive: true, products: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
  </UiCrudPage>
</template>
