<script setup lang="ts">
definePageMeta({ title: 'Cashier & register' })
const reports = useReportsApi()
</script>

<template>
  <UiReportPage
    title="Cashier & register"
    description="Per cashier: invoices, sales, voids and returns, plus register sessions and the cash over/short recorded at close."
    :filters="[{ key: 'register', label: 'Registers', options: [{ value: 'Counter 1', label: 'Counter 1' }, { value: 'Counter 2', label: 'Counter 2' }, { value: 'Back office', label: 'Back office' }] }]"
    :load="reports.cashiers"
  />
</template>
