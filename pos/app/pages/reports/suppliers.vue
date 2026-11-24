<script setup lang="ts">
import { PackagePlus, FileText } from 'lucide-vue-next'
definePageMeta({ title: 'Supplier & due' })
const reports = useReportsApi()
function supplierActions(r: Record<string, any>) {
  if (!r.supplierId) return null
  return {
    view: '/suppliers',
    items: [
      { label: 'New purchase', icon: PackagePlus, to: `/purchases/new?supplier=${r.supplierId}` },
      { label: 'Purchase report', icon: FileText, to: '/reports/purchases' },
    ],
  }
}
</script>

<template>
  <UiReportPage
    title="Supplier & due"
    description="Purchases, returns and payments per supplier in the period, with the running balance from the supplier ledger."
    :filters="[{ key: 'due', label: 'Balances', options: [{ value: 'yes', label: 'With balance only' }] }]"
    :load="reports.suppliers" :row-actions="supplierActions"
  />
</template>
