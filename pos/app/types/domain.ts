// Types mirror the planned /api/pos/v1 contract (docs/rebuild/database-design.md).

export type UnitCode = 'piece' | 'strip' | 'box' | 'bottle' | 'tube' | 'vial'

export interface ProductUnit {
  id: number
  code: UnitCode
  label: string
  factor: number // pieces per unit
  price: number // retail price for this unit (BDT)
  isSaleDefault?: boolean
}

export interface BatchStock {
  batchId: number
  batchNo: string
  expiryDate: string // ISO date
  qtyBase: number
  unitCost: number
}

export interface Product {
  id: number
  sku: string
  name: string
  strength: string | null
  generic: string | null
  manufacturer: string
  dosageForm: string
  category: string
  requiresPrescription: boolean
  isActive: boolean
  reorderLevelBase: number
  units: ProductUnit[]
  batches: BatchStock[] // FEFO order (earliest expiry first)
  stockBase: number // sellable (non-expired) stock in pieces
}

export interface Customer {
  id: number
  code: string
  name: string
  phone: string | null
  group: string | null
  dueBalance: number
  points: number
  membership: string | null
  isWalkIn?: boolean
  totalSpent: number
  lastVisit: string | null
}

export type SaleStatus = 'held' | 'completed' | 'void'
export type PaymentStatus = 'unpaid' | 'partial' | 'paid'
export type Channel = 'pos' | 'manual' | 'website' | 'app'

export interface SaleSummary {
  id: number
  number: string
  date: string
  customer: string
  channel: Channel
  items: number
  grandTotal: number
  paidTotal: number
  dueTotal: number
  status: SaleStatus
  paymentStatus: PaymentStatus
  cashier: string
}

export interface PurchaseSummary {
  id: number
  number: string
  date: string
  supplier: string
  supplierInvoiceNo: string
  items: number
  grandTotal: number
  paidTotal: number
  dueTotal: number
  status: 'draft' | 'received' | 'void'
}

export interface BatchRow {
  batchId: number
  batchNo: string
  productId: number
  productName: string
  manufacturer: string
  expiryDate: string
  qtyBase: number
  value: number
  status: 'active' | 'quarantined' | 'recalled'
}

export interface DashboardSummary {
  todaySales: number
  todaySalesDelta: number
  todayInvoices: number
  todayProfit: number
  todayProfitDelta: number
  cashInHand: number
  customerDue: number
  supplierDue: number
  lowStockCount: number
  nearExpiryCount: number
  expiredCount: number
  salesSeries: { label: string; value: number }[]
  paymentMix: { method: string; value: number }[]
  topProducts: { name: string; qty: number; revenue: number }[]
}

export interface PaymentLine {
  method: 'cash' | 'card' | 'bkash' | 'nagad' | 'rocket' | 'customer_credit'
  amount: number
  reference?: string
}

export interface Paginated<T> {
  data: T[]
  meta: { page: number; perPage: number; total: number; lastPage: number }
}

export interface ListQuery {
  page?: number
  perPage?: number
  search?: string
  sort?: string
  filters?: Record<string, string | number | boolean | undefined>
}

export interface Column {
  key: string
  label: string
  align?: 'left' | 'right' | 'center'
  width?: string
  class?: string
}
