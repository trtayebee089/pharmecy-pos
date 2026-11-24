<script setup lang="ts">
import { PackagePlus, SlidersHorizontal } from 'lucide-vue-next'
const auth = useAuthStore()
definePageMeta({ title: 'Stock & valuation' })
const reports = useReportsApi()
const opts = ref<{ manufacturers: string[]; forms: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
const toOpts = (xs: string[]) => xs.map(x => ({ value: x, label: x }))
function stockActions(r: Record<string, any>) {
  if (!r.productId) return null
  return {
    view: `/products/${r.productId}`,
    items: [
      { label: 'Reorder', icon: PackagePlus, to: '/purchases/new', hidden: r.status === 'ok' },
      { label: 'Adjust stock', icon: SlidersHorizontal, to: '/inventory/adjustments', hidden: !auth.can('inventory.adjust') },
    ],
  }
}
</script>

<template>
  <UiReportPage
    v-if="opts"
    title="Stock & valuation"
    description="Sellable stock per product, valued at batch cost and at current retail price. Expired batches are not counted as sellable."
    :filters="[{ key: 'manufacturer', label: 'Manufacturers', options: toOpts(opts.manufacturers) }, { key: 'form', label: 'Dosage forms', options: toOpts(opts.forms) }]"
    :load="reports.stock" :row-actions="stockActions"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
