<script setup lang="ts">
import { gsap } from 'gsap'
import { X } from 'lucide-vue-next'

const open = defineModel<boolean>({ default: false })
const props = withDefaults(defineProps<{
  title?: string
  description?: string
  size?: 'sm' | 'md' | 'lg' | 'xl'
  dismissible?: boolean
  /** Sits above other open modals (confirm dialogs asked from inside a modal). */
  stacked?: boolean
}>(), { size: 'md', dismissible: true, stacked: false })

const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

function close() { if (props.dismissible) open.value = false }
onKeyStroke('Escape', (e) => { if (open.value) { e.preventDefault(); close() } })

function onEnter(el: Element, done: () => void) {
  const backdrop = el.querySelector('[data-backdrop]')
  const panel = el.querySelector('[data-panel]')
  if (prefersReducedMotion()) return done()
  gsap.timeline({ onComplete: done })
    .fromTo(backdrop, { opacity: 0 }, { opacity: 1, duration: 0.25, ease: 'power1.out' }, 0)
    .fromTo(panel, { opacity: 0, y: 24, scale: 0.96, filter: 'blur(6px)' }, { opacity: 1, y: 0, scale: 1, filter: 'blur(0px)', duration: 0.42, ease: 'power3.out', clearProps: 'filter' }, 0.02)
  nextTick(() => (panel?.querySelector('[autofocus], input, button.primary-action') as HTMLElement | null)?.focus())
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.timeline({ onComplete: done })
    .to(el.querySelector('[data-panel]'), { opacity: 0, y: 12, scale: 0.98, duration: 0.18, ease: 'power1.in' }, 0)
    .to(el.querySelector('[data-backdrop]'), { opacity: 0, duration: 0.2 }, 0)
}
</script>

<template>
  <Teleport to="body">
    <Transition :css="false" @enter="onEnter" @leave="onLeave">
      <div v-if="open" class="fixed inset-0 flex items-end justify-center p-0 sm:items-center sm:p-6" :class="stacked ? 'z-[90]' : 'z-[80]'" role="dialog" aria-modal="true" :aria-label="title">
        <div data-backdrop class="absolute inset-0 bg-slate-950/40 backdrop-blur-[3px]" @click="close" />
        <div data-panel class="relative max-h-[92vh] w-full overflow-hidden rounded-t-3xl bg-white shadow-[var(--shadow-lift)] sm:rounded-3xl" :class="widths[size]">
          <div v-if="title" class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 pt-5 pb-4">
            <div>
              <h2 class="font-display text-lg font-bold text-ink">{{ title }}</h2>
              <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
            </div>
            <button v-if="dismissible" class="-mr-2 rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Close" @click="close">
              <X class="size-5" />
            </button>
          </div>
          <div class="max-h-[calc(92vh-9rem)] overflow-y-auto px-6 py-5">
            <slot />
          </div>
          <div v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
