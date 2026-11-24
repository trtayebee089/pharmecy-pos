<script setup lang="ts">
import { gsap } from 'gsap'
import { Banknote, CreditCard, Smartphone, Plus, X, CheckCircle2, Printer } from 'lucide-vue-next'
import type { PaymentLine } from '~/types/domain'

const open = defineModel<boolean>({ default: false })
const props = withDefaults(defineProps<{ presetMethod?: PaymentLine['method'] }>(), { presetMethod: 'cash' })
const emit = defineEmits<{ completed: [number: string] }>()

const cart = useCartStore()
const auth = useAuthStore()
const salesApi = useSalesApi()
const toast = useToast()

const methods = [
  { code: 'cash', label: 'Cash', icon: Banknote },
  { code: 'bkash', label: 'bKash', icon: Smartphone },
  { code: 'nagad', label: 'Nagad', icon: Smartphone },
  { code: 'card', label: 'Card', icon: CreditCard },
] as const

const payments = ref<PaymentLine[]>([])
const submitting = ref(false)
const done = ref<{ number: string } | null>(null)
let idemKey = ''

watch(open, (o) => {
  if (o) {
    done.value = null
    payments.value = [{ method: props.presetMethod, amount: cart.total }]
    idemKey = crypto.randomUUID() // one key per checkout attempt: retries cannot double-charge
  }
})

const paid = computed(() => payments.value.reduce((s, p) => s + (Number(p.amount) || 0), 0))
const cashPaid = computed(() => payments.value.filter(p => p.method === 'cash').reduce((s, p) => s + (Number(p.amount) || 0), 0))
const balance = computed(() => Math.round((cart.total - paid.value) * 100) / 100)
const change = computed(() => balance.value < 0 ? Math.min(-balance.value, cashPaid.value) : 0)
const due = computed(() => Math.max(0, balance.value))
const walkIn = computed(() => !cart.customer || cart.customer.isWalkIn)
const canComplete = computed(() => !submitting.value && (due.value === 0 || !walkIn.value))

const quick = computed(() => {
  const t = cart.total
  const r = (n: number) => Math.ceil(t / n) * n
  return [...new Set([t, r(50), r(100), r(500), r(1000)])].filter(v => v >= t).slice(0, 4)
})

function addMethod(code: PaymentLine['method']) {
  const remaining = Math.max(0, balance.value)
  payments.value.push({ method: code, amount: remaining })
}

const successIcon = ref<HTMLElement | null>(null)
async function complete() {
  if (!canComplete.value) return
  submitting.value = true
  try {
    const res = await salesApi.complete(cart.payload(auth.warehouseId, payments.value), idemKey)
    done.value = { number: res.number }
    cart.lastReceipt = cart.snapshot(res.number, payments.value, change.value)
    await nextTick()
    if (successIcon.value && !prefersReducedMotion()) {
      gsap.fromTo(successIcon.value, { scale: 0, rotate: -30 }, { scale: 1, rotate: 0, duration: 0.6, ease: 'back.out(2)' })
      gsap.fromTo('[data-success-line]', { y: 10, opacity: 0 }, { y: 0, opacity: 1, stagger: 0.08, delay: 0.2, duration: 0.4 })
    }
    emit('completed', res.number)
  }
  catch (e: any) {
    toast.error('Sale not completed', e?.message)
  }
  finally { submitting.value = false }
}

function newSale() {
  cart.clear()
  open.value = false
}
function printReceipt() { window.print() }

onKeyStroke('Enter', (e) => {
  if (!open.value) return
  if (done.value) { e.preventDefault(); newSale() }
  else if (e.ctrlKey) { e.preventDefault(); complete() }
})
</script>

<template>
  <UiModal v-model="open" :title="done ? undefined : 'Collect payment'" :description="done ? undefined : `${cart.itemCount} items · ${cart.customer?.name ?? 'Walk-in customer'}`" size="lg" :dismissible="!submitting">
    <!-- Success state -->
    <div v-if="done" class="py-6 text-center">
      <div ref="successIcon" class="mx-auto grid size-20 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 shadow-[0_16px_40px_-10px_rgb(8_127_116/.7)]">
        <CheckCircle2 class="size-10 text-white" />
      </div>
      <h3 data-success-line class="mt-5 font-display text-2xl font-extrabold text-ink">Sale completed</h3>
      <p data-success-line class="mt-1 text-sm text-slate-500 tabular">{{ done.number }}</p>
      <div data-success-line class="mx-auto mt-6 grid max-w-sm grid-cols-2 gap-3 text-left">
        <div class="rounded-2xl bg-slate-50 p-4"><p class="label">Total</p><p class="mt-1 font-display text-xl font-extrabold tabular">{{ money(cart.total) }}</p></div>
        <div class="rounded-2xl bg-brand-50 p-4"><p class="label text-brand-700">Change</p><p class="mt-1 font-display text-xl font-extrabold text-brand-700 tabular">{{ money(change) }}</p></div>
      </div>
      <div data-success-line class="mt-6 flex justify-center gap-2">
        <UiButton :icon="Printer" size="lg" @click="printReceipt">Print receipt</UiButton>
        <UiButton variant="primary" size="lg" @click="newSale">New sale <UiKbd class="ml-1 border-white/20 bg-white/10 text-white">Enter</UiKbd></UiButton>
      </div>
      <PosReceipt v-if="cart.lastReceipt" :receipt="cart.lastReceipt" />
    </div>

    <!-- Payment entry -->
    <div v-else class="grid gap-6 md:grid-cols-[1fr_.9fr]">
      <div class="space-y-3">
        <div v-for="(p, i) in payments" :key="i" class="flex items-center gap-2 rounded-2xl border border-slate-200 p-2 pl-3">
          <select v-model="p.method" class="h-10 rounded-lg bg-transparent text-sm font-semibold focus:outline-none">
            <option v-for="m in methods" :key="m.code" :value="m.code">{{ m.label }}</option>
            <option value="customer_credit" :disabled="walkIn">Customer credit</option>
          </select>
          <input v-model.number="p.amount" type="number" min="0" step="0.01" class="h-10 min-w-0 flex-1 rounded-xl bg-slate-50 px-3 text-right font-display text-lg font-bold tabular focus:bg-white focus:ring-2 focus:ring-brand-300 focus:outline-none" :autofocus="i === 0">
          <input v-if="p.method !== 'cash'" v-model="p.reference" placeholder="Txn / ref" class="h-10 w-28 rounded-xl bg-slate-50 px-3 text-sm focus:outline-none">
          <button v-if="payments.length > 1" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove payment" @click="payments.splice(i, 1)"><X class="size-4" /></button>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-for="m in methods" :key="m.code" class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-dashed border-slate-300 px-3 text-[13px] font-semibold text-slate-600 transition hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700" @click="addMethod(m.code)">
            <Plus class="size-3.5" />{{ m.label }}
          </button>
        </div>
        <div>
          <p class="label mb-2">Quick cash</p>
          <div class="grid grid-cols-4 gap-2">
            <button v-for="q in quick" :key="q" class="h-11 rounded-xl bg-slate-100 text-sm font-bold text-slate-700 tabular transition hover:bg-brand-600 hover:text-white" @click="payments = [{ method: 'cash', amount: q }]">{{ num(q) }}</button>
          </div>
        </div>
      </div>

      <div class="rounded-3xl bg-gradient-to-b from-brand-950 to-brand-900 p-5 text-white">
        <p class="text-xs font-semibold tracking-wider text-brand-200/70 uppercase">Amount due</p>
        <p class="mt-1 font-display text-3xl font-extrabold whitespace-nowrap tabular xl:text-4xl">{{ money(cart.total) }}</p>
        <dl class="mt-5 space-y-2.5 text-sm">
          <div class="flex justify-between text-brand-100/80"><dt>Paid</dt><dd class="font-semibold tabular">{{ money(paid) }}</dd></div>
          <div class="flex justify-between" :class="change ? 'text-brand-300' : 'text-brand-100/50'"><dt>Change</dt><dd class="font-bold tabular">{{ money(change) }}</dd></div>
          <div class="flex justify-between" :class="due ? 'text-amber-300' : 'text-brand-100/50'"><dt>Due (customer credit)</dt><dd class="font-bold tabular">{{ money(due) }}</dd></div>
        </dl>
        <p v-if="due && walkIn" class="mt-4 rounded-xl bg-amber-400/15 p-3 text-xs text-amber-200">A due balance needs a named customer. Select a customer or collect the full amount.</p>
        <p class="mt-4 text-[11px] text-brand-200/50">Final totals are confirmed by the server on completion.</p>
      </div>
    </div>

    <template v-if="!done" #footer>
      <UiButton variant="ghost" :disabled="submitting" @click="open = false">Back to cart</UiButton>
      <UiButton class="primary-action" variant="primary" size="lg" :loading="submitting" :disabled="!canComplete" magnetic @click="complete">
        Complete sale <UiKbd class="ml-1 border-white/20 bg-white/10 text-white">Ctrl ↵</UiKbd>
      </UiButton>
    </template>
  </UiModal>
</template>
