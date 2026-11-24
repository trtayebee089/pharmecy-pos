<script setup lang="ts">
import { Power, Pill } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { DosageForm } from '~/types/inventory'

definePageMeta({ title: 'Dosage forms' })

const resource = useResource<DosageForm>('catalog/dosage-forms', async () => (await import('~/mocks/inventory')).dosageForms, 'catalog')
const auth = useAuthStore()
const toast = useToast()
const crud = ref<{ reload: () => void } | null>(null)
async function toggleActive(r: DosageForm) {
  try { await resource.save({ id: r.id, isActive: !r.isActive } as Partial<DosageForm>); toast.success(r.isActive ? 'Dosage form deactivated' : 'Dosage form activated', r.name); crud.value?.reload() }
  catch (e: any) { toast.error('Not changed', e?.message) }
}
const rowActions = (r: DosageForm): RowAction[] => [
  { label: 'View products', icon: Pill, to: `/products?search=${encodeURIComponent(r.name)}` },
  { label: r.isActive ? 'Deactivate' : 'Activate', icon: Power, onClick: () => toggleActive(r), hidden: !auth.can('catalog.manage') },
]

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
    :resource="resource" :columns="columns" :fields="fields" ref="crud" noun="Dosage form" create-permission="catalog.manage" :row-actions="rowActions"
    :defaults="() => ({ name: '', isActive: true, products: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
  </UiCrudPage>
</template>
