<script setup lang="ts">
import { CalendarRange } from 'lucide-vue-next'
import type { DateRange } from '~/types/domain'

const model = defineModel<DateRange>({ required: true })
const iso = (d: Date) => d.toISOString().slice(0, 10)
const presets = [
  { key: 'today', label: 'Today', make: () => { const t = new Date(); return { from: iso(t), to: iso(t) } } },
  { key: '7d', label: '7 days', make: () => { const t = new Date(); const f = new Date(); f.setDate(t.getDate() - 6); return { from: iso(f), to: iso(t) } } },
  { key: '30d', label: '30 days', make: () => { const t = new Date(); const f = new Date(); f.setDate(t.getDate() - 29); return { from: iso(f), to: iso(t) } } },
  { key: 'month', label: 'This month', make: () => { const t = new Date(); return { from: iso(new Date(t.getFullYear(), t.getMonth(), 1)), to: iso(t) } } },
]
const active = computed(() => presets.find(p => { const r = p.make(); return r.from === model.value.from && r.to === model.value.to })?.key ?? 'custom')
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="inline-flex rounded-xl bg-slate-100 p-1">
      <button
        v-for="p in presets" :key="p.key" type="button"
        class="h-8 rounded-lg px-3 text-[13px] font-semibold transition"
        :class="active === p.key ? 'bg-white text-ink shadow-[0_1px_3px_rgb(15_27_45/.12)]' : 'text-slate-500 hover:text-slate-700'"
        @click="model = p.make()"
      >{{ p.label }}</button>
    </div>
    <div class="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm">
      <CalendarRange class="size-4 text-slate-400" />
      <input v-model="model.from" type="date" class="bg-transparent focus:outline-none" aria-label="From date">
      <span class="text-slate-300">→</span>
      <input v-model="model.to" type="date" class="bg-transparent focus:outline-none" aria-label="To date">
    </div>
  </div>
</template>
