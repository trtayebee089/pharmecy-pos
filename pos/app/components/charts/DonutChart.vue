<script setup lang="ts">
import { gsap } from 'gsap'

const props = defineProps<{ items: { method: string; value: number }[] }>()
const colors = ['#0e9f8f', '#e2136e', '#6366f1', '#f59e0b', '#64748b']
const R = 54, C = 2 * Math.PI * R
const total = computed(() => props.items.reduce((s, i) => s + i.value, 0) || 1)
const arcs = computed(() => {
  let acc = 0
  return props.items.map((it, i) => {
    const len = (it.value / total.value) * C
    const a = { len, offset: -acc, color: colors[i % colors.length]!, ...it }
    acc += len
    return a
  })
})
const svg = ref<SVGSVGElement | null>(null)
watch(arcs, () => nextTick(() => {
  if (prefersReducedMotion()) return
  svg.value?.querySelectorAll<SVGCircleElement>('[data-arc]').forEach((el, i) => {
    const len = Number(el.dataset.len)
    gsap.fromTo(el, { strokeDasharray: `0 ${C}` }, { strokeDasharray: `${len} ${C - len}`, duration: 1, delay: 0.2 + i * 0.12, ease: 'power3.out' })
  })
}), { immediate: true })
</script>

<template>
  <div class="flex items-center gap-6">
    <svg ref="svg" viewBox="0 0 140 140" class="size-36 shrink-0 -rotate-90">
      <circle cx="70" cy="70" :r="R" fill="none" stroke="#f1f5f9" stroke-width="16" />
      <circle
        v-for="a in arcs" :key="a.method" data-arc :data-len="a.len" cx="70" cy="70" :r="R" fill="none"
        :stroke="a.color" stroke-width="16" stroke-linecap="butt" :stroke-dasharray="`${a.len} ${C - a.len}`" :stroke-dashoffset="a.offset"
      />
    </svg>
    <ul class="min-w-0 flex-1 space-y-2.5">
      <li v-for="a in arcs" :key="a.method" class="flex items-center gap-2.5 text-sm">
        <span class="size-2.5 rounded-full" :style="{ background: a.color }" />
        <span class="flex-1 text-slate-600">{{ a.method }}</span>
        <span class="font-semibold text-ink tabular">{{ a.value }}%</span>
      </li>
    </ul>
  </div>
</template>
