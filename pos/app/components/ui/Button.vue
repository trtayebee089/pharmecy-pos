<script setup lang="ts">
import type { Component } from 'vue'
import { Loader2 } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'soft'
  size?: 'sm' | 'md' | 'lg' | 'xl'
  icon?: Component
  iconRight?: Component
  loading?: boolean
  disabled?: boolean
  block?: boolean
  type?: 'button' | 'submit'
  magnetic?: boolean
}>(), { variant: 'secondary', size: 'md', type: 'button' })

const el = ref<HTMLElement | null>(null)
if (props.magnetic) useMagnetic(el, 0.18)

const variants: Record<string, string> = {
  primary: 'text-white bg-gradient-to-b from-brand-500 to-brand-700 shadow-[inset_0_1px_0_rgb(255_255_255/.18),0_1px_2px_rgb(2_40_38/.3)] hover:shadow-[var(--shadow-glow)] hover:from-brand-500 hover:to-brand-600',
  secondary: 'bg-white text-slate-700 border border-slate-200 shadow-xs hover:bg-slate-50 hover:border-slate-300',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  soft: 'bg-brand-50 text-brand-700 hover:bg-brand-100',
  danger: 'text-white bg-gradient-to-b from-rose-500 to-rose-600 hover:shadow-[0_8px_24px_-6px_rgb(244_63_94/.55)]',
}
const sizes: Record<string, string> = {
  sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-lg',
  md: 'h-10 px-4 text-sm gap-2 rounded-xl',
  lg: 'h-12 px-5 text-[15px] gap-2 rounded-xl',
  xl: 'h-14 px-6 text-base gap-2.5 rounded-2xl',
}
</script>

<template>
  <button
    ref="el"
    :type="type"
    :disabled="disabled || loading"
    class="relative inline-flex select-none items-center justify-center font-semibold whitespace-nowrap transition-[background,box-shadow,color,border-color,transform] duration-200 active:scale-[.97] disabled:pointer-events-none disabled:opacity-50"
    :class="[variants[variant], sizes[size], block && 'w-full']"
  >
    <Loader2 v-if="loading" class="size-4 animate-spin" />
    <component :is="icon" v-else-if="icon" class="size-4 shrink-0" />
    <slot />
    <component :is="iconRight" v-if="iconRight" class="size-4 shrink-0" />
  </button>
</template>
