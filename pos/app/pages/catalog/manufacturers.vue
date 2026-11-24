<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'
import type { Manufacturer } from '~/types/inventory'

definePageMeta({ title: 'Manufacturers' })

const resource = useResource<Manufacturer>('catalog/manufacturers', async () => (await import('~/mocks/inventory')).manufacturers)

const columns: CrudColumn[] = [
  { key: 'name', label: 'Manufacturer' },
  { key: 'shortName', label: 'Short name', format: 'mono' },
  { key: 'country', label: 'Country' },
  { key: 'products', label: 'Products', align: 'right', format: 'number' },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Manufacturer name', type: 'text', required: true, span: 2, placeholder: 'e.g. Square Pharmaceuticals PLC' },
  { key: 'shortName', label: 'Short name', type: 'text', placeholder: 'e.g. SQUARE', hint: 'Shown on labels and compact lists' },
  { key: 'country', label: 'Country', type: 'text', placeholder: 'Bangladesh' },
  { key: 'isActive', label: 'Active', type: 'switch', hint: 'Inactive manufacturers are hidden from product forms', span: 2 },
]
</script>

<template>
  <UiCrudPage
    title="Manufacturers" description="Pharmaceutical companies. Legacy “brands” are imported here."
    :resource="resource" :columns="columns" :fields="fields" noun="Manufacturer" create-permission="catalog.manage"
    :defaults="() => ({ name: '', shortName: '', country: 'Bangladesh', isActive: true, products: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }">
      <div class="flex items-center gap-3">
        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-brand-50 to-brand-100 text-[11px] font-bold text-brand-700">{{ initials(row.name) }}</span>
        <span class="font-semibold text-ink">{{ row.name }}</span>
      </div>
    </template>
  </UiCrudPage>
</template>
