<script setup lang="ts">
definePageMeta({ title: 'Expiry report' })
const reports = useReportsApi()
const opts = ref<{ manufacturers: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
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
    :load="reports.expiry"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
