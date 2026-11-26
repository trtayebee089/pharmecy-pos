<template>
  <div class="mx-auto max-w-3xl px-4 py-12">
    <div class="rounded-[2rem] bg-white p-8 text-center shadow-soft">
      <div class="mx-auto grid h-16 w-16 place-items-center rounded-full" :class="needsPayment ? 'bg-amber-50 text-amber-600' : 'bg-primary-50 text-primary-700'">
        <CircleAlert v-if="needsPayment" class="h-8 w-8" />
        <CheckCircle v-else class="h-8 w-8" />
      </div>
      <h1 class="mt-5 text-3xl font-black text-ink">{{ heading }}</h1>
      <p class="mt-2 text-slate-500">
        Your order number is <strong>{{ $route.params.id }}</strong>.
        <template v-if="!needsPayment">Our pharmacist will call you to confirm it.</template>
      </p>

      <!-- Online payment not finished: pay again, or pay on delivery -->
      <div v-if="needsPayment" class="mx-auto mt-6 max-w-md rounded-2xl bg-amber-50 p-4 text-left text-sm text-amber-900" role="alert">
        <p class="font-bold">{{ paymentMessage }}</p>
        <p class="mt-1">The order is saved. Pay now, or choose to pay in cash when it arrives.</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-for="option in onlineOptions"
            :key="option.code"
            type="button"
            :disabled="busy"
            class="rounded-xl bg-primary-600 px-4 py-2 font-bold text-white hover:bg-primary-700 disabled:opacity-50"
            @click="payAgain(option.code)"
          >
            Pay with {{ option.label }}
          </button>
          <button v-if="codAvailable" type="button" :disabled="busy" class="rounded-xl border border-amber-300 bg-white px-4 py-2 font-bold text-amber-900 hover:bg-amber-100 disabled:opacity-50" @click="payOnDelivery">
            Pay on delivery instead
          </button>
        </div>
        <p v-if="actionError" class="mt-2 text-red-700">{{ actionError }}</p>
      </div>

      <dl v-if="order" class="mx-auto mt-6 grid max-w-md gap-2 rounded-2xl bg-slate-50 p-4 text-left text-sm">
        <div class="flex justify-between"><dt>Tracking code</dt><dd class="font-black tracking-wider">{{ order.tracking_code }}</dd></div>
        <div v-if="order.subtotal != null" class="flex justify-between"><dt>Items</dt><dd>{{ formatPrice(order.subtotal) }}</dd></div>
        <div v-if="order.discount_total" class="flex justify-between"><dt>Member discount</dt><dd>−{{ formatPrice(order.discount_total) }}</dd></div>
        <div v-if="order.shipping != null" class="flex justify-between"><dt>Delivery</dt><dd>{{ formatPrice(order.shipping) }}</dd></div>
        <div v-if="order.points_value" class="flex justify-between"><dt>Reward points ({{ order.points_redeemed }})</dt><dd>−{{ formatPrice(order.points_value) }}</dd></div>
        <div v-if="order.paid_online" class="flex justify-between text-primary-700"><dt>Paid online</dt><dd>−{{ formatPrice(order.paid_online) }}</dd></div>
        <div class="flex justify-between border-t border-slate-200 pt-2 text-base">
          <dt class="font-bold">{{ needsPayment ? 'To pay' : 'Cash on delivery' }}</dt>
          <dd class="font-black">{{ formatPrice(order.cod_amount) }}</dd>
        </div>
      </dl>
      <LiveDeliveryCard v-if="order?.live && code" class="mx-auto mt-6 max-w-md text-left" :live="order.live" :fetch-live="fetchLive" @status-change="refresh" />
      <p v-if="order?.password_setup" class="mx-auto mt-4 max-w-md text-sm text-primary-800">{{ order.password_setup }}</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3">
        <RouterLink :to="order ? `/track-order?code=${order.tracking_code}` : '/track-order'" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white">Track order</RouterLink>
        <RouterLink to="/" class="rounded-full bg-slate-100 px-6 py-3 font-bold text-slate-700">Continue shopping</RouterLink>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CheckCircle, CircleAlert } from 'lucide-vue-next'
import { orderService } from '../services/orderService'
import LiveDeliveryCard from '../components/maps/LiveDeliveryCard.vue'
import { useSiteStore } from '../stores/siteStore'
import { tracking } from '../composables/useTracking'
import { formatPrice } from '../utils/formatPrice'

const route = useRoute()
const site = useSiteStore()
const busy = ref(false)
const actionError = ref('')

function saved() {
  try {
    const s = JSON.parse(sessionStorage.getItem('redpharma_last_order') || 'null')
    return s?.number === route.params.id ? s : null
  } catch {
    return null
  }
}
function remember(patch) {
  try { sessionStorage.setItem('redpharma_last_order', JSON.stringify({ ...order.value, ...patch })) } catch { /* storage blocked */ }
}

const order = ref(saved())
const result = String(route.query.payment || '') // set by the payment gateway's return
const code = computed(() => order.value?.tracking_code || String(route.query.code || ''))

const needsPayment = computed(() => order.value?.payment_status === 'pending')
const heading = computed(() => {
  if (order.value?.payment_status === 'paid') return 'Payment received, order placed'
  return needsPayment.value ? 'Your order is waiting for payment' : 'Order placed successfully'
})
const paymentMessage = computed(() => {
  if (result === 'cancelled') return 'You cancelled the payment.'
  if (result === 'failed') return 'The payment did not go through.'
  return order.value?.payment_error || 'The payment is not complete yet.'
})
const onlineOptions = computed(() => site.payments.filter((p) => p.code !== 'cod'))
const codAvailable = computed(() => site.payments.some((p) => p.code === 'cod'))

const fetchLive = () => orderService.trackLive(code.value)

/** Latest status and payment state of the order (by its tracking code). */
async function refresh() {
  if (!code.value) return
  try {
    const fresh = await orderService.track(code.value)
    order.value = { ...(order.value || {}), ...fresh }
    remember({ ...fresh, payment_error: order.value.payment_error })
  } catch { /* keep what we have */ }
}

async function payAgain(gateway) {
  busy.value = true
  actionError.value = ''
  try {
    const { redirect_url: url } = await orderService.pay(code.value, gateway)
    window.location.assign(url)
  } catch (e) {
    actionError.value = Object.values(e.errors || {})[0]?.[0] || e.message
    busy.value = false
  }
}

async function payOnDelivery() {
  busy.value = true
  actionError.value = ''
  try {
    const fresh = await orderService.payOnDelivery(code.value)
    order.value = { ...order.value, ...fresh, payment_error: null }
    remember(order.value)
  } catch (e) {
    actionError.value = Object.values(e.errors || {})[0]?.[0] || e.message
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  // Back from the gateway (or a reload): read the latest payment state.
  await refresh()
  // Purchase is reported once per order, even if this page is reloaded.
  if (order.value?.items && !order.value.tracked) {
    tracking.purchase(order.value, order.value.event_id)
    remember({ tracked: true })
    order.value = { ...order.value, tracked: true }
  }
})
</script>
