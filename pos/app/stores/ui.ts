import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', () => {
  const collapsed = useLocalStorage('rp-sidebar-collapsed', false)
  const mobileNav = ref(false)
  const commandOpen = ref(false)
  // Sidebar badges, refreshed from the dashboard summary endpoint
  const badges = ref<Record<string, number>>({ nearExpiry: 0, lowStock: 0, onlineOrders: 0 })
  return { collapsed, mobileNav, commandOpen, badges }
})
