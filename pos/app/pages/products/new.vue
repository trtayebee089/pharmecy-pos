<script setup lang="ts">
import type { ProductInput } from '~/types/domain'

definePageMeta({ title: 'Add product' })
const initial: ProductInput = {
  sku: '', name: '', strength: '', genericId: null, manufacturerId: null, dosageFormId: null, categoryId: null,
  requiresPrescription: false, isActive: true, reorderLevelBase: 0, description: '',
  units: [{ code: 'piece', factor: 1, price: 0, wholesalePrice: null, barcode: '', isSaleDefault: true, isPurchaseDefault: true }],
}
</script>

<template>
  <CatalogProductForm :initial="initial" />
</template>
