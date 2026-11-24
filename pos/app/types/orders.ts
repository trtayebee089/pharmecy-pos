// Types for held sales, sale returns, online orders and delivery (/api/pos/v1).

export interface HeldSaleSummary {
  id: string
  heldAt: string
  heldBy: string
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
  channel: 'website' | 'app'
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
  riderId: number | null
  riderName: string | null
  lines: OrderLine[]
  history: OrderHistory[]
}

export interface Rider { id: number; name: string; phone: string; nid: string; status: 'active' | 'on_leave' | 'inactive'; assignedOrders: number }

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
