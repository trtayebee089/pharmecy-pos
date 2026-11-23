<script setup lang="ts">
const ui = useUiStore()
const auth = useAuthStore()
const dashboardApi = useDashboardApi()

// Sidebar badges (near-expiry, low stock) from the dashboard summary
onMounted(async () => {
  try {
    const s = await dashboardApi.summary(auth.warehouseId)
    ui.badges = { ...ui.badges, nearExpiry: s.nearExpiryCount, lowStock: s.lowStockCount }
  }
  catch { /* badges are optional */ }
})
</script>

<template>
  <div class="flex min-h-dvh">
    <LayoutAppSidebar />
    <div class="flex min-w-0 flex-1 flex-col">
      <LayoutAppTopbar />
      <main class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <slot />
      </main>
    </div>
    <LayoutCommandPalette />
  </div>
</template>
