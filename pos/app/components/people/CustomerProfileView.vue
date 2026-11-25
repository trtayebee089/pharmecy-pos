<script setup lang="ts">
import { Crown, Phone, Mail, MapPin, Users, Wallet, Star, ShoppingBag, CalendarDays, Printer } from 'lucide-vue-next'
import type { CustomerProfile } from '~/types/people'

/** Shared content of the customer drawer and the full customer page. */
const props = defineProps<{ profile: CustomerProfile }>()
const tab = ref<'purchases' | 'ledger' | 'rewards'>('purchases')
const p = computed(() => props.profile)
const root = ref<HTMLElement | null>(null)
const tabsList = computed(() => [
  { value: 'purchases' as const, label: 'Purchases', count: p.value.purchases.length },
  { value: 'ledger' as const, label: 'Due ledger', count: p.value.ledger.length },
  { value: 'rewards' as const, label: 'Points', count: p.value.rewards.length },
])
watch(tab, async () => {
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-row]') ?? [], { y: 6, stagger: 0.02, duration: 0.3 })
})
const entryTone = { opening: 'neutral', invoice: 'info', payment: 'success', return: 'violet', refund: 'warning', adjustment: 'neutral', earn: 'success', redeem: 'brand', expire: 'danger', adjust: 'warning', reverse: 'neutral', membership: 'brand', void: 'neutral' } as const
</script>

<template>
  <div ref="root">
    <div class="flex flex-wrap items-start gap-4" data-stagger>
      <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-brand-300 to-brand-700 font-display text-lg font-extrabold text-white">{{ initials(p.name) }}</span>
      <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-2 font-display text-xl font-extrabold text-ink">
          {{ p.name }}
          <UiBadge v-if="p.membership" :tone="p.membership === 'Gold' ? 'warning' : 'violet'"><Crown class="size-3" />{{ p.membership }}</UiBadge>
          <UiBadge v-if="p.group" tone="brand"><Users class="size-3" />{{ p.group }}</UiBadge>
        </p>
        <p class="text-xs text-slate-500">{{ p.code }}</p>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-slate-600">
          <span class="flex items-center gap-1.5 tabular"><Phone class="size-3.5 text-slate-400" />{{ p.phone ?? 'No phone' }}</span>
          <span v-if="p.email" class="flex items-center gap-1.5"><Mail class="size-3.5 text-slate-400" />{{ p.email }}</span>
          <span v-if="p.address" class="flex items-center gap-1.5"><MapPin class="size-3.5 text-slate-400" />{{ p.address }}</span>
        </div>
      </div>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-stagger>
      <div class="rounded-2xl bg-slate-50 p-3.5"><p class="label flex items-center gap-1"><ShoppingBag class="size-3" />Lifetime</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ compactMoney(p.totalSpent) }}</p><p class="text-[11px] text-slate-500">{{ p.visits }} visits</p></div>
      <div class="rounded-2xl bg-slate-50 p-3.5"><p class="label">Avg basket</p><p class="mt-1 font-display text-lg font-extrabold tabular">{{ money(p.avgBasket) }}</p></div>
      <div class="rounded-2xl p-3.5" :class="p.dueBalance ? 'bg-amber-50' : 'bg-slate-50'"><p class="label flex items-center gap-1" :class="p.dueBalance ? 'text-amber-700' : ''"><Wallet class="size-3" />Due</p><p class="mt-1 font-display text-lg font-extrabold tabular" :class="p.dueBalance ? 'text-amber-700' : ''">{{ money(p.dueBalance) }}</p></div>
      <div class="rounded-2xl bg-brand-50 p-3.5"><p class="label flex items-center gap-1 text-brand-700"><Star class="size-3" />Points</p><p class="mt-1 font-display text-lg font-extrabold text-brand-800 tabular">{{ num(p.points) }}</p></div>
    </div>
    <p class="mt-2 flex items-center gap-1.5 text-xs text-slate-500" data-stagger><CalendarDays class="size-3.5" />Customer since {{ date(p.firstVisit) }} · last visit {{ date(p.lastVisit) }}</p>

    <div class="mt-5" data-stagger><UiTabs v-model="tab" :tabs="tabsList" /></div>

    <div class="mt-3">
      <ul v-if="tab === 'purchases'" class="divide-y divide-slate-100">
        <li v-for="s in p.purchases" :key="s.id" data-row class="flex items-center gap-3 py-2.5 text-sm">
          <NuxtLink :to="`/sales/${s.id}`" class="font-mono text-[12.5px] font-semibold text-ink hover:text-brand-700 hover:underline">{{ s.number }}</NuxtLink>
          <span class="text-xs text-slate-500">{{ dateTime(s.date) }} · {{ s.items }} items</span>
          <UiBadge v-if="s.status === 'void'" tone="neutral" class="ml-auto">Void</UiBadge>
          <span class="ml-auto font-semibold tabular" :class="s.status === 'void' && 'ml-0 text-slate-400 line-through'">{{ money(s.total) }}</span>
          <UiRowActions :label="s.number" :view="`/sales/${s.id}`" :items="[{ label: 'Print receipt', icon: Printer, to: `/sales/${s.id}?print=1` }]" />
        </li>
        <li v-if="!p.purchases.length" class="py-6 text-center text-sm text-slate-500">No purchases yet.</li>
      </ul>

      <div v-else-if="tab === 'ledger'" class="-mx-1 overflow-x-auto">
        <table class="w-full min-w-[480px] text-sm">
          <thead><tr class="border-b border-slate-100"><th class="label h-9 px-1 text-left">Date</th><th class="label text-left">Entry</th><th class="label text-right">Debit</th><th class="label text-right">Credit</th><th class="label px-1 text-right">Balance</th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in p.ledger" :key="i" data-row class="border-b border-slate-50">
              <td class="px-1 py-2 text-xs text-slate-500">{{ date(l.date) }}</td>
              <td class="py-2"><UiBadge :tone="entryTone[l.type]" class="capitalize">{{ l.type }}</UiBadge> <span class="font-mono text-[11.5px] text-slate-500">{{ l.ref }}</span></td>
              <td class="py-2 text-right tabular">{{ l.debit ? money(l.debit) : '' }}</td>
              <td class="py-2 text-right tabular text-emerald-700">{{ l.credit ? money(l.credit) : '' }}</td>
              <td class="px-1 py-2 text-right font-semibold tabular">{{ money(l.balance) }}</td>
            </tr>
          </tbody>
        </table>
        <p class="mt-2 text-[11px] text-slate-400">Balances are computed from the party ledger by the server; they cannot be edited.</p>
      </div>

      <ul v-else class="divide-y divide-slate-100">
        <li v-for="e in p.rewards" :key="e.id" data-row class="flex items-center gap-3 py-2.5 text-sm">
          <UiBadge :tone="entryTone[e.type]" class="capitalize">{{ e.type }}</UiBadge>
          <span class="min-w-0 flex-1 truncate text-xs text-slate-500">{{ date(e.date) }} · {{ e.ref }}<template v-if="e.note"> · {{ e.note }}</template></span>
          <span class="font-semibold tabular" :class="e.points >= 0 ? 'text-emerald-700' : 'text-rose-600'">{{ e.points > 0 ? '+' : '' }}{{ num(e.points) }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>
