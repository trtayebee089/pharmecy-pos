<script setup lang="ts">
/** Compact 12-month calendar highlighting the given dates (YYYY-MM-DD → label). */
const props = defineProps<{ year: number; marks: Record<string, string> }>()
const root = ref<HTMLElement | null>(null)
const today = new Date().toISOString().slice(0, 10)

const months = computed(() => Array.from({ length: 12 }, (_, m) => {
  const first = new Date(props.year, m, 1)
  const days = new Date(props.year, m + 1, 0).getDate()
  const lead = (first.getDay() + 1) % 7 // week starts Saturday (Bangladesh)
  const cells: ({ day: number; key: string } | null)[] = Array.from({ length: lead }, () => null)
  for (let d = 1; d <= days; d++) cells.push({ day: d, key: `${props.year}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}` })
  return { name: first.toLocaleDateString('en-GB', { month: 'long' }), cells }
}))
watch(() => props.year, () => nextTick(() => revealStagger(root.value?.children ?? [], { y: 8, stagger: 0.03, duration: 0.3 })))
onMounted(() => revealStagger(root.value?.children ?? [], { y: 8, stagger: 0.03, duration: 0.3 }))
</script>

<template>
  <div ref="root" class="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-4">
    <div v-for="m in months" :key="m.name" class="rounded-2xl border border-slate-200/80 bg-white p-3">
      <p class="mb-2 text-[13px] font-bold text-ink">{{ m.name }}</p>
      <div class="grid grid-cols-7 gap-0.5 text-center text-[10px] font-semibold text-slate-400">
        <span v-for="(d, i) in ['S', 'S', 'M', 'T', 'W', 'T', 'F']" :key="i">{{ d }}</span>
      </div>
      <div class="mt-1 grid grid-cols-7 gap-0.5 text-center text-[11px]">
        <span
          v-for="(c, i) in m.cells" :key="i"
          class="grid aspect-square place-items-center rounded-md tabular"
          :class="!c ? '' : marks[c.key] ? 'bg-brand-600 font-bold text-white shadow-sm' : c.key === today ? 'font-bold text-brand-700 ring-1 ring-brand-300' : (i % 7 === 6 ? 'text-rose-400' : 'text-slate-600')"
          :title="c && marks[c.key] ? marks[c.key] : undefined"
        >{{ c?.day }}</span>
      </div>
    </div>
  </div>
</template>
