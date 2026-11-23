<script setup lang="ts">
import type { Component } from 'vue'
import { TrendingUp, TrendingDown } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  label: string
  value: number
  format?: 'money' | 'number'
  delta?: number
  hint?: string
  icon?: Component
  tone?: 'brand' | 'amber' | 'rose' | 'sky' | 'violet'
  loading?: boolean
}>(), { format: 'money', tone: 'brand' })

const card = ref<HTMLElement | null>(null)
const valueEl = ref<HTMLElement | null>(null)
useSpotlight(card)

const fmt = (n: number) => props.format === 'money' ? money(n) : num(Math.round(n))
watch(() => [props.value, props.loading] as const, ([v, l]) => {
  if (!l) nextTick(() => valueEl.value && countUp(valueEl.value, v, fmt))
}, { immediate: true })

const tones: Record<string, string> = {
  brand: 'from-brand-500/15 to-brand-500/0 text-brand-600',
  amber: 'from-amber-500/15 to-amber-500/0 text-amber-600',
  rose: 'from-rose-500/15 to-rose-500/0 text-rose-600',
  sky: 'from-sky-500/15 to-sky-500/0 text-sky-600',
  violet: 'from-violet-500/15 to-violet-500/0 text-violet-600',
}
</script>

<template>
  <div ref="card" class="card spotlight group overflow-hidden p-5 transition-shadow duration-300 hover:shadow-[var(--shadow-lift)]">
    <div class="flex items-start justify-between">
      <p class="label">{{ label }}</p>
      <span v-if="icon" class="grid size-9 place-items-center rounded-xl bg-gradient-to-br transition-transform duration-500 group-hover:scale-110 group-hover:rotate-[-6deg]" :class="tones[tone]">
        <component :is="icon" class="size-[18px]" />
      </span>
    </div>
    <div v-if="loading" class="skeleton mt-3 h-8 w-36" />
    <p v-else ref="valueEl" class="mt-2 font-display text-[26px] leading-tight font-extrabold tracking-tight text-ink tabular">{{ fmt(0) }}</p>
    <div class="mt-2 flex items-center gap-2 text-xs">
      <span v-if="delta !== undefined && !loading" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold" :class="delta >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">
        <component :is="delta >= 0 ? TrendingUp : TrendingDown" class="size-3" />
        {{ Math.abs(delta).toFixed(1) }}%
      </span>
      <span v-if="hint" class="text-slate-500">{{ hint }}</span>
    </div>
  </div>
</template>
