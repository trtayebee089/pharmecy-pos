<script setup lang="ts">
withDefaults(defineProps<{
  tone?: 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'violet'
  dot?: boolean
  size?: 'sm' | 'md'
}>(), { tone: 'neutral', size: 'sm' })

const tones: Record<string, string> = {
  neutral: 'bg-slate-100 text-slate-600 ring-slate-200',
  brand: 'bg-brand-50 text-brand-700 ring-brand-200',
  success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-700 ring-amber-200',
  danger: 'bg-rose-50 text-rose-700 ring-rose-200',
  info: 'bg-sky-50 text-sky-700 ring-sky-200',
  violet: 'bg-violet-50 text-violet-700 ring-violet-200',
}
const dots: Record<string, string> = {
  neutral: 'bg-slate-400', brand: 'bg-brand-500', success: 'bg-emerald-500', warning: 'bg-amber-500',
  danger: 'bg-rose-500', info: 'bg-sky-500', violet: 'bg-violet-500',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-semibold ring-1 ring-inset"
    :class="[tones[tone], size === 'sm' ? 'h-[22px] px-2 text-[11px]' : 'h-7 px-2.5 text-xs']"
  >
    <span v-if="dot" class="size-1.5 rounded-full" :class="dots[tone]" />
    <slot />
  </span>
</template>
