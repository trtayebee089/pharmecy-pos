<script setup lang="ts" generic="T extends string | number">
import { gsap } from 'gsap'

const model = defineModel<T>({ required: true })
const props = defineProps<{ options: { value: T; label: string; count?: number }[] }>()

const wrap = ref<HTMLElement | null>(null)
const pill = ref<HTMLElement | null>(null)

function movePill(animate = true) {
  const btn = wrap.value?.querySelector<HTMLElement>(`[data-value="${String(model.value)}"]`)
  if (!btn || !pill.value) return
  const vars = { x: btn.offsetLeft, width: btn.offsetWidth }
  if (!animate || prefersReducedMotion()) gsap.set(pill.value, vars)
  else gsap.to(pill.value, { ...vars, duration: 0.45, ease: 'expo.out' })
}
watch(model, () => movePill())
onMounted(() => nextTick(() => movePill(false)))
useResizeObserver(wrap, () => movePill(false))
</script>

<template>
  <div ref="wrap" class="relative inline-flex rounded-xl bg-slate-100 p-1">
    <span ref="pill" class="absolute top-1 bottom-1 left-0 rounded-lg bg-white shadow-[0_1px_3px_rgb(15_27_45/.12)]" />
    <button
      v-for="o in props.options" :key="String(o.value)" :data-value="String(o.value)"
      class="relative z-10 inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold transition-colors"
      :class="model === o.value ? 'text-ink' : 'text-slate-500 hover:text-slate-700'"
      @click="model = o.value"
    >
      {{ o.label }}
      <span v-if="o.count !== undefined" class="rounded-md bg-slate-200/70 px-1.5 text-[10.5px] tabular">{{ o.count }}</span>
    </button>
  </div>
</template>
