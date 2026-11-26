<script setup lang="ts">
import { Phone, Kanban, UserCheck, UserX, HandCoins, KeyRound, ShieldOff, MapPinned, Smartphone } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { Rider } from '~/types/orders'

definePageMeta({ title: 'Riders' })
const resource = useRidersResource()
const toast = useToast()
const auth = useAuthStore()
const { ask } = useConfirm()
const crud = ref<{ reload: () => void } | null>(null)
/** The API validates every profile field on save, so partial updates send the whole profile. */
const profile = (r: Rider) => ({ id: r.id, name: r.name, phone: r.phone, nid: r.nid, status: r.status })
async function setStatus(r: Rider, status: Rider['status']) {
  try { await resource.save({ ...profile(r), status }); toast.success(`${r.name} is now ${status.replace('_', ' ')}`); crud.value?.reload() }
  catch (e: any) { toast.error('Status not changed', e?.message) }
}

// ---- Rider app access: staff set the password; a new password or revoking signs the app out ----
const accessTarget = ref<Rider | null>(null)
const accessPassword = ref('')
const accessError = ref('')
const accessSaving = ref(false)
const accessOpen = computed({ get: () => !!accessTarget.value, set: (v) => { if (!v) accessTarget.value = null } })
function startAccess(r: Rider) { accessTarget.value = r; accessPassword.value = ''; accessError.value = '' }
async function saveAccess() {
  const r = accessTarget.value
  if (!r) return
  if (accessPassword.value.length < 6) { accessError.value = 'At least 6 characters'; return }
  accessSaving.value = true
  try {
    await resource.save({ ...profile(r), password: accessPassword.value })
    toast.success(r.appAccess ? 'App password replaced' : 'App access given', `${r.name} signs in with ${r.phone}${r.appAccess ? '; other devices were signed out' : ''}`)
    accessTarget.value = null
    crud.value?.reload()
  }
  catch (e: any) { accessError.value = e?.errors?.password?.[0] ?? ''; toast.error('Password not saved', e?.message) }
  finally { accessSaving.value = false }
}
function revokeFromModal() { const r = accessTarget.value; accessTarget.value = null; if (r) revokeAccess(r) }
async function revokeAccess(r: Rider) {
  const { ok } = await ask({ title: `Revoke app access for ${r.name}?`, description: 'The rider app signs out on every device and the rider goes off duty. Set a new password to give access again.', confirmLabel: 'Revoke access' })
  if (!ok) return
  try { await resource.save({ ...profile(r), revokeAppAccess: true }); toast.success('App access revoked', r.name); crud.value?.reload() }
  catch (e: any) { toast.error('Access not revoked', e?.message) }
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
  { label: r.appAccess ? 'Replace app password' : 'Give app access', icon: KeyRound, onClick: () => startAccess(r), hidden: !auth.can('riders.manage') },
  { label: 'Revoke app access', icon: ShieldOff, danger: true, onClick: () => revokeAccess(r), hidden: !r.appAccess || !auth.can('riders.manage') },
  { label: 'Live map', icon: MapPinned, to: '/delivery/live', hidden: !auth.can('orders.view') },
]

const columns: CrudColumn[] = [
  { key: 'name', label: 'Rider' },
  { key: 'phone', label: 'Phone' },
  { key: 'nid', label: 'NID', format: 'mono' },
  { key: 'assignedOrders', label: 'Active orders', align: 'right', format: 'number' },
  { key: 'cashHeld', label: 'Cash held', align: 'right', format: 'money' },
  { key: 'appAccess', label: 'App access', format: 'boolean' },
  { key: 'lastLoginAt', label: 'Last sign-in', format: 'datetime' },
  { key: 'onDuty', label: 'On duty', format: 'boolean' },
  { key: 'deliveries30d', label: 'Deliveries (30 d)', align: 'right', format: 'number' },
  { key: 'avgDeliveryMinutes30d', label: 'Avg. delivery', align: 'right' },
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
    :defaults="() => ({ name: '', phone: '', nid: '', status: 'active' as const, assignedOrders: 0, password: '' })" :item-label="r => r.name"
  >
    <template #cell-name="{ row }">
      <span class="inline-flex items-center gap-2.5">
        <span class="grid size-8 place-items-center rounded-full bg-gradient-to-br from-brand-300 to-brand-600 text-[11px] font-bold text-white">{{ initials(row.name) }}</span>
        <span class="font-semibold text-ink">{{ row.name }}</span>
      </span>
    </template>
    <template #cell-phone="{ row }"><a :href="`tel:${row.phone}`" class="text-brand-700 tabular hover:underline">{{ row.phone }}</a></template>
    <template #cell-appAccess="{ row }">
      <UiBadge :tone="row.appAccess ? 'success' : 'neutral'" dot><Smartphone class="size-3" />{{ row.appAccess ? 'Can sign in' : 'No access' }}</UiBadge>
    </template>
    <template #cell-lastLoginAt="{ row }"><span class="whitespace-nowrap text-slate-600">{{ row.lastLoginAt ? timeAgo(row.lastLoginAt) : 'Never' }}</span></template>
    <template #cell-onDuty="{ row }">
      <span class="inline-flex flex-col leading-tight">
        <UiBadge :tone="row.onDuty ? (row.position?.is_live ? 'success' : 'warning') : 'neutral'" dot>{{ row.onDuty ? 'On duty' : 'Off duty' }}</UiBadge>
        <span class="mt-0.5 whitespace-nowrap text-[11.5px] text-slate-500" :title="row.position?.updated_at ? dateTime(row.position.updated_at) : ''">
          {{ row.position?.updated_at ? `seen ${timeAgo(row.position.updated_at)}` : 'no position yet' }}
        </span>
      </span>
    </template>
    <template #cell-avgDeliveryMinutes30d="{ row }">
      <span v-if="row.avgDeliveryMinutes30d != null" class="tabular">{{ row.avgDeliveryMinutes30d }} min</span>
      <span v-else class="text-slate-400">—</span>
    </template>
    <template #form-extra="{ form }">
      <div v-if="!form.id" class="sm:col-span-2">
        <UiInput v-model="form.password" type="password" autocomplete="new-password" label="Rider app password (optional)" placeholder="At least 6 characters" :error="form.password && form.password.length < 6 ? 'At least 6 characters' : undefined" hint="The rider signs in to the rider app with the mobile number and this password. You can set it later." />
      </div>
    </template>
  </UiCrudPage>

  <UiModal v-model="accessOpen" :title="accessTarget?.appAccess ? 'Replace app password' : 'Give app access'" :description="accessTarget ? `${accessTarget.name} signs in to the rider app with ${accessTarget.phone} and this password.` : ''" size="sm" :dismissible="!accessSaving">
    <form class="space-y-3" @submit.prevent="saveAccess">
      <UiInput v-model="accessPassword" type="password" autocomplete="new-password" label="New password" placeholder="At least 6 characters" :error="accessError || undefined" />
      <p class="text-[12.5px] text-slate-500">
        Give it to the rider in person. It is stored encrypted and never shown again.
        <template v-if="accessTarget?.appAccess"> The rider app signs out on every device that used the old password.</template>
      </p>
      <p v-if="accessTarget?.lastLoginAt" class="text-[12.5px] text-slate-500">Last sign-in {{ dateTime(accessTarget.lastLoginAt) }}</p>
      <button type="submit" class="hidden" />
    </form>
    <template #footer>
      <UiButton v-if="accessTarget?.appAccess" variant="ghost" class="mr-auto !text-rose-600" :icon="ShieldOff" :disabled="accessSaving" @click="revokeFromModal">Revoke access</UiButton>
      <UiButton variant="ghost" :disabled="accessSaving" @click="accessOpen = false">Cancel</UiButton>
      <UiButton variant="primary" :loading="accessSaving" :disabled="accessPassword.length < 6" @click="saveAccess">Save password</UiButton>
    </template>
  </UiModal>

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
