<script setup lang="ts">
import { ClipboardList, Play, Printer, ClipboardCheck } from 'lucide-vue-next'
import type { Column, RowAction } from '~/types/domain'
import type { CountSession } from '~/types/inventory'
import type { StartCountPayload } from '~/services/inventory'

definePageMeta({ title: 'Stock counts' })
const api = useCountsApi()
const toast = useToast()
const auth = useAuthStore()
const { ask } = useConfirm()
const { search, page, filters, result, loading, reload } = useList<CountSession>(q => api.list(q), { status: '' })
const status = computed({ get: () => filters.value.status || 'all', set: v => (filters.value.status = v === 'all' ? '' : v) })

const columns: Column[] = [
  { key: 'number', label: 'Count' },
  { key: 'scope', label: 'Scope' },
  { key: 'startedAt', label: 'Started' },
  { key: 'progress', label: 'Progress', width: '180px' },
  { key: 'varianceValue', label: 'Variance', align: 'right' },
  { key: 'status', label: 'Status' },
  { key: 'go', label: 'Actions', align: 'right', width: '1%' },
]
const isOpen = (s: CountSession) => s.status === 'open' || s.status === 'counting'
async function cancelCount(s: CountSession) {
  const { ok, reason } = await ask({ title: `Cancel ${s.number}?`, description: 'Counted quantities are discarded and no stock changes. The session stays on record as cancelled.', confirmLabel: 'Cancel count', requireReason: true })
  if (!ok) return
  try { await api.cancel(s.id, reason); toast.success('Count cancelled', s.number); reload() }
  catch (e: any) { toast.error('Not cancelled', e?.message) }
}
const rowItems = (s: CountSession): RowAction[] => [
  { label: 'Continue counting', icon: ClipboardCheck, to: `/inventory/counts/${s.id}`, hidden: !isOpen(s) },
  { label: 'Print count sheet', icon: Printer, to: `/inventory/counts/${s.id}?print=1` },
]
const tone = { open: 'info', counting: 'brand', posted: 'success', cancelled: 'neutral' } as const

const open = ref(false)
const form = reactive({ name: '', scope: 'all', value: '' })
const starting = ref(false)
const scopes = [
  { value: 'all', label: 'All products' },
  { value: 'dosage', label: 'One dosage form' },
  { value: 'manufacturer', label: 'One manufacturer' },
  { value: 'category', label: 'One category' },
]
async function start() {
  if (!form.name.trim()) { toast.warning('Give the count a name'); return }
  if (form.scope !== 'all' && !form.value.trim()) { toast.warning('Enter the scope value', 'e.g. Tablet, Square Pharmaceuticals…'); return }
  starting.value = true
  try {
    const s = await api.start({ warehouseId: auth.warehouseId, name: form.name, scopeType: form.scope as StartCountPayload['scopeType'], scopeValue: form.value }, crypto.randomUUID())
    open.value = false
    await navigateTo(`/inventory/counts/${s.id}`)
  }
  catch (e: any) { toast.error('Could not start count', e?.message) }
  finally { starting.value = false }
}
</script>

<template>
  <div>
    <UiPageHeader title="Stock counts" description="Count physical stock per batch. Posting records the variances as stock movements; system quantities are never typed over.">
      <UiButton variant="primary" :icon="Play" @click="open = true">Start count</UiButton>
    </UiPageHeader>

    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Count number, name, scope…">
      <template #filters>
        <UiSegmented v-model="status" :options="[{ value: 'all', label: 'All' }, { value: 'counting', label: 'In progress' }, { value: 'posted', label: 'Posted' }, { value: 'cancelled', label: 'Cancelled' }]" />
      </template>
      <template #cell-number="{ row }">
        <NuxtLink :to="`/inventory/counts/${row.id}`" class="block">
          <p class="font-semibold text-ink hover:text-brand-700">{{ row.name }}</p>
          <p class="font-mono text-xs text-slate-500">{{ row.number }}</p>
        </NuxtLink>
      </template>
      <template #cell-scope="{ row }"><span class="text-slate-600">{{ row.scope }}</span></template>
      <template #cell-startedAt="{ row }"><span class="text-slate-600">{{ dateTime(row.startedAt) }}</span><p class="text-xs text-slate-400">by {{ row.startedBy }}</p></template>
      <template #cell-progress="{ row }">
        <div class="flex items-center gap-2">
          <div class="h-1.5 w-28 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${row.lines ? (row.counted / row.lines) * 100 : 0}%` }" /></div>
          <span class="text-xs text-slate-500 tabular">{{ row.counted }}/{{ row.lines }}</span>
        </div>
      </template>
      <template #cell-varianceValue="{ row }">
        <span class="tabular" :class="row.varianceValue < 0 ? 'font-semibold text-rose-600' : row.varianceValue > 0 ? 'font-semibold text-emerald-600' : 'text-slate-400'">{{ row.varianceValue ? money(row.varianceValue) : '—' }}</span>
      </template>
      <template #cell-status="{ row }"><UiBadge :tone="tone[row.status as keyof typeof tone]" dot class="capitalize">{{ row.status === 'counting' ? 'In progress' : row.status }}</UiBadge></template>
      <template #cell-go="{ row }">
        <UiRowActions
          :label="row.number" :view="`/inventory/counts/${row.id}`"
          :edit="isOpen(row) && auth.can('inventory.count') ? `/inventory/counts/${row.id}` : undefined"
          remove-kind="void" :remove="isOpen(row) && auth.can('inventory.count') ? () => cancelCount(row) : undefined"
          :items="rowItems(row)"
        />
      </template>
    </UiDataTable>

    <UiModal v-model="open" title="Start a stock count" description="The system snapshot is taken per batch when the count starts." :dismissible="!starting">
      <div class="grid gap-4">
        <UiInput v-model="form.name" label="Name" placeholder="e.g. Monthly count · Tablets" :icon="ClipboardList" />
        <UiSelect v-model="form.scope" label="What to count" :options="scopes" />
        <UiInput v-if="form.scope !== 'all'" v-model="form.value" :label="scopes.find(s => s.value === form.scope)?.label.replace('One ', '') ?? ''" placeholder="Type the name" />
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Play" :loading="starting" @click="start">Start counting</UiButton>
      </template>
    </UiModal>
  </div>
</template>
