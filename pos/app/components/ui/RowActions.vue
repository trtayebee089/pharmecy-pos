<script setup lang="ts">
import { gsap } from 'gsap'
import { Eye, Pencil, Trash2, Ban, MoreHorizontal } from 'lucide-vue-next'
import type { RowAction } from '~/types/domain'

/**
 * Standard, always-visible Action column for every table:
 * View · Edit · Delete (or Void for financial documents) · More (extra actions).
 * Permission checks belong to the caller (pass undefined to hide a button);
 * the API enforces them again.
 */
const props = withDefaults(defineProps<{
  label?: string // row name, for screen readers
  view?: string | (() => void)
  edit?: string | (() => void)
  remove?: () => void
  /** 'delete' for master data, 'void' for documents that must never be hard-deleted */
  removeKind?: 'delete' | 'void'
  items?: RowAction[]
}>(), { removeKind: 'delete', items: () => [] })

const extra = computed(() => props.items.filter(i => !i.hidden))
const open = ref(false)
const btn = ref<HTMLElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function run(a: string | (() => void) | undefined) {
  if (!a) return
  if (typeof a === 'string') navigateTo(a)
  else a()
}
function toggle() {
  if (open.value) { open.value = false; return }
  const r = btn.value!.getBoundingClientRect()
  const width = 208
  pos.value = { top: r.bottom + 6, left: Math.max(8, Math.min(window.innerWidth - width - 8, r.right - width)) }
  open.value = true
  nextTick(() => {
    if (!menu.value) return
    // Flip above the button when there is no room below
    const h = menu.value.offsetHeight
    if (pos.value.top + h > window.innerHeight - 8) pos.value.top = Math.max(8, r.top - h - 6)
    if (!prefersReducedMotion()) gsap.fromTo(menu.value, { opacity: 0, y: -4, scale: 0.97 }, { opacity: 1, y: 0, scale: 1, duration: 0.18, ease: 'power2.out' })
    menu.value.querySelector<HTMLElement>('[role=menuitem]:not([disabled])')?.focus()
  })
}
function choose(a: RowAction) {
  if (a.disabled) return
  open.value = false
  if (a.to) navigateTo(a.to)
  else a.onClick?.()
}
onClickOutside(menu, (e) => { if (!btn.value?.contains(e.target as Node)) open.value = false })
onKeyStroke('Escape', () => { if (open.value) { open.value = false; btn.value?.focus() } })
useEventListener('scroll', () => { open.value = false }, { capture: true, passive: true })
function onMenuKey(e: KeyboardEvent) {
  const items = [...(menu.value?.querySelectorAll<HTMLElement>('[role=menuitem]:not([disabled])') ?? [])]
  const i = items.indexOf(document.activeElement as HTMLElement)
  if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length]?.focus() }
  if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length]?.focus() }
}
const base = 'grid size-8 place-items-center rounded-lg text-slate-500 transition-colors focus-visible:outline-2 focus-visible:outline-brand-500'
</script>

<template>
  <div class="flex items-center justify-end gap-0.5" @click.stop>
    <slot />
    <button v-if="view" type="button" :class="base" class="hover:bg-brand-50 hover:text-brand-700" :title="'View'" :aria-label="`View ${label ?? ''}`.trim()" @click="run(view)">
      <Eye class="size-4" />
    </button>
    <button v-if="edit" type="button" :class="base" class="hover:bg-sky-50 hover:text-sky-700" :title="'Edit'" :aria-label="`Edit ${label ?? ''}`.trim()" @click="run(edit)">
      <Pencil class="size-4" />
    </button>
    <button v-if="remove" type="button" :class="base" class="hover:bg-rose-50 hover:text-rose-600" :title="removeKind === 'void' ? 'Void' : 'Delete'" :aria-label="`${removeKind === 'void' ? 'Void' : 'Delete'} ${label ?? ''}`.trim()" @click="remove()">
      <component :is="removeKind === 'void' ? Ban : Trash2" class="size-4" />
    </button>
    <button v-if="extra.length" ref="btn" type="button" :class="base" class="hover:bg-slate-100 hover:text-ink" :aria-expanded="open" aria-haspopup="menu" title="More actions" :aria-label="`More actions ${label ?? ''}`.trim()" @click="toggle">
      <MoreHorizontal class="size-4" />
    </button>
    <Teleport to="body">
      <div
        v-if="open" ref="menu" role="menu" class="fixed z-[95] w-52 rounded-2xl border border-slate-200 bg-white p-1.5 shadow-[var(--shadow-lift)]"
        :style="{ top: `${pos.top}px`, left: `${pos.left}px` }" @keydown="onMenuKey"
      >
        <button
          v-for="a in extra" :key="a.label" type="button" role="menuitem" :disabled="a.disabled"
          class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition-colors focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
          :class="a.danger ? 'text-rose-600 hover:bg-rose-50 focus:bg-rose-50' : 'text-slate-700 hover:bg-slate-50 focus:bg-slate-50'"
          @click="choose(a)"
        >
          <component :is="a.icon" v-if="a.icon" class="size-4 shrink-0" :class="a.danger ? '' : 'text-slate-400'" />{{ a.label }}
        </button>
      </div>
    </Teleport>
  </div>
</template>
