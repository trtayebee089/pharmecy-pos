<script setup lang="ts">
definePageMeta({ title: 'Supplier & due' })
const reports = useReportsApi()
</script>

<template>
  <UiReportPage
    title="Supplier & due"
    description="Purchases, returns and payments per supplier in the period, with the running balance from the supplier ledger."
    :filters="[{ key: 'due', label: 'Balances', options: [{ value: 'yes', label: 'With balance only' }] }]"
    :load="reports.suppliers"
  />
</template>
