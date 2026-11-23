<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowRight, CornerDownLeft, FileText, Pill, Search } from 'lucide-vue-next'
import { flatNav } from '~/config/navigation'
import type { Product } from '~/types/domain'

const ui = useUiStore()
const auth = useAuthStore()
const productsApi = useProductsApi()
const q = ref('')
const active = ref(0)
const input = ref<HTMLInputElement | null>(null)
const products = ref<Product[]>([])

onKeyStroke(['k', 'K'], (e) => {
  if (e.ctrlKey || e.metaKey) { e.preventDefault(); ui.commandOpen = !ui.commandOpen }
})

const pages = computed(() => {
  const s = q.value.trim().toLowerCase()
  return flatNav().filter(n => auth.can(n.permission))
    .filter(n => !s || `${n.group} ${n.label} ${n.legacy ?? ''}`.toLowerCase().includes(s))
    .slice(0, s ? 8 : 6)
})
watchDebounced(q, async (s) => {
  products.value = s.trim().length >= 2 ? (await productsApi.search(s, auth.warehouseId)).slice(0, 5) : []
}, { debounce: 150 })

type Hit = { kind: 'page'; label: string; sub: string; to: string } | { kind: 'product'; label: string; sub: string; to: string }
const hits = computed<Hit[]>(() => [
  ...pages.value.map(p => ({ kind: 'page' as const, label: p.label, sub: p.group === p.label ? 'Page' : p.group, to: p.to })),
  ...products.value.map(p => ({ kind: 'product' as const, label: `${p.name} ${p.strength ?? ''}`, sub: `${p.generic ?? ''} · ${p.manufacturer}`, to: `/products?search=${encodeURIComponent(p.name)}` })),
])
watch(hits, () => (active.value = 0))

function go(h?: Hit) {
  if (!h) return
  ui.commandOpen = false
  navigateTo(h.to)
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); active.value = (active.value + 1) % Math.max(1, hits.value.length) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = (active.value - 1 + hits.value.length) % Math.max(1, hits.value.length) }
  else if (e.key === 'Enter') go(hits.value[active.value])
  else if (e.key === 'Escape') ui.commandOpen = false
}

function onEnter(el: Element, done: () => void) {
  q.value = ''
  nextTick(() => input.value?.focus())
  if (prefersReducedMotion()) return done()
  gsap.timeline({ onComplete: done })
    .fromTo(el.querySelector('[data-backdrop]'), { opacity: 0 }, { opacity: 1, duration: 0.2 }, 0)
    .fromTo(el.querySelector('[data-panel]'), { opacity: 0, y: -16, scale: 0.97 }, { opacity: 1, y: 0, scale: 1, duration: 0.35, ease: 'back.out(1.4)' }, 0)
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.to(el, { opacity: 0, duration: 0.15, onComplete: done })
}
</script>

<template>
  <Teleport to="body">
    <Transition :css="false" @enter="onEnter" @leave="onLeave">
      <div v-if="ui.commandOpen" class="fixed inset-0 z-[90] flex items-start justify-center px-4 pt-[12vh]">
        <div data-backdrop class="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" @click="ui.commandOpen = false" />
        <div data-panel class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[var(--shadow-lift)]">
          <div class="flex items-center gap-3 border-b border-slate-100 px-4">
            <Search class="size-5 text-brand-600" />
            <input ref="input" v-model="q" class="h-14 flex-1 bg-transparent text-[15px] placeholder:text-slate-400 focus:outline-none" placeholder="Jump to a page or find a medicine…" @keydown="onKey">
            <UiKbd>Esc</UiKbd>
          </div>
          <ul class="max-h-[50vh] overflow-y-auto p-2" role="listbox">
            <li v-for="(h, i) in hits" :key="h.kind + h.to + h.label">
              <button
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors"
                :class="i === active ? 'bg-brand-50 text-brand-900' : 'text-slate-700'"
                @mouseenter="active = i" @click="go(h)"
              >
                <span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="h.kind === 'page' ? 'bg-slate-100 text-slate-500' : 'bg-brand-100 text-brand-700'">
                  <component :is="h.kind === 'page' ? FileText : Pill" class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-semibold">{{ h.label }}</span>
                  <span class="block truncate text-xs text-slate-500">{{ h.sub }}</span>
                </span>
                <ArrowRight v-if="i === active" class="size-4 text-brand-600" />
              </button>
            </li>
            <li v-if="!hits.length" class="px-3 py-8 text-center text-sm text-slate-500">No matches for "{{ q }}"</li>
          </ul>
          <div class="flex items-center gap-4 border-t border-slate-100 bg-slate-50/70 px-4 py-2.5 text-[11.5px] text-slate-500">
            <span class="flex items-center gap-1.5"><UiKbd>↑</UiKbd><UiKbd>↓</UiKbd> navigate</span>
            <span class="flex items-center gap-1.5"><UiKbd><CornerDownLeft class="size-3" /></UiKbd> open</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
