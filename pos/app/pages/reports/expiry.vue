<script setup lang="ts">
import { Trash2, Undo2, Layers } from 'lucide-vue-next'
const auth = useAuthStore()
definePageMeta({ title: 'Expiry report' })
const reports = useReportsApi()
const opts = ref<{ manufacturers: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
function batchActions(r: Record<string, any>) {
  if (!r.productId) return null
  return {
    view: `/products/${r.productId}`,
    items: [
      { label: 'Write off', icon: Trash2, danger: true, to: `/inventory/adjustments?reason=expiry&product=${r.productId}&batch=${r.batchId}`, hidden: r.bucket !== 'expired' || !auth.can('inventory.adjust') },
      { label: 'Return to supplier', icon: Undo2, to: '/purchases/returns' },
      { label: 'Batch list', icon: Layers, to: '/inventory/batches' },
    ],
  }
}
</script>

<template>
  <UiReportPage
    v-if="opts"
    title="Expiry report"
    description="Batches that are expired or expiring soon, with the stock value at risk. Expired batches are blocked from sale; return them to the supplier or write them off."
    :filters="[
      { key: 'window', label: 'Windows', options: [{ value: 'expired', label: 'Expired' }, { value: 'critical', label: '≤ 30 days' }, { value: 'soon', label: '31–90 days' }] },
      { key: 'manufacturer', label: 'Manufacturers', options: opts.manufacturers.map(x => ({ value: x, label: x })) },
    ]"
    :load="reports.expiry" :row-actions="batchActions"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
