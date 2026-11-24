<script setup lang="ts">
import { pageTransition } from '~/composables/useMotion'
</script>

<template>
  <NuxtLayout>
    <NuxtPage :transition="pageTransition" />
  </NuxtLayout>
  <UiToaster />
  <UiConfirmHost />
</template>
