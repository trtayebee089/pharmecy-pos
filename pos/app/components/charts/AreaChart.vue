<script setup lang="ts">
import { gsap } from 'gsap'

const props = withDefaults(defineProps<{
  series: { label: string; value: number }[]
  height?: number
  format?: (n: number) => string
}>(), { height: 240, format: (n: number) => compactMoney(n) })

const W = 760
const pad = { t: 16, r: 12, b: 28, l: 52 }
const wrap = ref<HTMLElement | null>(null)
const line = ref<SVGPathElement | null>(null)
const area = ref<SVGPathElement | null>(null)
const hover = ref<number | null>(null)

const max = computed(() => Math.max(...props.series.map(s => s.value), 1) * 1.12)
const pts = computed(() => props.series.map((s, i) => ({
  x: pad.l + (i * (W - pad.l - pad.r)) / Math.max(1, props.series.length - 1),
  y: pad.t + (1 - s.value / max.value) * (props.height - pad.t - pad.b),
  ...s,
})))

// Smooth path (monotone-ish cubic through points)
const d = computed(() => {
  const p = pts.value
  if (!p.length) return ''
  let s = `M${p[0]!.x},${p[0]!.y}`
  for (let i = 1; i < p.length; i++) {
    const a = p[i - 1]!, b = p[i]!, cx = (a.x + b.x) / 2
    s += ` C${cx},${a.y} ${cx},${b.y} ${b.x},${b.y}`
  }
  return s
})
const fill = computed(() => {
  const p = pts.value
  return p.length ? `${d.value} L${p[p.length - 1]!.x},${props.height - pad.b} L${p[0]!.x},${props.height - pad.b} Z` : ''
})
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map(t => ({ y: pad.t + (1 - t) * (props.height - pad.t - pad.b), v: max.value * t })))

function animate() {
  nextTick(() => {
    if (line.value) drawPath(line.value, 1.4)
    if (area.value && !prefersReducedMotion()) gsap.fromTo(area.value, { opacity: 0 }, { opacity: 1, duration: 1.2, delay: 0.5, ease: 'power1.out' })
    if (!prefersReducedMotion()) gsap.fromTo(wrap.value?.querySelectorAll('[data-dot]') ?? [], { scale: 0, transformOrigin: 'center' }, { scale: 1, duration: 0.35, stagger: 0.05, delay: 0.7, ease: 'back.out(2)' })
  })
}
watch(() => props.series, animate, { immediate: true })

function onMove(e: PointerEvent) {
  const svg = (e.currentTarget as SVGSVGElement).getBoundingClientRect()
  const x = ((e.clientX - svg.left) / svg.width) * W
  let best = 0, dist = Infinity
  pts.value.forEach((p, i) => { const dd = Math.abs(p.x - x); if (dd < dist) { dist = dd; best = i } })
  hover.value = best
}
</script>

<template>
  <div ref="wrap" class="relative">
    <svg :viewBox="`0 0 ${W} ${height}`" class="w-full overflow-visible" @pointermove="onMove" @pointerleave="hover = null">
      <defs>
        <linearGradient id="areaFill" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stop-color="#0e9f8f" stop-opacity=".28" />
          <stop offset="100%" stop-color="#0e9f8f" stop-opacity="0" />
        </linearGradient>
        <linearGradient id="lineStroke" x1="0" x2="1">
          <stop offset="0%" stop-color="#27bba9" />
          <stop offset="100%" stop-color="#0b655e" />
        </linearGradient>
      </defs>
      <g v-for="t in ticks" :key="t.y">
        <line :x1="pad.l" :x2="W - pad.r" :y1="t.y" :y2="t.y" stroke="#e2e8f0" stroke-dasharray="3 5" />
        <text :x="pad.l - 10" :y="t.y + 4" text-anchor="end" class="fill-slate-400 text-[11px] tabular">{{ format(t.v) }}</text>
      </g>
      <text v-for="(p, i) in pts" v-show="i % 2 === 0 || pts.length < 9" :key="p.label" :x="p.x" :y="height - 6" text-anchor="middle" class="fill-slate-400 text-[11px]">{{ p.label }}</text>
      <path ref="area" :d="fill" fill="url(#areaFill)" />
      <path ref="line" :d="d" fill="none" stroke="url(#lineStroke)" stroke-width="3" stroke-linecap="round" />
      <g v-if="hover !== null">
        <line :x1="pts[hover]!.x" :x2="pts[hover]!.x" :y1="pad.t" :y2="height - pad.b" stroke="#0e9f8f" stroke-opacity=".3" />
      </g>
      <circle v-for="(p, i) in pts" :key="`d${i}`" data-dot :cx="p.x" :cy="p.y" :r="hover === i ? 6 : 3.5" fill="#fff" stroke="#0e9f8f" stroke-width="2.5" class="transition-[r] duration-200" />
    </svg>
    <div
      v-if="hover !== null"
      class="pointer-events-none absolute -translate-x-1/2 -translate-y-full rounded-xl bg-ink px-3 py-2 text-xs text-white shadow-lg"
      :style="{ left: `${(pts[hover]!.x / W) * 100}%`, top: `${(pts[hover]!.y / height) * 100}%`, marginTop: '-12px' }"
    >
      <p class="text-slate-400">{{ pts[hover]!.label }}</p>
      <p class="font-semibold tabular">{{ money(pts[hover]!.value) }}</p>
    </div>
  </div>
</template>
