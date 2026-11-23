<script setup lang="ts">
import { Minus, Plus, Trash2, Layers, AlertTriangle } from 'lucide-vue-next'
import type { CartLine } from '~/stores/cart'

const props = defineProps<{ line: CartLine }>()
const cart = useCartStore()
const totalEl = ref<HTMLElement | null>(null)

const unit = computed(() => cart.unitOf(props.line))
const alloc = computed(() => cart.allocation(props.line))
const today = new Date().toISOString().slice(0, 10)
const sellableBatches = computed(() => props.line.product.batches.filter(b => b.expiryDate >= today))
const expanded = ref(false)

watch(() => cart.lineTotal(props.line), () => pop(totalEl.value))

function step(d: number) {
  const n = Math.max(1, props.line.qty + d)
  props.line.qty = n
}
</script>

<template>
  <div class="group rounded-2xl border border-transparent p-3 transition-colors hover:border-slate-200 hover:bg-slate-50/60">
    <div class="flex items-start gap-3">
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-bold text-ink">{{ line.product.name }} <span class="font-medium text-slate-500">{{ line.product.strength }}</span></p>
        <button class="mt-0.5 flex items-center gap-1 text-[11.5px] text-slate-500 hover:text-brand-700" @click="expanded = !expanded">
          <Layers class="size-3" />
          <template v-for="(a, i) in alloc.parts" :key="a.batchId">
            <span v-if="i">+</span>
            <span class="tabular" :class="expiryState(a.expiryDate) === 'critical' && 'font-semibold text-rose-600'">{{ a.batchNo }} · {{ date(a.expiryDate, { month: 'short', year: '2-digit' }) }}</span>
          </template>
        </button>
        <p v-if="alloc.short" class="mt-1 flex items-center gap-1 text-[11.5px] font-semibold text-rose-600">
          <AlertTriangle class="size-3" />Short by {{ alloc.short }} pcs, reduce quantity
        </p>
      </div>
      <p ref="totalEl" class="font-display text-[15px] font-extrabold text-ink tabular">{{ money(cart.lineTotal(line)) }}</p>
    </div>

    <div class="mt-2.5 flex items-center gap-2">
      <select v-model="line.unitId" class="h-8 rounded-lg border border-slate-200 bg-white px-2 text-[12.5px] font-semibold text-slate-700 focus:border-brand-400 focus:outline-none" aria-label="Unit">
        <option v-for="u in line.product.units" :key="u.id" :value="u.id">{{ u.label }} · {{ money(u.price, false) }}</option>
      </select>
      <div class="flex h-8 items-center rounded-lg border border-slate-200 bg-white">
        <button class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" aria-label="Decrease" @click="step(-1)"><Minus class="size-3.5" /></button>
        <input v-model.number="line.qty" type="number" min="1" class="h-full w-10 border-x border-slate-200 text-center text-sm font-bold tabular focus:outline-none" aria-label="Quantity">
        <button class="grid h-full w-8 place-items-center text-slate-500 hover:text-ink" aria-label="Increase" @click="step(1)"><Plus class="size-3.5" /></button>
      </div>
      <span class="text-[11.5px] text-slate-400 tabular">= {{ cart.qtyBase(line) }} pcs</span>
      <button class="ml-auto rounded-lg p-1.5 text-slate-300 transition hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="cart.remove(line.key)"><Trash2 class="size-4" /></button>
    </div>

    <div v-if="expanded" class="mt-2.5 rounded-xl bg-white p-2.5 ring-1 ring-slate-200">
      <p class="label mb-1.5">Batch (FEFO default)</p>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="b in sellableBatches" :key="b.batchId"
          class="rounded-lg border px-2 py-1 text-[11.5px] tabular transition"
          :class="(line.batchId ?? alloc.parts[0]?.batchId) === b.batchId ? 'border-brand-400 bg-brand-50 font-semibold text-brand-800' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
          @click="line.batchId = b.batchId"
        >
          {{ b.batchNo }} · {{ date(b.expiryDate, { month: 'short', year: '2-digit' }) }} · {{ b.qtyBase }} pcs
        </button>
      </div>
      <div class="mt-2 flex items-center gap-2 text-[12px]">
        <span class="text-slate-500">Line discount</span>
        <input v-model.number="line.discountPct" type="number" min="0" max="100" class="h-7 w-16 rounded-md border border-slate-200 px-2 text-right tabular focus:border-brand-400 focus:outline-none">
        <span class="text-slate-400">% (limit set per role by the API)</span>
      </div>
    </div>
  </div>
</template>
