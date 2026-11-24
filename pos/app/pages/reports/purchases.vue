<script setup lang="ts">
definePageMeta({ title: 'Purchase report' })
const reports = useReportsApi()
const opts = ref<{ suppliers: string[] } | null>(null)
onMounted(async () => { opts.value = await reports.options() })
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
    :load="reports.purchases"
  />
  <div v-else class="space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /></div>
</template>
