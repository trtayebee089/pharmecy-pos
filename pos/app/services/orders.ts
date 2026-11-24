/**
 * Domain API modules for held sales, sale returns, online orders, riders and
 * shipping zones (/api/pos/v1). With useMocks on (development only) they
 * resolve from app/mocks/orders.ts, kept in an in-memory session store.
 */
import type { ListQuery, Paginated } from '~/types/domain'
import type { HeldSaleSummary, OnlineOrder, OrderStatus, Rider, SaleReturnSummary, ShippingZone } from '~/types/orders'

const ordersMock = () => import('~/mocks/orders')

export const ORDER_FLOW: OrderStatus[] = ['pending', 'confirmed', 'packed', 'in_transit', 'delivered']
export const ORDER_STATUS_LABEL: Record<OrderStatus, string> = {
  pending: 'Pending', confirmed: 'Confirmed', packed: 'Packed', in_transit: 'In transit', delivered: 'Delivered', cancelled: 'Cancelled',
}
export const ORDER_STATUS_TONE: Record<OrderStatus, 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'violet'> = {
  pending: 'warning', confirmed: 'info', packed: 'violet', in_transit: 'brand', delivered: 'success', cancelled: 'neutral',
}
export function nextOrderStatus(s: OrderStatus): OrderStatus | null {
  const i = ORDER_FLOW.indexOf(s)
  return i >= 0 && i < ORDER_FLOW.length - 1 ? ORDER_FLOW[i + 1]! : null
}

export function useHeldSalesApi() {
  const api = useApi()
  const store = useState<HeldSaleSummary[] | null>('mock-held-sales', () => null)
  return {
    /** Sales parked on other counters (stored server-side, no stock effect). */
    async list(): Promise<HeldSaleSummary[]> {
      if (!api.useMocks) return api.get('/sales/held')
      await mockDelay(250)
      store.value ??= structuredClone((await ordersMock()).heldSales)
      return store.value
    },
    async discard(id: string): Promise<void> {
      if (!api.useMocks) return api.del(`/sales/held/${id}`)
      await mockDelay(300)
      store.value = (store.value ?? []).filter(h => h.id !== id)
    },
  }
}

export function useSaleReturnsApi() {
  const api = useApi()
  return {
    async list(q: ListQuery): Promise<Paginated<SaleReturnSummary>> {
      if (!api.useMocks) return api.get('/sales/returns', { ...q, ...q.filters })
      await mockDelay()
      let rows = (await ordersMock()).saleReturns
      if (q.filters?.refundMethod) rows = rows.filter(x => x.refundMethod === q.filters!.refundMethod)
      return paginate(rows, q, (x, s) => `${x.number} ${x.saleNumber} ${x.customer} ${x.reason}`.toLowerCase().includes(s))
    },
  }
}

export function useOnlineOrdersApi() {
  const api = useApi()
  const store = useState<OnlineOrder[] | null>('mock-online-orders', () => null)
  async function rows() {
    store.value ??= structuredClone((await ordersMock()).orders)
    return store.value
  }
  return {
    async all(): Promise<OnlineOrder[]> {
      if (!api.useMocks) return api.get('/orders', { all: 1 })
      await mockDelay(300)
      return rows()
    },
    /**
     * Moves an order forward. The API allocates batch stock exactly once (on
     * confirm) and releases it on cancel; the client never changes stock.
     */
    async transition(id: number, to: OrderStatus, note: string, idempotencyKey: string): Promise<OnlineOrder> {
      if (!api.useMocks) return api.post(`/orders/${id}/status`, { status: to, note }, idempotencyKey)
      await mockDelay(450)
      const o = (await rows()).find(x => x.id === id)
      if (!o) throw new ApiError('Order not found', 404)
      if (o.status === 'delivered' || o.status === 'cancelled') throw new ApiError('This order is already closed.', 422)
      o.status = to
      o.history.push({ status: to, at: new Date().toISOString(), by: useAuthStore().user?.name ?? 'You', note: note || undefined })
      if (to === 'confirmed') o.lines.forEach((l, i) => (l.batchNo ??= `FEFO-${o.id}-${i + 1}`))
      if (to === 'cancelled') o.lines.forEach(l => (l.batchNo = null))
      return o
    },
    async assignRider(id: number, rider: Rider, idempotencyKey: string): Promise<OnlineOrder> {
      if (!api.useMocks) return api.post(`/orders/${id}/rider`, { rider_id: rider.id }, idempotencyKey)
      await mockDelay(350)
      const o = (await rows()).find(x => x.id === id)!
      o.riderId = rider.id
      o.riderName = rider.name
      return o
    },
  }
}

export function useRidersResource() {
  return useResource<Rider>('delivery/riders', async () => (await ordersMock()).riders)
}

export function useZonesResource() {
  return useResource<ShippingZone>('delivery/zones', async () => (await ordersMock()).zones)
}
