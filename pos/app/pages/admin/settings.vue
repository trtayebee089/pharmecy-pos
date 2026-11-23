<script setup lang="ts">
import { CalendarClock, Printer, ScanBarcode, HeartHandshake, Save, Lock } from 'lucide-vue-next'
import type { PosSettings } from '~/types/domain'

definePageMeta({ title: 'Settings' })
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
  await api.saveSettings(s.value)
  saving.value = false
  toast.success('Settings saved', 'They apply to every counter immediately.')
}
</script>

<template>
  <div ref="root">
    <UiPageHeader title="Settings" description="Counter, stock and receipt behaviour for this warehouse.">
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
          <div class="flex items-start justify-between gap-4 rounded-xl bg-slate-50 p-3">
            <div><p class="flex items-center gap-1.5 text-sm font-medium"><Lock class="size-3.5 text-slate-400" />Block sale of expired batches</p><p class="mt-0.5 text-[12.5px] text-slate-500">Always on: decided business rule (R3)</p></div>
            <UiBadge tone="success">On</UiBadge>
          </div>
          <UiSwitch v-model="s.allowNegativeStock" label="Allow selling without stock" description="Not recommended: creates negative balances that must be corrected" />
        </div>
      </UiSection>

      <UiSection title="Counter" :icon="ScanBarcode">
        <div class="space-y-5">
          <div>
            <p class="mb-2 text-[13px] font-medium text-slate-700">Round invoice total to</p>
            <UiSegmented v-model="s.roundTo" :options="[{ value: 0, label: 'No rounding' }, { value: 0.5, label: '৳0.50' }, { value: 1, label: '৳1' }]" />
          </div>
          <UiSwitch v-model="s.rxReminder" label="Prescription reminder" description="Show a reminder when an Rx medicine is added (does not block)" />
          <UiSwitch v-model="s.printOnComplete" label="Print receipt automatically" description="Opens the print dialog after each completed sale" />
        </div>
      </UiSection>

      <UiSection title="Receipt" :icon="Printer">
        <div class="grid gap-5 md:grid-cols-[1fr_200px]">
          <div class="space-y-4">
            <div>
              <p class="mb-2 text-[13px] font-medium text-slate-700">Paper</p>
              <UiSegmented v-model="s.receiptWidth" :options="[{ value: '58mm', label: '58 mm' }, { value: '80mm', label: '80 mm' }, { value: 'A4', label: 'A4' }]" />
            </div>
            <UiTextarea v-model="s.receiptFooter" label="Footer text" :rows="3" />
          </div>
          <div class="rounded-xl border border-dashed border-slate-300 bg-white p-3 font-mono text-[10px] leading-snug text-slate-700 shadow-inner" :style="{ maxWidth: s.receiptWidth === '58mm' ? '150px' : '200px' }">
            <p class="text-center font-bold">Red Pharma</p>
            <p class="text-center">Dhanmondi</p>
            <p class="my-1 border-t border-dashed border-slate-400" />
            <p class="flex justify-between"><span>Napa 500 mg ×2</span><span>24.00</span></p>
            <p class="flex justify-between"><span>Seclo 20 mg ×1</span><span>60.00</span></p>
            <p class="my-1 border-t border-dashed border-slate-400" />
            <p class="flex justify-between font-bold"><span>TOTAL</span><span>84.00</span></p>
            <p class="mt-1 text-center text-[9px]">{{ s.receiptFooter }}</p>
          </div>
        </div>
      </UiSection>

      <UiSection title="Loyalty points" :icon="HeartHandshake">
        <div class="grid grid-cols-2 gap-4">
          <UiInput v-model.number="s.pointsPerTaka" type="number" label="Points per ৳ spent" :hint="`৳1,000 earns ${Math.floor(1000 * s.pointsPerTaka)} points`" />
          <UiInput v-model.number="s.pointValue" type="number" label="Value of 1 point (৳)" :hint="`100 points = ${money(100 * s.pointValue)}`" />
        </div>
      </UiSection>
    </div>
  </div>
</template>
