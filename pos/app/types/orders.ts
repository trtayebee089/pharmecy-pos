// Types for held sales, sale returns, online orders and delivery (/api/pos/v1).

export interface HeldSaleSummary {
  id: string
  heldAt: string
  heldBy: string
  /** Live API only: who parked it (to split "yours" from other counters). */
  heldById?: number
  register: string
  customer: string | null
  items: number
  total: number
  source: 'this_counter' | 'server'
}

export interface SaleReturnLine {
  productName: string
  batchNo: string
  expiryDate: string
  qtyBase: number
  unitPrice: number
  lineTotal: number
  restock: boolean
}

export interface SaleReturnSummary {
  id: number
  number: string
  date: string
  saleId: number
  saleNumber: string
  customer: string
  items: number
  refundMethod: 'cash' | 'credit' | 'bkash'
  total: number
  restockedQty: number
  writtenOffQty: number
  reason: string
  createdBy: string
  lines: SaleReturnLine[]
}

export type OrderStatus = 'pending' | 'confirmed' | 'packed' | 'in_transit' | 'delivered' | 'cancelled'

export interface OrderLine { productName: string; qty: number; unitLabel: string; unitPrice: number; lineTotal: number; batchNo: string | null }

export interface OrderHistory { status: OrderStatus; at: string; by: string; note?: string }

export interface OnlineOrder {
  id: number
  number: string
  legacyRef: string
  trackingCode: string
  channel: 'website' | 'app' | 'phone'
  /** Live API: the sale posted at confirm, customer and zone ids. */
  saleId?: number | null
  customerId?: number
  zoneId?: number | null
  grandTotal?: number
  placedAt: string
  status: OrderStatus
  customer: string
  phone: string
  address: string
  division: string
  district: string
  thana: string
  zone: string
  items: number
  subtotal: number
  shipping: number
  codAmount: number
  /** Live API: reward points used, and online payment (SSLCommerz / bKash). */
  pointsValue?: number
  paymentMethod?: 'cod' | 'sslcommerz' | 'bkash'
  paymentStatus?: 'cod' | 'pending' | 'paid' | 'refund_due'
  paidOnline?: number
  /** Live API: parcel booked with Steadfast / Pathao. */
  courier?: { name: 'steadfast' | 'pathao'; consignmentId: string; trackingCode: string | null; status: string | null; statusAt: string | null; fee: number | null; trackingUrl: string | null } | null
  /** Pathao city / zone / area remembered for this order's delivery zone. */
  pathaoPlace?: { cityId: number; zoneId: number; areaId: number | null } | null
  riderId: number | null
  riderName: string | null
  lines: OrderLine[]
  history: OrderHistory[]
}

export interface Rider { id: number; name: string; phone: string; nid: string; status: 'active' | 'on_leave' | 'inactive'; assignedOrders: number; /** COD cash collected and not yet handed over (live API). */ cashHeld?: number }

export interface NewOrderInput {
  customerId?: number | null
  name: string
  phone: string
  address: string
  zoneId: number
  note: string
  lines: { productId: number; unitId: number; qty: number }[]
}

export interface ShippingZone {
  id: number
  division: string
  district: string
  thana: string
  rate: number
  freeShippingMin: number | null
  partner: 'own_rider' | 'pathao' | 'steadfast' | 'redx'
  isActive: boolean
}
