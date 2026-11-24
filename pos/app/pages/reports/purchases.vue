<script setup lang="ts">
import { Undo2 } from 'lucide-vue-next'
definePageMeta({ title: 'Purchase report' })
const reports = useReportsApi()
const opts = ref<{ suppliers: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
function purchaseActions(r: Record<string, any>) {
  if (!r.id) return null // supplier grouping rows are aggregates
  return { view: `/purchases/${r.id}`, items: [{ label: 'Purchase return', icon: Undo2, to: '/purchases/returns' }] }
}
</script>

<template>
  <UiReportPage
    v-if="opts"
    title="Purchase report"
    description="Received purchases in the period, per invoice or grouped by supplier. Drafts and voided purchases are excluded."
    :filters="[
      { key: 'view', label: 'Groupings', options: [{ value: 'invoice', label: 'By invoice' }, { value: 'supplier', label: 'By supplier' }] },
      { key: 'supplier', label: 'Suppliers', options: opts.suppliers.map(x => ({ value: x, label: x })) },
    ]"
    :load="reports.purchases" :row-actions="purchaseActions"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
