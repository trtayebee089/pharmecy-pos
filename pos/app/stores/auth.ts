import { defineStore } from 'pinia'

export interface AuthUser {
  id: number
  name: string
  username: string
  role: string
  permissions: string[] // '*' = all (Admin)
  warehouses: { id: number; name: string }[]
}

/**
 * Session lives in the Laravel session cookie; this store only holds the
 * `/me` payload for UI decisions. Hiding UI is not authorization: the API
 * re-checks every permission.
 */
export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    warehouseId: 1,
    loaded: false,
  }),
  getters: {
    isAuthenticated: s => !!s.user,
    can: s => (permission?: string) => {
      if (!permission) return true
      const p = s.user?.permissions ?? []
      return p.includes('*') || p.includes(permission)
    },
    warehouse: s => s.user?.warehouses.find(w => w.id === s.warehouseId) ?? null,
  },
  actions: {
    async fetchMe() {
      const api = useApi('auth')
      try {
        if (api.useMocks) {
          const raw = sessionStorage.getItem('rp-mock-session')
          this.user = raw ? JSON.parse(raw) : null
        }
        else {
          this.user = await api.get<AuthUser>('/me')
        }
      }
      catch { this.user = null }
      this.loaded = true
    },
    async login(username: string, password: string) {
      const api = useApi('auth')
      if (api.useMocks) {
        await mockDelay(600)
        if (!username || password.length < 4) throw new ApiError('These credentials do not match our records.', 422, { username: ['Invalid username or password'] })
        this.user = {
          id: 1, name: 'Fahim Rahman', username, role: 'Admin', permissions: ['*'],
          warehouses: [{ id: 1, name: 'Red Pharma · Dhanmondi' }, { id: 2, name: 'Red Pharma · Uttara' }],
        }
        sessionStorage.setItem('rp-mock-session', JSON.stringify(this.user))
        return
      }
      await api.post('/auth/login', { username, password })
      await this.fetchMe()
    },
    async logout() {
      const api = useApi('auth')
      if (!api.useMocks) await api.post('/auth/logout').catch(() => {})
      this.clear()
      await navigateTo('/login')
    },
    clear() {
      this.user = null
      sessionStorage.removeItem('rp-mock-session')
    },
  },
})
