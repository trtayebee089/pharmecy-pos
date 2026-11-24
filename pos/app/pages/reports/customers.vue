<script setup lang="ts">
definePageMeta({ title: 'Customer & due' })
const reports = useReportsApi()
</script>

<template>
  <UiReportPage
    title="Customer & due"
    description="Named customers with spend in the period, lifetime value, loyalty points and outstanding due from the customer ledger."
    :filters="[
      { key: 'membership', label: 'Memberships', options: [{ value: 'Gold', label: 'Gold' }, { value: 'Silver', label: 'Silver' }, { value: 'none', label: 'No membership' }] },
      { key: 'due', label: 'Due states', options: [{ value: 'yes', label: 'With due only' }] },
    ]"
    :load="reports.customers"
  />
</template>
