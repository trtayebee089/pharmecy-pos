import { defineStore } from 'pinia'
import { orderService } from '../services/orderService'

export const useOrderStore = defineStore('orders', {
  state: () => ({
    orders: [],
    loading: false,
    error: '',
  }),
  actions: {
    async fetch() {
      this.loading = true
      this.error = ''
      try {
        const result = await orderService.list()
        this.orders = result.data
        return result
      } catch (e) {
        this.error = e.message || 'Unable to load orders.'
        throw e
      } finally {
        this.loading = false
      }
    },
  },
})
