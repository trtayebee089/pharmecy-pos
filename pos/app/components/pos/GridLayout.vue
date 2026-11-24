<script setup lang="ts">
import { gsap } from 'gsap'
import { ScanBarcode, UserRound, PauseCircle, ShoppingBag, Trash2, Clock, Keyboard, X, PackagePlus } from 'lucide-vue-next'
import type { Product } from '~/types/domain'

/** Grid counter: product cards with a cart panel (the original new-POS layout). */
const cart = useCartStore()
const auth = useAuthStore()
const api = useProductsApi()
const toast = useToast()

const term = ref('')
const results = ref<Product[]>([])
const searching = ref(false)
const activeIdx = ref(0)
const form = ref<string>('All')
const searchInput = ref<{ focus: () => void; select: () => void } | null>(null)
const grid = ref<HTMLElement | null>(null)
const cartPanel = ref<HTMLElement | null>(null)
const totalEl = ref<HTMLElement | null>(null)
const payOpen = ref(false)
const customerOpen = ref(false)
const customOpen = ref(false)
const toolbar = ref<{ openRecent: (t?: 'sales' | 'drafts') => void } | null>(null)
const helpOpen = ref(false)

let controller: AbortController | null = null
async function search() {
  controller?.abort()
  controller = new AbortController()
  searching.value = true
  try {
    results.value = await api.search(term.value, auth.warehouseId, controller.signal)
    activeIdx.value = 0
    await nextTick()
    revealStagger(grid.value?.children ?? [], { y: 10, stagger: 0.018, duration: 0.35 })
  }
  catch { /* aborted */ }
  finally { searching.value = false }
}
watchDebounced(term, search, { debounce: 140 })
onMounted(() => { search(); searchInput.value?.focus() })

const forms = computed(() => ['All', ...new Set(results.value.map(p => p.dosageForm))].slice(0, 9))
const visible = computed(() => form.value === 'All' ? results.value : results.value.filter(p => p.dosageForm === form.value))

function addProduct(p: Product, rect?: DOMRect) {
  const r = cart.add(p)
  if (r === 'no-stock') {
    const card = grid.value?.querySelector(`[data-pid="${p.id}"]`)
    shake(card ?? null)
    toast.warning(`${p.name} cannot be sold`, 'No non-expired stock is available in this warehouse.')
    return
  }
  if (p.requiresPrescription && r === 'added') toast.info('Prescription medicine', `${p.name}: check the prescription before handing over.`)
  if (rect) flyTo(rect, cartPanel.value)
  pop(totalEl.value)
}

/** Barcode scanners type the code and press Enter: an exact SKU match adds instantly. */
function onSearchEnter() {
  const code = term.value.trim()
  const exact = results.value.find(p => p.sku === code)
  const target = exact ?? visible.value[activeIdx.value]
  if (target) {
    const el = grid.value?.querySelector<HTMLElement>(`[data-pid="${target.id}"]`)
    addProduct(target, el?.getBoundingClientRect())
    if (exact) term.value = ''
    searchInput.value?.select()
  }
}
function onSearchKey(e: KeyboardEvent) {
  const cols = window.innerWidth >= 1536 ? 4 : window.innerWidth >= 1024 ? 3 : 2
  const max = visible.value.length - 1
  if (e.key === 'ArrowRight') activeIdx.value = Math.min(max, activeIdx.value + 1)
  else if (e.key === 'ArrowLeft') activeIdx.value = Math.max(0, activeIdx.value - 1)
  else if (e.key === 'ArrowDown') activeIdx.value = Math.min(max, activeIdx.value + cols)
  else if (e.key === 'ArrowUp') activeIdx.value = Math.max(0, activeIdx.value - cols)
  else return
  e.preventDefault()
  grid.value?.querySelector(`[data-pid="${visible.value[activeIdx.value]?.id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

function openPay() {
  if (!cart.lines.length) { toast.info('Cart is empty', 'Scan or search a product first.'); return }
  if (cart.hasShortage) { toast.error('Not enough stock', 'Reduce the highlighted quantities before payment.'); return }
  payOpen.value = true
}
function hold() {
  if (!cart.lines.length) return
  cart.hold()
  toast.success('Saved as draft', 'Resume it from Drafts (F7).')
  searchInput.value?.focus()
}

// Keyboard-first counter
usePosShortcuts({
  help: () => (helpOpen.value = true),
  search: () => { searchInput.value?.focus(); searchInput.value?.select() },
  customer: () => (customerOpen.value = true),
  drafts: () => toolbar.value?.openRecent('drafts'),
  hold,
  pay: openPay,
  custom: () => (customOpen.value = true),
  clear: () => { if (cart.lines.length) cart.clear() },
})
watch(payOpen, o => !o && nextTick(() => searchInput.value?.focus()))

// Cart line enter/leave animations
function lineEnter(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.fromTo(el, { opacity: 0, x: 24, height: 0 }, { opacity: 1, x: 0, height: 'auto', duration: 0.4, ease: 'power3.out', onComplete: done })
}
function lineLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.to(el, { opacity: 0, x: -24, height: 0, paddingTop: 0, paddingBottom: 0, duration: 0.28, ease: 'power2.in', onComplete: done })
}
</script>

<template>
  <div class="grid gap-5 lg:h-[calc(100dvh-4rem-3rem)] lg:grid-cols-[minmax(0,1fr)_400px] 2xl:grid-cols-[minmax(0,1fr)_440px]">
    <!-- LEFT: search + products -->
    <section class="flex min-h-0 flex-col">
      <div class="card flex items-center gap-3 p-3">
        <div class="flex-1">
          <UiInput
            ref="searchInput" v-model="term" :icon="ScanBarcode" size="lg" kbd="F2" inputmode="search"
            placeholder="Scan barcode or search brand, generic, strength…"
            @keydown.enter.prevent="onSearchEnter" @keydown="onSearchKey"
          />
        </div>
        <UiButton variant="soft" :icon="PackagePlus" title="Add custom item (F10)" aria-label="Add custom item" @click="customOpen = true" />
        <UiButton variant="ghost" :icon="Keyboard" aria-label="Keyboard shortcuts" @click="helpOpen = true" />
      </div>
      <div class="mt-2 flex justify-end"><PosToolbar ref="toolbar" compact /></div>

      <div class="mt-3 flex items-center gap-2 overflow-x-auto pb-1">
        <button
          v-for="f in forms" :key="f"
          class="h-8 shrink-0 rounded-full px-3.5 text-[12.5px] font-semibold transition"
          :class="form === f ? 'bg-ink text-white shadow-md' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-slate-300'"
          @click="form = f; activeIdx = 0"
        >{{ f }}</button>
        <span v-if="searching" class="ml-auto shrink-0 text-xs text-slate-400">Searching…</span>
      </div>

      <div class="mt-3 min-h-0 flex-1 overflow-y-auto pr-1 pb-2">
        <div v-if="searching && !results.length" class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-4">
          <div v-for="i in 12" :key="i" class="skeleton h-44 rounded-2xl" />
        </div>
        <div v-else ref="grid" class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-4">
          <PosProductCard
            v-for="(p, i) in visible" :key="p.id" :data-pid="p.id" :product="p" :active="i === activeIdx"
            @add="rect => addProduct(p, rect)"
          />
        </div>
        <UiEmptyState v-if="!searching && !visible.length" title="No medicine matches" :description="`Nothing for “${term}”. Try the generic name or scan the barcode again.`" />
      </div>
    </section>

    <!-- RIGHT: cart -->
    <aside ref="cartPanel" class="card flex min-h-[520px] flex-col overflow-hidden">
      <div class="flex items-center gap-2 border-b border-slate-100 p-3">
        <button class="flex min-w-0 flex-1 items-center gap-3 rounded-xl p-2 text-left transition hover:bg-slate-50" @click="customerOpen = true">
          <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="cart.customer ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-500'">
            <UserRound class="size-5" />
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm font-bold text-ink">{{ cart.customer?.name ?? 'Walk-in customer' }}</span>
            <span class="block truncate text-xs text-slate-500">
              <template v-if="cart.customer">{{ cart.customer.phone }} · {{ num(cart.customer.points) }} pts<template v-if="cart.customer.dueBalance"> · due {{ money(cart.customer.dueBalance) }}</template></template>
              <template v-else>Press F4 to attach a customer</template>
            </span>
          </span>
        </button>
        <button v-if="cart.customer" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" aria-label="Remove customer" @click="cart.customer = null"><X class="size-4" /></button>
        <button class="relative rounded-xl p-2.5 text-slate-500 transition hover:bg-slate-100" aria-label="Drafts" @click="toolbar?.openRecent('drafts')">
          <Clock class="size-5" />
          <span v-if="cart.held.length" class="absolute -top-0.5 -right-0.5 grid size-5 place-items-center rounded-full bg-amber-500 text-[10px] font-bold text-white">{{ cart.held.length }}</span>
        </button>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto p-2">
        <TransitionGroup :css="false" @enter="lineEnter" @leave="lineLeave">
          <PosCartLine v-for="l in cart.lines" :key="l.key" :line="l" />
        </TransitionGroup>
        <UiEmptyState v-if="!cart.lines.length" :icon="ShoppingBag" title="Cart is empty" description="Scan a barcode or click a product. Batches are picked earliest-expiry-first." />
      </div>

      <div class="border-t border-slate-100 bg-slate-50/60 p-4">
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between text-slate-500"><dt>Subtotal · {{ cart.itemCount }} items</dt><dd class="tabular">{{ money(cart.subtotal) }}</dd></div>
          <div v-if="cart.lineDiscounts" class="flex justify-between text-slate-500"><dt>Line discounts</dt><dd class="tabular">−{{ money(cart.lineDiscounts) }}</dd></div>
          <div class="flex items-center justify-between text-slate-500">
            <dt>Order discount</dt>
            <dd class="flex items-center gap-1"><span class="text-xs">৳</span><input v-model.number="cart.orderDiscount" type="number" min="0" class="h-7 w-20 rounded-md border border-slate-200 bg-white px-2 text-right text-sm tabular focus:border-brand-400 focus:outline-none" aria-label="Order discount"></dd>
          </div>
        </dl>
        <div class="mt-3 flex items-end justify-between border-t border-dashed border-slate-200 pt-3">
          <span class="text-sm font-semibold text-slate-600">Total</span>
          <span ref="totalEl" class="font-display text-3xl font-extrabold tracking-tight text-ink tabular">{{ money(cart.total) }}</span>
        </div>
        <div class="mt-4 grid grid-cols-[auto_auto_1fr] gap-2">
          <UiButton size="lg" :icon="Trash2" aria-label="Clear cart" :disabled="!cart.lines.length" @click="cart.clear()" />
          <UiButton size="lg" :icon="PauseCircle" :disabled="!cart.lines.length" @click="hold">Hold <UiKbd>F8</UiKbd></UiButton>
          <UiButton variant="primary" size="lg" block magnetic :disabled="!cart.lines.length" @click="openPay">Pay <UiKbd class="border-white/20 bg-white/10 text-white">F9</UiKbd></UiButton>
        </div>
      </div>
    </aside>

    <PosPaymentModal v-model="payOpen" @completed="n => toast.success('Sale completed', n)" />
    <PosCustomerPicker v-model="customerOpen" />

    <PosCustomItemModal v-model="customOpen" />

    <UiModal v-model="helpOpen" title="Keyboard shortcuts" size="sm">
      <ul class="space-y-2.5 text-sm">
        <li v-for="[k, d] in POS_SHORTCUTS" :key="k" class="flex items-center justify-between">
          <span class="text-slate-600">{{ d }}</span><UiKbd>{{ k }}</UiKbd>
        </li>
      </ul>
    </UiModal>
  </div>
</template>
