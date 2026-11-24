<script setup lang="ts">
import { UserRound, ChevronRight } from 'lucide-vue-next'

/** Small link row that opens the full customer profile. */
defineProps<{ customerId: number }>()
</script>

<template>
  <NuxtLink :to="`/customers/${customerId}`" class="flex items-center gap-3 text-sm font-semibold text-brand-700 hover:underline">
    <UserRound class="size-4" />View full customer profile, purchases and ledger
    <ChevronRight class="ml-auto size-4" />
  </NuxtLink>
</template>
