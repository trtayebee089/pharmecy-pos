<script setup lang="ts">
import { gsap } from 'gsap'
import { X } from 'lucide-vue-next'

const open = defineModel<boolean>({ default: false })
withDefaults(defineProps<{ title?: string; description?: string; width?: 'md' | 'lg' | 'xl' }>(), { width: 'lg' })
const widths = { md: 'max-w-md', lg: 'max-w-xl', xl: 'max-w-3xl' }
onKeyStroke('Escape', () => { if (open.value) open.value = false })

function onEnter(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.timeline({ onComplete: done })
    .fromTo(el.querySelector('[data-backdrop]'), { opacity: 0 }, { opacity: 1, duration: 0.25 }, 0)
    .fromTo(el.querySelector('[data-panel]'), { xPercent: 100 }, { xPercent: 0, duration: 0.5, ease: 'expo.out' }, 0)
    .fromTo(el.querySelectorAll('[data-panel] [data-stagger]'), { opacity: 0, y: 10 }, { opacity: 1, y: 0, stagger: 0.05, duration: 0.35 }, 0.15)
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.timeline({ onComplete: done })
    .to(el.querySelector('[data-panel]'), { xPercent: 100, duration: 0.3, ease: 'power2.in' }, 0)
    .to(el.querySelector('[data-backdrop]'), { opacity: 0, duration: 0.3 }, 0)
}
</script>

<template>
  <Teleport to="body">
    <Transition :css="false" @enter="onEnter" @leave="onLeave">
      <div v-if="open" class="fixed inset-0 z-[70] flex justify-end" role="dialog" aria-modal="true" :aria-label="title">
        <div data-backdrop class="absolute inset-0 bg-slate-950/30 backdrop-blur-[2px]" @click="open = false" />
        <aside data-panel class="relative flex h-full w-full flex-col bg-white shadow-[var(--shadow-lift)]" :class="widths[width]">
          <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
            <div class="min-w-0">
              <slot name="header">
                <h2 class="font-display text-lg font-bold text-ink">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
              </slot>
            </div>
            <button class="-mr-2 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close" @click="open = false"><X class="size-5" /></button>
          </header>
          <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5"><slot /></div>
          <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4"><slot name="footer" /></footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>
