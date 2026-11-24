<script setup lang="ts">
import type { CrudColumn } from '~/types/domain'

/** Renders a table value according to its column format. */
const props = defineProps<{ value: any; column: CrudColumn }>()
const tone = computed(() => props.column.tones?.[String(props.value)] ?? 'neutral')
</script>

<template>
  <span v-if="value === null || value === undefined || value === ''" class="text-slate-400">—</span>
  <span v-else-if="column.format === 'money'" class="tabular" :class="value < 0 ? 'text-rose-600' : ''">{{ money(value) }}</span>
  <span v-else-if="column.format === 'number'" class="tabular">{{ num(value) }}</span>
  <span v-else-if="column.format === 'percent'" class="tabular">{{ Number(value).toFixed(1) }}%</span>
  <span v-else-if="column.format === 'date'" class="text-slate-600">{{ date(value) }}</span>
  <span v-else-if="column.format === 'datetime'" class="text-slate-600">{{ dateTime(value) }}</span>
  <UiBadge v-else-if="column.format === 'boolean'" :tone="value ? 'success' : 'neutral'" dot>{{ value ? 'Yes' : 'No' }}</UiBadge>
  <UiBadge v-else-if="column.format === 'badge'" :tone="tone" dot class="capitalize">{{ String(value).replace(/_/g, ' ') }}</UiBadge>
  <span v-else-if="column.format === 'mono'" class="font-mono text-[12.5px] font-semibold">{{ value }}</span>
  <span v-else>{{ value }}</span>
</template>
