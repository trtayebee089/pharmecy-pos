<script setup lang="ts">
import type { Component } from 'vue'
import { gsap } from 'gsap'
import { Inbox } from 'lucide-vue-next'

defineProps<{ title: string; description?: string; icon?: Component }>()
const orb = ref<HTMLElement | null>(null)
onMounted(() => {
  if (!orb.value || prefersReducedMotion()) return
  gsap.to(orb.value, { y: -6, duration: 1.8, ease: 'sine.inOut', yoyo: true, repeat: -1 })
})
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div ref="orb" class="relative mb-5 grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-brand-50 to-white ring-1 ring-brand-100">
      <div class="absolute inset-0 rounded-2xl bg-brand-400/10 blur-xl" />
      <component :is="icon ?? Inbox" class="relative size-7 text-brand-600" />
    </div>
    <h3 class="font-display text-base font-bold text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500">{{ description }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>
