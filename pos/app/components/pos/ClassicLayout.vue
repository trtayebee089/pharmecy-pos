<script setup lang="ts">
import { gsap } from 'gsap'
import {
  Home, ScanBarcode, UserPlus, UserRound, Plus, PackagePlus, Trash2, Minus, History, PauseCircle, XCircle, Banknote,
  CreditCard, Smartphone, Layers, Pencil, Keyboard, FileWarning, Cross, LogOut, CalendarDays,
} from 'lucide-vue-next'
import type { PaymentLine, Product } from '~/types/domain'
import type { CartLine } from '~/stores/cart'

/**
 * "Classic" counter: the familiar table-based screen of the legacy POS
 * (date / warehouse / biller / customer row, search with a results table,
 * cart table, grand-total bar and big action buttons), rebuilt on the same
 * cart, batch (FEFO) and payment logic as the grid layout.
 */
const cart = useCartStore()
const auth = useAuthStore()
const api = useProductsApi()
const toast = useToast()
const { ask } = useConfirm()

const toolbar = ref<{ openRecent: (t?: 'sales' | 'drafts') => void } | null>(null)
const searchEl = ref<HTMLInputElement | null>(null)
const tableBody = ref<HTMLElement | null>(null)
const grandEl = ref<HTMLElement | null>(null)
const dropdown = ref<HTMLElement | null>(null)
const searchWrap = ref<HTMLElement | null>(null)

// ---- Header selects ----
const billers = ref<{ id: number; name: string }[]>([])
const billerId = ref<number>(1)
onMounted(async () => {
  const cfg = await useSettingsApi().pos()
  billerId.value = cfg.defaultBillerId
  billers.value = useApi().useMocks ? (await import('~/mocks/reports-extra')).billers : await useApi().get('/billers')
  searchEl.value?.focus()
})
const todayLabel = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, '-')

// ---- Search with results table ----
const term = ref('')
const results = ref<Product[]>([])
const searching = ref(false)
const showResults = ref(false)
const activeIdx = ref(0)
const showCost = computed(() => auth.can('reports.profit'))
let controller: AbortController | null = null
async function search() {
  controller?.abort()
  controller = new AbortController()
  searching.value = true
  try { results.value = await api.search(term.value, auth.warehouseId, controller.signal); activeIdx.value = 0 }
  catch { /* aborted */ }
  finally { searching.value = false }
}
watchDebounced(term, () => { showResults.value = true; search() }, { debounce: 120 })
onClickOutside(searchWrap, () => (showResults.value = false))
const nextExpiry = (p: Product) => p.batches.find(b => daysUntil(b.expiryDate) >= 0)?.expiryDate
const saleUnit = (p: Product) => p.units.find(u => u.isSaleDefault) ?? p.units[0]!
const avgCost = (p: Product) => { const b = p.batches.find(x => daysUntil(x.expiryDate) >= 0); return b ? b.unitCost : 0 }

function onSearchKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); showResults.value = true; activeIdx.value = Math.min(results.value.length - 1, activeIdx.value + 1); scrollActive() }
  else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx.value = Math.max(0, activeIdx.value - 1); scrollActive() }
  else if (e.key === 'Escape') showResults.value = false
  else if (e.key === 'Enter') {
    e.preventDefault()
    const code = term.value.trim()
    const p = results.value.find(x => x.sku === code) ?? (showResults.value ? results.value[activeIdx.value] : undefined)
    if (p) addProduct(p)
  }
}
function scrollActive() { nextTick(() => dropdown.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })) }

function addProduct(p: Product) {
  const r = cart.add(p)
  if (r === 'no-stock') {
    shake(searchWrap.value)
    toast.warning(`${p.name} cannot be sold`, 'No non-expired stock in this warehouse.')
    return
  }
  if (p.requiresPrescription && r === 'added') toast.info('Prescription medicine', `${p.name}: check the prescription before handing over.`)
  term.value = ''
  showResults.value = false
  nextTick(() => {
    const row = tableBody.value?.querySelector(`[data-pid="${p.id}"]`)
    if (row && !prefersReducedMotion()) gsap.fromTo(row, { backgroundColor: '#c9f5ec' }, { backgroundColor: 'rgba(0,0,0,0)', duration: 1.2, ease: 'power2.out' })
    pop(grandEl.value)
    searchEl.value?.focus()
  })
}

// ---- Cart table ----
const expanded = ref<string | null>(null)
const today = new Date().toISOString().slice(0, 10)
const sellable = (l: CartLine) => l.product.batches.filter(b => b.expiryDate >= today)
function step(l: CartLine, d: number) { l.qty = Math.max(1, l.qty + d) }
const pieces = computed(() => cart.lines.reduce((s, l) => s + (l.custom ? l.qty : cart.qtyBase(l)), 0))
watch(() => cart.total, () => pop(grandEl.value))

const discountEdit = ref(false)

// ---- Actions ----
const payOpen = ref(false)
const presetMethod = ref<PaymentLine['method']>('cash')
const customerOpen = ref(false)
const newCustomerOpen = ref(false)
const customOpen = ref(false)
const helpOpen = ref(false)

function openPay(method: PaymentLine['method']) {
  if (!cart.lines.length) { toast.info('Cart is empty', 'Scan or search a product first.'); return }
  if (cart.hasShortage) { toast.error('Not enough stock', 'Reduce the highlighted quantities before payment.'); return }
  presetMethod.value = method
  payOpen.value = true
}
async function hold() {
  if (!cart.lines.length) return
  try {
    const h = await cart.hold()
    toast.success('Saved as draft', `${h?.id ?? ''} · find it under Recent transaction → Drafts (F7).`)
  }
  catch (e: any) { toast.error('Draft not saved', e?.message) }
}
async function cancelSale() {
  if (!cart.lines.length) return
  const { ok } = await ask({ title: 'Cancel this sale?', description: `${cart.itemCount} items will be removed from the cart. Nothing has been charged.`, confirmLabel: 'Cancel sale' })
  if (ok) { cart.clear(); toast.info('Sale cancelled') }
}
usePosShortcuts({
  help: () => (helpOpen.value = true),
  search: () => { searchEl.value?.focus(); searchEl.value?.select() },
  customer: () => (customerOpen.value = true),
  drafts: () => toolbar.value?.openRecent('drafts'),
  hold,
  pay: () => openPay('cash'),
  custom: () => (customOpen.value = true),
  clear: cancelSale,
})
watch(payOpen, o => !o && nextTick(() => searchEl.value?.focus()))

const actionBtn = 'flex h-12 min-w-[8.5rem] items-center justify-center gap-2 rounded-xl px-5 text-[14px] font-bold tracking-wide text-white shadow-[0_6px_16px_-6px_rgb(15_27_45/.35)] transition active:scale-[.97] disabled:opacity-50'
</script>

<template>
  <div class="flex h-dvh flex-col bg-surface">
    <!-- Top bar -->
    <header class="flex h-14 shrink-0 items-center gap-3 border-b border-slate-200/80 bg-white px-3 sm:px-4">
      <NuxtLink to="/dashboard" class="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:border-brand-300 hover:text-brand-700" aria-label="Back to dashboard"><Home class="size-5" /></NuxtLink>
      <div class="hidden items-center gap-2 sm:flex">
        <span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-brand-400 to-brand-700"><Cross class="size-4 text-white" :stroke-width="2.6" /></span>
        <span class="font-display text-[15px] font-extrabold">Red Pharma POS</span>
      </div>
      <div class="ml-auto"><PosToolbar ref="toolbar" /></div>
      <div class="hidden items-center gap-2 border-l border-slate-200 pl-3 md:flex">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-100 text-xs font-bold text-brand-700">{{ initials(auth.user?.name ?? '') }}</span>
        <span class="text-[13px] font-semibold">{{ auth.user?.name }}</span>
        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Sign out" @click="auth.logout()"><LogOut class="size-4" /></button>
      </div>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-3 p-3 sm:p-4">
      <!-- Date / warehouse / biller / customer -->
      <div class="grid shrink-0 grid-cols-2 gap-3 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.5fr)]">
        <div class="rounded-xl border border-slate-200 bg-white px-3 py-1.5">
          <p class="text-[10.5px] font-semibold text-slate-500">Date</p>
          <p class="flex items-center gap-2 text-sm font-semibold tabular"><CalendarDays class="size-3.5 text-slate-400" />{{ todayLabel }}</p>
        </div>
        <label class="rounded-xl border border-slate-200 bg-white px-3 py-1.5">
          <span class="block text-[10.5px] font-semibold text-slate-500">Warehouse</span>
          <select v-model="auth.warehouseId" class="w-full bg-transparent text-sm font-semibold text-brand-800 focus:outline-none">
            <option v-for="w in auth.user?.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </label>
        <label class="rounded-xl border border-slate-200 bg-white px-3 py-1.5">
          <span class="block text-[10.5px] font-semibold text-slate-500">Biller</span>
          <select v-model="billerId" class="w-full bg-transparent text-sm font-semibold text-brand-800 focus:outline-none">
            <option v-for="b in billers" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </label>
        <div class="flex items-stretch gap-2">
          <button type="button" class="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-left transition hover:border-brand-300" @click="customerOpen = true">
            <UserRound class="size-4 shrink-0 text-slate-400" />
            <span class="min-w-0">
              <span class="block text-[10.5px] font-semibold text-slate-500">Customer <span class="text-slate-400">(F4)</span></span>
              <span class="block truncate text-sm font-semibold text-brand-800">{{ cart.customer ? `${cart.customer.name} (${cart.customer.phone ?? '-'})` : 'Walk-in customer' }}</span>
            </span>
            <span v-if="cart.customer?.dueBalance" class="ml-auto shrink-0 rounded-md bg-amber-50 px-1.5 text-[11px] font-semibold text-amber-700">Due {{ money(cart.customer.dueBalance) }}</span>
          </button>
          <button type="button" class="grid w-11 place-items-center rounded-xl border border-slate-200 bg-white text-brand-700 transition hover:border-brand-300 hover:bg-brand-50" aria-label="New customer" title="New customer" @click="newCustomerOpen = true"><UserPlus class="size-4" /></button>
        </div>
      </div>

      <!-- Search -->
      <div ref="searchWrap" class="relative shrink-0">
        <div class="flex gap-2">
          <div class="flex h-12 flex-1 items-center rounded-xl border-2 border-slate-200 bg-white transition-colors focus-within:border-brand-400 focus-within:shadow-[0_0_0_4px_rgb(14_159_143/.12)]">
            <ScanBarcode class="ml-3 size-5 text-brand-600" />
            <input
              ref="searchEl" v-model="term" class="h-full flex-1 bg-transparent px-3 text-[15px] focus:outline-none" placeholder="Scan barcode or search product by name, generic or code…"
              aria-label="Scan or search product" autocomplete="off" @keydown="onSearchKey" @focus="results.length && term && (showResults = true)"
            >
            <UiKbd class="mr-3">F2</UiKbd>
          </div>
          <button type="button" class="grid w-12 place-items-center rounded-xl bg-emerald-600 text-white shadow-sm transition hover:bg-emerald-700" title="Add custom item (F10)" aria-label="Add custom item" @click="customOpen = true"><PackagePlus class="size-5" /></button>
          <button type="button" class="grid w-12 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-700" title="Keyboard shortcuts (F1)" aria-label="Keyboard shortcuts" @click="helpOpen = true"><Keyboard class="size-5" /></button>
        </div>

        <div v-if="showResults && term" ref="dropdown" class="absolute inset-x-0 top-full z-40 mt-2 max-h-[52vh] overflow-auto rounded-2xl border border-slate-200 bg-white shadow-[var(--shadow-lift)]">
          <table class="w-full min-w-[760px] text-sm">
            <thead class="sticky top-0 bg-slate-50">
              <tr>
                <th class="label px-4 py-2.5 text-left">Code</th><th class="label px-3 text-left">Name</th><th class="label px-3 text-left">Company</th>
                <th v-if="showCost" class="label px-3 text-right">Cost</th><th class="label px-3 text-left">Category</th>
                <th class="label px-3 text-right">Stock</th><th class="label px-3 text-left">Next expiry</th><th class="label px-4 text-right">Price</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(p, i) in results" :key="p.id" :data-active="i === activeIdx" class="cursor-pointer border-t border-slate-50 transition-colors"
                :class="i === activeIdx ? 'bg-brand-600 text-white' : p.stockBase <= 0 ? 'text-slate-400 hover:bg-slate-50' : 'hover:bg-brand-50'"
                @mouseenter="activeIdx = i" @click="addProduct(p)"
              >
                <td class="px-4 py-2.5 font-mono text-[12.5px]">{{ p.sku }}</td>
                <td class="px-3">
                  <span class="font-semibold">{{ p.name }} {{ p.strength }}</span>
                  <FileWarning v-if="p.requiresPrescription" class="ml-1 inline size-3.5" :class="i === activeIdx ? 'text-white' : 'text-violet-600'" />
                  <span class="block text-[11.5px]" :class="i === activeIdx ? 'text-brand-100' : 'text-slate-500'">{{ p.generic }}</span>
                </td>
                <td class="px-3">{{ p.manufacturer }}</td>
                <td v-if="showCost" class="px-3 text-right tabular">{{ money(avgCost(p)) }} / pc</td>
                <td class="px-3">{{ p.dosageForm }}</td>
                <td class="px-3 text-right font-semibold tabular" :class="i !== activeIdx && p.stockBase <= 0 && 'text-rose-500'">{{ p.stockBase > 0 ? `${num(p.stockBase)} pc` : 'Out' }}</td>
                <td class="px-3 tabular">{{ nextExpiry(p) ? date(nextExpiry(p)!, { month: 'short', year: 'numeric' }) : '—' }}</td>
                <td class="px-4 text-right font-semibold tabular">{{ money(saleUnit(p).price) }} / {{ saleUnit(p).code }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!searching && !results.length" class="px-4 py-6 text-center text-sm text-slate-500">No product matches “{{ term }}”.</p>
          <p v-if="searching" class="px-4 py-2 text-xs text-slate-400">Searching…</p>
        </div>
      </div>

      <!-- Cart table -->
      <div class="card flex min-h-[260px] flex-1 flex-col overflow-hidden">
        <div class="min-h-0 flex-1 overflow-auto">
          <table class="w-full min-w-[860px] text-sm">
            <thead class="sticky top-0 z-10 bg-slate-50">
              <tr class="border-b border-slate-100">
                <th class="label h-10 w-10 px-3 text-left">#</th><th class="label px-3 text-left">Product</th><th class="label px-3 text-left">Company</th>
                <th class="label px-3 text-left">Unit</th><th class="label px-3 text-right">Price</th><th class="label px-3 text-center">Quantity</th>
                <th class="label px-3 text-right">Disc %</th><th class="label px-3 text-right">Subtotal</th><th class="w-12" />
              </tr>
            </thead>
            <tbody ref="tableBody">
              <template v-for="(l, i) in cart.lines" :key="l.key">
                <tr :data-pid="l.product.id" class="border-b border-slate-50 align-middle">
                  <td class="px-3 py-2 text-slate-400 tabular">{{ cart.lines.length - i }}</td>
                  <td class="px-3 py-2">
                    <p class="font-semibold text-ink">{{ l.product.name }} <span class="font-medium text-slate-500">{{ l.product.strength }}</span></p>
                    <p v-if="l.custom" class="text-[11.5px] text-violet-600">Custom item · no stock</p>
                    <button v-else type="button" class="flex items-center gap-1 text-[11.5px] text-slate-500 hover:text-brand-700" @click="expanded = expanded === l.key ? null : l.key">
                      <Layers class="size-3" />
                      <template v-for="(a, j) in cart.allocation(l).parts" :key="a.batchId">
                        <span v-if="j">+</span>
                        <span class="font-mono" :class="expiryState(a.expiryDate) === 'critical' && 'font-semibold text-rose-600'">{{ a.batchNo }} · {{ date(a.expiryDate, { month: 'short', year: '2-digit' }) }}</span>
                      </template>
                    </button>
                    <p v-if="cart.allocation(l).short" class="text-[11.5px] font-semibold text-rose-600">Short by {{ cart.allocation(l).short }} pcs</p>
                  </td>
                  <td class="px-3 text-slate-600">{{ l.product.manufacturer }}</td>
                  <td class="px-3">
                    <select v-model="l.unitId" :disabled="!!l.custom" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-[13px] font-semibold focus:border-brand-400 focus:outline-none disabled:bg-slate-50" :aria-label="`Unit for ${l.product.name}`">
                      <option v-for="u in l.product.units" :key="u.id" :value="u.id">{{ u.label }}</option>
                    </select>
                  </td>
                  <td class="px-3 text-right tabular">{{ money(cart.unitOf(l).price) }}</td>
                  <td class="px-3">
                    <div class="mx-auto flex h-9 w-fit items-center rounded-lg border border-slate-200 bg-white">
                      <button type="button" class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" :aria-label="`Decrease ${l.product.name}`" @click="step(l, -1)"><Minus class="size-3.5" /></button>
                      <input v-model.number="l.qty" type="number" min="1" class="h-full w-12 border-x border-slate-200 text-center text-sm font-bold tabular focus:outline-none" :aria-label="`Quantity of ${l.product.name}`">
                      <button type="button" class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" :aria-label="`Increase ${l.product.name}`" @click="step(l, 1)"><Plus class="size-3.5" /></button>
                    </div>
                  </td>
                  <td class="px-3 text-right"><input v-model.number="l.discountPct" type="number" min="0" max="100" class="h-9 w-16 rounded-lg border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none" :aria-label="`Discount for ${l.product.name}`"></td>
                  <td class="px-3 text-right font-display text-[15px] font-extrabold tabular">{{ money(cart.lineTotal(l)) }}</td>
                  <td class="pr-3"><button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-rose-50 hover:text-rose-600" :aria-label="`Remove ${l.product.name}`" @click="cart.remove(l.key)"><Trash2 class="size-4" /></button></td>
                </tr>
                <tr v-if="expanded === l.key && !l.custom" class="bg-slate-50/70">
                  <td />
                  <td colspan="8" class="px-3 py-2">
                    <span class="mr-2 text-xs font-semibold text-slate-500">Batch (earliest expiry first):</span>
                    <button
                      v-for="b in sellable(l)" :key="b.batchId" type="button" class="mr-1.5 mb-1 rounded-lg border px-2 py-1 text-[11.5px] tabular transition"
                      :class="(l.batchId ?? cart.allocation(l).parts[0]?.batchId) === b.batchId ? 'border-brand-400 bg-brand-50 font-semibold text-brand-800' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                      @click="l.batchId = b.batchId"
                    >{{ b.batchNo }} · {{ date(b.expiryDate, { month: 'short', year: '2-digit' }) }} · {{ b.qtyBase }} pcs</button>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <UiEmptyState v-if="!cart.lines.length" :icon="ScanBarcode" title="Scan a barcode or search a product" description="Items are added with the earliest-expiry batch. Press F1 for keyboard shortcuts." />
        </div>

        <!-- Summary -->
        <div class="grid shrink-0 grid-cols-3 items-center gap-3 border-t border-slate-200 bg-slate-50/80 px-4 py-3 text-sm">
          <p><span class="text-slate-500">Items</span> <strong class="ml-2 font-display text-lg tabular">{{ cart.itemCount }} ({{ num(pieces) }})</strong></p>
          <p class="text-center"><span class="text-slate-500">Total</span> <strong class="ml-2 font-display text-lg tabular">{{ money(cart.subtotal) }}</strong></p>
          <p class="flex items-center justify-end gap-2">
            <span class="text-slate-500">Discount</span>
            <button type="button" class="rounded-md p-1 text-brand-600 hover:bg-brand-50" aria-label="Edit order discount" @click="discountEdit = !discountEdit"><Pencil class="size-3.5" /></button>
            <input v-if="discountEdit" v-model.number="cart.orderDiscount" type="number" min="0" class="h-8 w-24 rounded-lg border border-slate-200 bg-white px-2 text-right tabular focus:border-brand-400 focus:outline-none" aria-label="Order discount in taka" @keydown.enter="discountEdit = false">
            <strong v-else class="font-display text-lg tabular">{{ money(cart.lineDiscounts + cart.orderDiscount) }}</strong>
          </p>
        </div>
        <div class="relative shrink-0 overflow-hidden bg-gradient-to-r from-brand-700 via-brand-600 to-brand-800 py-3 text-center text-white">
          <div class="grain pointer-events-none absolute inset-0 opacity-60" />
          <p class="relative font-display text-2xl font-extrabold tracking-tight">Grand Total <span ref="grandEl" class="inline-block tabular">{{ money(cart.total) }}</span></p>
        </div>
      </div>

      <!-- Action bar -->
      <div class="flex shrink-0 flex-wrap items-center justify-center gap-2.5 pb-1">
        <button type="button" :class="actionBtn" class="bg-slate-700 hover:bg-slate-800" @click="toolbar?.openRecent()"><History class="size-4" />Recent transaction</button>
        <button type="button" :class="actionBtn" class="bg-amber-500 hover:bg-amber-600" :disabled="!cart.lines.length" @click="hold"><PauseCircle class="size-4" />Draft <span class="text-[11px] opacity-80">F8</span></button>
        <button type="button" :class="actionBtn" class="bg-rose-600 hover:bg-rose-700" :disabled="!cart.lines.length" @click="cancelSale"><XCircle class="size-4" />Cancel</button>
        <span class="mx-1 hidden h-8 w-px bg-slate-200 sm:block" />
        <button type="button" :class="actionBtn" class="bg-emerald-600 hover:bg-emerald-700" :disabled="!cart.lines.length" @click="openPay('cash')"><Banknote class="size-4" />Cash <span class="text-[11px] opacity-80">F9</span></button>
        <button type="button" :class="actionBtn" class="bg-pink-600 hover:bg-pink-700" :disabled="!cart.lines.length" @click="openPay('bkash')"><Smartphone class="size-4" />bKash</button>
        <button type="button" :class="actionBtn" class="bg-sky-600 hover:bg-sky-700" :disabled="!cart.lines.length" @click="openPay('card')"><CreditCard class="size-4" />Card</button>
        <button type="button" :class="actionBtn" class="bg-brand-700 hover:bg-brand-800" :disabled="!cart.lines.length" @click="openPay('cash')"><Layers class="size-4" />Multiple</button>
      </div>
    </div>

    <PosPaymentModal v-model="payOpen" :preset-method="presetMethod" @completed="n => toast.success('Sale completed', n)" />
    <PosCustomerPicker v-model="customerOpen" />
    <PosNewCustomerModal v-model="newCustomerOpen" @created="c => (cart.customer = c)" />
    <PosCustomItemModal v-model="customOpen" />
    <UiModal v-model="helpOpen" title="Keyboard shortcuts" size="sm">
      <ul class="space-y-2.5 text-sm">
        <li v-for="[k, d] in POS_SHORTCUTS" :key="k" class="flex items-center justify-between"><span class="text-slate-600">{{ d }}</span><UiKbd>{{ k }}</UiKbd></li>
      </ul>
    </UiModal>
  </div>
</template>
