<script setup lang="ts">
import { Phone, Kanban, UserCheck, UserX, HandCoins } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { Rider } from '~/types/orders'

definePageMeta({ title: 'Riders' })
const resource = useRidersResource()
const toast = useToast()
const auth = useAuthStore()
const crud = ref<{ reload: () => void } | null>(null)
async function setStatus(r: Rider, status: Rider['status']) {
  try { await resource.save({ id: r.id, status }); toast.success(`${r.name} is now ${status.replace('_', ' ')}`); crud.value?.reload() }
  catch (e: any) { toast.error('Status not changed', e?.message) }
}

// ---- COD cash handover: the rider gives collected cash to the shop (moves into Cash in hand) ----
const ordersApi = useOnlineOrdersApi()
const handTarget = ref<Rider | null>(null)
const handAmount = ref(0)
const handNote = ref('')
const handing = ref(false)
let handKey = ''
function startHandover(r: Rider) { handTarget.value = r; handAmount.value = r.cashHeld ?? 0; handNote.value = ''; handKey = crypto.randomUUID() }
const handOpen = computed({ get: () => !!handTarget.value, set: (v) => { if (!v) handTarget.value = null } })
async function submitHandover() {
  const r = handTarget.value
  if (!r || !(handAmount.value > 0)) return
  handing.value = true
  try { await ordersApi.handover(r.id, Number(handAmount.value), handNote.value, handKey); toast.success('Cash received', `${money(handAmount.value)} from ${r.name}`); handTarget.value = null; crud.value?.reload() }
  catch (e: any) { toast.error('Handover not recorded', e?.message) }
  finally { handing.value = false }
}

const rowActions = (r: Rider): RowAction[] => [
  { label: 'Receive COD cash', icon: HandCoins, onClick: () => startHandover(r), hidden: !(r.cashHeld && r.cashHeld > 0) || !auth.can('riders.manage') },
  { label: `Call ${r.phone}`, icon: Phone, onClick: () => { window.location.href = `tel:${r.phone}` } },
  { label: 'Open fulfilment board', icon: Kanban, to: '/delivery' },
  { label: 'Mark active', icon: UserCheck, onClick: () => setStatus(r, 'active'), hidden: r.status === 'active' || !auth.can('riders.manage') },
  { label: 'Mark on leave', icon: UserX, onClick: () => setStatus(r, 'on_leave'), hidden: r.status !== 'active' || !auth.can('riders.manage') },
]

const columns: CrudColumn[] = [
  { key: 'name', label: 'Rider' },
  { key: 'phone', label: 'Phone' },
  { key: 'nid', label: 'NID', format: 'mono' },
  { key: 'assignedOrders', label: 'Active orders', align: 'right', format: 'number' },
  { key: 'cashHeld', label: 'Cash held', align: 'right', format: 'money' },
  { key: 'status', label: 'Status', format: 'badge', tones: { active: 'success', on_leave: 'warning', inactive: 'neutral' } },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Full name', type: 'text', required: true, span: 2 },
  { key: 'phone', label: 'Mobile', type: 'tel', required: true, placeholder: '01XXXXXXXXX', hint: 'Must be unique' },
  { key: 'nid', label: 'National ID', type: 'text' },
  { key: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Active' }, { value: 'on_leave', label: 'On leave' }, { value: 'inactive', label: 'Inactive' }] },
]
</script>

<template>
  <div>
  <UiCrudPage
    title="Riders" description="Own delivery riders. Only active riders can be assigned to orders; phone numbers are unique."
    :resource="resource" :columns="columns" :fields="fields" ref="crud" noun="Rider" create-permission="riders.manage" :row-actions="rowActions"
    :segments="{ key: 'status', options: [{ value: 'active', label: 'Active' }, { value: 'on_leave', label: 'On leave' }, { value: 'inactive', label: 'Inactive' }] }"
    :defaults="() => ({ name: '', phone: '', nid: '', status: 'active' as const, assignedOrders: 0 })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }">
      <span class="inline-flex items-center gap-2.5">
        <span class="grid size-8 place-items-center rounded-full bg-gradient-to-br from-brand-300 to-brand-600 text-[11px] font-bold text-white">{{ initials(row.name) }}</span>
        <span class="font-semibold text-ink">{{ row.name }}</span>
      </span>
    </template>
    <template #cell-phone="{ row }"><a :href="`tel:${row.phone}`" class="text-brand-700 tabular hover:underline">{{ row.phone }}</a></template>
  </UiCrudPage>

  <UiModal v-model="handOpen" title="Receive COD cash" :description="handTarget ? `${handTarget.name} holds ${money(handTarget.cashHeld ?? 0)} from deliveries.` : ''" size="sm" :dismissible="!handing">
    <div class="space-y-3">
      <UiInput v-model.number="handAmount" type="number" label="Amount received (৳)" />
      <UiInput v-model="handNote" label="Note (optional)" placeholder="e.g. evening handover" />
      <p class="text-[12.5px] text-slate-500">The cash moves into Cash in hand. It cannot be more than the rider holds.</p>
    </div>
    <template #footer>
      <UiButton variant="ghost" :disabled="handing" @click="handOpen = false">Cancel</UiButton>
      <UiButton variant="primary" :loading="handing" :disabled="!(handAmount > 0)" @click="submitHandover">Record handover</UiButton>
    </template>
  </UiModal>
  </div>
</template>
