<template>
  <InfoShell title="Terms & conditions">
    <p>Use of {{ site.brand }} services is subject to availability, prescription requirements, delivery coverage, and successful order verification.</p>
  </InfoShell>
</template>

<script setup>
import { useSiteStore } from '../../stores/siteStore'
import InfoShell from './InfoShell.vue'

const site = useSiteStore()
</script>
