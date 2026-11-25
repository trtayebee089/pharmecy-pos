import type { Component } from 'vue'
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
  /** The group's automatic line discount (0 for walk-in / no group). The larger of this and a manual line discount applies. */
  groupDiscountPct?: number
  /** Active membership plan discount; combined with the group % per the reward settings mode. */
  membershipDiscountPct?: number
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
  status: 'draft' | 'closed' | 'received' | 'void'
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
  method: 'cash' | 'card' | 'bkash' | 'nagad' | 'rocket' | 'points' | 'customer_credit'
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

// ---- Catalog write model ----------------------------------------------------
export interface ProductInput {
  id?: number
  sku: string
  name: string
  strength: string
  genericId: number | null
  manufacturerId: number | null
  dosageFormId: number | null
  categoryId: number | null
  requiresPrescription: boolean
  isActive: boolean
  reorderLevelBase: number
  description: string
  units: { id?: number; code: UnitCode; factor: number; price: number; wholesalePrice: number | null; barcode: string; isSaleDefault: boolean; isPurchaseDefault: boolean }[]
}

export interface Lookup { id: number; name: string }

export interface CatalogLookups {
  generics: Lookup[]
  manufacturers: Lookup[]
  dosageForms: Lookup[]
  categories: Lookup[]
}

// ---- Purchasing --------------------------------------------------------------
export interface Supplier { id: number; name: string; phone: string; dueBalance: number }

export interface PurchaseLineInput {
  key: string
  product: Product
  unitId: number
  qty: number
  freeQty: number
  batchNo: string
  expiryDate: string
  unitCost: number
  discountPct: number
  updateRetailPrice: number | null
}

// ---- Sales detail & returns ------------------------------------------------
export interface SaleLineDetail {
  id: number
  productName: string
  unitLabel: string
  qty: number
  qtyBase: number
  unitPrice: number
  discount: number
  lineTotal: number
  batches: { saleItemBatchId: number; batchNo: string; expiryDate: string; qtyBase: number; returnedQtyBase: number }[]
}

export interface SaleDetail extends SaleSummary {
  warehouse: string
  customerPhone: string | null
  subtotal: number
  discountTotal: number
  lines: SaleLineDetail[]
  payments: { method: string; amount: number; reference?: string; paidAt: string }[]
  returns: { number: string; date: string; total: number }[]
}

// ---- Cash register ------------------------------------------------------------
export interface RegisterSession {
  id: number
  register: string
  status: 'open' | 'closed'
  openedBy: string
  openedAt: string
  openingFloat: number
  cashSales: number
  cashRefunds: number
  cashIn: number
  cashOut: number
  expectedCash: number
  countedCash: number | null
  difference: number | null
  closedAt: string | null
  nonCash: { method: string; amount: number }[]
}

// ---- Inventory adjustments -------------------------------------------------
export interface AdjustmentSummary {
  id: number
  number: string
  date: string
  reason: 'damage' | 'expiry' | 'correction' | 'lost' | 'found'
  lines: number
  qtyIn: number
  qtyOut: number
  value: number
  status: 'draft' | 'posted' | 'void'
  createdBy: string
}

// ---- Administration --------------------------------------------------------
export interface StaffUser {
  id: number
  name: string
  username: string
  email: string
  role: string
  warehouses: string[]
  isActive: boolean
  lastLoginAt: string | null
  mustChangePassword: boolean
}

export interface Role { id: number; name: string; description: string; users: number; permissions: string[]; maxDiscountPct: number; system?: boolean }

export interface PermissionGroup { module: string; permissions: { key: string; label: string }[] }

export interface PosSettings {
  nearExpiryDays: number
  criticalExpiryDays: number
  allowNegativeStock: boolean
  blockExpiredSale: boolean
  roundTo: 0 | 0.5 | 1
  receiptWidth: '58mm' | '80mm' | 'A4'
  receiptFooter: string
  printOnComplete: boolean
  rxReminder: boolean
  defaultCustomerId: number
  pointsPerTaka: number
  pointValue: number
}

// ---- Generic page templates ---------------------------------------------------
export type CellFormat = 'text' | 'money' | 'number' | 'date' | 'datetime' | 'percent' | 'boolean' | 'badge' | 'mono'

export interface CrudColumn extends Column {
  format?: CellFormat
  /** For format 'badge': value → tone */
  tones?: Record<string, 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'violet'>
}

export interface FieldDef {
  key: string
  label: string
  type: 'text' | 'number' | 'money' | 'textarea' | 'select' | 'switch' | 'date' | 'email' | 'tel' | 'color'
  options?: { value: string | number; label: string }[]
  required?: boolean
  hint?: string
  placeholder?: string
  span?: 1 | 2
}

export interface ReportResult {
  kpis: { label: string; value: number; format?: 'money' | 'number' | 'percent'; hint?: string }[]
  series?: { label: string; value: number }[]
  seriesTitle?: string
  columns: CrudColumn[]
  rows: Record<string, any>[]
  totals?: Record<string, number>
}

export interface DateRange { from: string; to: string }

// ---- Row actions -----------------------------------------------------------------
export interface RowAction {
  label: string
  icon?: Component
  /** Route to open, or a handler */
  to?: string
  onClick?: () => void
  danger?: boolean
  disabled?: boolean
  hidden?: boolean
}
