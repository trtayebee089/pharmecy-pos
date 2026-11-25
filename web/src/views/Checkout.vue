<template>
  <div class="mx-auto max-w-7xl px-4 py-8">
    <Breadcrumb :items="[{ label: 'Checkout' }]" />
    <h1 class="mt-5 text-3xl font-black text-ink">Checkout</h1>
    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_360px]">
      <form class="rounded-3xl bg-white p-6 shadow-soft" @submit.prevent="submit">
        <p v-if="!auth.isAuthenticated" class="mb-5 rounded-2xl bg-primary-50 p-4 text-sm text-primary-800">
          Ordering as a guest. <RouterLink to="/login" class="font-bold underline">Sign in</RouterLink> to use your member discount and reward points.
        </p>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="text-sm font-bold text-slate-700">Full name <input v-model.trim="form.name" required autocomplete="name" :readonly="auth.isAuthenticated" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
          <label class="text-sm font-bold text-slate-700">Phone number <input v-model.trim="form.phone" required inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" :readonly="auth.isAuthenticated" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
          <label v-if="!auth.isAuthenticated" class="md:col-span-2 text-sm font-bold text-slate-700">Email <span class="font-normal text-slate-500">(optional: lets you set a password later and see your orders)</span><input v-model.trim="form.email" type="email" autocomplete="email" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
          <label class="md:col-span-2 text-sm font-bold text-slate-700">Delivery address <textarea v-model.trim="form.address" required rows="3" autocomplete="street-address" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500"></textarea></label>
          <label class="text-sm font-bold text-slate-700">Division
            <select v-model="form.division" required class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" @change="form.district = ''; form.zoneId = ''">
              <option value="" disabled>Choose…</option><option v-for="d in divisions" :key="d">{{ d }}</option>
            </select>
          </label>
          <label class="text-sm font-bold text-slate-700">District
            <select v-model="form.district" required :disabled="!form.division" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" @change="form.zoneId = areas.length === 1 ? areas[0].id : ''">
              <option value="" disabled>Choose…</option><option v-for="d in districts" :key="d">{{ d }}</option>
            </select>
          </label>
          <label v-if="areas.length > 1" class="text-sm font-bold text-slate-700">Area
            <select v-model="form.zoneId" required class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500">
              <option value="" disabled>Choose…</option><option v-for="z in areas" :key="z.id" :value="z.id">{{ z.thana || 'Anywhere in ' + z.district }}</option>
            </select>
          </label>
          <p v-if="zonesError" class="md:col-span-2 text-sm text-red-600">{{ zonesError }}</p>
          <fieldset class="md:col-span-2 text-sm font-bold text-slate-700">
            <legend>Payment</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
              <label
                v-for="option in site.payments"
                :key="option.code"
                class="flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 font-semibold transition"
                :class="paymentMethod === option.code ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-slate-200 bg-white hover:border-slate-300'"
              >
                <input v-model="paymentMethod" type="radio" name="payment" :value="option.code" class="h-4 w-4 accent-primary-600" />
                {{ option.label }}
              </label>
            </div>
            <p v-if="paymentMethod !== 'cod'" class="mt-2 text-xs font-normal text-slate-500">You will pay on the next page; the order is sent to the pharmacy once the payment is complete.</p>
          </fieldset>
          <label v-if="auth.isAuthenticated && cart.rewardPoints" class="text-sm font-bold text-slate-700">Reward points to use
            <input v-model.number="points" type="number" min="0" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" />
          </label>
          <label class="md:col-span-2 text-sm font-bold text-slate-700">Order notes <textarea v-model.trim="form.notes" rows="3" maxlength="500" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500"></textarea></label>
        </div>
        <p v-if="cart.hasPrescription" class="mt-4 text-sm text-amber-700">Your cart has prescription medicines. Please keep the prescription ready: our pharmacist checks it on the confirmation call or at delivery.</p>
        <ErrorState v-if="error" class="mt-5" title="Checkout failed" :message="error" />
        <button :disabled="submitting || !cart.items.length || !selectedZone" class="mt-6 rounded-full bg-primary-600 px-7 py-3 font-bold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-slate-300">
          {{ submitting ? 'Placing order...' : 'Place order' }}
        </button>
        <p class="mt-3 text-xs text-slate-500">Prices, discounts and the delivery charge are confirmed by the pharmacy when the order is placed.</p>
      </form>
      <CartSummary />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Breadcrumb from '../components/common/Breadcrumb.vue'
import ErrorState from '../components/common/ErrorState.vue'
import CartSummary from './partials/CartSummary.vue'
import { useAuthStore } from '../stores/authStore'
import { useCartStore } from '../stores/cartStore'
import { useSiteStore } from '../stores/siteStore'
import { orderService } from '../services/orderService'
import { tracking } from '../composables/useTracking'

const cart = useCartStore()
const auth = useAuthStore()
const site = useSiteStore()
const paymentMethod = ref('cod')
watch(() => site.payments, (list) => {
  if (!list.some((p) => p.code === paymentMethod.value)) paymentMethod.value = list[0]?.code || 'cod'
}, { immediate: true })
const router = useRouter()
const submitting = ref(false)
const error = ref('')
const zones = ref([])
const zonesError = ref('')
const points = ref(cart.rewardPoints)
const user = auth.user || {}
const form = reactive({
  name: user.name || '', phone: user.phone || user.phone_number || '', email: '', address: user.address || '',
  division: cart.zone?.division || '', district: cart.zone?.district || '', zoneId: cart.zone?.id || '', notes: '',
})
// One key per filled-in checkout: pressing "Place order" again after a network error cannot create a second order.
let attemptKey = orderService.newKey()

const divisions = computed(() => [...new Set(zones.value.map((z) => z.division))].sort())
const districts = computed(() => [...new Set(zones.value.filter((z) => z.division === form.division).map((z) => z.district))].sort())
const areas = computed(() => zones.value.filter((z) => z.division === form.division && z.district === form.district))
const selectedZone = computed(() => zones.value.find((z) => z.id === form.zoneId) || (areas.value.length === 1 ? areas.value[0] : null))

watch(selectedZone, (zone) => cart.setZone(zone))
watch(() => [form.name, form.phone, form.address, form.zoneId, form.notes, points.value, cart.count], () => { attemptKey = orderService.newKey() })

onMounted(async () => {
  tracking.beginCheckout(cart.items)
  try {
    zones.value = await orderService.zones()
    if (cart.zone && !zones.value.some((z) => z.id === cart.zone.id)) cart.setZone(null)
  } catch {
    zonesError.value = 'Delivery areas could not be loaded. Please refresh the page.'
  }
})

async function submit() {
  if (!cart.items.length || !selectedZone.value) return
  submitting.value = true
  error.value = ''
  try {
    const result = await orderService.checkout({
      customer: { name: form.name, phone: form.phone, email: form.email || null, address: form.address },
      zone_id: selectedZone.value.id,
      payment_method: paymentMethod.value,
      notes: form.notes || null,
      items: cart.items.map((item) => ({ product_id: item.product.id, qty: item.quantity })),
      reward_points: auth.isAuthenticated ? Number(points.value || 0) : 0,
      // The same event id goes to the browser Pixel and the server's Conversions API (counted once).
      tracking: tracking.serverContext(attemptKey),
    }, attemptKey)
    sessionStorage.setItem('redpharma_last_order', JSON.stringify({ ...result.order, is_new_customer: result.is_new_customer, password_setup: result.password_setup, payment_error: result.payment?.error || null }))
    cart.clear()
    // Online payment: off to the gateway, which brings the customer back to the confirmation page.
    if (result.payment?.redirect_url) {
      window.location.assign(result.payment.redirect_url)
      return
    }
    router.push(`/order-confirmation/${result.order.number}`)
  } catch (exception) {
    const first = Object.values(exception.errors || {})[0]
    error.value = (Array.isArray(first) ? first[0] : first) || exception.message || 'Please check your information and try again.'
  } finally {
    submitting.value = false
  }
}
</script>
