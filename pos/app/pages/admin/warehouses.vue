<script setup lang="ts">
import { Power, Store, Boxes, MapPin, MapPinned } from 'lucide-vue-next'
import type { LatLng, MapsConfig } from '~/composables/useGoogleMaps'
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
  // Send the whole row: the API validates every field and would clear an omitted map position.
  try { await resource.save({ ...w, isActive: !w.isActive }); toast.success(w.isActive ? 'Warehouse deactivated' : 'Warehouse activated', w.name); crud.value?.reload() }
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
  { key: 'latitude', label: 'Map position' },
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

// ---- Map position (store marker for riders and the start of each delivery) ----
const maps = ref<MapsConfig | null>(null)
const integrations = useIntegrationsApi()
onMounted(async () => {
  // Integrations needs settings.manage; without it (or with maps off) the form keeps plain number inputs.
  try { maps.value = (await integrations.load()).maps ?? null } catch { maps.value = null }
})
const canPick = computed(() => mapsUsable(maps.value))
const picking = ref(false)
const pickForm = shallowRef<Record<string, any> | null>(null)
function startPick(form: Record<string, any>) { pickForm.value = form; picking.value = true }
function onPick(at: LatLng) { if (pickForm.value) { pickForm.value.latitude = at.lat; pickForm.value.longitude = at.lng } }
const coord = (v: string | number | undefined) => (v === '' || v === undefined || v === null ? null : Number(v))
const pinOf = (form: Record<string, any>): LatLng | null => (hasPosition(form.latitude, form.longitude) ? { lat: Number(form.latitude), lng: Number(form.longitude) } : null)
</script>

<template>
  <div>
  <UiCrudPage
    title="Warehouses" description="Stores and stock locations. Each has its own stock, registers, document numbering and receipt header."
    :resource="resource" :columns="columns" :fields="fields" ref="crud" noun="Warehouse" create-permission="warehouses.manage" :deletable="false" :row-actions="rowActions"
    :defaults="() => ({ code: '', name: '', type: 'store' as const, address: '', phone: '', invoiceHeader: '', invoiceFooter: '', isActive: true, latitude: null, longitude: null })"
    :item-label="r => r.name"
  >
    <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
    <template #cell-address="{ row }"><span class="block max-w-64 truncate text-slate-600" :title="row.address">{{ row.address || '—' }}</span></template>
    <template #cell-latitude="{ row }">
      <a v-if="hasPosition(row.latitude, row.longitude)" :href="googleMapsLink({ lat: Number(row.latitude), lng: Number(row.longitude) })" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-700 tabular hover:underline">
        <MapPin class="size-3.5" />{{ Number(row.latitude).toFixed(4) }}, {{ Number(row.longitude).toFixed(4) }}
      </a>
      <span v-else class="text-slate-400">Not set</span>
    </template>
    <template #form-extra="{ form }">
      <div class="sm:col-span-2">
        <div class="mb-1.5 flex items-center justify-between gap-2">
          <span class="text-[13px] font-medium text-slate-700">Map position</span>
          <span class="flex gap-1">
            <UiButton v-if="canPick" size="sm" variant="ghost" :icon="MapPinned" @click="startPick(form)">Pick on map</UiButton>
            <UiButton v-if="pinOf(form)" size="sm" variant="ghost" @click="form.latitude = null; form.longitude = null">Clear</UiButton>
          </span>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <UiInput :model-value="form.latitude ?? ''" type="number" placeholder="Latitude, e.g. 23.7465" aria-label="Latitude" @update:model-value="v => (form.latitude = coord(v))" />
          <UiInput :model-value="form.longitude ?? ''" type="number" placeholder="Longitude, e.g. 90.3760" aria-label="Longitude" @update:model-value="v => (form.longitude = coord(v))" />
        </div>
        <span class="mt-1 block text-[12px] text-slate-500">
          The store marker in the rider app and the start of each delivery route. Both or neither.
          <template v-if="!canPick"> Turn on Google Maps in Administration › Integrations › Maps to pick it on a map.</template>
        </span>
      </div>
    </template>
  </UiCrudPage>
  <DeliveryPinPicker v-if="maps && canPick" v-model="picking" :config="maps" :value="pickForm ? pinOf(pickForm) : null" title="Branch position" @pick="onPick" />
  </div>
</template>
