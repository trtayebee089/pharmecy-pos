<script setup lang="ts">
import { Maximize2, Minimize2, Settings2, Printer, Wallet, ShoppingBag, TrendingUp, History, PauseCircle, Trash2, Play } from 'lucide-vue-next'
import type { DashboardSummary, RegisterSession, SaleSummary } from '~/types/domain'
const { brand } = useBrand()

/**
 * Counter toolbar shared by both POS layouts (legacy: Full Screen, POS Settings,
 * Print Last Receipt, Cash Register Details, Today Sale, Today Profit, Recent transactions).
 */
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })
const auth = useAuthStore()
const cart = useCartStore()
const toast = useToast()
const dashboardApi = useDashboardApi()
const registerApi = useRegisterApi()
const salesApi = useSalesApi()

// Full screen
const isFull = ref(false)
function toggleFull() {
  if (document.fullscreenElement) document.exitFullscreen()
  else document.documentElement.requestFullscreen?.().catch(() => toast.warning('Full screen is not available in this browser'))
}
useEventListener(document, 'fullscreenchange', () => (isFull.value = !!document.fullscreenElement))

// Today sale / profit
const todayOpen = ref<'sale' | 'profit' | null>(null)
const today = ref<DashboardSummary | null>(null)
async function openToday(kind: 'sale' | 'profit') {
  todayOpen.value = kind
  today.value = null
  today.value = await dashboardApi.summary(auth.warehouseId)
}
const mixAmounts = computed(() => today.value?.paymentMix.map(m => ({ method: m.method, amount: Math.round(today.value!.todaySales * m.value) / 100 })) ?? [])

// Register details
const regOpen = ref(false)
const session = ref<RegisterSession | null | undefined>(undefined)
async function openRegister() {
  regOpen.value = true
  session.value = undefined
  session.value = (await registerApi.sessions()).find(s => s.status === 'open') ?? null
}

// Recent transactions (+ drafts)
const recentOpen = ref(false)
const recentTab = ref<'sales' | 'drafts'>('sales')
const recent = ref<SaleSummary[] | null>(null)
async function openRecent(tab: 'sales' | 'drafts' = 'sales') {
  recentTab.value = tab
  recentOpen.value = true
  recent.value = null
  cart.loadHeld().catch((e: any) => toast.error('Could not load drafts', e?.message))
  recent.value = (await salesApi.list({ perPage: 12 })).data
}
async function resume(id: string) {
  try {
    const skipped = await cart.resume(id)
    recentOpen.value = false
    skipped.length ? toast.warning('Draft resumed without some items', skipped.join(', ')) : toast.success('Draft resumed', id)
  }
  catch (e: any) { toast.error('Could not resume', e?.message); cart.loadHeld().catch(() => {}) }
}
async function discard(id: string) {
  try { await cart.discardHeld(id); toast.info('Draft discarded', id) }
  catch (e: any) { toast.error('Could not discard', e?.message); cart.loadHeld().catch(() => {}) }
}
// Held carts of this warehouse (any counter), for the Drafts badge and list.
onMounted(() => cart.loadHeld().catch(() => {}))

// Last receipt
const receiptOpen = ref(false)
function openLastReceipt() {
  if (!cart.lastReceipt) { toast.info('No sale completed on this counter yet'); return }
  receiptOpen.value = true
}
function printNow() { window.print() }

defineExpose({ openRecent })
const btn = 'flex h-9 items-center gap-2 rounded-xl px-2.5 text-[13px] font-medium text-slate-600 transition hover:bg-brand-50 hover:text-brand-700'
</script>

<template>
  <div class="flex flex-wrap items-center gap-0.5">
    <button type="button" :class="btn" :title="isFull ? 'Exit full screen' : 'Full screen'" @click="toggleFull">
      <component :is="isFull ? Minimize2 : Maximize2" class="size-4" /><span v-if="!compact" class="hidden xl:inline">{{ isFull ? 'Exit full screen' : 'Full screen' }}</span>
    </button>
    <NuxtLink v-if="auth.can('settings.manage')" to="/admin/pos-settings" :class="btn" title="POS settings">
      <Settings2 class="size-4" /><span v-if="!compact" class="hidden xl:inline">POS settings</span>
    </NuxtLink>
    <button type="button" :class="btn" title="Print last receipt" @click="openLastReceipt">
      <Printer class="size-4" /><span v-if="!compact" class="hidden xl:inline">Last receipt</span>
    </button>
    <button type="button" :class="btn" title="Cash register details" @click="openRegister">
      <Wallet class="size-4" /><span v-if="!compact" class="hidden xl:inline">Register</span>
    </button>
    <button type="button" :class="btn" title="Today's sales" @click="openToday('sale')">
      <ShoppingBag class="size-4" /><span v-if="!compact" class="hidden xl:inline">Today sale</span>
    </button>
    <button v-if="auth.can('reports.profit')" type="button" :class="btn" title="Today's profit" @click="openToday('profit')">
      <TrendingUp class="size-4" /><span v-if="!compact" class="hidden xl:inline">Today profit</span>
    </button>
    <button type="button" :class="btn" title="Recent transactions" @click="openRecent()">
      <History class="size-4" /><span v-if="!compact" class="hidden xl:inline">Recent</span>
    </button>

    <!-- Today sale / profit -->
    <UiModal :model-value="!!todayOpen" :title="todayOpen === 'profit' ? 'Today\'s profit' : 'Today\'s sales'" :description="auth.warehouse?.name" size="sm" @update:model-value="v => !v && (todayOpen = null)">
      <div v-if="!today" class="space-y-3"><div v-for="i in 4" :key="i" class="skeleton h-10" /></div>
      <template v-else-if="todayOpen === 'sale'">
        <div class="rounded-2xl bg-brand-50 p-4"><p class="label text-brand-700">Sales today</p><p class="mt-1 font-display text-3xl font-extrabold text-brand-800 tabular">{{ money(today.todaySales) }}</p><p class="text-xs text-brand-700/70">{{ num(today.todayInvoices) }} invoices · avg {{ money(today.todayInvoices ? today.todaySales / today.todayInvoices : 0) }}</p></div>
        <dl class="mt-4 space-y-2 text-sm">
          <div v-for="m in mixAmounts" :key="m.method" class="flex justify-between"><dt class="text-slate-500">{{ m.method }}</dt><dd class="font-semibold tabular">{{ money(m.amount) }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">Cash in hand (open registers)</dt><dd class="font-semibold tabular">{{ money(today.cashInHand) }}</dd></div>
        </dl>
      </template>
      <template v-else>
        <dl class="space-y-2.5 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Revenue</dt><dd class="tabular">{{ money(today.todaySales) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Cost of goods (batch cost)</dt><dd class="tabular">−{{ money(today.todaySales - today.todayProfit) }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-2.5 font-display text-lg font-extrabold"><dt>Gross profit</dt><dd class="text-emerald-700 tabular">{{ money(today.todayProfit) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Margin</dt><dd class="font-semibold tabular">{{ today.todaySales ? ((today.todayProfit / today.todaySales) * 100).toFixed(1) : '0.0' }}%</dd></div>
        </dl>
      </template>
    </UiModal>

    <!-- Register -->
    <UiModal v-model="regOpen" title="Cash register" size="sm">
      <div v-if="session === undefined" class="space-y-3"><div v-for="i in 4" :key="i" class="skeleton h-9" /></div>
      <UiEmptyState v-else-if="!session" :icon="Wallet" title="No open register" description="Open a register before selling.">
        <NuxtLink to="/finance/registers"><UiButton variant="primary">Open register</UiButton></NuxtLink>
      </UiEmptyState>
      <template v-else>
        <p class="text-sm text-slate-500">{{ session.register }} · opened by {{ session.openedBy }} at {{ dateTime(session.openedAt) }}</p>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Opening float</dt><dd class="tabular">{{ money(session.openingFloat) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Cash sales</dt><dd class="tabular">+{{ money(session.cashSales) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Cash refunds</dt><dd class="tabular">−{{ money(session.cashRefunds) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Cash in / out</dt><dd class="tabular">+{{ money(session.cashIn) }} / −{{ money(session.cashOut) }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-2 font-semibold"><dt>Expected in drawer</dt><dd class="tabular">{{ money(session.expectedCash) }}</dd></div>
          <div v-for="n in session.nonCash" :key="n.method" class="flex justify-between text-slate-500"><dt>{{ n.method }} (not in drawer)</dt><dd class="tabular">{{ money(n.amount) }}</dd></div>
        </dl>
      </template>
      <template v-if="session" #footer><NuxtLink to="/finance/registers"><UiButton>Cash in/out & close</UiButton></NuxtLink></template>
    </UiModal>

    <!-- Recent transactions -->
    <UiDrawer v-model="recentOpen" title="Recent transactions" width="lg">
      <UiTabs v-model="recentTab" :tabs="[{ value: 'sales', label: 'Sales' }, { value: 'drafts', label: 'Drafts', count: cart.held.length }]" class="mb-4" />
      <template v-if="recentTab === 'sales'">
        <div v-if="!recent" class="space-y-2"><div v-for="i in 6" :key="i" class="skeleton h-12" /></div>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="s in recent" :key="s.id" class="flex items-center gap-3 py-2.5">
            <div class="min-w-0 flex-1">
              <p class="font-mono text-[12.5px] font-semibold" :class="s.status === 'void' && 'text-slate-400 line-through'">{{ s.number }}</p>
              <p class="truncate text-xs text-slate-500">{{ dateTime(s.date) }} · {{ s.customer }}</p>
            </div>
            <span class="font-semibold tabular">{{ money(s.grandTotal) }}</span>
            <UiRowActions :label="s.number" :view="`/sales/${s.id}`" :items="[{ label: 'Print invoice', icon: Printer, to: `/sales/${s.id}?print=1` }]" />
          </li>
        </ul>
      </template>
      <template v-else>
        <ul v-if="cart.held.length" class="divide-y divide-slate-100">
          <li v-for="h in cart.held" :key="h.id" class="flex items-center gap-3 py-2.5">
            <span class="grid size-9 place-items-center rounded-xl bg-amber-50 text-amber-600"><PauseCircle class="size-4" /></span>
            <div class="flex-1"><p class="text-sm font-semibold">{{ h.id }} · {{ h.customerName ?? 'Walk-in' }}</p><p class="text-xs text-slate-500">{{ h.items }} items · {{ money(h.total) }} · {{ dateTime(h.at) }}<template v-if="h.heldBy"> · {{ h.heldBy }}</template></p></div>
            <UiButton size="sm" variant="soft" :icon="Play" @click="resume(h.id)">Resume</UiButton>
            <UiButton size="sm" variant="ghost" :icon="Trash2" aria-label="Discard draft" @click="discard(h.id)" />
          </li>
        </ul>
        <UiEmptyState v-else :icon="PauseCircle" title="No drafts" description="Use Draft to park the current cart." />
      </template>
    </UiDrawer>

    <!-- Last receipt -->
    <UiModal v-model="receiptOpen" title="Last receipt" :description="cart.lastReceipt?.number" size="sm">
      <div v-if="cart.lastReceipt" class="rounded-xl border border-dashed border-slate-300 p-4 font-mono text-[11px] leading-snug">
        <p class="text-center font-bold">{{ brand }}</p>
        <p class="text-center">{{ cart.lastReceipt.number }} · {{ dateTime(cart.lastReceipt.at) }}</p>
        <p class="my-1.5 border-t border-dashed border-slate-400" />
        <p v-for="(l, i) in cart.lastReceipt.lines" :key="i" class="flex justify-between gap-2"><span>{{ l.name }} ×{{ l.qty }}</span><span>{{ money(l.total, false) }}</span></p>
        <p class="my-1.5 border-t border-dashed border-slate-400" />
        <p class="flex justify-between font-bold"><span>TOTAL</span><span>{{ money(cart.lastReceipt.total) }}</span></p>
        <p class="flex justify-between"><span>Change</span><span>{{ money(cart.lastReceipt.change, false) }}</span></p>
      </div>
      <PosReceipt v-if="receiptOpen && cart.lastReceipt" :receipt="cart.lastReceipt" />
      <template #footer>
        <UiButton variant="primary" :icon="Printer" @click="printNow">Print</UiButton>
      </template>
    </UiModal>
  </div>
</template>
