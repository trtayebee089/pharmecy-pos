<script setup lang="ts">
import { gsap } from 'gsap'
import { Hammer, ArrowLeft, History } from 'lucide-vue-next'
import { flatNav, phaseNames } from '~/config/navigation'

const route = useRoute()
const item = computed(() => flatNav().find(n => n.to === route.path))
definePageMeta({ title: 'Planned' })

const card = ref<HTMLElement | null>(null)
onMounted(() => {
  if (!card.value || prefersReducedMotion()) return
  gsap.from(card.value.querySelectorAll('[data-in]'), { y: 16, opacity: 0, stagger: 0.08, duration: 0.6, ease: 'power3.out' })
  gsap.to(card.value.querySelector('[data-ring]'), { rotate: 360, duration: 14, ease: 'none', repeat: -1 })
})
</script>

<template>
  <div class="grid min-h-[60vh] place-items-center">
    <div ref="card" class="card relative w-full max-w-xl overflow-hidden p-10 text-center">
      <div class="pointer-events-none absolute -top-24 left-1/2 size-64 -translate-x-1/2 rounded-full bg-brand-400/15 blur-3xl" />
      <div data-in class="relative mx-auto grid size-20 place-items-center">
        <div data-ring class="absolute inset-0 rounded-full border-2 border-dashed border-brand-300" />
        <div class="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-700 text-white shadow-[var(--shadow-glow)]"><Hammer class="size-6" /></div>
      </div>
      <template v-if="item">
        <p data-in class="label relative mt-6">{{ item.group }} · Phase {{ item.phase }}{{ item.phase ? ` (${phaseNames[item.phase]})` : '' }}</p>
        <h1 data-in class="relative mt-1 font-display text-2xl font-extrabold text-ink">{{ item.label }}</h1>
        <p data-in class="relative mt-2 text-sm text-slate-500">This module is part of the rebuild plan and opens once its API and workflow are implemented and verified. It is not a mock-up.</p>
        <p v-if="item.legacy" data-in class="relative mt-4 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600"><History class="size-3.5" />Replaces legacy: {{ item.legacy }}</p>
      </template>
      <template v-else>
        <h1 data-in class="relative mt-6 font-display text-2xl font-extrabold text-ink">Page not found</h1>
        <p data-in class="relative mt-2 text-sm text-slate-500">The address {{ route.path }} does not exist.</p>
      </template>
      <div data-in class="relative mt-8"><NuxtLink to="/dashboard"><UiButton :icon="ArrowLeft">Back to dashboard</UiButton></NuxtLink></div>
    </div>
  </div>
</template>
