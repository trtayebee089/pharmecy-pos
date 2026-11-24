<script setup lang="ts">
import { UserPlus, Download, Crown, HandCoins, ScanBarcode, FileText, Phone, Save } from 'lucide-vue-next'
import type { Column, Customer, RowAction } from '~/types/domain'

definePageMeta({ title: 'Customers' })
const api = useCustomersApi()
const newOpen = ref(false)
const { search, page, filters, result, loading, reload } = useList<Customer>(q => api.list(q), { due: false })
const view = computed({ get: () => (filters.value.due ? 'due' : 'all'), set: v => (filters.value.due = v === 'due') })

const columns: Column[] = [
  { key: 'name', label: 'Customer' },
  { key: 'phone', label: 'Phone' },
  { key: 'membership', label: 'Membership' },
  { key: 'points', label: 'Points', align: 'right' },
  { key: 'totalSpent', label: 'Lifetime spend', align: 'right' },
  { key: 'dueBalance', label: 'Due', align: 'right' },
  { key: 'lastVisit', label: 'Last visit' },
  { key: 'actions', label: 'Actions', align: 'right', width: '1%' },
]
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const actionsApi = useRowActionsApi()

// ---- Edit profile ----
const editOpen = ref(false)
const editing = reactive({ id: 0, name: '', phone: '', group: '' })
const editErrors = ref<Record<string, string>>({})
const saving = ref(false)
function startEdit(c: Customer) { Object.assign(editing, { id: c.id, name: c.name, phone: c.phone ?? '', group: c.group ?? '' }); editErrors.value = {}; editOpen.value = true }
async function saveEdit() {
  editErrors.value = {}
  if (editing.name.trim().length < 2) editErrors.value.name = 'Name is required'
  const phone = editing.phone ? normalizeBdPhone(editing.phone) : null
  if (editing.phone && !phone) editErrors.value.phone = 'Enter a valid Bangladeshi mobile (01XXXXXXXXX)'
  if (Object.keys(editErrors.value).length) return
  saving.value = true
  try { await actionsApi.updateCustomer(editing.id, { name: editing.name.trim(), phone, group: editing.group || null }); toast.success('Customer updated', editing.name); editOpen.value = false; reload() }
  catch (e: any) {
    if (e instanceof ApiError) for (const [k, v] of Object.entries(e.errors)) editErrors.value[k] = v[0] ?? ''
    else toast.error('Not saved', e?.message)
  }
  finally { saving.value = false }
}
async function deactivate(c: Customer) {
  const { ok } = await ask({ title: `Deactivate ${c.name}?`, description: c.dueBalance ? `This customer still owes ${money(c.dueBalance)}. Deactivating hides them from the counter but keeps their history and due.` : 'Customers are never deleted, because their invoices and points history must remain. They are hidden from the counter and search.', confirmLabel: 'Deactivate' })
  if (!ok) return
  try { await actionsApi.deactivateCustomer(c.id); toast.success('Customer deactivated', c.name); reload() }
  catch (e: any) { toast.error('Not deactivated', e?.message) }
}
const rowItems = (c: Customer): RowAction[] => [
  { label: 'Collect due', icon: HandCoins, to: '/customers/due', hidden: !c.dueBalance || !auth.can('payments.collect') },
  { label: 'New sale', icon: ScanBarcode, to: '/pos', hidden: !auth.can('pos.sell') },
  { label: 'Statement', icon: FileText, to: `/reports/customer?customer=${c.id}`, hidden: !auth.can('reports.customers') },
  { label: `Call ${c.phone ?? ''}`, icon: Phone, onClick: () => { window.location.href = `tel:${c.phone}` }, hidden: !c.phone },
]
function exportCsv() { downloadCsv('customers.csv', columns.filter(c => c.key !== 'actions'), result.value?.data ?? []) }
</script>

<template>
  <div>
    <UiPageHeader title="Customers" description="Patients and regular buyers: dues, loyalty points and membership.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <UiButton variant="primary" :icon="UserPlus" @click="newOpen = true">Add customer</UiButton>
    </UiPageHeader>
    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Name, phone, code…">
      <template #filters>
        <UiSegmented v-model="view" :options="[{ value: 'all', label: 'All' }, { value: 'due', label: 'With due' }]" />
      </template>
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <span class="grid size-9 place-items-center rounded-xl text-xs font-bold" :class="row.isWalkIn ? 'bg-slate-100 text-slate-500' : 'bg-brand-100 text-brand-700'">{{ initials(row.name) }}</span>
          <div><NuxtLink :to="`/customers/${row.id}`" class="font-semibold text-ink hover:text-brand-700 hover:underline">{{ row.name }}</NuxtLink><p class="text-xs text-slate-500">{{ row.code }}<template v-if="row.group"> · {{ row.group }}</template></p></div>
        </div>
      </template>
      <template #cell-phone="{ row }"><span class="text-slate-600 tabular">{{ row.phone ?? '—' }}</span></template>
      <template #cell-membership="{ row }"><UiBadge v-if="row.membership" :tone="row.membership === 'Gold' ? 'warning' : 'neutral'"><Crown class="size-3" />{{ row.membership }}</UiBadge><span v-else class="text-slate-400">—</span></template>
      <template #cell-points="{ row }"><span class="tabular">{{ num(row.points) }}</span></template>
      <template #cell-totalSpent="{ row }"><span class="tabular">{{ money(row.totalSpent) }}</span></template>
      <template #cell-dueBalance="{ row }">
        <NuxtLink v-if="row.dueBalance" to="/customers/due" class="inline-flex items-center gap-1 font-semibold text-amber-600 tabular hover:underline"><HandCoins class="size-3.5" />{{ money(row.dueBalance) }}</NuxtLink>
        <span v-else class="text-slate-400">—</span>
      </template>
      <template #cell-lastVisit="{ row }"><span class="text-slate-500">{{ date(row.lastVisit) }}</span></template>
      <template #cell-actions="{ row }">
        <UiRowActions
          :label="row.name" :view="`/customers/${row.id}`"
          :edit="!row.isWalkIn && auth.can('customers.manage') ? () => startEdit(row) : undefined"
          :remove="!row.isWalkIn && auth.can('customers.manage') ? () => deactivate(row) : undefined"
          :items="row.isWalkIn ? [] : rowItems(row)"
        />
      </template>
    </UiDataTable>

    <UiModal v-model="editOpen" title="Edit customer" description="Profile only. Due, points and membership come from their ledgers." size="sm" :dismissible="!saving">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <UiInput v-model="editing.name" label="Full name *" :error="editErrors.name" />
        <UiInput v-model="editing.phone" label="Mobile" placeholder="01XXXXXXXXX" inputmode="tel" :error="editErrors.phone" />
        <UiSelect v-model="editing.group" label="Customer group" :options="[{ value: '', label: 'None' }, { value: 'Regular patient', label: 'Regular patient' }, { value: 'Corporate', label: 'Corporate' }]" />
        <button type="submit" class="hidden" />
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="editOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Save" :loading="saving" @click="saveEdit">Save changes</UiButton>
      </template>
    </UiModal>
    <PosNewCustomerModal v-model="newOpen" @created="reload" />
  </div>
</template>
