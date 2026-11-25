<template>
  <div class="mx-auto max-w-3xl px-4 py-12">
    <div class="rounded-[2rem] bg-white p-8 text-center shadow-soft">
      <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-primary-50 text-primary-700">
        <CheckCircle class="h-8 w-8" />
      </div>
      <h1 class="mt-5 text-3xl font-black text-ink">Order placed successfully</h1>
      <p class="mt-2 text-slate-500">Your order number is <strong>{{ $route.params.id }}</strong>. Our pharmacist will call you to confirm it.</p>
      <dl v-if="order" class="mx-auto mt-6 grid max-w-md gap-2 rounded-2xl bg-slate-50 p-4 text-left text-sm">
        <div class="flex justify-between"><dt>Tracking code</dt><dd class="font-black tracking-wider">{{ order.tracking_code }}</dd></div>
        <div class="flex justify-between"><dt>Items</dt><dd>{{ formatPrice(order.subtotal) }}</dd></div>
        <div v-if="order.discount_total" class="flex justify-between"><dt>Member discount</dt><dd>−{{ formatPrice(order.discount_total) }}</dd></div>
        <div class="flex justify-between"><dt>Delivery</dt><dd>{{ formatPrice(order.shipping) }}</dd></div>
        <div v-if="order.points_value" class="flex justify-between"><dt>Reward points ({{ order.points_redeemed }})</dt><dd>−{{ formatPrice(order.points_value) }}</dd></div>
        <div class="flex justify-between border-t border-slate-200 pt-2 text-base"><dt class="font-bold">Cash on delivery</dt><dd class="font-black">{{ formatPrice(order.cod_amount) }}</dd></div>
      </dl>
      <p v-if="order?.password_setup" class="mx-auto mt-4 max-w-md text-sm text-primary-800">{{ order.password_setup }}</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3">
        <RouterLink :to="order ? `/track-order?code=${order.tracking_code}` : '/track-order'" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white">Track order</RouterLink>
        <RouterLink to="/" class="rounded-full bg-slate-100 px-6 py-3 font-bold text-slate-700">Continue shopping</RouterLink>
      </div>
    </div>
  </div>
</template>

<script setup>
import { CheckCircle } from 'lucide-vue-next'
import { useRoute } from 'vue-router'
import { formatPrice } from '../utils/formatPrice'

const route = useRoute()
let order = null
try {
  const saved = JSON.parse(sessionStorage.getItem('redpharma_last_order') || 'null')
  order = saved?.number === route.params.id ? saved : null
} catch {
  order = null
}
</script>
