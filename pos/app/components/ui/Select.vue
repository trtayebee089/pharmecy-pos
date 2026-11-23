<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next'

const model = defineModel<string | number | null>()
defineProps<{
  label?: string
  options: { value: string | number | null; label: string }[]
  placeholder?: string
  error?: string
  hint?: string
}>()
const id = useId()
</script>

<template>
  <label :for="id" class="block">
    <span v-if="label" class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ label }}</span>
    <span class="relative block">
      <select
        :id="id" v-model="model"
        class="h-10 w-full appearance-none rounded-xl border bg-white pr-9 pl-3 text-sm text-ink transition-[border-color,box-shadow] focus:border-brand-400 focus:shadow-[0_0_0_4px_rgb(14_159_143/.12)] focus:outline-none"
        :class="error ? 'border-rose-300' : 'border-slate-200 hover:border-slate-300'"
      >
        <option v-if="placeholder" :value="null" disabled>{{ placeholder }}</option>
        <option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
      </select>
      <ChevronDown class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-400" />
    </span>
    <span v-if="error" class="mt-1.5 block text-xs font-medium text-rose-600">{{ error }}</span>
    <span v-else-if="hint" class="mt-1.5 block text-xs text-slate-500">{{ hint }}</span>
  </label>
</template>
