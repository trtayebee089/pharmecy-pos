<script setup lang="ts">
import { AlertTriangle, Banknote, CalendarClock, HandCoins, PackageMinus, Receipt, TrendingUp, Wallet, ArrowUpRight, RefreshCw } from 'lucide-vue-next'
import type { DashboardSummary } from '~/types/domain'

definePageMeta({ title: 'Dashboard' })

const auth = useAuthStore()
const api = useDashboardApi()
const data = ref<DashboardSummary | null>(null)
const loading = ref(true)
const range = ref<'14d' | '30d'>('14d')
const root = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { data.value = await api.summary(auth.warehouseId, range.value === '30d' ? 30 : 14) }
  catch (e: any) { useToast().error('Could not load the dashboard', e?.message) }
  finally { loading.value = false }
}
watch(() => [auth.warehouseId, range.value], load)
onMounted(async () => {
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.06 })
  await load()
})

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})
</script>

<template>
  <div ref="root" class="space-y-6">
    <div data-reveal class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-sm text-slate-500">{{ greeting }}, {{ auth.user?.name.split(' ')[0] }}</p>
        <h1 class="font-display text-[28px] font-extrabold tracking-tight text-ink">Today at {{ auth.warehouse?.name }}</h1>
      </div>
      <div class="flex items-center gap-2">
        <UiSegmented v-model="range" :options="[{ value: '14d', label: '14 days' }, { value: '30d', label: '30 days' }]" />
        <UiButton :icon="RefreshCw" :loading="loading" @click="load">Refresh</UiButton>
      </div>
    </div>

    <div data-reveal class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <UiStatCard label="Today's sales" :value="data?.todaySales ?? 0" :delta="data?.todaySalesDelta" hint="vs. yesterday" :icon="TrendingUp" :loading="loading" />
      <UiStatCard label="Gross profit" :value="data?.todayProfit ?? 0" :delta="data?.todayProfitDelta" hint="from batch cost" :icon="HandCoins" tone="violet" :loading="loading" />
      <UiStatCard label="Invoices" :value="data?.todayInvoices ?? 0" format="number" hint="completed today" :icon="Receipt" tone="sky" :loading="loading" />
      <UiStatCard label="Cash in hand" :value="data?.cashInHand ?? 0" hint="open registers" :icon="Banknote" tone="amber" :loading="loading" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <div data-reveal class="card p-5 xl:col-span-2">
        <div class="mb-2 flex items-center justify-between">
          <div>
            <p class="label">Sales trend</p>
            <p class="font-display text-lg font-bold text-ink">Last {{ data?.salesSeries.length ?? (range === '14d' ? 14 : 30) }} days</p>
          </div>
          <UiBadge tone="success" dot>Live</UiBadge>
        </div>
        <div v-if="loading && !data" class="skeleton h-60 w-full" />
        <ChartsAreaChart v-else-if="data" :series="data.salesSeries" />
      </div>

      <div data-reveal class="card p-5">
        <p class="label">Payment mix</p>
        <p class="mb-5 font-display text-lg font-bold text-ink">Today</p>
        <div v-if="loading && !data" class="skeleton h-36 w-full" />
        <ChartsDonutChart v-else-if="data" :items="data.paymentMix" />
        <div class="mt-6 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4">
          <div>
            <p class="label flex items-center gap-1.5"><Wallet class="size-3.5" />Customer due</p>
            <p class="mt-1 font-semibold text-ink tabular">{{ money(data?.customerDue) }}</p>
          </div>
          <div>
            <p class="label flex items-center gap-1.5"><HandCoins class="size-3.5" />Supplier due</p>
            <p class="mt-1 font-semibold text-ink tabular">{{ money(data?.supplierDue) }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <!-- Stock health -->
      <div data-reveal class="card overflow-hidden xl:col-span-1">
        <div class="border-b border-slate-100 p-5">
          <p class="label">Stock health</p>
          <p class="font-display text-lg font-bold text-ink">Needs attention</p>
        </div>
        <div class="divide-y divide-slate-100">
          <NuxtLink to="/inventory/batches" class="group flex items-center gap-4 p-5 transition-colors hover:bg-rose-50/40">
            <span class="grid size-11 place-items-center rounded-xl bg-rose-50 text-rose-600"><AlertTriangle class="size-5" /></span>
            <span class="flex-1"><span class="block text-sm font-semibold text-ink">Expired batches</span><span class="text-xs text-slate-500">Blocked from sale · write off or return</span></span>
            <span class="font-display text-2xl font-extrabold text-rose-600 tabular">{{ data?.expiredCount ?? '–' }}</span>
          </NuxtLink>
          <NuxtLink to="/inventory/batches" class="group flex items-center gap-4 p-5 transition-colors hover:bg-amber-50/40">
            <span class="grid size-11 place-items-center rounded-xl bg-amber-50 text-amber-600"><CalendarClock class="size-5" /></span>
            <span class="flex-1"><span class="block text-sm font-semibold text-ink">Expiring ≤ 90 days</span><span class="text-xs text-slate-500">Sell first (FEFO) or return to supplier</span></span>
            <span class="font-display text-2xl font-extrabold text-amber-600 tabular">{{ data?.nearExpiryCount ?? '–' }}</span>
          </NuxtLink>
          <NuxtLink to="/products" class="group flex items-center gap-4 p-5 transition-colors hover:bg-sky-50/40">
            <span class="grid size-11 place-items-center rounded-xl bg-sky-50 text-sky-600"><PackageMinus class="size-5" /></span>
            <span class="flex-1"><span class="block text-sm font-semibold text-ink">Low stock</span><span class="text-xs text-slate-500">At or below reorder level</span></span>
            <span class="font-display text-2xl font-extrabold text-sky-600 tabular">{{ data?.lowStockCount ?? '–' }}</span>
          </NuxtLink>
        </div>
      </div>

      <!-- Top products -->
      <div data-reveal class="card p-5 xl:col-span-2">
        <div class="mb-4 flex items-center justify-between">
          <div>
            <p class="label">Best sellers</p>
            <p class="font-display text-lg font-bold text-ink">Top products today</p>
          </div>
          <NuxtLink to="/products" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">All products<ArrowUpRight class="size-4" /></NuxtLink>
        </div>
        <div v-if="loading && !data" class="space-y-3"><div v-for="i in 5" :key="i" class="skeleton h-10" /></div>
        <ul v-else class="space-y-3">
          <li v-for="(p, i) in data?.topProducts" :key="p.name" class="flex items-center gap-4">
            <span class="grid size-8 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500 tabular">{{ i + 1 }}</span>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-3 text-sm">
                <span class="truncate font-semibold text-ink">{{ p.name }}</span>
                <span class="font-semibold text-ink tabular">{{ money(p.revenue) }}</span>
              </div>
              <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-brand-600 transition-[width] duration-1000 ease-out" :style="{ width: `${(p.revenue / (data!.topProducts[0]!.revenue || 1)) * 100}%` }" />
              </div>
            </div>
            <span class="w-20 text-right text-xs text-slate-500 tabular">{{ num(p.qty) }} pcs</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
