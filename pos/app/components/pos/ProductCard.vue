<script setup lang="ts">
import { CalendarClock, FileWarning, Plus } from 'lucide-vue-next'
import type { Product } from '~/types/domain'

const props = defineProps<{ product: Product; active?: boolean }>()
const emit = defineEmits<{ add: [rect: DOMRect] }>()
const el = ref<HTMLElement | null>(null)
useSpotlight(el)

const unit = computed(() => props.product.units.find(u => u.isSaleDefault) ?? props.product.units[0]!)
const nextBatch = computed(() => props.product.batches.find(b => daysUntil(b.expiryDate) >= 0))
const exp = computed(() => nextBatch.value ? expiryState(nextBatch.value.expiryDate) : 'expired')
const out = computed(() => props.product.stockBase <= 0)
const low = computed(() => !out.value && props.product.stockBase <= props.product.reorderLevelBase)

function add() { emit('add', el.value!.getBoundingClientRect()) }
defineExpose({ add })
</script>

<template>
  <button
    ref="el"
    class="spotlight group relative flex w-full flex-col rounded-2xl border bg-white p-3.5 text-left transition-[border-color,box-shadow,transform] duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-lift)]"
    :class="[active ? 'border-brand-400 shadow-[var(--shadow-glow)]' : 'border-slate-200/80', out && 'opacity-60']"
    @click="add"
  >
    <div class="flex items-start justify-between gap-2">
      <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-slate-500">{{ product.dosageForm }}</span>
      <span v-if="product.requiresPrescription" class="flex items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-[10.5px] font-bold text-violet-700" title="Prescription medicine"><FileWarning class="size-3" />Rx</span>
    </div>
    <p class="mt-2.5 line-clamp-1 text-[15px] leading-tight font-bold text-ink">{{ product.name }}</p>
    <p class="line-clamp-1 text-[12.5px] font-medium text-slate-500">{{ product.strength }} · {{ product.generic }}</p>
    <p class="mt-0.5 line-clamp-1 text-[11.5px] text-slate-400">{{ product.manufacturer }}</p>

    <div class="mt-3 flex items-end justify-between gap-2">
      <div>
        <p class="font-display text-[17px] font-extrabold text-ink tabular">{{ money(unit.price) }}</p>
        <p class="text-[11px] text-slate-500">per {{ unit.code }}</p>
      </div>
      <span class="grid size-8 place-items-center rounded-xl bg-brand-600 text-white opacity-0 shadow-[0_6px_16px_-4px_rgb(8_127_116/.6)] transition-all duration-200 group-hover:opacity-100" :class="active && 'opacity-100'">
        <Plus class="size-4" />
      </span>
    </div>

    <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-2.5 text-[11.5px]">
      <span :class="out ? 'font-semibold text-rose-600' : low ? 'font-semibold text-amber-600' : 'text-slate-500'" class="tabular">
        {{ out ? 'No sellable stock' : unitBreakdown(product.stockBase, product.units) }}
      </span>
      <span
        v-if="nextBatch" class="flex items-center gap-1 font-semibold"
        :class="{ 'text-rose-600': exp === 'critical', 'text-amber-600': exp === 'soon', 'text-slate-400': exp === 'ok' }"
        :title="`Next batch ${nextBatch.batchNo}`"
      >
        <CalendarClock class="size-3" />{{ date(nextBatch.expiryDate, { month: 'short', year: '2-digit' }) }}
      </span>
    </div>
  </button>
</template>
