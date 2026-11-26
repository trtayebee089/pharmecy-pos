import { flatNav } from '~/config/navigation'

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()

  // Static hosting may add a trailing slash (/login -> /login/): compare without it.
  const path = to.path.length > 1 ? to.path.replace(/\/+$/, '') : to.path

  if (path === '/login') {
    return auth.isAuthenticated ? navigateTo('/dashboard') : undefined
  }
  if (!auth.isAuthenticated) return navigateTo({ path: '/login', query: { redirect: to.fullPath } })

  // A temporary password set by an admin must be changed first (the API enforces the same).
  if (auth.user?.mustChangePassword && path !== '/account/password') return navigateTo('/account/password')

  // UX-level permission check (the API enforces the same permission).
  const item = flatNav().find(n => n.to === path)
  if (item && !auth.can(item.permission)) {
    useToast().error('You do not have permission to open that page.')
    return navigateTo('/dashboard')
  }
})
