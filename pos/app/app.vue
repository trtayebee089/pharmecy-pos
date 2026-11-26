<script setup lang="ts">
import { pageTransition } from '~/composables/useMotion'

const { brand } = useBrand()
useHead({ title: () => `${brand.value} POS` })
</script>

<template>
  <NuxtLayout>
    <NuxtPage :transition="pageTransition" />
  </NuxtLayout>
  <UiToaster />
  <UiConfirmHost />
</template>
