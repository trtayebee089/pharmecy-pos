<script setup lang="ts">
import { gsap } from 'gsap'
import { CheckCircle2, AlertTriangle, Info, XCircle, X } from 'lucide-vue-next'

const { toasts, dismiss } = useToast()
const icons = { success: CheckCircle2, error: XCircle, info: Info, warning: AlertTriangle }
const colors = { success: 'text-emerald-500', error: 'text-rose-500', info: 'text-sky-500', warning: 'text-amber-500' }

function onEnter(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.fromTo(el, { opacity: 0, x: 40, scale: 0.94 }, { opacity: 1, x: 0, scale: 1, duration: 0.45, ease: 'back.out(1.6)', onComplete: done })
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.to(el, { opacity: 0, x: 30, height: 0, marginTop: 0, paddingTop: 0, paddingBottom: 0, duration: 0.3, ease: 'power2.in', onComplete: done })
}
</script>

<template>
  <div class="pointer-events-none fixed right-4 bottom-4 z-[100] flex w-[min(380px,calc(100vw-2rem))] flex-col gap-2" aria-live="polite">
    <TransitionGroup :css="false" @enter="onEnter" @leave="onLeave">
      <div v-for="t in toasts" :key="t.id" class="glass pointer-events-auto flex items-start gap-3 overflow-hidden rounded-2xl p-4 shadow-[var(--shadow-lift)]">
        <component :is="icons[t.kind]" class="mt-0.5 size-5 shrink-0" :class="colors[t.kind]" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-ink">{{ t.title }}</p>
          <p v-if="t.description" class="mt-0.5 text-[13px] text-slate-500">{{ t.description }}</p>
        </div>
        <button class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Dismiss" @click="dismiss(t.id)">
          <X class="size-4" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
