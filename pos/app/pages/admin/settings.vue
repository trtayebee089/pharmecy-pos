<script setup lang="ts">
import { CalendarClock, Printer, ScanBarcode, HeartHandshake, Save, Lock } from 'lucide-vue-next'
import type { PosSettings } from '~/types/domain'

definePageMeta({ title: 'General settings' })
const api = useAdminApi()
const toast = useToast()
const s = ref<PosSettings | null>(null)
const saving = ref(false)
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  s.value = await api.settings()
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
})

async function save() {
  if (!s.value) return
  if (s.value.criticalExpiryDays >= s.value.nearExpiryDays) { toast.error('Check expiry thresholds', 'Critical days must be lower than near-expiry days.'); return }
  saving.value = true
  try { await api.saveSettings(s.value); toast.success('Settings saved', 'They apply to every counter immediately.') }
  catch (e: any) { toast.error('Settings not saved', e?.message) }
  finally { saving.value = false }
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="General settings" description="Stock, expiry and counter rules for this warehouse.">
      <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="!s" @click="save">Save settings</UiButton>
    </UiPageHeader>

    <div v-if="!s" class="grid gap-5 lg:grid-cols-2"><div v-for="i in 4" :key="i" class="skeleton h-56" /></div>
    <div v-else class="grid gap-5 lg:grid-cols-2">
      <UiSection title="Stock & expiry" description="Batch rules applied at the counter" :icon="CalendarClock">
        <div class="space-y-5">
          <div class="grid grid-cols-2 gap-4">
            <UiInput v-model.number="s.nearExpiryDays" type="number" label="Near-expiry (days)" hint="Amber warnings and dashboard count" />
            <UiInput v-model.number="s.criticalExpiryDays" type="number" label="Critical (days)" hint="Red warnings" />
          </div>
          <UiSwitch v-model="s.blockExpiredSale" label="Block sale of expired batches" description="Recommended. When off, expired batches can be sold, but only after all unexpired stock of the product" />
          <p v-if="!s.blockExpiredSale" class="rounded-xl bg-rose-50 px-3 py-2 text-[12.5px] text-rose-700">Expired medicines can now be sold. Switch this back on unless you have a reason.</p>
          <UiSwitch v-model="s.allowNegativeStock" label="Allow selling without stock" description="Not recommended: creates negative balances that must be corrected" />
        </div>
      </UiSection>

      <UiSection title="Counter rules" :icon="ScanBarcode">
        <div class="space-y-5">
          <div>
            <p class="mb-2 text-[13px] font-medium text-slate-700">Round invoice total to</p>
            <UiSegmented v-model="s.roundTo" :options="[{ value: 0, label: 'No rounding' }, { value: 0.5, label: '৳0.50' }, { value: 1, label: '৳1' }]" />
          </div>
          <UiSwitch v-model="s.rxReminder" label="Prescription reminder" description="Show a reminder when an Rx medicine is added (does not block)" />
        </div>
      </UiSection>

      <NuxtLink to="/admin/pos-settings" class="card group flex items-center gap-4 p-5 transition hover:border-brand-300 hover:shadow-[var(--shadow-lift)]" data-reveal>
        <span class="grid size-11 place-items-center rounded-xl bg-brand-50 text-brand-700"><Printer class="size-5" /></span>
        <span class="flex-1"><span class="block font-display font-bold">POS settings & printers</span><span class="text-sm text-slate-500">Receipt format, payment methods, counter defaults, printer setup</span></span>
      </NuxtLink>
      <NuxtLink to="/admin/reward-points" class="card group flex items-center gap-4 p-5 transition hover:border-brand-300 hover:shadow-[var(--shadow-lift)]" data-reveal>
        <span class="grid size-11 place-items-center rounded-xl bg-brand-50 text-brand-700"><HeartHandshake class="size-5" /></span>
        <span class="flex-1"><span class="block font-display font-bold">Reward points</span><span class="text-sm text-slate-500">Earning, expiry and redemption rules</span></span>
      </NuxtLink>
    </div>
  </div>
</template>
