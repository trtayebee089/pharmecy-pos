<script setup lang="ts">
import type { Component } from 'vue'

const model = defineModel<string | number>()
const props = defineProps<{
  label?: string
  placeholder?: string
  icon?: Component
  error?: string
  hint?: string
  type?: string
  kbd?: string
  size?: 'md' | 'lg'
  autocomplete?: string
  inputmode?: 'text' | 'numeric' | 'decimal' | 'search' | 'tel'
}>()
const input = ref<HTMLInputElement | null>(null)
const id = useId()
defineExpose({ focus: () => input.value?.focus(), select: () => input.value?.select(), el: input })
</script>

<template>
  <label :for="id" class="block">
    <span v-if="label" class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ label }}</span>
    <span
      class="group relative flex items-center rounded-xl border bg-white transition-[border-color,box-shadow] duration-200 focus-within:border-brand-400 focus-within:shadow-[0_0_0_4px_rgb(14_159_143/.12)]"
      :class="[error ? 'border-rose-300' : 'border-slate-200 hover:border-slate-300', props.size === 'lg' ? 'h-12' : 'h-10']"
    >
      <component :is="icon" v-if="icon" class="ml-3 size-[18px] shrink-0 text-slate-400 transition-colors group-focus-within:text-brand-600" />
      <input
        :id="id"
        ref="input"
        v-model="model"
        :type="type ?? 'text'"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-invalid="!!error"
        class="h-full w-full min-w-0 bg-transparent px-3 text-sm text-ink placeholder:text-slate-400 focus:outline-none"
        :class="props.size === 'lg' && 'text-[15px]'"
      >
      <UiKbd v-if="kbd" class="mr-2.5">{{ kbd }}</UiKbd>
      <slot name="suffix" />
    </span>
    <span v-if="error" class="mt-1.5 block text-xs font-medium text-rose-600">{{ error }}</span>
    <span v-else-if="hint" class="mt-1.5 block text-xs text-slate-500">{{ hint }}</span>
  </label>
</template>
