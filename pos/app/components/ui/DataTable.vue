<script setup lang="ts" generic="T extends Record<string, any>">
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next'
import type { Column, Paginated } from '~/types/domain'

const props = defineProps<{
  columns: Column[]
  result: Paginated<T> | null
  loading?: boolean
  rowKey: keyof T
  searchPlaceholder?: string
  emptyTitle?: string
  emptyDescription?: string
}>()
const search = defineModel<string>('search', { default: '' })
const page = defineModel<number>('page', { default: 1 })

const body = ref<HTMLElement | null>(null)
watch(() => props.result, async () => {
  await nextTick()
  const rows = body.value?.querySelectorAll('tr')
  if (rows?.length) revealStagger(rows, { y: 8, stagger: 0.022, duration: 0.4 })
})

const range = computed(() => {
  const m = props.result?.meta
  if (!m || !m.total) return '0 results'
  const from = (m.page - 1) * m.perPage + 1
  return `${from}–${Math.min(m.total, m.page * m.perPage)} of ${num(m.total)}`
})
const align = (a?: string) => a === 'right' ? 'text-right' : a === 'center' ? 'text-center' : 'text-left'
</script>

<template>
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
      <div class="w-full sm:w-72">
        <UiInput v-model="search" :icon="Search" :placeholder="searchPlaceholder ?? 'Search…'" />
      </div>
      <slot name="filters" />
      <div class="ml-auto flex items-center gap-2"><slot name="actions" /></div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-100 bg-slate-50/70">
            <th v-for="c in columns" :key="c.key" class="label h-10 px-4 font-semibold whitespace-nowrap" :class="align(c.align)" :style="c.width ? { width: c.width } : undefined">
              {{ c.label }}
            </th>
          </tr>
        </thead>
        <tbody v-if="loading && !result">
          <tr v-for="i in 8" :key="i" class="border-b border-slate-50">
            <td v-for="c in columns" :key="c.key" class="px-4 py-3.5"><div class="skeleton h-4" :class="c.align === 'right' ? 'ml-auto w-20' : 'w-3/4'" /></td>
          </tr>
        </tbody>
        <tbody v-else ref="body" :class="loading && 'opacity-50 transition-opacity'">
          <tr v-for="row in result?.data ?? []" :key="String(row[rowKey])" class="group border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40">
            <td v-for="c in columns" :key="c.key" class="px-4 py-3 whitespace-nowrap" :class="[align(c.align), c.class]">
              <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
      <UiEmptyState v-if="!loading && result && !result.data.length" :title="emptyTitle ?? 'Nothing found'" :description="emptyDescription ?? 'Try a different search or filter.'" />
    </div>

    <div class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-[13px] text-slate-500">
      <span class="tabular">{{ range }}</span>
      <div class="flex items-center gap-1">
        <UiButton size="sm" variant="ghost" :icon="ChevronLeft" :disabled="page <= 1 || loading" aria-label="Previous page" @click="page--" />
        <span class="px-2 tabular">Page {{ result?.meta.page ?? 1 }} / {{ result?.meta.lastPage ?? 1 }}</span>
        <UiButton size="sm" variant="ghost" :icon="ChevronRight" :disabled="!result || page >= result.meta.lastPage || loading" aria-label="Next page" @click="page++" />
      </div>
    </div>
  </div>
</template>
