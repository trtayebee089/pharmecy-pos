<script setup lang="ts">
definePageMeta({ title: 'Payments' })
const reports = useReportsApi()
function paymentActions(r: Record<string, any>) {
  if (!r.docId) return null
  return { view: r.docType === 'purchase' ? `/purchases/${r.docId}` : `/sales/${r.docId}` }
}
</script>

<template>
  <UiReportPage
    title="Payments"
    description="Money received from customers and paid to suppliers, by method and account. The total row shows net cash flow."
    :filters="[
      { key: 'direction', label: 'Directions', options: [{ value: 'in', label: 'Received' }, { value: 'out', label: 'Paid out' }] },
      { key: 'method', label: 'Methods', options: [{ value: 'cash', label: 'Cash' }, { value: 'bkash', label: 'bKash' }, { value: 'nagad', label: 'Nagad' }, { value: 'card', label: 'Card' }, { value: 'bank', label: 'Bank' }] },
    ]"
    :load="reports.payments" :row-actions="paymentActions"
  />
</template>
