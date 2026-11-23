<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
const el = ref<HTMLElement | null>(null)
onMounted(() => revealStagger(el.value?.children ?? [], { y: 10, stagger: 0.06 }))
</script>

<template>
  <div ref="el" class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
      <h1 class="font-display text-[26px] font-extrabold tracking-tight text-ink">{{ title }}</h1>
      <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2"><slot /></div>
  </div>
</template>
