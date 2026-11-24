<script setup lang="ts">
import { gsap } from 'gsap'
import { Crown, Check, Users, Search, Plus, X, CalendarClock, RefreshCw, ArrowUpDown, Pause, Play, Ban } from 'lucide-vue-next'
import type { Member, MembershipPlan } from '~/types/people'

definePageMeta({ title: 'Membership plans' })
const api = useLoyaltyApi()
const auth = useAuthStore()
const toast = useToast()

const tab = ref<'plans' | 'members'>('plans')
const plans = ref<MembershipPlan[]>([])
const members = ref<Member[]>([])
const loading = ref(true)
const cards = ref<HTMLElement | null>(null)

onMounted(async () => {
  try { [plans.value, members.value] = await Promise.all([api.plans(), api.members()]) }
  catch (e: any) { toast.error('Could not load memberships', e?.message) }
  finally { loading.value = false }
  await nextTick()
  if (cards.value && !prefersReducedMotion()) gsap.fromTo(cards.value.children, { opacity: 0, y: 30, scale: 0.96 }, { opacity: 1, y: 0, scale: 1, stagger: 0.1, duration: 0.6, ease: 'back.out(1.4)' })
})

const theme = {
  Silver: { card: 'from-slate-100 via-white to-slate-200 text-ink', ring: 'ring-slate-300', chip: 'bg-slate-900/5 text-slate-700', accent: 'text-slate-700' },
  Gold: { card: 'from-amber-200 via-amber-50 to-amber-300 text-amber-950', ring: 'ring-amber-300', chip: 'bg-amber-900/10 text-amber-900', accent: 'text-amber-800' },
  Platinum: { card: 'from-brand-950 via-brand-800 to-brand-950 text-white', ring: 'ring-brand-400', chip: 'bg-white/10 text-brand-100', accent: 'text-brand-200' },
} as const

function tilt(e: PointerEvent) {
  if (prefersReducedMotion()) return
  const el = e.currentTarget as HTMLElement
  const r = el.getBoundingClientRect()
  gsap.to(el, { rotateY: ((e.clientX - r.left) / r.width - 0.5) * 10, rotateX: -((e.clientY - r.top) / r.height - 0.5) * 8, duration: 0.4, ease: 'power2.out' })
}
function untilt(e: PointerEvent) { gsap.to(e.currentTarget as HTMLElement, { rotateX: 0, rotateY: 0, duration: 0.6, ease: 'elastic.out(1, 0.5)' }) }

// ---- Edit plan ----
const editOpen = ref(false)
const form = ref<MembershipPlan | null>(null)
const newBenefit = ref('')
const saving = ref(false)
function edit(p: MembershipPlan) { form.value = structuredClone(toRaw(p)); newBenefit.value = ''; editOpen.value = true }
function addBenefit() { if (form.value && newBenefit.value.trim()) { form.value.benefits.push(newBenefit.value.trim()); newBenefit.value = '' } }
async function save() {
  if (!form.value) return
  const f = form.value
  if (!f.name.trim() || f.discountPct < 0 || f.discountPct > 50 || f.pointsMultiplier < 1 || f.durationMonths < 1) { toast.warning('Check the plan values', 'Discount 0–50%, multiplier ≥ 1×, duration ≥ 1 month.'); return }
  saving.value = true
  const saved = await api.savePlan({ ...f, minAnnualSpend: Number(f.minAnnualSpend), discountPct: Number(f.discountPct), pointsMultiplier: Number(f.pointsMultiplier), fee: Number(f.fee), durationMonths: Number(f.durationMonths) })
  const i = plans.value.findIndex(p => p.id === saved.id)
  if (i >= 0) plans.value[i] = saved
  saving.value = false
  editOpen.value = false
  toast.success('Plan updated', `${saved.name} applies to new joins and renewals.`)
}

// ---- Members ----
const q = ref('')
const planFilter = ref<'all' | 'Silver' | 'Gold' | 'Platinum'>('all')
const daysLeft = (m: Member) => daysUntil(m.endsAt)
const filteredMembers = computed(() => members.value.filter(m =>
  `${m.customer} ${m.phone}`.toLowerCase().includes(q.value.toLowerCase()) && (planFilter.value === 'all' || m.plan === planFilter.value)))
const renewalsSoon = computed(() => members.value.filter(m => m.status === 'active' && daysLeft(m) <= 30).length)
const planTone = { Silver: 'neutral', Gold: 'warning', Platinum: 'violet' } as const

// ---- Row actions ----
const { ask } = useConfirm()
const canManage = computed(() => auth.can('loyalty.manage'))
const viewPlan = ref<MembershipPlan | null>(null)
const viewPlanOpen = ref(false)
async function togglePlan(p: MembershipPlan) {
  const saved = await api.savePlan({ ...p, isActive: !p.isActive })
  Object.assign(p, saved)
  toast.success(p.isActive ? 'Plan open for new members' : 'Plan paused', p.name)
}
async function removePlan(p: MembershipPlan) {
  const { ok } = await ask({ title: `Delete ${p.name}?`, description: 'Only plans without members can be deleted.', confirmLabel: 'Delete plan' })
  if (!ok) return
  try { await api.deletePlan(p.id); plans.value = plans.value.filter(x => x.id !== p.id); toast.success('Plan deleted', p.name) }
  catch (e: any) { toast.error('Could not delete', e?.message) }
}
function planActions(p: MembershipPlan) {
  return [{ label: p.isActive ? 'Pause new joins' : 'Open for new members', icon: p.isActive ? Pause : Play, onClick: () => togglePlan(p), hidden: !canManage.value }]
}

async function renew(m: Member) {
  const { ok } = await ask({ title: `Renew ${m.customer} (${m.plan})?`, description: 'Extends the membership by the plan duration from its current end date. A joining fee, if set, is collected at the counter.', confirmLabel: 'Renew', tone: 'primary' })
  if (!ok) return
  try {
    const r = await api.renewMember(m.id, crypto.randomUUID())
    m.endsAt = r.endsAt
    m.status = 'active'
    toast.success('Membership renewed', `${m.customer} · until ${date(r.endsAt)}`)
  }
  catch (e: any) { toast.error('Renewal failed', e?.message) }
}
const planOpen = ref(false)
const planTarget = ref<Member | null>(null)
const newPlan = ref<Member['plan']>('Silver')
const planReason = ref('')
function startChangePlan(m: Member) { planTarget.value = m; newPlan.value = m.plan; planReason.value = ''; planOpen.value = true }
async function changePlan() {
  const m = planTarget.value
  if (!m || newPlan.value === m.plan || !planReason.value.trim()) return
  try {
    await api.changeMemberPlan(m.id, newPlan.value, planReason.value)
    toast.success('Plan changed', `${m.customer}: ${m.plan} → ${newPlan.value}`)
    m.plan = newPlan.value
    m.source = 'manual'
    planOpen.value = false
  }
  catch (e: any) { toast.error('Could not change plan', e?.message) }
}
async function cancelMembership(m: Member) {
  const { ok, reason } = await ask({ title: `Cancel membership of ${m.customer}?`, description: 'Benefits stop immediately. The record is kept.', confirmLabel: 'Cancel membership', requireReason: true })
  if (!ok) return
  try { await api.cancelMember(m.id, reason); m.status = 'cancelled'; toast.success('Membership cancelled', m.customer) }
  catch (e: any) { toast.error('Could not cancel', e?.message) }
}
function memberActions(m: Member) {
  return [
    { label: 'Renew', icon: RefreshCw, onClick: () => renew(m), hidden: !canManage.value || m.status === 'cancelled' },
    { label: 'Change plan', icon: ArrowUpDown, onClick: () => startChangePlan(m), hidden: !canManage.value || m.status !== 'active' },
    { label: 'Cancel membership', icon: Ban, onClick: () => cancelMembership(m), danger: true, hidden: !canManage.value || m.status !== 'active' },
  ]
}
</script>

<template>
  <div>
    <UiPageHeader title="Membership plans" description="Tiers customers qualify for by annual spend or by paying a fee. Benefits are applied by the API at checkout." />

    <div class="mb-4"><UiTabs v-model="tab" :tabs="[{ value: 'plans', label: 'Plans', count: plans.length }, { value: 'members', label: 'Members', count: members.length }]" /></div>

    <template v-if="tab === 'plans'">
      <div v-if="loading" class="grid gap-5 lg:grid-cols-3"><div v-for="i in 3" :key="i" class="skeleton h-96 rounded-3xl" /></div>
      <div v-else ref="cards" class="grid gap-5 [perspective:1000px] lg:grid-cols-3">
        <div v-for="p in plans" :key="p.id" class="flex flex-col">
          <div
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br p-6 shadow-[var(--shadow-lift)] ring-1 [transform-style:preserve-3d]"
            :class="[theme[p.code].card, theme[p.code].ring]"
            @pointermove="tilt" @pointerleave="untilt"
          >
            <div class="grain pointer-events-none absolute inset-0" />
            <div class="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full bg-white/30 blur-3xl" />
            <div class="relative flex items-start justify-between">
              <span class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wider uppercase" :class="theme[p.code].chip"><Crown class="size-3.5" />{{ p.code }}</span>
              <UiBadge v-if="!p.isActive" tone="neutral">Paused</UiBadge>
            </div>
            <p class="relative mt-6 font-display text-2xl font-extrabold">{{ p.name }}</p>
            <p class="relative mt-1 text-sm opacity-80">From {{ money(p.minAnnualSpend) }} / year spend<template v-if="p.fee"> or {{ money(p.fee) }} fee</template></p>
            <div class="relative mt-6 flex items-end gap-6">
              <div><p class="font-display text-4xl font-extrabold">{{ p.discountPct }}%</p><p class="text-xs opacity-70">off every invoice</p></div>
              <div><p class="font-display text-4xl font-extrabold">{{ p.pointsMultiplier }}×</p><p class="text-xs opacity-70">points</p></div>
            </div>
            <p class="relative mt-5 flex items-center gap-1.5 text-xs font-semibold" :class="theme[p.code].accent"><Users class="size-3.5" />{{ p.members }} members · {{ p.durationMonths }} months</p>
          </div>
          <div class="card -mt-4 flex-1 rounded-t-none pt-8 pb-5 px-5">
            <ul class="space-y-2">
              <li v-for="b in p.benefits" :key="b" class="flex items-start gap-2 text-sm text-slate-600"><Check class="mt-0.5 size-4 shrink-0 text-brand-600" />{{ b }}</li>
            </ul>
            <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-3">
              <span class="label">Actions</span>
              <UiRowActions
                :label="p.name" :view="() => { viewPlan = p; viewPlanOpen = true }"
                :edit="canManage ? () => edit(p) : undefined"
                :remove="canManage && p.members === 0 ? () => removePlan(p) : undefined"
                :items="planActions(p)"
              />
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-else class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
        <div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Member or phone…" /></div>
        <UiSegmented v-model="planFilter" :options="[{ value: 'all', label: 'All' }, { value: 'Silver', label: 'Silver' }, { value: 'Gold', label: 'Gold' }, { value: 'Platinum', label: 'Platinum' }]" />
        <UiBadge v-if="renewalsSoon" tone="warning" class="ml-auto"><CalendarClock class="size-3" />{{ renewalsSoon }} renewals due in 30 days</UiBadge>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
          <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Member</th><th class="label text-left">Plan</th><th class="label text-left">Started</th><th class="label text-left">Ends</th><th class="label text-left">Source</th><th class="label px-4 text-left">Status</th><th class="label px-4 text-right">Actions</th></tr></thead>
          <tbody>
            <tr v-for="m in filteredMembers" :key="m.id" class="border-b border-slate-50 hover:bg-brand-50/40">
              <td class="px-4 py-3"><NuxtLink :to="`/customers/${m.id}`" class="font-semibold text-ink hover:text-brand-700 hover:underline">{{ m.customer }}</NuxtLink><p class="text-xs text-slate-500 tabular">{{ m.phone }}</p></td>
              <td><UiBadge :tone="planTone[m.plan]"><Crown class="size-3" />{{ m.plan }}</UiBadge></td>
              <td class="text-slate-600">{{ date(m.startsAt) }}</td>
              <td :class="m.status === 'active' && daysLeft(m) <= 30 ? 'font-semibold text-amber-600' : 'text-slate-600'">{{ date(m.endsAt) }}<span v-if="m.status === 'active' && daysLeft(m) <= 30" class="ml-1 text-xs">({{ daysLeft(m) }}d)</span></td>
              <td class="capitalize text-slate-500">{{ m.source }}</td>
              <td class="px-4"><UiBadge :tone="m.status === 'active' ? 'success' : 'neutral'" dot class="capitalize">{{ m.status }}</UiBadge></td>
              <td class="px-3"><UiRowActions :label="m.customer" :view="`/customers/${m.id}`" :items="memberActions(m)" /></td>
            </tr>
          </tbody>
        </table>
        <UiEmptyState v-if="!filteredMembers.length && !loading" :icon="Crown" title="No members match" />
      </div>
    </div>

    <UiDrawer v-model="viewPlanOpen" :title="viewPlan?.name" :description="viewPlan ? `${viewPlan.code} plan · ${viewPlan.members} members` : ''" width="md">
      <dl v-if="viewPlan" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Qualifying spend', `${money(viewPlan.minAnnualSpend)} / year`], ['Joining fee', viewPlan.fee ? money(viewPlan.fee) : 'None'], ['Discount', `${viewPlan.discountPct}%`], ['Points', `${viewPlan.pointsMultiplier}×`], ['Duration', `${viewPlan.durationMonths} months`], ['New members', viewPlan.isActive ? 'Open' : 'Paused']]" :key="k" data-stagger class="flex justify-between gap-6 py-3"><dt class="text-slate-500">{{ k }}</dt><dd class="font-medium">{{ v }}</dd></div>
      </dl>
      <ul v-if="viewPlan" class="mt-4 space-y-2">
        <li v-for="b in viewPlan.benefits" :key="b" class="flex items-start gap-2 text-sm text-slate-600"><Check class="mt-0.5 size-4 shrink-0 text-brand-600" />{{ b }}</li>
      </ul>
      <template #footer>
        <UiButton variant="ghost" @click="viewPlanOpen = false">Close</UiButton>
        <UiButton v-if="canManage && viewPlan" variant="primary" @click="viewPlanOpen = false; edit(viewPlan!)">Edit plan</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="planOpen" :title="planTarget ? `Change plan · ${planTarget.customer}` : ''" description="Takes effect immediately; the end date is unchanged." size="sm">
      <div class="space-y-4">
        <UiSelect v-model="newPlan" label="New plan" :options="plans.map(p => ({ value: p.code, label: p.name }))" />
        <UiInput v-model="planReason" label="Reason (required)" />
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="planOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :disabled="!planTarget || newPlan === planTarget.plan || !planReason.trim()" @click="changePlan">Change plan</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="editOpen":title="form ? `Edit ${form.name}` : ''" description="Changes apply to new joins and renewals; current terms are honoured until they end." size="lg" :dismissible="!saving">
      <div v-if="form" class="grid gap-4 sm:grid-cols-2">
        <UiInput v-model="form.name" label="Plan name" />
        <UiInput v-model.number="form.minAnnualSpend" type="number" label="Qualifying annual spend (৳)" />
        <UiInput v-model.number="form.discountPct" type="number" label="Discount %" hint="Capped at 50%; role limits still apply" />
        <UiInput v-model.number="form.pointsMultiplier" type="number" label="Points multiplier" />
        <UiInput v-model.number="form.fee" type="number" label="Joining fee (৳)" hint="0 = qualify by spend only" />
        <UiInput v-model.number="form.durationMonths" type="number" label="Duration (months)" />
        <div class="sm:col-span-2"><UiSwitch v-model="form.isActive" label="Open for new members" /></div>
        <div class="sm:col-span-2">
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Benefits</p>
          <ul class="mb-2 space-y-1.5">
            <li v-for="(b, i) in form.benefits" :key="i" class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm">
              <Check class="size-4 text-brand-600" /><span class="flex-1">{{ b }}</span>
              <button type="button" class="rounded-md p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove benefit" @click="form.benefits.splice(i, 1)"><X class="size-3.5" /></button>
            </li>
          </ul>
          <div class="flex gap-2"><div class="flex-1"><UiInput v-model="newBenefit" placeholder="Add a benefit…" @keydown.enter.prevent="addBenefit" /></div><UiButton :icon="Plus" @click="addBenefit">Add</UiButton></div>
        </div>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="editOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="saving" @click="save">Save plan</UiButton>
      </template>
    </UiModal>
  </div>
</template>
