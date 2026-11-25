<template>
  <AccountShell>
    <h1 class="text-3xl font-black text-ink">Orders</h1>
    <LoadingSkeleton v-if="orders.loading && !orders.orders.length" class="mt-6 h-32" />
    <ErrorState v-else-if="orders.error" class="mt-6" title="Orders unavailable" :message="orders.error" />
    <EmptyState v-else-if="!orders.orders.length" class="mt-6" title="No orders yet" message="Your website and app orders will appear here." />
    <div v-else class="mt-6 space-y-3">
      <RouterLink v-for="order in orders.orders" :key="order.number" :to="`/account/orders/${order.number}`" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-50 p-4 hover:bg-primary-50">
        <div>
          <p class="font-bold text-ink">Order {{ order.number }}</p>
          <p class="text-sm text-slate-500">{{ date(order.placed_at) }} · {{ order.items_count }} item{{ order.items_count === 1 ? '' : 's' }}</p>
        </div>
        <div class="text-right">
          <p class="font-black text-ink">{{ formatPrice(order.cod_amount) }}</p>
          <p class="text-sm font-bold" :class="order.status === 'cancelled' ? 'text-red-600' : 'text-primary-700'">{{ order.status_label }}</p>
        </div>
      </RouterLink>
    </div>
  </AccountShell>
</template>

<script setup>
import { onMounted } from 'vue'
import AccountShell from './partials/AccountShell.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ErrorState from '../../components/common/ErrorState.vue'
import LoadingSkeleton from '../../components/common/LoadingSkeleton.vue'
import { useOrderStore } from '../../stores/orderStore'
import { formatPrice } from '../../utils/formatPrice'

const orders = useOrderStore()
const date = (v) => (v ? new Intl.DateTimeFormat('en-BD', { dateStyle: 'medium' }).format(new Date(v)) : '—')
onMounted(() => orders.fetch().catch(() => {}))
</script>
