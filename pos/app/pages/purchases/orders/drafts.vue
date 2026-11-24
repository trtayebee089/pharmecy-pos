<script setup lang="ts">
import { Plus, Sparkles, Truck, ClipboardList, PackageOpen } from 'lucide-vue-next'
import type { Company, DraftSummary } from '~/types/purchase-orders'
import type { DateRange, RowAction } from '~/types/domain'

definePageMeta({ title: 'Draft orders' })
const api = usePurchaseOrdersApi()
const toast = useToast()
const { ask } = useConfirm()

const iso = (d: Date) => d.toISOString().slice(0, 10)
const range = ref<DateRange>({ from: iso(new Date(Date.now() - 29 * 864e5)), to: iso(new Date()) })
const companies = ref<Company[]>([])
const companyId = ref<number | ''>('')
const status = ref<'draft' | 'converted' | 'all'>('draft')
const rows = ref<DraftSummary[]>([])
const loading = ref(true)
const list = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  rows.value = await api.drafts({ from: range.value.from, to: range.value.to, companyId: companyId.value || undefined, status: status.value === 'all' ? undefined : status.value })
  loading.value = false
  await nextTick()
  revealStagger(list.value?.querySelectorAll('tr') ?? [], { y: 6, stagger: 0.03 })
}
onMounted(async () => { companies.value = await api.companies(); load() })
watch([() => range.value.from, () => range.value.to, companyId, status], load)

const auth = useAuthStore()
const rowItems = (d: DraftSummary): RowAction[] => [
  { label: 'Create purchase order', icon: Truck, to: `/purchases/orders/${d.id}`, hidden: d.status !== 'draft' || !auth.can('purchases.create') },
  { label: 'Receive goods now', icon: PackageOpen, to: `/purchases/new?draft=${d.id}`, hidden: !auth.can('purchases.create') },
  { label: 'Plan again for this company', icon: ClipboardList, to: `/purchases/orders?company=${d.companyId}` },
]
async function remove(d: DraftSummary) {
  const { ok } = await ask({ title: `Delete draft ${d.number}?`, description: `${d.company} · ${d.lines} lines. Drafts are planning only; nothing was ordered yet.`, confirmLabel: 'Delete draft' })
  if (!ok) return
  await api.deleteDraft(d.id)
  toast.success('Draft deleted')
  load()
}
const totals = computed(() => ({ pcs: rows.value.reduce((s, d) => s + d.orderQty, 0), value: rows.value.reduce((s, d) => s + d.estValue, 0) }))
</script>

<template>
  <div>
    <UiPageHeader title="Draft orders" description="Saved purchase plans per company and date. Open one to adjust quantities and send it to a supplier as a purchase order.">
      <NuxtLink to="/purchases/orders"><UiButton variant="primary" :icon="Plus">New order plan</UiButton></NuxtLink>
    </UiPageHeader>

    <div class="card mb-5 flex flex-wrap items-center gap-3 p-3">
      <UiDateRange v-model="range" />
      <select v-model="companyId" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium focus:border-brand-400 focus:outline-none" aria-label="Company">
        <option value="">All companies</option>
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <UiSegmented v-model="status" :options="[{ value: 'draft', label: 'Open' }, { value: 'converted', label: 'Ordered' }, { value: 'all', label: 'All' }]" />
    </div>

    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm">
          <thead><tr class="border-b border-slate-100 bg-slate-50/70">
            <th class="label h-10 px-4 text-left">Draft</th><th class="label px-3 text-left">Company</th><th class="label px-3 text-left">Date</th>
            <th class="label px-3 text-right">Lines</th><th class="label px-3 text-right">Order (pcs)</th><th class="label px-3 text-right">Est. value</th>
            <th class="label px-3 text-left">Status</th><th class="label px-4 text-right">Actions</th>
          </tr></thead>
          <tbody v-if="loading"><tr v-for="i in 5" :key="i"><td colspan="8" class="px-4 py-2"><div class="skeleton h-9" /></td></tr></tbody>
          <tbody v-else ref="list">
            <tr v-for="d in rows" :key="d.id" class="group border-b border-slate-50 hover:bg-brand-50/40">
              <td class="px-4 py-3">
                <NuxtLink :to="`/purchases/orders/${d.id}`" class="font-mono text-[12.5px] font-semibold hover:text-brand-700 hover:underline">{{ d.number }}</NuxtLink>
                <p class="flex items-center gap-1 text-xs text-slate-500"><Sparkles v-if="d.source === 'ai'" class="size-3 text-violet-500" />{{ d.createdBy }}</p>
              </td>
              <td class="px-3 font-medium">{{ d.company }}</td>
              <td class="px-3 text-slate-600">{{ date(d.date) }}</td>
              <td class="px-3 text-right tabular">{{ d.lines }}</td>
              <td class="px-3 text-right font-semibold tabular">{{ num(d.orderQty) }}</td>
              <td class="px-3 text-right tabular">{{ money(d.estValue) }}</td>
              <td class="px-3">
                <UiBadge v-if="d.status === 'converted'" tone="success" dot>Ordered · {{ d.purchaseNumber }}</UiBadge>
                <UiBadge v-else tone="info" dot>Draft</UiBadge>
              </td>
              <td class="pr-3">
                <UiRowActions
                  :label="d.number" :view="`/purchases/orders/${d.id}`"
                  :edit="d.status === 'draft' && auth.can('reorder.manage') ? `/purchases/orders/${d.id}` : undefined"
                  :remove="d.status === 'draft' && auth.can('reorder.manage') ? () => remove(d) : undefined"
                  :items="rowItems(d)"
                />
              </td>
            </tr>
          </tbody>
          <tfoot v-if="rows.length"><tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
            <td class="px-4 py-3" colspan="4">Total · {{ rows.length }} drafts</td>
            <td class="px-3 text-right tabular">{{ num(totals.pcs) }}</td><td class="px-3 text-right tabular">{{ money(totals.value) }}</td><td colspan="2" />
          </tr></tfoot>
        </table>
        <UiEmptyState v-if="!loading && !rows.length" title="No draft orders" description="Plan an order from the Purchase orders screen or let AI draft one for every company." />
      </div>
    </div>
  </div>
</template>
