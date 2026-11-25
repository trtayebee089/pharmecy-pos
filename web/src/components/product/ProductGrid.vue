<template>
  <div
    v-if="loading"
    :class="gridClass"
  >
    <LoadingSkeleton v-for="item in skeletons" :key="item" />
  </div>
  <EmptyState
    v-else-if="!products.length"
    title="No products found"
    message="Try another category or search term."
  />
  <div
    v-else
    :class="gridClass"
  >
    <ProductCard v-for="product in products" :key="product.id" :product="product" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ProductCard from './ProductCard.vue'
import LoadingSkeleton from '../common/LoadingSkeleton.vue'
import EmptyState from '../common/EmptyState.vue'

const props = defineProps({
  products: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  skeletons: { type: Number, default: 10 },
  /** 'dense' = up to 6 per row (home shelves); 4 = at most 4 (category pages with the filter column). */
  columns: { type: [String, Number], default: 'dense' },
})

const gridClass = computed(() =>
  String(props.columns) === '4'
    ? 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:gap-5'
    : 'grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:gap-4 2xl:grid-cols-6',
)
</script>
