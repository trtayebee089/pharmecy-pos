<script setup lang="ts">
definePageMeta({ title: 'Expenses' })
</script>

<template>
  <FinanceEntriesPage kind="expenses" permission="expenses.view" />
</template>
