<script setup lang="ts">
import type { CatalogLookups, ProductInput } from '~/types/domain'

definePageMeta({ title: 'Edit product' })
const route = useRoute()
const catalog = useCatalogApi()
const initial = ref<ProductInput | null>(null)
const missing = ref(false)

onMounted(async () => {
  const [p, l] = await Promise.all([catalog.product(Number(route.params.id)), catalog.lookups()]) as [Awaited<ReturnType<typeof catalog.product>>, CatalogLookups]
  if (!p) { missing.value = true; return }
  const idOf = (xs: { id: number; name: string }[], name: string | null) => xs.find(x => x.name === name)?.id ?? null
  initial.value = {
    id: p.id, sku: p.sku, name: p.name, strength: p.strength ?? '',
    genericId: idOf(l.generics, p.generic), manufacturerId: idOf(l.manufacturers, p.manufacturer),
    dosageFormId: idOf(l.dosageForms, p.dosageForm), categoryId: idOf(l.categories, p.category),
    requiresPrescription: p.requiresPrescription, isActive: p.isActive, reorderLevelBase: p.reorderLevelBase, description: '',
    units: p.units.map(u => ({ id: u.id, code: u.code, factor: u.factor, price: u.price, wholesalePrice: null, barcode: u.factor === 1 ? p.sku : '', isSaleDefault: !!u.isSaleDefault || p.units.length === 1, isPurchaseDefault: u.factor === Math.max(...p.units.map(x => x.factor)) })),
  }
})
</script>

<template>
  <CatalogProductForm v-if="initial" :initial="initial" />
  <UiEmptyState v-else-if="missing" title="Product not found" description="It may have been removed." />
  <div v-else class="mx-auto max-w-6xl space-y-4"><div class="skeleton h-10 w-72" /><div class="skeleton h-80" /><div class="skeleton h-56" /></div>
</template>
