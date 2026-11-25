import api, { normalizeList, unwrap } from './api'

export const rewardService = {
  /** { wallet, settings, membership, campaigns } */
  async profile() { return unwrap(await api.get('/v1/customer/rewards')) },
  async transactions(params = {}) { return normalizeList((await api.get('/v1/customer/rewards/transactions', { params })).data) },
  /** Checks the rules only; points are spent when the order is placed. */
  async redeem(points, orderTotal) { return unwrap(await api.post('/v1/customer/rewards/quote', { points, order_total: orderTotal })) },
}
