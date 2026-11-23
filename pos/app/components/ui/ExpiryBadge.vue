<script setup lang="ts">
const props = defineProps<{ date: string }>()
const state = computed(() => expiryState(props.date))
const days = computed(() => daysUntil(props.date))
const tone = computed(() => ({ expired: 'danger', critical: 'danger', soon: 'warning', ok: 'success' } as const)[state.value])
const text = computed(() => state.value === 'expired' ? `Expired ${-days.value}d ago` : `${date(props.date, { month: 'short', year: 'numeric' })} · ${days.value}d`)
</script>

<template>
  <UiBadge :tone="tone" dot>{{ text }}</UiBadge>
</template>
