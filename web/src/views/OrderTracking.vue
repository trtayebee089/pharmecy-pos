<template>
  <div class="mx-auto max-w-3xl px-4 py-10">
    <div class="rounded-[2rem] bg-white p-6 shadow-soft md:p-8">
      <h1 class="text-3xl font-black text-ink">Track your order</h1>
      <p class="mt-2 text-slate-500">Enter the tracking code from your order confirmation (it starts with RP).</p>
      <form class="mt-6 flex flex-wrap gap-3" @submit.prevent="track">
        <label class="sr-only" for="tracking-code">Tracking code</label>
        <input id="tracking-code" v-model.trim="code" required placeholder="RPXXXXXXXX" class="min-w-0 flex-1 rounded-2xl bg-slate-50 px-4 py-3 uppercase tracking-wider outline-none focus:ring-2 focus:ring-primary-500" />
        <button :disabled="loading" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ loading ? 'Checking…' : 'Track order' }}</button>
      </form>
      <p v-if="error" class="mt-4 text-sm text-red-600" role="alert">{{ error }}</p>
      <section v-if="order" class="mt-6" aria-live="polite">
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-primary-50 p-4">
          <p class="font-bold text-ink">Order {{ order.number }}</p>
          <p class="font-black text-primary-800">{{ order.status_label }}</p>
        </div>
        <ol class="mt-4 space-y-3 border-l-2 border-primary-100 pl-4">
          <li v-for="(step, i) in order.history" :key="i"><p class="font-bold text-ink">{{ step.label }}</p><p class="text-sm text-slate-500">{{ date(step.at) }}</p></li>
        </ol>
        <p class="mt-4 text-sm text-slate-600">
          <template v-if="order.payment_status === 'paid'">Paid online. Nothing to pay on delivery.</template>
          <template v-else>Cash to pay on delivery: <strong>{{ formatPrice(order.cod_amount) }}</strong></template>
        </p>
        <p v-if="order.courier" class="mt-2 text-sm text-slate-600">
          Delivered by {{ order.courier.name }}.
          <a v-if="order.courier.tracking_url" :href="order.courier.tracking_url" target="_blank" rel="noopener" class="font-bold text-primary-700 underline">Track the parcel</a>
        </p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { orderService } from '../services/orderService'
import { formatPrice } from '../utils/formatPrice'

const route = useRoute()
const code = ref(String(route.query.code || ''))
const order = ref(null)
const error = ref('')
const loading = ref(false)
const date = (v) => (v ? new Intl.DateTimeFormat('en-BD', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : '')

async function track() {
  if (!code.value) return
  loading.value = true
  error.value = ''
  order.value = null
  try {
    order.value = await orderService.track(code.value)
  } catch (e) {
    error.value = e.status === 404 ? 'No order with that tracking code. Please check the code.' : e.message
  } finally {
    loading.value = false
  }
}

onMounted(() => code.value && track())
</script>
