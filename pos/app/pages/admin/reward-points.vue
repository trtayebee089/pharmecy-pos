<script setup lang="ts">
import { Save, Coins, Gift, CalendarClock, Calculator, Crown } from 'lucide-vue-next'
import type { RewardPointConfig } from '~/services/settings'

/** Legacy "Reward Point Setting" (setting/reward-point-setting) plus redemption rules used at the counter. */
definePageMeta({ title: 'Reward points' })
const api = useSettingsApi()
const toast = useToast()
const c = ref<RewardPointConfig | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const root = ref<HTMLElement | null>(null)
const earnedEl = ref<HTMLElement | null>(null)

onMounted(async () => {
  c.value = await api.rewards()
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
})

// Live calculator
const invoice = ref(1250)
const earned = computed(() => {
  if (!c.value?.active || invoice.value < c.value.minimumSoldAmount || c.value.soldAmountPerPoint <= 0) return 0
  return Math.floor(invoice.value / c.value.soldAmountPerPoint)
})
const balance = ref(320)
const redeemable = computed(() => {
  if (!c.value?.active || balance.value < c.value.minRedeemPoints || invoice.value < (c.value.minRedeemSaleAmount || 0)) return 0
  const maxByInvoice = Math.floor((invoice.value * c.value.maxRedeemPercent / 100) / Math.max(c.value.pointValue, 0.01))
  return Math.min(balance.value, maxByInvoice)
})
watch(earned, () => pop(earnedEl.value))
const effectiveRate = computed(() => c.value && c.value.soldAmountPerPoint > 0 ? (c.value.pointValue / c.value.soldAmountPerPoint) * 100 : 0)

async function save() {
  if (!c.value) return
  errors.value = {}
  if (!(c.value.soldAmountPerPoint > 0)) errors.value.perPoint = 'Must be above 0'
  if (c.value.minimumSoldAmount < 0) errors.value.min = 'Cannot be negative'
  if (!(c.value.pointValue > 0)) errors.value.value = 'Must be above 0'
  if (c.value.maxRedeemPercent < 0 || c.value.maxRedeemPercent > 100) errors.value.maxPct = 'Between 0 and 100'
  if (Object.keys(errors.value).length) { toast.error('Check the highlighted fields'); return }
  saving.value = true
  try {
    await api.saveRewards(c.value)
    toast.success('Reward point settings saved', 'New rules apply to sales from now on; past points are unchanged.')
  }
  catch (e: any) { toast.error('Settings not saved', e?.message) }
  finally { saving.value = false }
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Reward points" description="How customers earn and spend points. Changes apply to new sales only; balances already earned are not recalculated.">
      <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="!c" @click="save">Save settings</UiButton>
    </UiPageHeader>

    <div v-if="!c" class="grid gap-5 lg:grid-cols-2"><div v-for="i in 3" :key="i" class="skeleton h-56 rounded-2xl" /></div>
    <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
      <div class="space-y-5">
        <UiSection title="Earning" description="Legacy: sold amount per point and minimum sold amount" :icon="Coins">
          <div class="space-y-5">
            <UiSwitch v-model="c.active" label="Reward points active" description="When off, sales earn no points and points cannot be redeemed" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model.number="c.soldAmountPerPoint" type="number" label="Sold amount per point (৳)" :error="errors.perPoint" :hint="`Spend ৳${c.soldAmountPerPoint || 0} to earn 1 point`" />
              <UiInput v-model.number="c.minimumSoldAmount" type="number" label="Minimum sold amount to earn (৳)" :error="errors.min" hint="Smaller invoices earn nothing" />
            </div>
            <UiSwitch v-model="c.excludeDiscountedItems" label="Exclude discounted lines" description="Discounted items do not count towards points" />
          </div>
        </UiSection>

        <UiSection title="Expiry" :icon="CalendarClock">
          <div class="grid gap-4 sm:grid-cols-2">
            <UiInput v-model.number="c.expiryDuration" type="number" label="Point expiry duration" hint="0 = points never expire" />
            <UiSelect v-model="c.expiryType" label="Duration type" :options="[{ value: 'Day', label: 'Days' }, { value: 'Month', label: 'Months' }, { value: 'Year', label: 'Years' }]" />
          </div>
          <p class="mt-3 text-[12.5px] text-slate-500">Points earned today expire {{ c.expiryDuration ? `after ${c.expiryDuration} ${c.expiryType.toLowerCase()}${c.expiryDuration > 1 ? 's' : ''}` : 'never' }}. Expired points are written to the reward ledger automatically.</p>
        </UiSection>

        <UiSection title="Redemption" description="How points pay at the counter" :icon="Gift">
          <div class="grid gap-4 sm:grid-cols-2">
            <UiInput v-model.number="c.pointValue" type="number" label="Value of 1 point (৳)" :error="errors.value" />
            <UiInput v-model.number="c.minRedeemPoints" type="number" label="Minimum points to redeem" hint="The customer needs at least this balance" />
            <UiInput v-model.number="c.minRedeemSaleAmount" type="number" label="Minimum invoice to use points (৳)" hint="0 = any invoice" />
            <UiInput v-model.number="c.maxRedeemPercent" type="number" label="Max % of invoice" :error="errors.maxPct" />
          </div>
          <p class="mt-3 text-[12.5px] text-slate-500">Effective reward rate: <strong class="text-ink">{{ effectiveRate.toFixed(2) }}%</strong> of spend returned as points value.</p>
        </UiSection>

        <UiSection title="Membership discount at the counter" description="Legacy applied membership discounts on the website only" :icon="Crown">
          <UiSelect
            v-model="c.membershipDiscountMode" label="When a member also has a customer group discount"
            :options="[{ value: 'off', label: 'Off: group discount only (website keeps membership)' }, { value: 'larger', label: 'Larger of group or membership discount' }, { value: 'stack', label: 'Both: membership on top of group discount' }]"
          />
          <p class="mt-3 text-[12.5px] text-slate-500">A manual line discount still replaces the automatic one only when it is larger, and only that larger part counts against the cashier's discount limit.</p>
        </UiSection>
      </div>

      <div class="card sticky top-20 h-fit overflow-hidden" data-reveal>
        <div class="bg-gradient-to-br from-brand-900 to-brand-950 p-5 text-white">
          <p class="flex items-center gap-2 text-[11px] font-semibold tracking-wider text-brand-200/70 uppercase"><Calculator class="size-3.5" />Try it</p>
          <p class="mt-1 text-sm text-brand-100/80">Preview with the current rules</p>
        </div>
        <div class="space-y-4 p-5">
          <UiInput v-model.number="invoice" type="number" label="Invoice total (৳)" />
          <div class="rounded-2xl bg-brand-50 p-4">
            <p class="label text-brand-700">Points earned</p>
            <p ref="earnedEl" class="mt-1 font-display text-3xl font-extrabold text-brand-800 tabular">{{ num(earned) }}</p>
            <p class="text-xs text-brand-700/70">worth {{ money(earned * c.pointValue) }} on a future purchase</p>
          </div>
          <UiInput v-model.number="balance" type="number" label="Customer point balance" />
          <div class="rounded-2xl bg-slate-50 p-4">
            <p class="label">Can redeem on this invoice</p>
            <p class="mt-1 font-display text-2xl font-extrabold tabular">{{ num(redeemable) }} pts · {{ money(redeemable * c.pointValue) }}</p>
            <p v-if="balance < c.minRedeemPoints" class="text-xs text-amber-600">Below the {{ c.minRedeemPoints }}-point minimum</p>
            <p v-else-if="invoice < (c.minRedeemSaleAmount || 0)" class="text-xs text-amber-600">Invoice below the {{ money(c.minRedeemSaleAmount) }} minimum for points</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
