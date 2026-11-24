<script setup lang="ts">
import { Search, UserPlus, Crown, Wallet } from 'lucide-vue-next'
import type { Customer } from '~/types/domain'

const open = defineModel<boolean>({ default: false })
const cart = useCartStore()
const api = useCustomersApi()
const q = ref('')
const results = ref<Customer[]>([])
const loading = ref(false)
const list = ref<HTMLElement | null>(null)
const newOpen = ref(false)
function created(c: Customer) { cart.customer = c; open.value = false }

async function load() {
  loading.value = true
  results.value = await api.lookup(q.value)
  loading.value = false
  await nextTick()
  revealStagger(list.value?.children ?? [], { y: 6, stagger: 0.03, duration: 0.3 })
}
watch(open, o => o && load())
watchDebounced(q, load, { debounce: 150 })

function choose(c: Customer) {
  cart.customer = c.isWalkIn ? null : c
  open.value = false
}
</script>

<template>
  <UiModal v-model="open" title="Select customer" description="Search by name or phone. Dues and points need a named customer.">
    <UiInput v-model="q" :icon="Search" placeholder="Name or 01XXXXXXXXX" size="lg" inputmode="search" />
    <div ref="list" class="mt-4 max-h-[46vh] space-y-1 overflow-y-auto">
      <button
        v-for="c in results" :key="c.id"
        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-brand-50"
        @click="choose(c)"
      >
        <span class="grid size-10 place-items-center rounded-xl text-sm font-bold" :class="c.isWalkIn ? 'bg-slate-100 text-slate-500' : 'bg-brand-100 text-brand-700'">{{ initials(c.name) }}</span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2 text-sm font-semibold text-ink">{{ c.name }}<UiBadge v-if="c.membership" tone="violet"><Crown class="size-3" />{{ c.membership }}</UiBadge></span>
          <span class="text-xs text-slate-500 tabular">{{ c.phone ?? 'No phone' }} · {{ num(c.points) }} pts</span>
        </span>
        <UiBadge v-if="c.dueBalance" tone="warning"><Wallet class="size-3" />Due {{ money(c.dueBalance) }}</UiBadge>
      </button>
      <p v-if="!loading && !results.length" class="py-6 text-center text-sm text-slate-500">No customer found.</p>
    </div>
    <template #footer>
      <UiButton :icon="UserPlus" @click="newOpen = true">New customer</UiButton>
    </template>
  </UiModal>
  <PosNewCustomerModal v-model="newOpen" @created="created" />
</template>
