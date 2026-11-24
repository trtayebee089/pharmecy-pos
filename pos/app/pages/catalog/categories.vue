<script setup lang="ts">
import { CornerDownRight, FolderTree, Power, Pill } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { CategoryRow } from '~/types/inventory'

definePageMeta({ title: 'Categories' })

const resource = useResource<CategoryRow>('catalog/categories', async () => (await import('~/mocks/inventory')).categories, 'catalog')
const auth = useAuthStore()
const toast = useToast()
const crud = ref<{ reload: () => void } | null>(null)
async function toggleActive(r: CategoryRow) {
  try { await resource.save({ id: r.id, isActive: !r.isActive } as Partial<CategoryRow>); toast.success(r.isActive ? 'Category deactivated' : 'Category activated', r.name); crud.value?.reload() }
  catch (e: any) { toast.error('Not changed', e?.message) }
}
const rowActions = (r: CategoryRow): RowAction[] => [
  { label: 'View products', icon: Pill, to: `/products?search=${encodeURIComponent(r.name)}` },
  { label: r.isActive ? 'Deactivate' : 'Activate', icon: Power, onClick: () => toggleActive(r), hidden: !auth.can('catalog.manage') },
]

// Parent options come from the current top-level categories.
const parents = ref<{ value: number; label: string }[]>([])
async function loadParents() {
  const all = await resource.all()
  parents.value = all.filter(c => c.parentId === null).map(c => ({ value: c.id, label: c.name }))
}
onMounted(loadParents)

const columns: CrudColumn[] = [
  { key: 'path', label: 'Category' },
  { key: 'products', label: 'Products', align: 'right', format: 'number' },
  { key: 'sortOrder', label: 'Order', align: 'right', format: 'number' },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields = computed<FieldDef[]>(() => [
  { key: 'name', label: 'Category name', type: 'text', required: true, span: 2 },
  { key: 'parentId', label: 'Parent category', type: 'select', options: parents.value, placeholder: 'None (top level)', hint: 'Leave empty for a top-level group' },
  { key: 'sortOrder', label: 'Sort order', type: 'number' },
  { key: 'isActive', label: 'Active', type: 'switch', span: 2 },
])

/** Keep the display path in sync with the chosen parent before saving (the API derives it too). */
async function onSaved(row: CategoryRow) {
  const parent = parents.value.find(p => p.value === row.parentId)
  const path = parent ? `${parent.label} › ${row.name}` : row.name
  if (row.path !== path) { await resource.save({ id: row.id, path }); crud.value?.reload() }
  loadParents()
}
</script>

<template>
  <UiCrudPage
    ref="crud"
    title="Categories" description="Shop and therapeutic grouping for products and reports (two levels)."
    :resource="resource" :columns="columns" :fields="fields" noun="Category" create-permission="catalog.manage" :row-actions="rowActions"
    :defaults="() => ({ name: '', parentId: null, sortOrder: 1, isActive: true, products: 0, path: '' })" :item-label="r => r.path || r.name"
    @saved="onSaved"
  >
    <template #cell-path="{ row }">
      <span v-if="row.parentId === null" class="flex items-center gap-2 font-bold text-ink"><FolderTree class="size-4 text-brand-600" />{{ row.name }}</span>
      <span v-else class="flex items-center gap-2 pl-5 text-slate-700"><CornerDownRight class="size-3.5 text-slate-300" /><span class="text-slate-400">{{ row.path.split(' › ')[0] }} ›</span><span class="font-semibold">{{ row.name }}</span></span>
    </template>
  </UiCrudPage>
</template>
