// Purchase orders by company (replaces legacy "Product Order Report",
// report/product_order_quantity_alert + draft lists + create purchase).

export interface OrderForecast {
  forecast7: number // forecast pieces sold in the next 7 days
  recommended: number // recommended order (pieces)
  confidence: 'high' | 'medium' | 'low'
  reason: string
}

export interface OrderRow {
  productId: number
  code: string
  name: string
  strength: string | null
  category: string
  soldQty: number // pieces sold in the selected period
  inStock: number // sellable pieces now
  piecesPerStrip: number
  stripsPerBox: number
  boxQty: number
  stripQty: number
  orderQty: number // pieces
  unitCost: number // last cost per piece
  forecast?: OrderForecast | null
}

export interface Company { id: number; name: string; products: number }

export interface DraftSummary {
  id: number
  number: string
  companyId: number
  company: string
  date: string
  lines: number
  orderQty: number
  estValue: number
  source: 'manual' | 'ai'
  status: 'draft' | 'converted'
  purchaseNumber?: string
  createdBy: string
}

export interface DraftDetail extends DraftSummary { rows: OrderRow[] }

export interface AiRun { id: number; total: number; processed: number; created: number; status: 'running' | 'done' }
