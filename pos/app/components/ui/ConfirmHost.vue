<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next'

const { state, settle } = useConfirm()
const open = computed({ get: () => state.value.open, set: v => { if (!v) settle(false) } })
const blocked = computed(() => !!state.value.requireReason && !state.value.reason.trim())
</script>

<template>
  <UiModal v-model="open" size="sm" stacked>
    <div class="flex gap-4 pt-2">
      <span class="grid size-11 shrink-0 place-items-center rounded-2xl" :class="state.tone === 'primary' ? 'bg-brand-50 text-brand-600' : 'bg-rose-50 text-rose-600'"><AlertTriangle class="size-5" /></span>
      <div class="min-w-0 flex-1">
        <h3 class="font-display text-lg font-bold">{{ state.title }}</h3>
        <p v-if="state.description" class="mt-1 text-sm text-slate-500">{{ state.description }}</p>
        <div v-if="state.requireReason" class="mt-4"><UiInput v-model="state.reason" label="Reason (required)" /></div>
      </div>
    </div>
    <template #footer>
      <UiButton variant="ghost" @click="settle(false)">Cancel</UiButton>
      <UiButton :variant="state.tone === 'primary' ? 'primary' : 'danger'" :disabled="blocked" @click="settle(true)">{{ state.confirmLabel ?? 'Confirm' }}</UiButton>
    </template>
  </UiModal>
</template>
