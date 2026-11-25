<script setup lang="ts">
import { gsap } from 'gsap'
import { z } from 'zod'
import { Pill, Layers, Plus, Trash2, Save, ArrowLeft, Barcode, Info, Sparkles } from 'lucide-vue-next'
import type { CatalogLookups, ProductInput, UnitCode } from '~/types/domain'

const props = defineProps<{ initial: ProductInput }>()
const form = reactive<ProductInput>(structuredClone(toRaw(props.initial)))
const catalog = useCatalogApi()
const toast = useToast()
const lookups = ref<CatalogLookups | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const root = ref<HTMLElement | null>(null)
const ladder = ref<HTMLElement | null>(null)

onMounted(async () => {
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
  lookups.value = await catalog.lookups()
})
const opts = (xs?: { id: number; name: string }[]) => (xs ?? []).map(x => ({ value: x.id, label: x.name }))

const unitCodes: { value: UnitCode; label: string }[] = [
  { value: 'piece', label: 'Piece' }, { value: 'strip', label: 'Strip' }, { value: 'box', label: 'Box' },
  { value: 'bottle', label: 'Bottle' }, { value: 'tube', label: 'Tube' }, { value: 'vial', label: 'Vial' },
]
const sortedUnits = computed(() => [...form.units].sort((a, b) => a.factor - b.factor))
const base = computed(() => form.units.find(u => u.factor === 1))
const piecePrice = computed(() => base.value?.price ?? 0)

/** Chain like: 1 box = 20 strip = 200 piece */
const chain = computed(() => {
  const s = sortedUnits.value
  if (s.length < 2) return ''
  const top = s[s.length - 1]!
  return [`1 ${top.code}`, ...s.slice(0, -1).reverse().map(u => `${num(top.factor / u.factor)} ${u.code}`)].join(' = ')
})

function addUnit() {
  const next = form.units.length === 1 ? { code: 'strip' as UnitCode, factor: 10 } : { code: 'box' as UnitCode, factor: Math.max(...form.units.map(u => u.factor)) * 10 }
  form.units.push({ ...next, price: Math.round(next.factor * piecePrice.value * 100) / 100, wholesalePrice: null, barcode: '', isSaleDefault: false, isPurchaseDefault: false })
  nextTick(() => {
    const rows = ladder.value?.querySelectorAll('[data-unit-row]')
    const last = rows?.[rows.length - 1]
    if (last && !prefersReducedMotion()) gsap.fromTo(last, { opacity: 0, y: -8, backgroundColor: '#effcf9' }, { opacity: 1, y: 0, backgroundColor: '#ffffff', duration: 0.6, ease: 'power2.out' })
  })
}
function removeUnit(i: number) { if (form.units[i]!.factor !== 1) form.units.splice(i, 1) }
function suggestPrices() {
  for (const u of form.units) if (u.factor !== 1) u.price = Math.round(u.factor * piecePrice.value * 100) / 100
}
function setDefault(kind: 'isSaleDefault' | 'isPurchaseDefault', i: number) {
  form.units.forEach((u, j) => (u[kind] = j === i))
}

const schema = z.object({
  sku: z.string().trim().min(1, 'SKU is required'),
  name: z.string().trim().min(2, 'Brand name is required'),
  manufacturerId: z.number({ message: 'Choose a manufacturer' }),
  dosageFormId: z.number({ message: 'Choose a dosage form' }),
  reorderLevelBase: z.number().int().min(0),
  units: z.array(z.object({
    code: z.string(),
    factor: z.number().int('Whole pieces only').positive('Must be at least 1'),
    price: z.number().positive('Price must be above 0'),
    barcode: z.string(),
  })).min(1)
    .refine(us => us.filter(u => u.factor === 1).length === 1, 'Exactly one base unit with factor 1 is required')
    .refine(us => new Set(us.map(u => u.factor)).size === us.length, 'Each unit needs a different factor')
    .refine(us => new Set(us.map(u => u.code)).size === us.length, 'Each unit type can appear once')
    .refine(us => { const b = us.map(u => u.barcode).filter(Boolean); return new Set(b).size === b.length }, 'Barcodes must be unique'),
})

async function save() {
  errors.value = {}
  const parsed = schema.safeParse(form)
  if (!parsed.success) {
    for (const issue of parsed.error.issues) errors.value[String(issue.path[0])] ??= issue.message
    shake(root.value?.querySelector('[data-save]') ?? null)
    toast.error('Please fix the highlighted fields')
    return
  }
  saving.value = true
  try {
    await catalog.save(form)
    toast.success(form.id ? 'Product updated' : 'Product created', `${form.name} ${form.strength}`)
    await navigateTo('/products')
  }
  catch (e: any) {
    if (e instanceof ApiError) for (const [k, v] of Object.entries(e.errors)) errors.value[k] = v[0] ?? ''
    toast.error('Could not save', e?.message)
  }
  finally { saving.value = false }
}
</script>

<template>
  <div ref="root" class="mx-auto max-w-6xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <NuxtLink to="/products"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back" /></NuxtLink>
        <div>
          <h1 class="font-display text-[26px] font-extrabold tracking-tight text-ink">{{ form.id ? `Edit ${initial.name}` : 'Add product' }}</h1>
          <p class="text-sm text-slate-500">Stock is always kept in the base unit; sale and purchase can use any unit.</p>
        </div>
      </div>
      <UiButton data-save variant="primary" size="lg" :icon="Save" :loading="saving" magnetic @click="save">Save product</UiButton>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div class="space-y-5">
        <UiSection title="Medicine" description="Brand, strength and classification" :icon="Pill">
          <div class="grid gap-4 sm:grid-cols-2">
            <UiInput v-model="form.name" label="Brand name" placeholder="e.g. Napa" :error="errors.name" />
            <UiInput v-model="form.strength" label="Strength" placeholder="e.g. 500 mg" />
            <UiSelect v-model="form.genericId" label="Generic" :options="opts(lookups?.generics)" placeholder="Choose generic" hint="Used by generic-name search at the counter" />
            <UiSelect v-model="form.manufacturerId" label="Manufacturer" :options="opts(lookups?.manufacturers)" placeholder="Choose manufacturer" :error="errors.manufacturerId" />
            <UiSelect v-model="form.dosageFormId" label="Dosage form" :options="opts(lookups?.dosageForms)" placeholder="Tablet, Syrup…" :error="errors.dosageFormId" />
            <UiSelect v-model="form.categoryId" label="Category" :options="opts(lookups?.categories)" placeholder="Optional" />
            <UiInput v-model="form.sku" label="SKU" placeholder="Internal code" :error="errors.sku" />
            <UiInput v-model.number="form.reorderLevelBase" type="number" label="Reorder level (pieces)" hint="Low-stock alert below this" />
          </div>
          <div class="mt-4"><UiTextarea v-model="form.description" label="Notes" placeholder="Storage, counselling notes…" :rows="2" /></div>
        </UiSection>

        <UiSection title="Units, prices & barcodes" description="Piece is the base unit. Add strip and box with how many pieces they contain." :icon="Layers">
          <template #actions>
            <UiButton size="sm" variant="soft" :icon="Sparkles" @click="suggestPrices">Price from piece</UiButton>
          </template>
          <p v-if="errors.units" class="mb-3 rounded-xl bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ errors.units }}</p>
          <div ref="ladder" class="space-y-2">
            <div class="label hidden gap-2 px-2 md:grid md:grid-cols-[110px_80px_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)_56px_56px_36px]">
              <span>Unit</span><span>Pieces</span><span>Retail ৳</span><span>Wholesale ৳</span><span>Barcode</span><span class="text-center">Sell</span><span class="text-center">Buy</span><span />
            </div>
            <div v-for="(u, i) in form.units" :key="i" data-unit-row class="grid grid-cols-2 items-end gap-2 rounded-2xl border border-slate-200 p-2 md:grid-cols-[110px_80px_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)_56px_56px_36px] md:items-center">
              <label class="min-w-0"><span class="mb-1 block text-[11px] font-semibold text-slate-500 md:hidden">Unit</span>
                <UiSelect v-model="u.code" :options="unitCodes" aria-label="Unit" />
              </label>
              <label class="min-w-0"><span class="mb-1 block text-[11px] font-semibold text-slate-500 md:hidden">Pieces per unit</span>
                <input v-model.number="u.factor" type="number" min="1" :disabled="u.factor === 1 && i === 0" class="h-10 w-full min-w-0 rounded-xl border border-slate-200 px-3 text-sm tabular focus:border-brand-400 focus:outline-none disabled:bg-slate-50 disabled:text-slate-400">
              </label>
              <label class="min-w-0"><span class="mb-1 block text-[11px] font-semibold text-slate-500 md:hidden">Retail price ৳</span>
                <input v-model.number="u.price" type="number" min="0" step="0.01" class="h-10 w-full min-w-0 rounded-xl border border-slate-200 px-3 text-sm font-semibold tabular focus:border-brand-400 focus:outline-none">
              </label>
              <label class="min-w-0"><span class="mb-1 block text-[11px] font-semibold text-slate-500 md:hidden">Wholesale ৳</span>
                <input v-model.number="u.wholesalePrice" type="number" min="0" step="0.01" placeholder="—" class="h-10 w-full min-w-0 rounded-xl border border-slate-200 px-3 text-sm tabular focus:border-brand-400 focus:outline-none">
              </label>
              <label class="relative col-span-2 min-w-0 md:col-span-1"><span class="mb-1 block text-[11px] font-semibold text-slate-500 md:hidden">Barcode</span>
                <span class="relative block">
                  <Barcode class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                  <input v-model="u.barcode" placeholder="Scan or type" class="h-10 w-full rounded-xl border border-slate-200 pr-3 pl-9 font-mono text-[13px] focus:border-brand-400 focus:outline-none">
                </span>
              </label>
              <label class="flex items-center gap-2 md:justify-center"><input type="radio" :checked="u.isSaleDefault" name="sale-default" class="size-4 accent-brand-600" :aria-label="`Default sale unit ${u.code}`" @change="setDefault('isSaleDefault', i)"><span class="text-xs text-slate-600 md:hidden">Default sale</span></label>
              <label class="flex items-center gap-2 md:justify-center"><input type="radio" :checked="u.isPurchaseDefault" name="buy-default" class="size-4 accent-brand-600" :aria-label="`Default purchase unit ${u.code}`" @change="setDefault('isPurchaseDefault', i)"><span class="text-xs text-slate-600 md:hidden">Default purchase</span></label>
              <button type="button" class="grid size-9 place-items-center justify-self-end rounded-lg text-slate-300 transition hover:bg-rose-50 hover:text-rose-600 disabled:invisible md:justify-self-auto" :disabled="u.factor === 1" aria-label="Remove unit" @click="removeUnit(i)"><Trash2 class="size-4" /></button>
            </div>
          </div>
          <div class="mt-3 flex items-center justify-between">
            <UiButton size="sm" :icon="Plus" :disabled="form.units.length >= 4" @click="addUnit">Add unit</UiButton>
            <span class="text-[12.5px] text-slate-500">Prices are per unit; the counter can sell any unit.</span>
          </div>
        </UiSection>
      </div>

      <div class="space-y-5">
        <UiSection title="Status" :icon="Info">
          <div class="space-y-4">
            <UiSwitch v-model="form.isActive" label="Active" description="Inactive products are hidden at the counter" />
            <UiSwitch v-model="form.requiresPrescription" label="Prescription medicine" description="Shows an Rx reminder at the counter (not blocking)" />
          </div>
        </UiSection>

        <div class="card sticky top-20 overflow-hidden" data-reveal>
          <div class="bg-gradient-to-br from-brand-900 to-brand-950 p-5 text-white">
            <p class="text-[11px] font-semibold tracking-wider text-brand-200/70 uppercase">Counter preview</p>
            <p class="mt-1 font-display text-xl font-extrabold">{{ form.name || 'Brand name' }} <span class="font-semibold text-brand-200">{{ form.strength }}</span></p>
            <p class="text-sm text-brand-100/70">{{ lookups?.generics.find(g => g.id === form.genericId)?.name ?? 'Generic' }} · {{ lookups?.manufacturers.find(m => m.id === form.manufacturerId)?.name ?? 'Manufacturer' }}</p>
            <p v-if="chain" class="mt-4 rounded-xl bg-white/10 px-3 py-2 text-[13px] font-semibold tabular">{{ chain }}</p>
          </div>
          <ul class="divide-y divide-slate-100">
            <li v-for="u in sortedUnits" :key="u.code + u.factor" class="flex items-center justify-between px-5 py-3 text-sm">
              <span><span class="font-semibold capitalize">{{ u.code }}</span> <span class="text-slate-500">· {{ u.factor }} pc</span></span>
              <span class="text-right">
                <span class="block font-semibold tabular">{{ money(u.price) }}</span>
                <span v-if="u.factor > 1 && piecePrice" class="block text-[11px] tabular" :class="u.price / u.factor > piecePrice ? 'text-amber-600' : 'text-slate-400'">{{ money(u.price / u.factor) }} / pc</span>
              </span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
