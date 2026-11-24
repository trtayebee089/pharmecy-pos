<script setup lang="ts">
import { Play, Trash2 } from 'lucide-vue-next'
import type { HeldSaleSummary } from '~/types/orders'

defineProps<{ held: HeldSaleSummary; age: string; canResume?: boolean }>()
defineEmits<{ resume: []; discard: [] }>()
const el = ref<HTMLElement | null>(null)
useSpotlight(el)
</script>

<template>
  <div ref="el" data-held class="card spotlight flex flex-col p-4 transition-shadow hover:shadow-[var(--shadow-lift)]">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="font-mono text-sm font-bold text-ink">{{ held.id }}</p>
        <p class="mt-0.5 truncate text-sm text-slate-600">{{ held.customer ?? 'Walk-in customer' }}</p>
      </div>
      <span class="shrink-0 rounded-lg bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700">{{ age }}</span>
    </div>
    <div class="mt-4">
      <p class="font-display text-xl font-extrabold text-ink tabular">{{ money(held.total) }}</p>
      <p class="text-xs text-slate-500">{{ held.items }} items · {{ held.register }} · {{ held.heldBy }}</p>
    </div>
    <div class="mt-4 flex gap-2 border-t border-slate-100 pt-3">
      <UiButton v-if="canResume" variant="primary" size="sm" :icon="Play" @click="$emit('resume')">Resume</UiButton>
      <UiButton variant="ghost" size="sm" :icon="Trash2" class="ml-auto hover:!text-rose-600" :aria-label="`Discard ${held.id}`" @click="$emit('discard')">Discard</UiButton>
    </div>
  </div>
</template>
