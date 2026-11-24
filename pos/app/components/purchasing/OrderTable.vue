<script setup lang="ts">
import { Sparkles, Trash2, Info } from 'lucide-vue-next'
import type { OrderRow } from '~/types/purchase-orders'

/**
 * Editable order lines: box / strip / piece quantities are linked
 * (1 box = stripsPerBox strips, 1 strip = piecesPerStrip pieces).
 */
const props = withDefaults(defineProps<{ rows: OrderRow[]; forecastLoading?: boolean; showForecast?: boolean; removable?: boolean; onlyOrdered?: boolean }>(), { showForecast: true })
const emit = defineEmits<{ remove: [productId: number]; changed: [] }>()

const visible = computed(() => props.onlyOrdered ? props.rows.filter(r => r.orderQty > 0) : props.rows)
const confTone = { high: 'success', medium: 'warning', low: 'neutral' } as const

function onBox(r: OrderRow, e: Event) { setBoxQty(r, Number((e.target as HTMLInputElement).value)); emit('changed') }
function onStrip(r: OrderRow, e: Event) { setStripQty(r, Number((e.target as HTMLInputElement).value)); emit('changed') }
function onPcs(r: OrderRow, e: Event) { setPieceQty(r, Number((e.target as HTMLInputElement).value)); emit('changed') }
function useForecast(r: OrderRow) {
  if (!r.forecast) return
  const perBox = r.piecesPerStrip * r.stripsPerBox
  setBoxQty(r, r.forecast.recommended > 0 ? Math.ceil(r.forecast.recommended / perBox) : 0)
  emit('changed')
}
const totals = computed(() => ({
  sold: visible.value.reduce((s, r) => s + r.soldQty, 0),
  box: visible.value.reduce((s, r) => s + r.boxQty, 0),
  strip: visible.value.reduce((s, r) => s + r.stripQty, 0),
  pcs: visible.value.reduce((s, r) => s + r.orderQty, 0),
  value: visible.value.reduce((s, r) => s + r.orderQty * r.unitCost, 0),
}))
const qtyInput = 'h-9 w-[88px] rounded-lg border px-2 text-right text-sm font-semibold tabular focus:border-brand-400 focus:shadow-[0_0_0_3px_rgb(14_159_143/.12)] focus:outline-none'
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[1080px] text-sm">
      <thead>
        <tr class="border-b border-slate-100 bg-slate-50/70">
          <th class="label h-10 px-4 text-left">Product</th>
          <th class="label px-3 text-left">Category</th>
          <th class="label px-3 text-right">Sold</th>
          <th class="label px-3 text-right">In stock</th>
          <th v-if="showForecast" class="label px-3 text-left">AI forecast (7 days)</th>
          <th class="label px-2 text-center">Box qty</th>
          <th class="label px-2 text-center">Strip qty</th>
          <th class="label px-2 text-center">Order (pcs)</th>
          <th class="label px-4 text-right">Est. cost</th>
          <th v-if="removable" class="w-10" />
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in visible" :key="r.productId" class="border-b border-slate-50 transition-colors hover:bg-brand-50/30" :class="r.orderQty > 0 && 'bg-brand-50/40'">
          <td class="px-4 py-2.5">
            <p class="font-semibold text-ink">{{ r.name }} <span class="font-medium text-slate-500">{{ r.strength }}</span></p>
            <p class="text-[11.5px] text-slate-400 tabular">{{ r.code }} · 1 box = {{ r.stripsPerBox }} × {{ r.piecesPerStrip }} pcs</p>
          </td>
          <td class="px-3 text-slate-600">{{ r.category }}</td>
          <td class="px-3 text-right tabular">{{ num(r.soldQty) }}</td>
          <td class="px-3 text-right font-semibold tabular" :class="r.inStock < r.soldQty ? 'text-amber-600' : 'text-ink'">{{ num(r.inStock) }}</td>
          <td v-if="showForecast" class="px-3">
            <div v-if="forecastLoading && !r.forecast" class="flex gap-3"><span class="skeleton h-8 w-12" /><span class="skeleton h-8 w-12" /></div>
            <div v-else-if="r.forecast" class="flex items-center gap-3">
              <div class="leading-tight"><p class="font-bold tabular">{{ num(r.forecast.forecast7) }}</p><p class="text-[10.5px] text-slate-400">forecast</p></div>
              <div class="leading-tight"><p class="font-bold text-brand-700 tabular">{{ num(r.forecast.recommended) }}</p><p class="text-[10.5px] text-slate-400">order</p></div>
              <UiBadge :tone="confTone[r.forecast.confidence]" class="capitalize">{{ r.forecast.confidence }}</UiBadge>
              <span class="group relative">
                <Info class="size-4 cursor-help text-slate-400" :aria-label="r.forecast.reason" tabindex="0" />
                <span class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-64 -translate-x-1/2 rounded-xl bg-ink px-3 py-2 text-xs leading-snug text-white opacity-0 shadow-lg transition group-hover:opacity-100 group-focus-within:opacity-100">{{ r.forecast.reason }}</span>
              </span>
              <button v-if="r.forecast.recommended > 0" type="button" class="rounded-md p-1 text-brand-600 hover:bg-brand-100" :aria-label="`Use forecast for ${r.name}`" title="Use forecast" @click="useForecast(r)"><Sparkles class="size-3.5" /></button>
            </div>
            <span v-else class="text-xs text-slate-400">—</span>
          </td>
          <td class="px-2 text-center"><input :value="r.boxQty" type="number" min="0" step="any" :class="qtyInput" class="border-slate-200" :aria-label="`Box quantity for ${r.name}`" @change="onBox(r, $event)"></td>
          <td class="px-2 text-center"><input :value="r.stripQty" type="number" min="0" step="any" :class="qtyInput" class="border-slate-200" :aria-label="`Strip quantity for ${r.name}`" @change="onStrip(r, $event)"></td>
          <td class="px-2 text-center"><input :value="r.orderQty" type="number" min="0" step="1" :class="qtyInput" class="border-brand-200 bg-brand-50/50" :aria-label="`Order pieces for ${r.name}`" @change="onPcs(r, $event)"></td>
          <td class="px-4 text-right tabular" :class="r.orderQty ? 'font-semibold' : 'text-slate-400'">{{ money(r.orderQty * r.unitCost) }}</td>
          <td v-if="removable" class="pr-3"><button type="button" class="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-600" :aria-label="`Remove ${r.name}`" @click="emit('remove', r.productId)"><Trash2 class="size-4" /></button></td>
        </tr>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
          <td class="px-4 py-3" colspan="2">Total · {{ visible.filter(r => r.orderQty > 0).length }} lines to order</td>
          <td class="px-3 text-right tabular">{{ num(totals.sold) }}</td>
          <td />
          <td v-if="showForecast" />
          <td class="px-2 text-center tabular">{{ num(totals.box) }}</td>
          <td class="px-2 text-center tabular">{{ num(totals.strip) }}</td>
          <td class="px-2 text-center text-brand-700 tabular">{{ num(totals.pcs) }}</td>
          <td class="px-4 text-right tabular">{{ money(totals.value) }}</td>
          <td v-if="removable" />
        </tr>
      </tfoot>
    </table>
    <UiEmptyState v-if="!visible.length" title="No products" description="Choose a company and date range to load its products." />
  </div>
</template>
