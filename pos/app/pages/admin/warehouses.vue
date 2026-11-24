<script setup lang="ts">
import { Power, Store, Boxes } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { Warehouse } from '~/types/hr'

definePageMeta({ title: 'Warehouses' })
const resource = useWarehousesResource()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const crud = ref<{ reload: () => void } | null>(null)
async function toggleActive(w: Warehouse) {
  if (w.isActive) {
    const { ok } = await ask({ title: `Deactivate ${w.name}?`, description: 'Warehouses hold stock and documents, so they are never deleted. An inactive warehouse cannot sell or receive stock; its history stays.', confirmLabel: 'Deactivate' })
    if (!ok) return
  }
  try { await resource.save({ id: w.id, isActive: !w.isActive }); toast.success(w.isActive ? 'Warehouse deactivated' : 'Warehouse activated', w.name); crud.value?.reload() }
  catch (e: any) { toast.error('Not changed', e?.message) }
}
function switchTo(w: Warehouse) {
  const mine = auth.user?.warehouses.find(x => x.id === w.id)
  if (!mine) { toast.warning('Not assigned to you', 'Ask an admin to add this warehouse to your user.'); return }
  auth.warehouseId = w.id
  toast.success('Switched warehouse', w.name)
}
const rowActions = (w: Warehouse): RowAction[] => [
  { label: 'Work in this warehouse', icon: Store, onClick: () => switchTo(w), hidden: auth.warehouseId === w.id || !w.isActive },
  { label: 'Stock overview', icon: Boxes, to: '/inventory/stock' },
  { label: w.isActive ? 'Deactivate' : 'Activate', icon: Power, danger: w.isActive, onClick: () => toggleActive(w), hidden: !auth.can('warehouses.manage') },
]

const columns: CrudColumn[] = [
  { key: 'code', label: 'Code', format: 'mono' },
  { key: 'name', label: 'Name' },
  { key: 'type', label: 'Type', format: 'badge', tones: { store: 'brand', warehouse: 'info' } },
  { key: 'address', label: 'Address' },
  { key: 'phone', label: 'Phone' },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields: FieldDef[] = [
  { key: 'code', label: 'Code', type: 'text', required: true, placeholder: 'e.g. DHK1', hint: 'Used in document numbers (S-DHK1-2026-…)' },
  { key: 'type', label: 'Type', type: 'select', required: true, options: [{ value: 'store', label: 'Store (sells at counter)' }, { value: 'warehouse', label: 'Warehouse (stock only)' }] },
  { key: 'name', label: 'Name', type: 'text', required: true, span: 2 },
  { key: 'address', label: 'Address', type: 'text', span: 2 },
  { key: 'phone', label: 'Phone', type: 'tel' },
  { key: 'isActive', label: 'Active', type: 'switch', hint: 'Inactive warehouses cannot receive or sell stock' },
  { key: 'invoiceHeader', label: 'Invoice header', type: 'textarea', span: 2, hint: 'Printed at the top of receipts (name, licence number)' },
  { key: 'invoiceFooter', label: 'Invoice footer', type: 'textarea', span: 2 },
]
</script>

<template>
  <UiCrudPage
    title="Warehouses" description="Stores and stock locations. Each has its own stock, registers, document numbering and receipt header."
    :resource="resource" :columns="columns" :fields="fields" ref="crud" noun="Warehouse" create-permission="warehouses.manage" :deletable="false" :row-actions="rowActions"
    :defaults="() => ({ code: '', name: '', type: 'store' as const, address: '', phone: '', invoiceHeader: '', invoiceFooter: '', isActive: true })"
    :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
    <template #cell-address="{ row }"><span class="block max-w-64 truncate text-slate-600" :title="row.address">{{ row.address || '—' }}</span></template>
  </UiCrudPage>
</template>
