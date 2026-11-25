<script setup lang="ts">
import { Check, ChevronDown, Search } from 'lucide-vue-next'

/**
 * Searchable dropdown (combobox). Same props as the old native select, plus:
 * - type while it is focused (or after opening) to filter; every word of the
 *   search must appear in the option, in any order;
 * - ↑ ↓ to move, Enter to choose, Esc to close, Home / End;
 * - `size="sm"` for table rows, `variant="bare"` for borderless boxes;
 * - `change` fires after the user picks (like a native select's change).
 * The list is teleported to <body> so cards, tables and drawers cannot clip it.
 */
type Value = string | number | null
export interface SelectOption { value: Value; label: string; disabled?: boolean; hint?: string }

defineOptions({ inheritAttrs: false })
const model = defineModel<Value | undefined>()
const props = withDefaults(defineProps<{
  label?: string
  options: SelectOption[]
  placeholder?: string
  error?: string
  hint?: string
  disabled?: boolean
  size?: 'sm' | 'md'
  variant?: 'default' | 'bare'
  /** Shown above the list; defaults to "Search…". */
  searchPlaceholder?: string
}>(), { size: 'md', variant: 'default' })
const emit = defineEmits<{ change: [value: Value] }>()
const attrs = useAttrs()

const id = useId()
const listId = `${id}-list`
const trigger = ref<HTMLButtonElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const search = ref<HTMLInputElement | null>(null)
const open = ref(false)
const query = ref('')
const active = ref(0)
const pos = ref<{ top: number; bottom: number; left: number; width: number; maxHeight: number; up: boolean }>({ top: 0, bottom: 0, left: 0, width: 0, maxHeight: 280, up: false })

const selected = computed(() => props.options.find(o => o.value === model.value) ?? null)
const words = computed(() => query.value.toLowerCase().split(/\s+/).filter(Boolean))
const filtered = computed(() => props.options.filter(o => {
  const hay = `${o.label} ${o.hint ?? ''}`.toLowerCase()
  return words.value.every(w => hay.includes(w))
}))

/** The label split into matched / unmatched parts, for highlighting. */
function parts(label: string): { text: string; hit: boolean }[] {
  if (!words.value.length) return [{ text: label, hit: false }]
  const pattern = new RegExp(`(${words.value.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'gi')
  return label.split(pattern).filter(Boolean).map(text => ({ text, hit: words.value.some(w => text.toLowerCase() === w) }))
}

function place() {
  const r = trigger.value?.getBoundingClientRect()
  if (!r) return
  const below = window.innerHeight - r.bottom - 12
  const above = r.top - 12
  const up = below < 220 && above > below
  pos.value = { top: r.bottom + 6, bottom: window.innerHeight - r.top + 6, left: Math.min(r.left, window.innerWidth - Math.max(r.width, 220) - 8), width: Math.max(r.width, 220), maxHeight: Math.min(320, Math.max(160, up ? above : below)), up }
}

async function show(seed = '') {
  if (props.disabled || open.value) return
  query.value = seed
  open.value = true
  place()
  const i = filtered.value.findIndex(o => o.value === model.value)
  active.value = i >= 0 ? i : firstEnabled(0, 1)
  await nextTick()
  search.value?.focus()
  scrollActive()
}
function hide(refocus = true) {
  if (!open.value) return
  open.value = false
  query.value = ''
  if (refocus) trigger.value?.focus()
}
function choose(o: SelectOption | undefined) {
  if (!o || o.disabled) return
  const changed = o.value !== model.value
  model.value = o.value
  hide()
  if (changed) emit('change', o.value)
}

function firstEnabled(from: number, dir: 1 | -1): number {
  const list = filtered.value
  for (let i = from; i >= 0 && i < list.length; i += dir) if (!list[i]!.disabled) return i
  return Math.min(Math.max(from, 0), Math.max(list.length - 1, 0))
}
function move(dir: 1 | -1) {
  const n = filtered.value.length
  if (!n) return
  let i = active.value
  for (let step = 0; step < n; step++) {
    i = (i + dir + n) % n
    if (!filtered.value[i]!.disabled) break
  }
  active.value = i
  scrollActive()
}
function scrollActive() {
  nextTick(() => panel.value?.querySelector(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }))
}
watch(query, () => { active.value = firstEnabled(0, 1); scrollActive() })

function onTriggerKey(e: KeyboardEvent) {
  if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show() }
  // Typing on the closed dropdown opens it and starts the search with that letter.
  else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); show(e.key) }
}
function onSearchKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); move(1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1) }
  else if (e.key === 'Home') { e.preventDefault(); active.value = firstEnabled(0, 1); scrollActive() }
  else if (e.key === 'End') { e.preventDefault(); active.value = firstEnabled(filtered.value.length - 1, -1); scrollActive() }
  else if (e.key === 'Enter') { e.preventDefault(); choose(filtered.value[active.value]) }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hide() }
  else if (e.key === 'Tab') hide(false)
}

onClickOutside(panel, (e) => { if (!trigger.value?.contains(e.target as Node)) hide(false) })
useEventListener(window, 'resize', () => open.value && place())
useEventListener(window, 'scroll', () => open.value && place(), { capture: true, passive: true })

const heights = { sm: 'h-9 text-[13px]', md: 'h-10 text-sm' }
</script>

<template>
  <div :class="attrs.class" :style="attrs.style as any">
    <label v-if="label" :for="id" class="mb-1.5 block text-[13px] font-medium text-slate-700">{{ label }}</label>
    <button
      :id="id" ref="trigger" type="button"
      role="combobox" aria-haspopup="listbox" :aria-expanded="open" :aria-controls="listId"
      :aria-label="(attrs['aria-label'] as string) || label || placeholder"
      :disabled="disabled"
      class="relative flex w-full min-w-0 items-center gap-2 text-left transition-[border-color,box-shadow] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
      :class="variant === 'bare'
        ? 'h-auto bg-transparent font-semibold text-brand-800'
        : [heights[size], 'rounded-xl border bg-white pr-8 pl-3 text-ink focus-visible:border-brand-400 focus-visible:shadow-[0_0_0_4px_rgb(14_159_143/.12)]', size === 'sm' && 'rounded-lg pl-2.5', error ? 'border-rose-300' : open ? 'border-brand-400' : 'border-slate-200 hover:border-slate-300']"
      @click="open ? hide() : show()"
      @keydown="onTriggerKey"
    >
      <span class="min-w-0 flex-1 truncate" :class="!selected && 'text-slate-400'">{{ selected?.label ?? placeholder ?? 'Choose…' }}</span>
      <ChevronDown class="size-4 shrink-0 text-slate-400 transition-transform" :class="[open && 'rotate-180', variant !== 'bare' && 'absolute right-2.5']" />
    </button>
    <span v-if="error" class="mt-1.5 block text-xs font-medium text-rose-600">{{ error }}</span>
    <span v-else-if="hint" class="mt-1.5 block text-xs text-slate-500">{{ hint }}</span>

    <Teleport to="body">
      <div
        v-if="open" ref="panel"
        class="fixed z-[200] flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_18px_40px_-12px_rgb(15_23_42/.25)]"
        :style="{ left: `${pos.left}px`, width: `${pos.width}px`, maxHeight: `${pos.maxHeight}px`, ...(pos.up ? { bottom: `${pos.bottom}px` } : { top: `${pos.top}px` }) }"
      >
        <div class="flex shrink-0 items-center gap-2 border-b border-slate-100 px-3">
          <Search class="size-4 shrink-0 text-slate-400" />
          <input
            ref="search" v-model="query"
            class="h-10 min-w-0 flex-1 bg-transparent text-sm text-ink placeholder:text-slate-400 focus:outline-none"
            :placeholder="searchPlaceholder ?? 'Search…'" autocomplete="off" spellcheck="false"
            role="searchbox" :aria-controls="listId" :aria-activedescendant="filtered.length ? `${id}-opt-${active}` : undefined"
            @keydown="onSearchKey"
          >
          <span class="shrink-0 text-[11px] text-slate-400 tabular">{{ filtered.length }}/{{ options.length }}</span>
        </div>
        <ul :id="listId" role="listbox" class="min-h-0 flex-1 overflow-y-auto p-1">
          <li
            v-for="(o, i) in filtered" :id="`${id}-opt-${i}`" :key="String(o.value)" :data-index="i"
            role="option" :aria-selected="o.value === model" :aria-disabled="o.disabled || undefined"
            class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm"
            :class="[o.disabled ? 'cursor-not-allowed text-slate-300' : i === active ? 'bg-brand-50 text-brand-900' : 'text-slate-700', o.value === model && 'font-semibold']"
            @mousemove="!o.disabled && (active = i)"
            @mousedown.prevent
            @click="choose(o)"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate"><template v-for="(p, k) in parts(o.label)" :key="k"><mark v-if="p.hit" class="rounded bg-amber-100 px-0.5 text-inherit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
              <span v-if="o.hint" class="block truncate text-[11.5px] font-normal text-slate-500">{{ o.hint }}</span>
            </span>
            <Check v-if="o.value === model" class="size-4 shrink-0 text-brand-600" />
          </li>
          <li v-if="!filtered.length" class="px-3 py-6 text-center text-sm text-slate-500">No match for “{{ query }}”</li>
        </ul>
      </div>
    </Teleport>
  </div>
</template>
