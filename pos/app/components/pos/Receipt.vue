<script setup lang="ts">
import type { ReceiptSnapshot } from '~/stores/cart'

/** 80 mm thermal receipt, rendered from a snapshot so it can be reprinted later. Visible only when printing. */
defineProps<{ receipt: ReceiptSnapshot }>()
const auth = useAuthStore()
</script>

<template>
  <Teleport to="body">
    <div id="print-receipt" class="hidden">
      <div class="receipt">
        <p class="center bold big">Red Pharma</p>
        <p class="center">{{ auth.warehouse?.name }}</p>
        <p class="center small">{{ receipt.number }} · {{ dateTime(receipt.at) }}</p>
        <p class="center small">Cashier: {{ auth.user?.name }} · Customer: {{ receipt.customer }}</p>
        <hr>
        <div v-for="(l, i) in receipt.lines" :key="i" class="row">
          <span>{{ l.name }}<br><small>{{ l.qty }} × {{ l.unitLabel }} @ {{ money(l.unitPrice, false) }}<template v-if="l.batch"> · B:{{ l.batch }}</template></small></span>
          <span>{{ money(l.total, false) }}</span>
        </div>
        <hr>
        <div class="row"><span>Subtotal</span><span>{{ money(receipt.subtotal, false) }}</span></div>
        <div v-if="receipt.discount" class="row"><span>Discount</span><span>-{{ money(receipt.discount, false) }}</span></div>
        <div class="row bold big"><span>TOTAL</span><span>{{ money(receipt.total) }}</span></div>
        <div v-for="(p, i) in receipt.payments" :key="`p${i}`" class="row"><span>{{ p.method.toUpperCase() }}</span><span>{{ money(p.amount, false) }}</span></div>
        <div class="row"><span>Change</span><span>{{ money(receipt.change, false) }}</span></div>
        <hr>
        <p class="center small">Medicines once sold are returnable only as per pharmacy policy. Thank you!</p>
      </div>
    </div>
  </Teleport>
</template>

<style>
@media print {
  body > *:not(#print-receipt) { display: none !important; }
  #print-receipt { display: block !important; }
  @page { size: 80mm auto; margin: 4mm; }
  .receipt { font: 12px/1.35 ui-monospace, monospace; color: #000; width: 72mm; }
  .receipt .center { text-align: center; }
  .receipt .bold { font-weight: 700; }
  .receipt .big { font-size: 15px; }
  .receipt .small, .receipt small { font-size: 10px; }
  .receipt .row { display: flex; justify-content: space-between; gap: 8px; margin: 3px 0; }
  .receipt hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
}
</style>
