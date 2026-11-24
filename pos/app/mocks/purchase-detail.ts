/** DEVELOPMENT-ONLY MOCK DATA for purchase detail. Loaded only when useMocks is true. */
import { products, purchases } from './seed'

export interface PurchaseDetail {
  id: number
  number: string
  status: 'draft' | 'closed' | 'received' | 'void'
  supplierId?: number
  supplier: string
  orderNumber?: string | null
  supplierInvoiceNo: string
  receivedAt: string
  warehouse: string
  receivedBy: string
  lines: { id: number; productId?: number; unitId?: number; product: string; unitLabel: string; qty: number; freeQty: number; pieces: number; batchNo: string; expiryDate: string; unitCost: number; discountPct: number; lineTotal: number }[]
  subtotal: number
  otherCharges: number
  grandTotal: number
  paidTotal: number
  dueTotal: number
  /** Credit from purchase returns that reduced this invoice (due = total − paid − returned). */
  returnedTotal?: number
  payments: { number: string; date: string; method: string; amount: number; reference?: string }[]
  returns: { number: string; date: string; total: number }[]
}

export function purchaseDetail(id: number): PurchaseDetail | null {
  const p = purchases.find(x => x.id === id)
  if (!p) return null
  const n = Math.min(8, Math.max(3, p.items % 9))
  let seq = id * 100
  const lines = products.filter((_, i) => (i * 5 + id) % products.length < n).slice(0, n).map((pr) => {
    const unit = [...pr.units].sort((a, b) => b.factor - a.factor)[0]!
    const qty = 1 + ((id + pr.id) % 4)
    const b = pr.batches[pr.batches.length - 1]!
    const unitCost = Math.round(b.unitCost * unit.factor * 100) / 100
    return {
      id: ++seq, product: `${pr.name} ${pr.strength ?? ''}`.trim(), unitLabel: unit.label, qty, freeQty: pr.id % 5 === 0 ? 1 : 0,
      pieces: (qty + (pr.id % 5 === 0 ? 1 : 0)) * unit.factor, batchNo: b.batchNo, expiryDate: b.expiryDate,
      unitCost, discountPct: pr.id % 3 === 0 ? 2 : 0, lineTotal: Math.round(qty * unitCost * (pr.id % 3 === 0 ? 0.98 : 1) * 100) / 100,
    }
  })
  const subtotal = Math.round(lines.reduce((s, l) => s + l.lineTotal, 0) * 100) / 100
  const otherCharges = id % 4 === 0 ? 150 : 0
  const grandTotal = subtotal + otherCharges
  const paidRatio = p.dueTotal === 0 ? 1 : p.paidTotal / p.grandTotal
  const paidTotal = Math.round(grandTotal * paidRatio * 100) / 100
  return {
    id: p.id, number: p.number, status: p.status, supplier: p.supplier, supplierInvoiceNo: p.supplierInvoiceNo, receivedAt: p.date,
    warehouse: 'Red Pharma · Dhanmondi', receivedBy: 'Rakib Uddin', lines, subtotal, otherCharges, grandTotal,
    paidTotal, dueTotal: Math.round((grandTotal - paidTotal) * 100) / 100,
    payments: paidTotal > 0 ? [{ number: `PAY-${p.id}`, date: p.date, method: 'Bank', amount: paidTotal, reference: `TRX${p.id * 7}` }] : [],
    returns: [],
  }
}
