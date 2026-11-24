<script setup lang="ts">
import { Power, Pill } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { Generic } from '~/mocks/catalog'

// Reference implementation of the CRUD template (UiCrudPage + useResource).
definePageMeta({ title: 'Generics' })

const resource = useResource<Generic>('catalog/generics', async () => (await import('~/mocks/catalog')).generics())
const auth = useAuthStore()
const toast = useToast()
const crud = ref<{ reload: () => void } | null>(null)
async function toggleActive(r: Generic) {
  try { await resource.save({ id: r.id, isActive: !r.isActive } as Partial<Generic>); toast.success(r.isActive ? 'Generic deactivated' : 'Generic activated', r.name); crud.value?.reload() }
  catch (e: any) { toast.error('Not changed', e?.message) }
}
const rowActions = (r: Generic): RowAction[] => [
  { label: 'View products', icon: Pill, to: `/products?search=${encodeURIComponent(r.name)}` },
  { label: r.isActive ? 'Deactivate' : 'Activate', icon: Power, onClick: () => toggleActive(r), hidden: !auth.can('catalog.manage') },
]

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
    :resource="resource" :columns="columns" :fields="fields" ref="crud" noun="Generic" create-permission="catalog.manage" :row-actions="rowActions"
    :defaults="() => ({ name: '', therapeuticClass: '', isActive: true, products: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
  </UiCrudPage>
</template>
