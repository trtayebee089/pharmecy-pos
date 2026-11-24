<script setup lang="ts">
import { UserPlus, Download, Crown, HandCoins } from 'lucide-vue-next'
import type { Column, Customer } from '~/types/domain'

definePageMeta({ title: 'Customers' })
const api = useCustomersApi()
const newOpen = ref(false)
const { search, page, filters, result, loading, reload } = useList<Customer>(q => api.list(q), { due: false })
const view = computed({ get: () => (filters.value.due ? 'due' : 'all'), set: v => (filters.value.due = v === 'due') })

const columns: Column[] = [
  { key: 'name', label: 'Customer' },
  { key: 'phone', label: 'Phone' },
  { key: 'membership', label: 'Membership' },
  { key: 'points', label: 'Points', align: 'right' },
  { key: 'totalSpent', label: 'Lifetime spend', align: 'right' },
  { key: 'dueBalance', label: 'Due', align: 'right' },
  { key: 'lastVisit', label: 'Last visit' },
]
function exportCsv() { downloadCsv('customers.csv', columns.filter(c => c.key !== 'actions'), result.value?.data ?? []) }
</script>

<template>
  <div>
    <UiPageHeader title="Customers" description="Patients and regular buyers: dues, loyalty points and membership.">
      <UiButton :icon="Download" :disabled="!result?.data.length" @click="exportCsv">Export</UiButton>
      <UiButton variant="primary" :icon="UserPlus" @click="newOpen = true">Add customer</UiButton>
    </UiPageHeader>
    <UiDataTable v-model:search="search" v-model:page="page" :columns="columns" :result="result" :loading="loading" row-key="id" search-placeholder="Name, phone, code…">
      <template #filters>
        <UiSegmented v-model="view" :options="[{ value: 'all', label: 'All' }, { value: 'due', label: 'With due' }]" />
      </template>
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <span class="grid size-9 place-items-center rounded-xl text-xs font-bold" :class="row.isWalkIn ? 'bg-slate-100 text-slate-500' : 'bg-brand-100 text-brand-700'">{{ initials(row.name) }}</span>
          <div><NuxtLink :to="`/customers/${row.id}`" class="font-semibold text-ink hover:text-brand-700 hover:underline">{{ row.name }}</NuxtLink><p class="text-xs text-slate-500">{{ row.code }}<template v-if="row.group"> · {{ row.group }}</template></p></div>
        </div>
      </template>
      <template #cell-phone="{ row }"><span class="text-slate-600 tabular">{{ row.phone ?? '—' }}</span></template>
      <template #cell-membership="{ row }"><UiBadge v-if="row.membership" :tone="row.membership === 'Gold' ? 'warning' : 'neutral'"><Crown class="size-3" />{{ row.membership }}</UiBadge><span v-else class="text-slate-400">—</span></template>
      <template #cell-points="{ row }"><span class="tabular">{{ num(row.points) }}</span></template>
      <template #cell-totalSpent="{ row }"><span class="tabular">{{ money(row.totalSpent) }}</span></template>
      <template #cell-dueBalance="{ row }">
        <NuxtLink v-if="row.dueBalance" to="/customers/due" class="inline-flex items-center gap-1 font-semibold text-amber-600 tabular hover:underline"><HandCoins class="size-3.5" />{{ money(row.dueBalance) }}</NuxtLink>
        <span v-else class="text-slate-400">—</span>
      </template>
      <template #cell-lastVisit="{ row }"><span class="text-slate-500">{{ date(row.lastVisit) }}</span></template>
    </UiDataTable>
    <PosNewCustomerModal v-model="newOpen" @created="reload" />
  </div>
</template>
