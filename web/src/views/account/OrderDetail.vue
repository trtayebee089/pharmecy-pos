<template>
  <AccountShell>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-3xl font-black text-ink">Order {{ route.params.id }}</h1>
      <button class="rounded-xl bg-primary-700 px-5 py-3 font-bold text-white disabled:opacity-50" :disabled="reordering || !order" @click="reorder">{{ reordering ? 'Recalculating…' : 'Reorder' }}</button>
    </div>
    <LoadingSkeleton v-if="loading" class="mt-6 h-52" />
    <ErrorState v-else-if="error && !order" class="mt-6" title="Order unavailable" :message="error" />
    <template v-else-if="order">
      <section class="mt-6 grid gap-3 sm:grid-cols-3">
        <article class="rounded-2xl bg-slate-50 p-4"><p class="text-sm text-slate-500">Status</p><p class="font-black text-ink">{{ order.status_label }}</p></article>
        <article class="rounded-2xl bg-slate-50 p-4"><p class="text-sm text-slate-500">Cash on delivery</p><p class="font-black text-ink">{{ formatPrice(order.cod_amount) }}</p></article>
        <article class="rounded-2xl bg-slate-50 p-4"><p class="text-sm text-slate-500">Placed</p><p class="font-black text-ink">{{ date(order.placed_at) }}</p></article>
      </section>
      <LiveDeliveryCard v-if="order.live" class="mt-6" :live="order.live" :fetch-live="fetchLive" @status-change="load" />
      <section class="mt-6">
        <h2 class="text-xl font-black text-ink">Items</h2>
        <div class="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-100">
          <div v-for="(item, i) in order.items" :key="i" class="flex items-center justify-between gap-4 p-4">
            <div><p class="font-bold text-ink">{{ item.name }}</p><p class="text-sm text-slate-500">{{ item.quantity }} × {{ formatPrice(item.unit_price) }}</p></div>
            <p class="font-bold">{{ formatPrice(item.subtotal) }}</p>
          </div>
        </div>
        <dl class="mt-4 ml-auto grid max-w-sm gap-1 text-sm">
          <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ formatPrice(order.subtotal) }}</dd></div>
          <div v-if="order.discount_total" class="flex justify-between"><dt>Member discount</dt><dd>−{{ formatPrice(order.discount_total) }}</dd></div>
          <div class="flex justify-between"><dt>Delivery</dt><dd>{{ formatPrice(order.shipping) }}</dd></div>
          <div v-if="order.points_value" class="flex justify-between"><dt>Reward points ({{ order.points_redeemed }})</dt><dd>−{{ formatPrice(order.points_value) }}</dd></div>
          <div class="flex justify-between border-t border-slate-100 pt-1 font-black"><dt>To pay</dt><dd>{{ formatPrice(order.cod_amount) }}</dd></div>
        </dl>
      </section>
      <section class="mt-6">
        <h2 class="text-xl font-black text-ink">Progress</h2>
        <p class="mt-1 text-sm text-slate-500">Tracking code <strong class="tracking-wider">{{ order.tracking_code }}</strong></p>
        <ol class="mt-3 space-y-3 border-l-2 border-primary-100 pl-4">
          <li v-for="(step, i) in order.history" :key="i"><p class="font-bold text-ink">{{ step.label }}</p><p class="text-sm text-slate-500">{{ date(step.at, true) }}<span v-if="step.note"> · {{ step.note }}</span></p></li>
        </ol>
        <p v-if="order.cancel_reason" class="mt-3 text-sm text-red-600">Cancelled: {{ order.cancel_reason }}</p>
      </section>
      <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>
    </template>
  </AccountShell>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AccountShell from './partials/AccountShell.vue'
import LoadingSkeleton from '../../components/common/LoadingSkeleton.vue'
import ErrorState from '../../components/common/ErrorState.vue'
import LiveDeliveryCard from '../../components/maps/LiveDeliveryCard.vue'
import { orderService } from '../../services/orderService'
import { useCartStore } from '../../stores/cartStore'
import { formatPrice } from '../../utils/formatPrice'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const order = ref(null)
const loading = ref(true)
const error = ref('')
const reordering = ref(false)
const date = (v, time = false) => (v ? new Intl.DateTimeFormat('en-BD', time ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(v)) : '—')

const fetchLive = () => orderService.live(route.params.id)

async function load() {
  try {
    order.value = await orderService.find(route.params.id)
  } catch (e) {
    error.value = e.status === 404 ? 'This order was not found in your account.' : e.message || 'Unable to load order.'
  } finally {
    loading.value = false
  }
}

/** Refills the cart at today's prices and stock; the cart page lists what changed. */
async function reorder() {
  reordering.value = true
  error.value = ''
  try {
    cart.replaceFromReorder(await orderService.reorder(route.params.id))
    router.push('/cart')
  } catch (e) {
    error.value = e.message || 'Unable to reorder.'
  } finally {
    reordering.value = false
  }
}

onMounted(load)
</script>
