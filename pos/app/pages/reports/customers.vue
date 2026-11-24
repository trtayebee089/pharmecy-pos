<script setup lang="ts">
import { HandCoins, Star } from 'lucide-vue-next'
definePageMeta({ title: 'Customer & due' })
const reports = useReportsApi()
function customerActions(r: Record<string, any>) {
  if (!r.customerId) return null
  return {
    view: `/customers/${r.customerId}`,
    items: [
      { label: 'Collect due', icon: HandCoins, to: '/customers/due', hidden: !(r.due > 0) },
      { label: 'Rewards wallet', icon: Star, to: '/loyalty/rewards' },
    ],
  }
}
</script>

<template>
  <UiReportPage
    title="Customer & due"
    description="Named customers with spend in the period, lifetime value, loyalty points and outstanding due from the customer ledger."
    :filters="[
      { key: 'membership', label: 'Memberships', options: [{ value: 'Gold', label: 'Gold' }, { value: 'Silver', label: 'Silver' }, { value: 'none', label: 'No membership' }] },
      { key: 'due', label: 'Due states', options: [{ value: 'yes', label: 'With due only' }] },
    ]"
    :load="reports.customers" :row-actions="customerActions"
  />
</template>
