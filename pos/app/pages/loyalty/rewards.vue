<script setup lang="ts">
import { gsap } from 'gsap'
import { Star, Gift, Hourglass, Search, SlidersHorizontal, Megaphone, Plus, Sparkles, UserRound, Copy, Pause, Play } from 'lucide-vue-next'
import type { Campaign, RewardEntry, RewardWallet } from '~/types/people'

definePageMeta({ title: 'Reward wallets' })
const api = useLoyaltyApi()
const auth = useAuthStore()
const toast = useToast()

const tab = ref<'wallets' | 'campaigns'>('wallets')
const wallets = ref<RewardWallet[]>([])
const loading = ref(true)
const q = ref('')
const tier = ref<'all' | 'Silver' | 'Gold' | 'Platinum'>('all')
const list = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { wallets.value = await api.wallets() }
  catch (e: any) { toast.error('Could not load wallets', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(list.value?.querySelectorAll('tr') ?? [], { y: 6, stagger: 0.02 })
}
onMounted(load)

const filtered = computed(() => wallets.value.filter(w =>
  `${w.customer} ${w.phone}`.toLowerCase().includes(q.value.toLowerCase()) && (tier.value === 'all' || w.tier === tier.value)))
const outstanding = computed(() => wallets.value.reduce((s, w) => s + w.balance, 0))
const redeemed = computed(() => wallets.value.reduce((s, w) => s + w.lifetimeRedeemed, 0))
const expiring = computed(() => wallets.value.reduce((s, w) => s + w.expiringSoon, 0))
const tierTone = { Silver: 'neutral', Gold: 'warning', Platinum: 'violet' } as const

// ---- Ledger drawer ----
const drawer = ref(false)
const current = ref<RewardWallet | null>(null)
const ledger = ref<RewardEntry[]>([])
const ledgerLoading = ref(false)
async function openWallet(w: RewardWallet) {
  current.value = w
  drawer.value = true
  ledgerLoading.value = true
  ledger.value = await api.ledger(w.customerId)
  ledgerLoading.value = false
}
const entryTone = { opening: 'neutral', earn: 'success', redeem: 'brand', expire: 'danger', adjust: 'warning', reverse: 'neutral' } as const

// ---- Adjust ----
const adjOpen = ref(false)
const adjDirection = ref<'add' | 'deduct'>('add')
const adjPoints = ref(0)
const adjReason = ref('')
const adjusting = ref(false)
const balanceEl = ref<HTMLElement | null>(null)
let idemKey = ''
function startAdjust() { adjDirection.value = 'add'; adjPoints.value = 0; adjReason.value = ''; idemKey = crypto.randomUUID(); adjOpen.value = true }
const signed = computed(() => (adjDirection.value === 'add' ? 1 : -1) * (Number(adjPoints.value) || 0))
const adjError = computed(() => {
  if (!adjPoints.value || adjPoints.value <= 0 || !Number.isInteger(Number(adjPoints.value))) return 'Enter whole points above 0'
  if (current.value && current.value.balance + signed.value < 0) return 'Balance cannot go below zero'
  if (!adjReason.value.trim()) return 'A reason is required for every manual adjustment'
  return ''
})
async function adjust() {
  if (adjError.value || !current.value) return
  adjusting.value = true
  try {
    await api.adjust(current.value.customerId, signed.value, adjReason.value, idemKey)
    current.value.balance += signed.value
    ledger.value.unshift({ id: Date.now(), date: new Date().toISOString(), type: 'adjust', points: signed.value, ref: 'Manual', note: adjReason.value })
    adjOpen.value = false
    pop(balanceEl.value)
    toast.success('Points adjusted', `${signed.value > 0 ? '+' : ''}${signed.value} for ${current.value.customer}`)
  }
  catch (e: any) { toast.error('Adjustment failed', e?.message) }
  finally { adjusting.value = false }
}

// ---- Campaigns ----
const campaigns = ref<Campaign[]>([])
const cardsEl = ref<HTMLElement | null>(null)
async function loadCampaigns() {
  campaigns.value = await api.campaigns.all()
  await nextTick()
  if (cardsEl.value && !prefersReducedMotion()) gsap.fromTo(cardsEl.value.children, { opacity: 0, y: 16, rotateX: -8 }, { opacity: 1, y: 0, rotateX: 0, stagger: 0.07, duration: 0.5, ease: 'power3.out' })
}
watch(tab, t => t === 'campaigns' && loadCampaigns())
const campOpen = ref(false)
const campForm = ref<Partial<Campaign>>({})
const savingCamp = ref(false)
function editCampaign(c?: Campaign) {
  const today = new Date().toISOString().slice(0, 10)
  campForm.value = c ? { ...c } : { name: '', multiplier: 2, startsAt: today, endsAt: today, isActive: true, description: '' }
  campOpen.value = true
}
async function saveCampaign() {
  const f = campForm.value
  if (!f.name?.trim() || !f.startsAt || !f.endsAt || (f.multiplier ?? 0) < 1) { toast.warning('Name, dates and a multiplier of at least 1× are required'); return }
  if (f.endsAt < f.startsAt) { toast.warning('End date must be after the start date'); return }
  savingCamp.value = true
  await api.campaigns.save({ ...f, multiplier: Number(f.multiplier) })
  savingCamp.value = false
  campOpen.value = false
  toast.success(f.id ? 'Campaign updated' : 'Campaign created', f.name)
  loadCampaigns()
}
// ---- Row actions ----
const { ask } = useConfirm()
const canManage = computed(() => auth.can('loyalty.manage'))
async function adjustFor(w: RewardWallet) { await openWallet(w); startAdjust() }
function walletActions(w: RewardWallet) {
  return [
    { label: 'Adjust points', icon: SlidersHorizontal, onClick: () => adjustFor(w), hidden: !canManage.value },
    { label: 'Ledger', icon: Star, onClick: () => openWallet(w) },
    { label: 'Customer profile', icon: UserRound, to: `/customers/${w.customerId}` },
  ]
}
const viewCamp = ref<Campaign | null>(null)
const viewCampOpen = ref(false)
async function removeCampaign(c: Campaign) {
  const { ok } = await ask({ title: `Delete campaign "${c.name}"?`, description: 'Points already earned under it are kept. Future sales stop using its multiplier.', confirmLabel: 'Delete campaign' })
  if (!ok) return
  try { await api.campaigns.remove(c.id); toast.success('Campaign deleted', c.name); loadCampaigns() }
  catch (e: any) { toast.error('Could not delete', e?.message) }
}
async function toggleCampaign(c: Campaign) {
  await api.campaigns.save({ ...c, isActive: !c.isActive })
  toast.success(c.isActive ? 'Campaign paused' : 'Campaign resumed', c.name)
  loadCampaigns()
}
function duplicateCampaign(c: Campaign) {
  editCampaign({ ...c })
  campForm.value = { ...campForm.value, id: undefined, name: `${c.name} (copy)`, isActive: false }
}
function campaignActions(c: Campaign) {
  return [
    { label: c.isActive ? 'Pause' : 'Resume', icon: c.isActive ? Pause : Play, onClick: () => toggleCampaign(c), hidden: !canManage.value },
    { label: 'Duplicate', icon: Copy, onClick: () => duplicateCampaign(c), hidden: !canManage.value },
  ]
}

const campStatus = (c: Campaign) => {
  const t = new Date().toISOString().slice(0, 10)
  if (!c.isActive) return { label: 'Paused', tone: 'neutral' as const }
  if (t < c.startsAt) return { label: 'Scheduled', tone: 'info' as const }
  if (t > c.endsAt) return { label: 'Ended', tone: 'neutral' as const }
  return { label: 'Running', tone: 'success' as const }
}
</script>

<template>
  <div>
    <UiPageHeader title="Reward wallets" description="Points balances come from the reward ledger. Every manual change is an adjustment entry with a reason." />

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
      <UiStatCard label="Points outstanding" :value="outstanding" format="number" :icon="Star" :loading="loading" hint="liability at point value" />
      <UiStatCard label="Points redeemed" :value="redeemed" format="number" :icon="Gift" tone="violet" :loading="loading" hint="lifetime" />
      <UiStatCard label="Expiring in 30 days" :value="expiring" format="number" :icon="Hourglass" tone="amber" :loading="loading" />
    </div>

    <div class="mb-4"><UiTabs v-model="tab" :tabs="[{ value: 'wallets', label: 'Wallets', count: wallets.length }, { value: 'campaigns', label: 'Campaigns' }]" /></div>

    <div v-if="tab === 'wallets'" class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
        <div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Customer or phone…" /></div>
        <UiSegmented v-model="tier" :options="[{ value: 'all', label: 'All' }, { value: 'Silver', label: 'Silver' }, { value: 'Gold', label: 'Gold' }, { value: 'Platinum', label: 'Platinum' }]" />
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-sm">
          <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Customer</th><th class="label text-left">Tier</th><th class="label text-right">Balance</th><th class="label text-right">Earned</th><th class="label text-right">Redeemed</th><th class="label px-4 text-right">Expiring</th><th class="label px-4 text-right">Actions</th></tr></thead>
          <tbody v-if="loading"><tr v-for="i in 8" :key="i"><td colspan="7" class="px-4 py-2"><div class="skeleton h-8" /></td></tr></tbody>
          <tbody v-else ref="list">
            <tr v-for="w in filtered" :key="w.id" class="cursor-pointer border-b border-slate-50 transition-colors hover:bg-brand-50/40" @click="openWallet(w)">
              <td class="px-4 py-3"><p class="font-semibold text-ink">{{ w.customer }}</p><p class="text-xs text-slate-500 tabular">{{ w.phone }}</p></td>
              <td><UiBadge v-if="w.tier" :tone="tierTone[w.tier]">{{ w.tier }}</UiBadge><span v-else class="text-slate-400">—</span></td>
              <td class="text-right font-display font-extrabold text-brand-700 tabular">{{ num(w.balance) }}</td>
              <td class="text-right tabular text-slate-600">{{ num(w.lifetimeEarned) }}</td>
              <td class="text-right tabular text-slate-600">{{ num(w.lifetimeRedeemed) }}</td>
              <td class="px-4 text-right tabular" :class="w.expiringSoon ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ w.expiringSoon || '—' }}</td>
              <td class="px-3"><UiRowActions :label="w.customer" :view="() => openWallet(w)" :items="walletActions(w)" /></td>
            </tr>
          </tbody>
        </table>
        <UiEmptyState v-if="!loading && !filtered.length" :icon="Star" title="No wallets match" />
      </div>
    </div>

    <div v-else>
      <div class="mb-4 flex justify-end"><UiButton v-if="auth.can('loyalty.manage')" variant="primary" :icon="Plus" @click="editCampaign()">New campaign</UiButton></div>
      <div ref="cardsEl" class="grid gap-4 [perspective:900px] md:grid-cols-2 xl:grid-cols-3">
        <div v-for="c in campaigns" :key="c.id" class="card group relative overflow-hidden p-5">
          <div class="pointer-events-none absolute -top-10 -right-10 size-32 rounded-full bg-brand-400/15 blur-2xl transition-transform duration-500 group-hover:scale-150" />
          <div class="relative flex items-start justify-between gap-3">
            <span class="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><Megaphone class="size-5" /></span>
            <UiBadge :tone="campStatus(c).tone" dot>{{ campStatus(c).label }}</UiBadge>
          </div>
          <p class="relative mt-3 font-display text-base font-bold">{{ c.name }}</p>
          <p class="relative mt-1 text-[13px] text-slate-500">{{ c.description }}</p>
          <div class="relative mt-4 flex items-end justify-between">
            <div><p class="font-display text-3xl font-extrabold text-brand-700">{{ c.multiplier }}×</p><p class="text-xs text-slate-500">{{ date(c.startsAt) }} → {{ date(c.endsAt) }}</p></div>
            <UiRowActions
              :label="c.name" :view="() => { viewCamp = c; viewCampOpen = true }"
              :edit="canManage ? () => editCampaign(c) : undefined"
              :remove="canManage ? () => removeCampaign(c) : undefined"
              :items="campaignActions(c)"
            />
          </div>
        </div>
      </div>
    </div>

    <UiDrawer v-model="viewCampOpen" :title="viewCamp?.name" description="Points campaign" width="md">
      <dl v-if="viewCamp" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Status', campStatus(viewCamp).label], ['Multiplier', `${viewCamp.multiplier}× points`], ['Runs', `${date(viewCamp.startsAt)} → ${date(viewCamp.endsAt)}`], ['Description', viewCamp.description || '—']]" :key="k" data-stagger class="flex justify-between gap-6 py-3"><dt class="text-slate-500">{{ k }}</dt><dd class="text-right font-medium">{{ v }}</dd></div>
      </dl>
      <template #footer>
        <UiButton variant="ghost" @click="viewCampOpen = false">Close</UiButton>
        <UiButton v-if="canManage && viewCamp" variant="primary" @click="viewCampOpen = false; editCampaign(viewCamp!)">Edit</UiButton>
      </template>
    </UiDrawer>

    <UiDrawer v-model="drawer" :title="current?.customer" :description="current?.phone ?? ''">
      <div v-if="current" class="mb-5 flex items-center justify-between rounded-2xl bg-gradient-to-br from-brand-800 to-brand-950 p-5 text-white" data-stagger>
        <div>
          <p class="text-xs font-semibold tracking-wider text-brand-200/80 uppercase">Balance</p>
          <p ref="balanceEl" class="font-display text-3xl font-extrabold tabular">{{ num(current.balance) }} <span class="text-base font-semibold text-brand-200">pts</span></p>
        </div>
        <UiBadge v-if="current.tier" :tone="tierTone[current.tier]"><Sparkles class="size-3" />{{ current.tier }}</UiBadge>
      </div>
      <p class="label mb-2" data-stagger>Ledger</p>
      <div v-if="ledgerLoading" class="space-y-2"><div v-for="i in 5" :key="i" class="skeleton h-10" /></div>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="e in ledger" :key="e.id" class="flex items-center gap-3 py-2.5 text-sm">
          <UiBadge :tone="entryTone[e.type]" class="capitalize">{{ e.type }}</UiBadge>
          <span class="min-w-0 flex-1 truncate text-xs text-slate-500">{{ date(e.date) }} · {{ e.ref }}<template v-if="e.note"> · {{ e.note }}</template></span>
          <span class="font-semibold tabular" :class="e.points >= 0 ? 'text-emerald-700' : 'text-rose-600'">{{ e.points > 0 ? '+' : '' }}{{ num(e.points) }}</span>
        </li>
      </ul>
      <template #footer>
        <UiButton v-if="auth.can('loyalty.manage')" variant="primary" :icon="SlidersHorizontal" @click="startAdjust">Adjust points</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="adjOpen" title="Adjust points" :description="current ? `${current.customer} · balance ${num(current.balance)} pts` : ''" size="sm" :dismissible="!adjusting">
      <div class="space-y-4">
        <UiSegmented v-model="adjDirection" :options="[{ value: 'add', label: 'Add points' }, { value: 'deduct', label: 'Deduct points' }]" />
        <UiInput v-model.number="adjPoints" type="number" label="Points" inputmode="numeric" :hint="current ? `New balance ${num(current.balance + signed)} pts` : ''" />
        <UiInput v-model="adjReason" label="Reason (required)" placeholder="e.g. goodwill for delayed delivery" />
        <p v-if="adjError && adjPoints" class="text-xs font-medium text-rose-600">{{ adjError }}</p>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="adjOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="adjusting" :disabled="!!adjError" @click="adjust">Post adjustment</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="campOpen" :title="campForm.id ? 'Edit campaign' : 'New campaign'" :dismissible="!savingCamp">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><UiInput v-model="campForm.name" label="Name *" /></div>
        <UiInput v-model.number="campForm.multiplier" type="number" label="Points multiplier" hint="e.g. 2 = double points" />
        <div class="pt-6"><UiSwitch v-model="campForm.isActive" label="Active" /></div>
        <UiInput v-model="campForm.startsAt" type="date" label="Starts" />
        <UiInput v-model="campForm.endsAt" type="date" label="Ends" />
        <div class="sm:col-span-2"><UiTextarea v-model="campForm.description" label="What qualifies" :rows="2" /></div>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="campOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="savingCamp" @click="saveCampaign">Save campaign</UiButton>
      </template>
    </UiModal>
  </div>
</template>
