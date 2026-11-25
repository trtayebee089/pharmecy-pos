<script setup lang="ts">
import { KeyRound } from 'lucide-vue-next'
import { SECRET_DRAFTS } from '~/services/integrations'

/**
 * A write-only secret (password, API key, token). The stored value is never
 * shown: when one is saved the field reads "Saved" with Replace / Remove.
 * The typed value and the remove flag go into the page's SECRET_DRAFTS under
 * `field` ('group.name'); the page sends them on save.
 */
const props = defineProps<{ field: string; label: string; set: boolean; hint?: string; placeholder?: string }>()
const drafts = inject(SECRET_DRAFTS)!
const editing = ref(false)
const value = computed({ get: () => drafts.typed[props.field] ?? '', set: (v: string) => { drafts.typed[props.field] = v } })
const clear = computed({ get: () => !!drafts.clear[props.field], set: (v: boolean) => { drafts.clear[props.field] = v } })
const showInput = computed(() => !props.set || editing.value || clear.value)

watch(() => props.set, () => { editing.value = false })
</script>

<template>
  <div>
    <span class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ label }}</span>
    <div v-if="!showInput" class="flex h-10 items-center justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50/60 px-3 text-sm">
      <span class="flex items-center gap-2 text-emerald-800"><KeyRound class="size-4" /> Saved</span>
      <span class="flex gap-1">
        <button type="button" class="rounded-lg px-2 py-1 text-[12.5px] font-semibold text-slate-600 hover:bg-white" @click="editing = true">Replace</button>
        <button type="button" class="rounded-lg px-2 py-1 text-[12.5px] font-semibold text-rose-600 hover:bg-white" @click="clear = true">Remove</button>
      </span>
    </div>
    <div v-else-if="clear" class="flex h-10 items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-3 text-sm text-rose-700">
      <span>Will be removed when you save</span>
      <button type="button" class="rounded-lg px-2 py-1 text-[12.5px] font-semibold hover:bg-white" @click="clear = false">Undo</button>
    </div>
    <UiInput v-else v-model="value" type="password" autocomplete="new-password" :placeholder="placeholder ?? (set ? 'Type a new value to replace' : 'Not set')" />
    <span v-if="hint" class="mt-1 block text-[12px] text-slate-500">{{ hint }}</span>
  </div>
</template>
