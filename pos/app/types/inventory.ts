// Types for catalog masters, inventory operations and purchasing (/api/pos/v1).
import type { BatchStock, ProductUnit } from '~/types/domain'

export interface Manufacturer { id: number; name: string; shortName: string; country: string; products: number; isActive: boolean }
export interface DosageForm { id: number; name: string; products: number; isActive: boolean }
export interface CategoryRow { id: number; name: string; parentId: number | null; path: string; products: number; sortOrder: number; isActive: boolean }

export interface StockRow {
  id: number // product id
  sku: string
  name: string
  strength: string | null
  manufacturer: string
  dosageForm: string
  units: ProductUnit[]
  sellableBase: number
  expiredBase: number
  valueAtCost: number
  reorderLevelBase: number
  status: 'out' | 'low' | 'ok'
  expiringSoon: number // batches expiring within 90 days
  batches: BatchStock[]
}

export type CountStatus = 'open' | 'counting' | 'posted' | 'cancelled'
export interface CountSession {
  id: number
  number: string
  name: string
  warehouse: string
  scope: string
  status: CountStatus
  startedBy: string
  startedAt: string
  postedAt: string | null
  lines: number
  counted: number
  varianceQty: number
  varianceValue: number
}
export interface CountLine {
  key: string
  productId: number
  productName: string
  manufacturer: string
  batchId: number
  batchNo: string
  expiryDate: string
  systemQty: number
  countedQty: number | null
  unitCost: number
}

export type TransferStatus = 'draft' | 'sent' | 'received' | 'void'
export interface TransferLine { productId: number; productName: string; batchId: number; batchNo: string; expiryDate: string; qtyBase: number }
export interface TransferSummary {
  id: number
  number: string
  date: string
  from: string
  to: string
  lines: TransferLine[]
  qty: number
  status: TransferStatus
  createdBy: string
  note: string
}

export interface PurchaseReturnSummary {
  id: number
  number: string
  date: string
  supplier: string
  creditNote: string
  lines: number
  qty: number
  total: number
  reason: string
  status: 'posted' | 'void'
}

export interface ReorderLine {
  key: string
  productId: number
  productName: string
  manufacturer: string
  supplierId: number
  supplierName: string
  soldQty: number // pieces sold in the analysis period
  inStock: number // sellable pieces
  reorderLevel: number
  stripFactor: number
  boxFactor: number
  suggestedBase: number
  unit: 'box' | 'strip' | 'piece'
  finalQty: number // in `unit`
  rationale: string
  source: 'ai' | 'low_stock'
  selected: boolean
}

export interface SupplierRow {
  id: number
  name: string
  companyName: string
  phone: string
  email: string
  address: string
  paymentTermsDays: number
  dueBalance: number
  purchases: number
  lastPurchase: string | null
  isActive: boolean
}

export interface SupplierLedgerEntry {
  id: number
  date: string
  type: 'opening' | 'invoice' | 'payment' | 'return'
  ref: string
  increase: number // amount we owe more (invoice/opening)
  decrease: number // payment or return
  balance: number
}
