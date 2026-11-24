<script setup lang="ts">
import type { FieldDef } from '~/types/domain'

/** Renders one FieldDef bound to model[field.key]. */
const props = defineProps<{ field: FieldDef; model: Record<string, any>; error?: string }>()
const f = computed(() => props.field)
</script>

<template>
  <div :class="f.span === 2 && 'sm:col-span-2'">
    <UiSelect v-if="f.type === 'select'" v-model="model[f.key]" :label="f.label" :options="f.options ?? []" :placeholder="f.placeholder ?? `Choose ${f.label.toLowerCase()}`" :error="error" :hint="f.hint" />
    <UiTextarea v-else-if="f.type === 'textarea'" v-model="model[f.key]" :label="f.label" :placeholder="f.placeholder" :hint="f.hint" :rows="3" />
    <div v-else-if="f.type === 'switch'" class="pt-1"><UiSwitch v-model="model[f.key]" :label="f.label" :description="f.hint" /></div>
    <label v-else-if="f.type === 'color'" class="block">
      <span class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ f.label }}</span>
      <input v-model="model[f.key]" type="color" class="h-10 w-20 cursor-pointer rounded-xl border border-slate-200 bg-white p-1">
    </label>
    <UiInput
      v-else
      v-model="model[f.key]"
      :label="f.label + (f.required ? ' *' : '')"
      :type="f.type === 'money' ? 'number' : f.type"
      :placeholder="f.placeholder"
      :error="error"
      :hint="f.hint"
      :inputmode="f.type === 'number' || f.type === 'money' ? 'decimal' : undefined"
    />
  </div>
</template>
