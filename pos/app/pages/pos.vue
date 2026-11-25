<script setup lang="ts">
/**
 * POS counter. The screen layout is chosen by an administrator in
 * Administration › POS settings › Counter: "grid" (product cards) or
 * "classic" (table-based, full-screen, like the legacy POS).
 */
definePageMeta({
  title: 'POS Counter',
  middleware: [async (to) => {
    const layout = useState<'grid' | 'classic'>('pos-layout', () => 'grid')
    // ?layout=grid|classic previews a layout without changing the saved setting (used by POS settings › Preview)
    const preview = to.query.layout
    if (preview === 'grid' || preview === 'classic') layout.value = preview
    else {
      try { layout.value = (await useSettingsApi().pos()).layout ?? 'grid' }
      catch { layout.value = 'grid' }
    }
    setPageLayout(layout.value === 'classic' ? 'bare' : 'default')
  }],
})
const layout = useState<'grid' | 'classic'>('pos-layout', () => 'grid')
// Reward rules drive the membership discount preview and points at checkout; refreshed each time the counter opens.
onMounted(() => useCartStore().loadRewardRules(true))
</script>

<template>
  <PosClassicLayout v-if="layout === 'classic'" />
  <PosGridLayout v-else />
</template>
