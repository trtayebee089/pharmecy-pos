import { flatNav } from '~/config/navigation'

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()

  if (to.path === '/login') {
    return auth.isAuthenticated ? navigateTo('/dashboard') : undefined
  }
  if (!auth.isAuthenticated) return navigateTo({ path: '/login', query: { redirect: to.fullPath } })

  // UX-level permission check (the API enforces the same permission).
  const item = flatNav().find(n => n.to === to.path)
  if (item && !auth.can(item.permission)) {
    useToast().error('You do not have permission to open that page.')
    return navigateTo('/dashboard')
  }
})
