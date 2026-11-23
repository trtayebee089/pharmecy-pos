<script setup lang="ts">
import { gsap } from 'gsap'

defineOptions({ inheritAttrs: false })
defineProps<{ open: boolean }>()
function onEnter(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.fromTo(el, { opacity: 0, y: -6, scale: 0.97 }, { opacity: 1, y: 0, scale: 1, duration: 0.22, ease: 'power2.out', onComplete: done })
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.to(el, { opacity: 0, y: -4, duration: 0.14, onComplete: done })
}
</script>

<template>
  <Transition :css="false" @enter="onEnter" @leave="onLeave">
    <div v-if="open" class="absolute top-full z-50 mt-2 origin-top-right rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-[var(--shadow-lift)]" v-bind="$attrs">
      <slot />
    </div>
  </Transition>
</template>
