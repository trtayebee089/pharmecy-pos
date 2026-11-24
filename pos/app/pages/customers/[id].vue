<script setup lang="ts">
import { ArrowLeft, HandCoins, ScanBarcode } from 'lucide-vue-next'
import type { CustomerProfile } from '~/types/people'

definePageMeta({ title: 'Customer' })
const route = useRoute()
const api = usePeopleApi()
const profile = ref<CustomerProfile | null>(null)
const loading = ref(true)
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  try { profile.value = await api.profile(Number(route.params.id)) }
  catch (e: any) { useToast().error('Could not load customer', e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-stagger]') ?? [], { stagger: 0.06 })
})
</script>

<template>
  <div ref="root" class="mx-auto max-w-5xl">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <NuxtLink to="/customers"><UiButton variant="ghost" :icon="ArrowLeft">All customers</UiButton></NuxtLink>
      <div v-if="profile" class="flex gap-2">
        <NuxtLink v-if="profile.dueBalance" to="/customers/due"><UiButton :icon="HandCoins">Collect due</UiButton></NuxtLink>
        <NuxtLink to="/pos"><UiButton variant="primary" :icon="ScanBarcode">New sale</UiButton></NuxtLink>
      </div>
    </div>
    <div v-if="loading" class="card space-y-3 p-6"><div class="skeleton h-16" /><div class="skeleton h-20" /><div class="skeleton h-72" /></div>
    <div v-else-if="profile" class="card p-6"><PeopleCustomerProfileView :profile="profile" /></div>
    <UiEmptyState v-else title="Customer not found" description="It may have been removed." />
  </div>
</template>
