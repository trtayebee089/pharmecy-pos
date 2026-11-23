<script setup lang="ts">
import { gsap } from 'gsap'
import { Search, Loader2 } from 'lucide-vue-next'
import type { Product } from '~/types/domain'

/** Combobox to find a product by name, generic, strength or barcode. Emits the chosen product. */
const props = defineProps<{ placeholder?: string; autofocus?: boolean }>()
const emit = defineEmits<{ pick: [product: Product] }>()
const auth = useAuthStore()
const api = useProductsApi()

const q = ref('')
const open = ref(false)
const loading = ref(false)
const results = ref<Product[]>([])
const active = ref(0)
const root = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const panel = ref<HTMLElement | null>(null)
onClickOutside(root, () => (open.value = false))

watchDebounced(q, async (s) => {
  if (!s.trim()) { results.value = []; return }
  loading.value = true
  results.value = (await api.search(s, auth.warehouseId)).slice(0, 8)
  loading.value = false
  active.value = 0
  open.value = true
  await nextTick()
  if (panel.value && !prefersReducedMotion()) gsap.fromTo(panel.value.children, { opacity: 0, y: 4 }, { opacity: 1, y: 0, stagger: 0.02, duration: 0.2 })
}, { debounce: 140 })

function pick(p?: Product) {
  if (!p) return
  emit('pick', p)
  q.value = ''
  results.value = []
  open.value = false
  input.value?.focus()
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(results.value.length - 1, active.value + 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(0, active.value - 1) }
  else if (e.key === 'Enter') {
    e.preventDefault()
    pick(results.value.find(p => p.sku === q.value.trim()) ?? results.value[active.value])
  }
  else if (e.key === 'Escape') open.value = false
}
onMounted(() => props.autofocus && input.value?.focus())
defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div ref="root" class="relative">
    <div class="flex h-11 items-center rounded-xl border border-slate-200 bg-white transition-[border-color,box-shadow] focus-within:border-brand-400 focus-within:shadow-[0_0_0_4px_rgb(14_159_143/.12)]">
      <Search class="ml-3 size-[18px] text-slate-400" />
      <input ref="input" v-model="q" class="h-full flex-1 bg-transparent px-3 text-sm focus:outline-none" :placeholder="placeholder ?? 'Scan barcode or search a product to add…'" @keydown="onKey" @focus="results.length && (open = true)">
      <Loader2 v-if="loading" class="mr-3 size-4 animate-spin text-slate-400" />
    </div>
    <div v-if="open && results.length" ref="panel" class="absolute inset-x-0 top-full z-40 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-[var(--shadow-lift)]">
      <button
        v-for="(p, i) in results" :key="p.id" type="button"
        class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left"
        :class="i === active ? 'bg-brand-50' : 'hover:bg-slate-50'"
        @mouseenter="active = i" @click="pick(p)"
      >
        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-[11px] font-bold text-slate-500">{{ initials(p.name) }}</span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-semibold text-ink">{{ p.name }} <span class="font-medium text-slate-500">{{ p.strength }}</span></span>
          <span class="block truncate text-xs text-slate-500">{{ p.generic }} · {{ p.manufacturer }} · SKU {{ p.sku }}</span>
        </span>
        <span class="text-xs text-slate-500 tabular">{{ unitBreakdown(p.stockBase, p.units) }}</span>
      </button>
    </div>
  </div>
</template>
