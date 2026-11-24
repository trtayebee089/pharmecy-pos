<script setup lang="ts">
import { ExternalLink, HandCoins } from 'lucide-vue-next'
import type { CustomerProfile } from '~/types/people'

/** Slide-in customer profile. Usage: <PeopleCustomerDrawer v-model="open" :customer-id="id" /> */
const open = defineModel<boolean>({ default: false })
const props = defineProps<{ customerId: number | null }>()
const api = usePeopleApi()
const profile = ref<CustomerProfile | null>(null)
const loading = ref(false)

watch(() => [open.value, props.customerId] as const, async ([o, id]) => {
  if (!o || !id) return
  loading.value = true
  profile.value = null
  try { profile.value = await api.profile(id) }
  catch (e: any) { useToast().error('Could not load customer', e?.message) }
  finally { loading.value = false }
}, { immediate: true })
</script>

<template>
  <UiDrawer v-model="open" :title="profile?.name ?? 'Customer'" description="Profile, purchases, dues and points" width="xl">
    <div v-if="loading" class="space-y-3"><div class="skeleton h-16" /><div class="skeleton h-20" /><div class="skeleton h-64" /></div>
    <PeopleCustomerProfileView v-else-if="profile" :profile="profile" />
    <UiEmptyState v-else title="Customer not found" />
    <template v-if="profile" #footer>
      <NuxtLink v-if="profile.dueBalance" to="/customers/due" @click="open = false"><UiButton :icon="HandCoins">Collect due</UiButton></NuxtLink>
      <NuxtLink :to="`/customers/${profile.id}`" @click="open = false"><UiButton variant="primary" :icon-right="ExternalLink">Open full profile</UiButton></NuxtLink>
    </template>
  </UiDrawer>
</template>
