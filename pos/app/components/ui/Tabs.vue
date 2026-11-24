<script setup lang="ts" generic="T extends string | number">
import { gsap } from 'gsap'

const model = defineModel<T>({ required: true })
const props = defineProps<{ tabs: { value: T; label: string; count?: number }[] }>()
const wrap = ref<HTMLElement | null>(null)
const bar = ref<HTMLElement | null>(null)

function move(animate = true) {
  const btn = wrap.value?.querySelector<HTMLElement>(`[data-tab="${String(model.value)}"]`)
  if (!btn || !bar.value) return
  const vars = { x: btn.offsetLeft, width: btn.offsetWidth }
  animate && !prefersReducedMotion() ? gsap.to(bar.value, { ...vars, duration: 0.45, ease: 'expo.out' }) : gsap.set(bar.value, vars)
}
watch(model, () => move())
onMounted(() => nextTick(() => move(false)))
useResizeObserver(wrap, () => move(false))
</script>

<template>
  <div ref="wrap" class="relative flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
    <button
      v-for="t in props.tabs" :key="String(t.value)" :data-tab="String(t.value)" role="tab" :aria-selected="model === t.value"
      class="relative flex h-11 shrink-0 items-center gap-2 px-3 text-sm font-semibold transition-colors"
      :class="model === t.value ? 'text-brand-700' : 'text-slate-500 hover:text-slate-800'"
      @click="model = t.value"
    >
      {{ t.label }}
      <span v-if="t.count !== undefined" class="rounded-md px-1.5 text-[11px] tabular" :class="model === t.value ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-500'">{{ t.count }}</span>
    </button>
    <span ref="bar" class="absolute bottom-0 left-0 h-0.5 rounded-full bg-brand-600" />
  </div>
</template>
