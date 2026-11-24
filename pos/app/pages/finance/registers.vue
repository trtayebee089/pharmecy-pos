<script setup lang="ts">
import { gsap } from 'gsap'
import { Banknote, Lock, LockOpen, Clock, ArrowDownLeft, ArrowUpRight, Smartphone, CheckCircle2, AlertTriangle } from 'lucide-vue-next'
import type { RegisterSession } from '~/types/domain'

definePageMeta({ title: 'Cash registers' })
const api = useRegisterApi()
const toast = useToast()
const sessions = ref<RegisterSession[]>([])
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  sessions.value = await api.sessions()
  loading.value = false
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
  const pulse = root.value?.querySelector('[data-pulse]')
  if (pulse && !prefersReducedMotion()) gsap.to(pulse, { scale: 1.6, opacity: 0, duration: 1.4, repeat: -1, ease: 'power1.out' })
}
onMounted(load)
const current = computed(() => sessions.value.find(s => s.status === 'open') ?? null)
const history = computed(() => sessions.value.filter(s => s.status === 'closed'))

// ---- Open ----
const openFloat = ref(5000)
const opening = ref(false)
async function openRegister() {
  opening.value = true
  const s = await api.open(1, Number(openFloat.value) || 0)
  sessions.value.unshift(s)
  opening.value = false
  toast.success('Register opened', `Float ${money(s.openingFloat)}`)
}

// ---- Close with denomination count ----
const closeOpen = ref(false)
const notes = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1]
const counts = ref<Record<number, number>>({})
const closeNote = ref('')
const closing = ref(false)
const countedEl = ref<HTMLElement | null>(null)
const counted = computed(() => notes.reduce((s, n) => s + n * (counts.value[n] || 0), 0))
const diff = computed(() => current.value ? Math.round((counted.value - current.value.expectedCash) * 100) / 100 : 0)
watch(counted, () => pop(countedEl.value))

function startClose() {
  counts.value = Object.fromEntries(notes.map(n => [n, 0]))
  closeNote.value = ''
  closeOpen.value = true
}
async function closeRegister() {
  if (!current.value) return
  if (diff.value !== 0 && !closeNote.value.trim()) { toast.warning('Explain the difference', 'A note is required when counted cash does not match.'); return }
  closing.value = true
  const session = current.value
  const finalDiff = diff.value // capture before the session leaves `current`
  const finalCount = counted.value
  await api.close(session.id, finalCount, closeNote.value)
  Object.assign(session, { status: 'closed', countedCash: finalCount, difference: finalDiff, closedAt: new Date().toISOString() })
  closing.value = false
  closeOpen.value = false
  toast.success('Register closed', finalDiff === 0 ? 'Cash matches exactly.' : `Difference ${money(finalDiff)} recorded.`)
}

// ---- Cash in / out ----
const moveOpen = ref(false)
const move = reactive({ type: 'cash_in' as 'cash_in' | 'cash_out', amount: 0, reason: '' })
const moving = ref(false)
let moveKey = ''
function startMove(type: 'cash_in' | 'cash_out') { Object.assign(move, { type, amount: 0, reason: '' }); moveKey = crypto.randomUUID(); moveOpen.value = true }
const moveError = computed(() => {
  if (!(move.amount > 0)) return 'Enter an amount'
  if (move.type === 'cash_out' && current.value && move.amount > current.value.expectedCash) return 'More than the cash in the drawer'
  if (!move.reason.trim()) return 'A reason is required'
  return ''
})
async function submitMove() {
  if (!current.value || moveError.value) return
  moving.value = true
  try {
    await api.cashMovement(current.value.id, move.type, move.amount, move.reason, moveKey)
    if (move.type === 'cash_in') { current.value.cashIn += move.amount; current.value.expectedCash += move.amount }
    else { current.value.cashOut += move.amount; current.value.expectedCash -= move.amount }
    toast.success(move.type === 'cash_in' ? 'Cash in recorded' : 'Cash out recorded', `${money(move.amount)} · ${move.reason}`)
    moveOpen.value = false
  }
  catch (e: any) { toast.error('Not recorded', e?.message) }
  finally { moving.value = false }
}

const elapsed = (iso: string) => {
  const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  return `${Math.floor(m / 60)}h ${m % 60}m`
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Cash registers" description="Open with a float, sell, then close by counting the drawer. Differences are recorded, never edited away." />

    <div v-if="loading" class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]"><div class="skeleton h-80" /><div class="skeleton h-80" /></div>

    <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
      <!-- Current session -->
      <div v-if="current" class="card overflow-hidden" data-reveal>
        <div class="relative overflow-hidden bg-gradient-to-br from-brand-800 to-brand-950 p-6 text-white">
          <div class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-brand-400/20 blur-3xl" />
          <div class="relative flex items-start justify-between">
            <div>
              <p class="flex items-center gap-2 text-xs font-semibold tracking-wider text-brand-200/80 uppercase">
                <span class="relative flex size-2"><span data-pulse class="absolute inline-flex size-full rounded-full bg-emerald-400" /><span class="relative inline-flex size-2 rounded-full bg-emerald-400" /></span>
                {{ current.register }} · open
              </p>
              <p class="mt-2 text-sm text-brand-100/70">Expected cash in drawer</p>
              <p class="font-display text-4xl font-extrabold whitespace-nowrap tabular">{{ money(current.expectedCash) }}</p>
            </div>
            <div class="text-right text-sm text-brand-100/70">
              <p class="flex items-center justify-end gap-1.5"><Clock class="size-4" />{{ elapsed(current.openedAt) }}</p>
              <p class="mt-1">by {{ current.openedBy }}</p>
            </div>
          </div>
        </div>
        <div class="grid gap-px bg-slate-100 sm:grid-cols-2">
          <div class="bg-white p-5">
            <p class="label mb-3">Cash movement</p>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between"><dt class="text-slate-500">Opening float</dt><dd class="tabular">{{ money(current.openingFloat) }}</dd></div>
              <div class="flex justify-between"><dt class="flex items-center gap-1 text-slate-500"><ArrowDownLeft class="size-3.5 text-emerald-600" />Cash sales</dt><dd class="tabular">+{{ money(current.cashSales) }}</dd></div>
              <div class="flex justify-between"><dt class="flex items-center gap-1 text-slate-500"><ArrowUpRight class="size-3.5 text-rose-500" />Cash refunds</dt><dd class="tabular">−{{ money(current.cashRefunds) }}</dd></div>
              <div class="flex justify-between"><dt class="text-slate-500">Cash in / out</dt><dd class="tabular">+{{ money(current.cashIn) }} / −{{ money(current.cashOut) }}</dd></div>
              <div class="flex justify-between border-t border-slate-100 pt-2 font-semibold"><dt>Expected</dt><dd class="tabular">{{ money(current.expectedCash) }}</dd></div>
            </dl>
          </div>
          <div class="bg-white p-5">
            <p class="label mb-3">Non-cash (not in drawer)</p>
            <ul class="space-y-2 text-sm">
              <li v-for="n in current.nonCash" :key="n.method" class="flex justify-between"><span class="flex items-center gap-1.5 text-slate-500"><Smartphone class="size-3.5" />{{ n.method }}</span><span class="tabular">{{ money(n.amount) }}</span></li>
              <li v-if="!current.nonCash.length" class="text-slate-400">None yet</li>
            </ul>
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-100 p-4">
          <UiButton :icon="ArrowDownLeft" @click="startMove('cash_in')">Cash in</UiButton>
          <UiButton :icon="ArrowUpRight" @click="startMove('cash_out')">Cash out</UiButton>
          <UiButton variant="primary" :icon="Lock" magnetic @click="startClose">Close & count</UiButton>
        </div>
      </div>

      <!-- No open session -->
      <div v-else class="card p-8 text-center" data-reveal>
        <div class="mx-auto grid size-16 place-items-center rounded-2xl bg-brand-50 text-brand-700"><LockOpen class="size-7" /></div>
        <h2 class="mt-4 font-display text-xl font-extrabold">No open register</h2>
        <p class="mt-1 text-sm text-slate-500">Open a session to start selling at the counter.</p>
        <div class="mx-auto mt-6 flex max-w-xs items-end gap-2">
          <div class="flex-1"><UiInput v-model.number="openFloat" type="number" label="Opening float (৳)" :icon="Banknote" /></div>
          <UiButton variant="primary" :loading="opening" @click="openRegister">Open</UiButton>
        </div>
      </div>

      <!-- History -->
      <div class="card overflow-hidden" data-reveal>
        <div class="border-b border-slate-100 px-5 py-4"><p class="font-display text-[15px] font-bold">Recent sessions</p></div>
        <ul class="divide-y divide-slate-100">
          <li v-for="s in history" :key="s.id" class="flex items-center gap-3 px-5 py-3">
            <span class="grid size-9 place-items-center rounded-xl" :class="s.difference ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600'">
              <component :is="s.difference ? AlertTriangle : CheckCircle2" class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold">{{ s.register }} · {{ s.openedBy }}</p>
              <p class="text-xs text-slate-500">{{ date(s.openedAt) }} · counted {{ money(s.countedCash) }}</p>
            </div>
            <span class="text-sm font-semibold tabular" :class="!s.difference ? 'text-slate-400' : s.difference > 0 ? 'text-emerald-600' : 'text-rose-600'">
              {{ s.difference ? (s.difference > 0 ? '+' : '') + money(s.difference) : 'exact' }}
            </span>
          </li>
        </ul>
      </div>
    </div>

    <UiModal v-model="moveOpen" :title="move.type === 'cash_in' ? 'Cash in' : 'Cash out'" :description="move.type === 'cash_in' ? 'Money added to the drawer, e.g. change float top-up.' : 'Money taken from the drawer, e.g. petty expense or bank deposit.'" size="sm" :dismissible="!moving">
      <div class="space-y-4">
        <UiInput v-model.number="move.amount" type="number" label="Amount (৳)" :icon="Banknote" />
        <UiInput v-model="move.reason" label="Reason" placeholder="e.g. bank deposit, tea & snacks" />
        <p v-if="moveError && move.amount" class="text-xs font-medium text-rose-600">{{ moveError }}</p>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="moveOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="moving" :disabled="!!moveError" @click="submitMove">Record</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="closeOpen" title="Close register" description="Count the notes and coins in the drawer." size="lg" :dismissible="!closing">
      <div class="grid gap-6 md:grid-cols-[1fr_.8fr]">
        <div class="grid grid-cols-2 gap-2">
          <label v-for="n in notes" :key="n" class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 focus-within:border-brand-400">
            <span class="w-14 font-display text-sm font-bold text-slate-600 tabular">৳{{ n }}</span>
            <span class="text-slate-300">×</span>
            <input v-model.number="counts[n]" type="number" min="0" class="h-8 w-full bg-transparent text-right font-semibold tabular focus:outline-none" :aria-label="`Count of ${n} taka`">
          </label>
        </div>
        <div class="space-y-3">
          <div class="rounded-2xl bg-slate-50 p-4"><p class="label">Expected</p><p class="mt-1 font-display text-xl font-extrabold tabular">{{ money(current?.expectedCash) }}</p></div>
          <div class="rounded-2xl bg-brand-50 p-4"><p class="label text-brand-700">Counted</p><p ref="countedEl" class="mt-1 font-display text-xl font-extrabold text-brand-800 tabular">{{ money(counted) }}</p></div>
          <div class="rounded-2xl p-4" :class="diff === 0 ? 'bg-emerald-50 text-emerald-700' : diff > 0 ? 'bg-sky-50 text-sky-700' : 'bg-rose-50 text-rose-700'">
            <p class="label" :class="diff === 0 ? 'text-emerald-700' : diff > 0 ? 'text-sky-700' : 'text-rose-700'">{{ diff === 0 ? 'Exact' : diff > 0 ? 'Over' : 'Short' }}</p>
            <p class="mt-1 font-display text-xl font-extrabold tabular">{{ money(diff) }}</p>
          </div>
          <UiTextarea v-if="diff !== 0" v-model="closeNote" placeholder="Explain the difference (required)" :rows="2" />
        </div>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="closeOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Lock" :loading="closing" @click="closeRegister">Close register</UiButton>
      </template>
    </UiModal>
  </div>
</template>
