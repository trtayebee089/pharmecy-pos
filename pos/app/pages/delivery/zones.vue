<script setup lang="ts">
import type { CrudColumn, FieldDef } from '~/types/domain'

definePageMeta({ title: 'Shipping zones' })
const resource = useZonesResource()

const partners = [
  { value: 'own_rider', label: 'Own rider' }, { value: 'pathao', label: 'Pathao' },
  { value: 'steadfast', label: 'Steadfast' }, { value: 'redx', label: 'RedX' },
]
const divisions = ['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'].map(d => ({ value: d, label: d }))

const columns: CrudColumn[] = [
  { key: 'thana', label: 'Area' },
  { key: 'division', label: 'Division' },
  { key: 'rate', label: 'Delivery charge', align: 'right', format: 'money' },
  { key: 'freeShippingMin', label: 'Free over', align: 'right', format: 'money' },
  { key: 'partner', label: 'Partner', format: 'badge', tones: { own_rider: 'brand', pathao: 'danger', steadfast: 'info', redx: 'violet' } },
  { key: 'isActive', label: 'Active', format: 'boolean' },
]
const fields: FieldDef[] = [
  { key: 'division', label: 'Division', type: 'select', required: true, options: divisions },
  { key: 'district', label: 'District', type: 'text', required: true },
  { key: 'thana', label: 'Thana / area', type: 'text', required: true, span: 2 },
  { key: 'rate', label: 'Delivery charge (৳)', type: 'money', required: true },
  { key: 'freeShippingMin', label: 'Free delivery over (৳)', type: 'money', hint: 'Leave empty for no free delivery' },
  { key: 'partner', label: 'Delivery partner', type: 'select', required: true, options: partners },
  { key: 'isActive', label: 'Active', type: 'switch', hint: 'Inactive zones are hidden at website checkout' },
]
</script>

<template>
  <UiCrudPage
    title="Shipping zones" description="Delivery charges by area, used by website and app checkout (pushed to the legacy storefront by the bridge)."
    :resource="resource" :columns="columns" :fields="fields" noun="Zone" create-permission="zones.manage"
    :segments="{ key: 'division', options: [{ value: 'Dhaka', label: 'Dhaka' }, { value: 'Chattogram', label: 'Chattogram' }] }"
    :defaults="() => ({ division: 'Dhaka', district: 'Dhaka', thana: '', rate: 60, freeShippingMin: null, partner: 'own_rider' as const, isActive: true })"
    :item-label="r => `${r.thana}, ${r.district}`"
  >
    <template #cell-thana="{ row }">
      <p class="font-semibold text-ink">{{ row.thana }}</p>
      <p class="text-xs text-slate-500">{{ row.district }}</p>
    </template>
  </UiCrudPage>
</template>
