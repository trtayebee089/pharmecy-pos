import api, { unwrap } from './api'

function normalizeAuth(payload) {
  const data = payload?.data || payload
  return { token: data?.token || payload?.token || '', user: data?.user || payload?.user || null }
}

export const authService = {
  /** { identifier: phone or email, password, device_name } */
  async login(credentials) {
    return normalizeAuth((await api.post('/v1/customer/login', credentials)).data)
  },
  async register(payload) {
    return normalizeAuth((await api.post('/v1/customer/register', payload)).data)
  },
  /** Sends a 6-digit code to the account's email (same answer whether or not the account exists). */
  async forgotPassword(payload) {
    return unwrap(await api.post('/v1/customer/forgot-password', payload))
  },
  async resetPassword(payload) {
    return unwrap(await api.post('/v1/customer/reset-password', payload))
  },
  async profile() {
    return unwrap(await api.get('/v1/customer/profile'))
  },
  async updateProfile(payload) {
    return unwrap(await api.put('/v1/customer/profile', payload))
  },
  async changePassword(payload) {
    return unwrap(await api.put('/v1/customer/password', payload))
  },
  async requestRemoval(reason) {
    return unwrap(await api.post('/v1/customer/account-removal-request', { reason }))
  },
  async logout() {
    try {
      // After a 401 the token is already gone: calling the API again would only loop.
      if (localStorage.getItem('redpharma_token')) await api.post('/v1/customer/logout')
    } finally {
      localStorage.removeItem('redpharma_token')
      localStorage.removeItem('redpharma_user')
    }
  },
}
