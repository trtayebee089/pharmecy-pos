<script setup lang="ts">
import type { PaymentLine } from '~/types/domain'

defineProps<{ number: string; payments: PaymentLine[]; change: number }>()
const cart = useCartStore()
const auth = useAuthStore()
</script>

<template>
  <!-- 80 mm thermal receipt, only visible when printing -->
  <Teleport to="body">
    <div id="print-receipt" class="hidden">
      <div class="receipt">
        <p class="center bold big">Red Pharma</p>
        <p class="center">{{ auth.warehouse?.name }}</p>
        <p class="center small">{{ number }} · {{ dateTime(new Date().toISOString()) }}</p>
        <p class="center small">Cashier: {{ auth.user?.name }} · Customer: {{ cart.customer?.name ?? 'Walk-in' }}</p>
        <hr>
        <div v-for="l in cart.lines" :key="l.key" class="row">
          <span>{{ l.product.name }} {{ l.product.strength }}<br><small>{{ l.qty }} × {{ cart.unitOf(l).label }} @ {{ money(cart.unitOf(l).price, false) }}</small></span>
          <span>{{ money(cart.lineTotal(l), false) }}</span>
        </div>
        <hr>
        <div class="row"><span>Subtotal</span><span>{{ money(cart.subtotal, false) }}</span></div>
        <div v-if="cart.lineDiscounts + cart.orderDiscount" class="row"><span>Discount</span><span>-{{ money(cart.lineDiscounts + cart.orderDiscount, false) }}</span></div>
        <div class="row bold big"><span>TOTAL</span><span>{{ money(cart.total) }}</span></div>
        <div v-for="(p, i) in payments" :key="i" class="row"><span>{{ p.method.toUpperCase() }}</span><span>{{ money(p.amount, false) }}</span></div>
        <div class="row"><span>Change</span><span>{{ money(change, false) }}</span></div>
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
