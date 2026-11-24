<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'
import type { Generic } from '~/mocks/catalog'

// Reference implementation of the CRUD template (UiCrudPage + useResource).
definePageMeta({ title: 'Generics' })

const resource = useResource<Generic>('catalog/generics', async () => (await import('~/mocks/catalog')).generics())

const columns: CrudColumn[] = [
  { key: 'name', label: 'Generic name' },
  { key: 'therapeuticClass', label: 'Therapeutic class' },
  { key: 'products', label: 'Products', align: 'right', format: 'number' },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Generic name', type: 'text', required: true, span: 2, placeholder: 'e.g. Paracetamol' },
  { key: 'therapeuticClass', label: 'Therapeutic class', type: 'text', span: 2, placeholder: 'e.g. Analgesic' },
  { key: 'isActive', label: 'Active', type: 'switch', hint: 'Inactive generics are hidden from product forms' },
]
</script>

<template>
  <UiCrudPage
    title="Generics" description="Active ingredients used for generic-name search at the counter. The legacy system had none; they are filled by data enrichment."
    :resource="resource" :columns="columns" :fields="fields" noun="Generic" create-permission="catalog.manage"
    :defaults="() => ({ name: '', therapeuticClass: '', isActive: true, products: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
  </UiCrudPage>
</template>
