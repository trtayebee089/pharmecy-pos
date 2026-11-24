<script setup lang="ts">
import { gsap } from 'gsap'
import type { AttendanceStatus } from '~/types/hr'

/** Month calendar coloured by attendance status. Week starts Saturday; Friday is the weekly off. */
const props = defineProps<{ year: number; month: number; days: Record<string, AttendanceStatus | null>; holidays?: Record<string, string> }>()
const grid = ref<HTMLElement | null>(null)

const cells = computed(() => {
  const first = new Date(props.year, props.month, 1)
  const lead = (first.getDay() + 1) % 7
  const out: ({ day: number; key: string } | null)[] = Array.from({ length: lead }, () => null)
  const n = new Date(props.year, props.month + 1, 0).getDate()
  for (let d = 1; d <= n; d++) out.push({ day: d, key: `${props.year}-${String(props.month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}` })
  return out
})
const tone: Record<string, string> = {
  present: 'bg-emerald-500 text-white', late: 'bg-amber-400 text-amber-950', leave: 'bg-sky-400 text-white', absent: 'bg-rose-500 text-white',
}
const counts = computed(() => {
  const c = { present: 0, late: 0, leave: 0, absent: 0 }
  for (const v of Object.values(props.days)) if (v) c[v]++
  return c
})
watch(() => props.days, () => nextTick(() => {
  if (!grid.value || prefersReducedMotion()) return
  gsap.fromTo(grid.value.querySelectorAll('[data-cell]'), { scale: 0.6, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.3, stagger: { each: 0.008, from: 'start' }, ease: 'back.out(2)' })
}), { immediate: true })
</script>

<template>
  <div>
    <div class="grid grid-cols-7 gap-1 text-center text-[10.5px] font-semibold text-slate-400">
      <span v-for="(d, i) in ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']" :key="i">{{ d }}</span>
    </div>
    <div ref="grid" class="mt-1 grid grid-cols-7 gap-1">
      <span
        v-for="(c, i) in cells" :key="i" :data-cell="c ? '' : undefined"
        class="grid aspect-square place-items-center rounded-lg text-xs font-semibold tabular"
        :class="!c ? '' : holidays?.[c.key] ? 'bg-violet-100 text-violet-700' : days[c.key] ? tone[days[c.key]!] : 'bg-slate-100 text-slate-400'"
        :title="c ? `${date(c.key)} · ${holidays?.[c.key] ?? days[c.key] ?? (new Date(c.key).getDay() === 5 ? 'weekly off' : 'no record')}` : undefined"
      >{{ c?.day }}</span>
    </div>
    <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-600">
      <span class="flex items-center gap-1.5"><span class="size-2.5 rounded bg-emerald-500" />Present {{ counts.present }}</span>
      <span class="flex items-center gap-1.5"><span class="size-2.5 rounded bg-amber-400" />Late {{ counts.late }}</span>
      <span class="flex items-center gap-1.5"><span class="size-2.5 rounded bg-sky-400" />Leave {{ counts.leave }}</span>
      <span class="flex items-center gap-1.5"><span class="size-2.5 rounded bg-rose-500" />Absent {{ counts.absent }}</span>
      <span class="flex items-center gap-1.5"><span class="size-2.5 rounded bg-violet-200" />Holiday</span>
    </div>
  </div>
</template>
