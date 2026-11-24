<script setup lang="ts">
import { gsap } from 'gsap'
import { Printer, Tags, Trash2, Minus, Plus, Settings2 } from 'lucide-vue-next'
import type { Product } from '~/types/domain'

definePageMeta({ title: 'Barcode labels' })

interface LabelLine { key: string; product: Product; unitId: number; copies: number }
const lines = ref<LabelLine[]>([])
const template = ref<'38x25' | '50x30' | 'a4'>('38x25')
const show = reactive({ name: true, price: true, batch: true })
const preview = ref<HTMLElement | null>(null)

function add(p: Product) {
  const unit = p.units.find(u => u.isSaleDefault) ?? p.units[0]!
  const existing = lines.value.find(l => l.product.id === p.id && l.unitId === unit.id)
  if (existing) { existing.copies++; return }
  lines.value.push({ key: `${p.id}-${unit.id}`, product: p, unitId: unit.id, copies: 4 })
}
const unitOf = (l: LabelLine) => l.product.units.find(u => u.id === l.unitId) ?? l.product.units[0]!
const nextBatch = (p: Product) => p.batches.find(b => daysUntil(b.expiryDate) >= 0)
/** Barcode value: per-unit barcode in the real catalog; here SKU + unit code suffix for non-base units. */
const code = (l: LabelLine) => unitOf(l).factor === 1 ? l.product.sku : `${l.product.sku}${unitOf(l).code[0]!.toUpperCase()}`

const labels = computed(() => lines.value.flatMap(l => Array.from({ length: Math.max(0, Math.min(200, l.copies)) }, (_, i) => ({ id: `${l.key}-${i}`, l }))))
const totalLabels = computed(() => labels.value.length)
const sheets = computed(() => template.value === 'a4' ? Math.ceil(totalLabels.value / 24) : totalLabels.value)

watch(() => [labels.value.length, template.value], async () => {
  await nextTick()
  const last = preview.value?.lastElementChild
  if (last && !prefersReducedMotion()) gsap.fromTo(last, { scale: 0.85, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.35, ease: 'back.out(2)' })
})

const sizes = {
  '38x25': { w: '38mm', h: '25mm', cols: 'repeat(auto-fill, 38mm)', bar: 22 },
  '50x30': { w: '50mm', h: '30mm', cols: 'repeat(auto-fill, 50mm)', bar: 28 },
  'a4': { w: '63.5mm', h: '33.9mm', cols: 'repeat(3, 63.5mm)', bar: 30 },
} as const
const size = computed(() => sizes[template.value])

function print() {
  if (!totalLabels.value) { useToast().info('Add products first'); return }
  window.print()
}
</script>

<template>
  <div>
    <UiPageHeader title="Barcode labels" description="Print shelf and pack labels. Each label carries the unit's barcode so the counter sells the right unit.">
      <UiButton variant="primary" :icon="Printer" :disabled="!totalLabels" @click="print">Print {{ totalLabels }} labels</UiButton>
    </UiPageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div class="space-y-5">
        <UiSection title="Products" description="Search or scan, then choose the unit and number of copies." :icon="Tags">
          <UiProductPicker placeholder="Scan or search a product to label…" @pick="add" />
          <ul v-if="lines.length" class="mt-4 divide-y divide-slate-100 rounded-2xl border border-slate-200">
            <li v-for="(l, i) in lines" :key="l.key" class="flex flex-wrap items-center gap-3 px-4 py-3">
              <div class="min-w-40 flex-1">
                <p class="text-sm font-semibold">{{ l.product.name }} <span class="font-medium text-slate-500">{{ l.product.strength }}</span></p>
                <p class="font-mono text-xs text-slate-500">{{ code(l) }}</p>
              </div>
              <select v-model="l.unitId" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-[13px] font-semibold focus:border-brand-400 focus:outline-none" aria-label="Unit">
                <option v-for="u in l.product.units" :key="u.id" :value="u.id">{{ u.label }} · {{ money(u.price, false) }}</option>
              </select>
              <div class="flex h-9 items-center rounded-lg border border-slate-200 bg-white">
                <button class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" aria-label="Fewer copies" @click="l.copies = Math.max(1, l.copies - 1)"><Minus class="size-3.5" /></button>
                <input v-model.number="l.copies" type="number" min="1" max="200" class="h-full w-12 border-x border-slate-200 text-center text-sm font-bold tabular focus:outline-none" aria-label="Copies">
                <button class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" aria-label="More copies" @click="l.copies = Math.min(200, l.copies + 1)"><Plus class="size-3.5" /></button>
              </div>
              <button class="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove product" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
            </li>
          </ul>
          <UiEmptyState v-else :icon="Tags" title="No products selected" description="Add products to preview their labels." />
        </UiSection>

        <div class="card overflow-hidden">
          <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <p class="font-display text-[15px] font-bold">Preview</p>
            <span class="text-xs text-slate-500 tabular">{{ totalLabels }} labels · {{ sheets }} {{ template === 'a4' ? 'A4 sheet(s)' : 'labels on roll' }}</span>
          </div>
          <div class="overflow-x-auto bg-[repeating-linear-gradient(45deg,#f8fafc,#f8fafc_10px,#f1f5f9_10px,#f1f5f9_20px)] p-5">
            <div id="label-sheet" ref="preview" class="grid gap-2" :style="{ gridTemplateColumns: size.cols }">
              <div
                v-for="x in labels" :key="x.id"
                class="label-item flex flex-col items-center justify-center overflow-hidden rounded-md border border-slate-300 bg-white px-1.5 py-1 text-center text-black shadow-sm"
                :style="{ width: size.w, height: size.h }"
              >
                <p v-if="show.name" class="w-full truncate text-[8.5px] leading-tight font-bold">{{ x.l.product.name }} {{ x.l.product.strength }}</p>
                <InventoryBarcode128 :value="code(x.l)" :height="size.bar" />
                <p class="font-mono text-[7.5px] leading-tight">{{ code(x.l) }}</p>
                <div class="flex w-full justify-between text-[7.5px] leading-tight">
                  <span v-if="show.batch && nextBatch(x.l.product)">B:{{ nextBatch(x.l.product)!.batchNo }} E:{{ date(nextBatch(x.l.product)!.expiryDate, { month: '2-digit', year: '2-digit' }) }}</span>
                  <span v-if="show.price" class="ml-auto font-bold">৳{{ money(unitOf(x.l).price, false) }}/{{ unitOf(x.l).code }}</span>
                </div>
              </div>
            </div>
            <p v-if="!totalLabels" class="py-10 text-center text-sm text-slate-500">Labels appear here.</p>
          </div>
        </div>
      </div>

      <div class="space-y-5">
        <UiSection title="Template" :icon="Settings2">
          <div class="space-y-4">
            <div class="grid gap-2">
              <button
                v-for="t in ([['38x25', '38 × 25 mm', 'Thermal roll, small packs'], ['50x30', '50 × 30 mm', 'Thermal roll, shelf labels'], ['a4', 'A4 sheet · 3 × 8', 'Laser printer, 24 per sheet']] as const)" :key="t[0]"
                class="flex items-center gap-3 rounded-2xl border p-3 text-left transition"
                :class="template === t[0] ? 'border-brand-400 bg-brand-50/60 shadow-[var(--shadow-glow)]' : 'border-slate-200 hover:border-slate-300'"
                @click="template = t[0]"
              >
                <span class="grid h-9 w-12 place-items-center rounded-md border-2 text-[9px] font-bold" :class="template === t[0] ? 'border-brand-500 text-brand-700' : 'border-slate-300 text-slate-400'">{{ t[0] === 'a4' ? 'A4' : t[0] }}</span>
                <span><span class="block text-sm font-semibold">{{ t[1] }}</span><span class="text-xs text-slate-500">{{ t[2] }}</span></span>
              </button>
            </div>
            <div class="space-y-3 border-t border-slate-100 pt-4">
              <UiSwitch v-model="show.name" label="Product name" />
              <UiSwitch v-model="show.price" label="Price per unit" />
              <UiSwitch v-model="show.batch" label="Batch & expiry" description="Uses the next sellable (FEFO) batch" />
            </div>
          </div>
        </UiSection>
        <p class="px-1 text-xs text-slate-500">Barcodes are Code 128-B. Test one label with the counter scanner before printing a full roll.</p>
      </div>
    </div>
  </div>
</template>

<style>
@media print {
  body * { visibility: hidden !important; }
  #label-sheet, #label-sheet * { visibility: visible !important; }
  #label-sheet { position: absolute; left: 0; top: 0; gap: 0 !important; }
  #label-sheet .label-item { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; break-inside: avoid; }
}
</style>
