<script setup lang="ts">
definePageMeta({ title: 'Stock & valuation' })
const reports = useReportsApi()
const opts = ref<{ manufacturers: string[]; forms: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
const toOpts = (xs: string[]) => xs.map(x => ({ value: x, label: x }))
</script>

<template>
  <UiReportPage
    v-if="opts"
    title="Stock & valuation"
    description="Sellable stock per product, valued at batch cost and at current retail price. Expired batches are not counted as sellable."
    :filters="[{ key: 'manufacturer', label: 'Manufacturers', options: toOpts(opts.manufacturers) }, { key: 'form', label: 'Dosage forms', options: toOpts(opts.forms) }]"
    :load="reports.stock"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
