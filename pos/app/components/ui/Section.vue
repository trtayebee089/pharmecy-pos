<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{ title: string; description?: string; icon?: Component }>()
</script>

<template>
  <section class="card overflow-hidden" data-reveal>
    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
      <div class="flex items-start gap-3">
        <span v-if="icon" class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><component :is="icon" class="size-[18px]" /></span>
        <div>
          <h2 class="font-display text-[15px] font-bold text-ink">{{ title }}</h2>
          <p v-if="description" class="mt-0.5 text-[12.5px] text-slate-500">{{ description }}</p>
        </div>
      </div>
      <slot name="actions" />
    </header>
    <div class="p-5"><slot /></div>
  </section>
</template>
