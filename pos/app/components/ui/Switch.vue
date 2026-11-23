<script setup lang="ts">
import { gsap } from 'gsap'

const model = defineModel<boolean>({ default: false })
defineProps<{ label?: string; description?: string }>()
const knob = ref<HTMLElement | null>(null)

watch(model, (on) => {
  if (!knob.value) return
  if (prefersReducedMotion()) return gsap.set(knob.value, { x: on ? 18 : 0 })
  gsap.timeline()
    .to(knob.value, { scaleX: 1.25, duration: 0.12, ease: 'power1.out' })
    .to(knob.value, { x: on ? 18 : 0, scaleX: 1, duration: 0.35, ease: 'back.out(2.2)' }, 0.04)
})
onMounted(() => knob.value && gsap.set(knob.value, { x: model.value ? 18 : 0 }))
</script>

<template>
  <label class="flex cursor-pointer items-start justify-between gap-4">
    <span v-if="label || description" class="min-w-0">
      <span class="block text-sm font-medium text-ink">{{ label }}</span>
      <span v-if="description" class="mt-0.5 block text-[12.5px] text-slate-500">{{ description }}</span>
    </span>
    <button
      type="button" role="switch" :aria-checked="model" :aria-label="label"
      class="relative mt-0.5 h-6 w-[42px] shrink-0 rounded-full transition-colors duration-300"
      :class="model ? 'bg-brand-600 shadow-[0_0_0_4px_rgb(14_159_143/.12)]' : 'bg-slate-300'"
      @click="model = !model"
    >
      <span ref="knob" class="absolute top-[3px] left-[3px] size-[18px] rounded-full bg-white shadow-[0_1px_3px_rgb(0_0_0/.25)]" />
    </button>
  </label>
</template>
