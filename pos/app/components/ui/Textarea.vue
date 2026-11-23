<script setup lang="ts">
const model = defineModel<string>()
defineProps<{ label?: string; placeholder?: string; rows?: number; hint?: string }>()
const id = useId()
</script>

<template>
  <label :for="id" class="block">
    <span v-if="label" class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ label }}</span>
    <textarea
      :id="id" v-model="model" :rows="rows ?? 3" :placeholder="placeholder"
      class="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-ink transition-[border-color,box-shadow] placeholder:text-slate-400 hover:border-slate-300 focus:border-brand-400 focus:shadow-[0_0_0_4px_rgb(14_159_143/.12)] focus:outline-none"
    />
    <span v-if="hint" class="mt-1.5 block text-xs text-slate-500">{{ hint }}</span>
  </label>
</template>
