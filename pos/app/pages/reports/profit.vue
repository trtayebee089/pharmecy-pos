<script setup lang="ts">
definePageMeta({ title: 'Profit & loss' })
const reports = useReportsApi()
</script>

<template>
  <UiReportPage
    title="Profit & loss"
    description="Revenue against the actual batch cost of what was sold (COGS), less posted expenses. Voided sales and returns are excluded."
    :filters="[{ key: 'view', label: 'Breakdowns', options: [{ value: 'day', label: 'By day' }, { value: 'product', label: 'By product' }] }]"
    :load="reports.profit"
  />
</template>
