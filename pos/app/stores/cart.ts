import { defineStore } from 'pinia'
import type { Customer, PaymentLine, Product } from '~/types/domain'

export interface CartLine {
  key: string
  product: Product
  unitId: number
  qty: number
  discountPct: number
  batchId?: number // cashier override (non-expired only)
  /** Free-text item not in the catalogue (legacy "custom product"); no stock, no batch. */
  custom?: { name: string; code: string }
}

/** Snapshot of the last completed sale, for "Print last receipt". */
export interface ReceiptSnapshot {
  number: string
  at: string
  customer: string
  lines: { name: string; qty: number; unitLabel: string; unitPrice: number; total: number; batch?: string }[]
  subtotal: number
  discount: number
  total: number
  payments: PaymentLine[]
  change: number
}

export interface Allocation { batchId: number; batchNo: string; expiryDate: string; qtyBase: number }

/**
 * PREVIEW ONLY. Prices, FEFO allocation, discount limits and totals shown here
 * help the cashier; the API recomputes all of them authoritatively when the
 * sale is completed (docs/rebuild/rebuild-architecture.md §6).
 */
export const useCartStore = defineStore('cart', {
  state: () => ({
    lines: [] as CartLine[],
    customer: null as Customer | null,
    orderDiscount: 0, // amount in BDT
    held: [] as { id: string; at: string; lines: CartLine[]; customer: Customer | null; orderDiscount: number }[],
    lastReceipt: null as ReceiptSnapshot | null,
  }),
  getters: {
    unitOf: () => (l: CartLine) => l.product.units.find(u => u.id === l.unitId) ?? l.product.units[0]!,
    qtyBase(): (l: CartLine) => number {
      return l => l.qty * this.unitOf(l).factor
    },
    /** FEFO across sellable batches, or the override batch first. */
    allocation(): (l: CartLine) => { parts: Allocation[]; short: number } {
      return (l) => {
        if (l.custom) return { parts: [], short: 0 }
        const today = new Date().toISOString().slice(0, 10)
        let need = this.qtyBase(l)
        const sellable = l.product.batches.filter(b => b.expiryDate >= today)
        const ordered = l.batchId ? [...sellable.filter(b => b.batchId === l.batchId), ...sellable.filter(b => b.batchId !== l.batchId)] : sellable
        const parts: Allocation[] = []
        for (const b of ordered) {
          if (need <= 0) break
          const take = Math.min(b.qtyBase, need)
          parts.push({ batchId: b.batchId, batchNo: b.batchNo, expiryDate: b.expiryDate, qtyBase: take })
          need -= take
        }
        return { parts, short: Math.max(0, need) }
      }
    },
    lineGross(): (l: CartLine) => number {
      return l => Math.round(l.qty * this.unitOf(l).price * 100) / 100
    },
    lineTotal(): (l: CartLine) => number {
      return l => Math.round(this.lineGross(l) * (1 - l.discountPct / 100) * 100) / 100
    },
    subtotal(): number { return this.lines.reduce((s, l) => s + this.lineGross(l), 0) },
    lineDiscounts(): number { return this.lines.reduce((s, l) => s + (this.lineGross(l) - this.lineTotal(l)), 0) },
    total(): number { return Math.max(0, Math.round((this.subtotal - this.lineDiscounts - this.orderDiscount) * 100) / 100) },
    itemCount: s => s.lines.length,
    hasShortage(): boolean { return this.lines.some(l => this.allocation(l).short > 0) },
  },
  actions: {
    add(product: Product, unitId?: number): 'added' | 'merged' | 'no-stock' {
      if (product.stockBase <= 0) return 'no-stock'
      const unit = unitId ?? (product.units.find(u => u.isSaleDefault) ?? product.units[0]!).id
      const existing = this.lines.find(l => l.product.id === product.id && l.unitId === unit)
      if (existing) { existing.qty += 1; return 'merged' }
      this.lines.unshift({ key: `${product.id}-${unit}-${Date.now()}`, product, unitId: unit, qty: 1, discountPct: 0 })
      return 'added'
    },
    /** Adds a free-text item at a typed price (no stock movement; needs the pos.custom_item permission). */
    addCustom(name: string, code: string, price: number, qty: number) {
      const id = -Date.now()
      const product: Product = {
        id, sku: code || 'CUSTOM', name, strength: null, generic: null, manufacturer: 'Custom item', dosageForm: 'Custom', category: 'Custom',
        requiresPrescription: false, isActive: true, reorderLevelBase: 0,
        units: [{ id, code: 'piece', label: 'Piece', factor: 1, price, isSaleDefault: true }], batches: [], stockBase: Number.MAX_SAFE_INTEGER,
      }
      this.lines.unshift({ key: `custom-${-id}`, product, unitId: id, qty, discountPct: 0, custom: { name, code } })
    },
    snapshot(number: string, payments: PaymentLine[], change: number): ReceiptSnapshot {
      return {
        number, at: new Date().toISOString(), customer: this.customer?.name ?? 'Walk-in customer',
        lines: this.lines.map(l => ({
          name: `${l.product.name}${l.product.strength ? ` ${l.product.strength}` : ''}`, qty: l.qty, unitLabel: this.unitOf(l).label,
          unitPrice: this.unitOf(l).price, total: this.lineTotal(l), batch: this.allocation(l).parts.map(p => p.batchNo).join('+') || undefined,
        })),
        subtotal: this.subtotal, discount: this.lineDiscounts + this.orderDiscount, total: this.total, payments: structuredClone(toRaw(payments)), change,
      }
    },
    remove(key: string) { this.lines = this.lines.filter(l => l.key !== key) },
    clear() { this.lines = []; this.customer = null; this.orderDiscount = 0 },
    hold() {
      if (!this.lines.length) return
      this.held.unshift({ id: `H${Date.now().toString().slice(-5)}`, at: new Date().toISOString(), lines: this.lines, customer: this.customer, orderDiscount: this.orderDiscount })
      this.lines = []; this.customer = null; this.orderDiscount = 0
    },
    resume(id: string) {
      const h = this.held.find(x => x.id === id)
      if (!h) return
      if (this.lines.length) this.hold()
      this.lines = h.lines; this.customer = h.customer; this.orderDiscount = h.orderDiscount
      this.held = this.held.filter(x => x.id !== id)
    },
    payload(warehouseId: number, payments: PaymentLine[]) {
      return {
        warehouseId,
        customerId: this.customer?.id ?? 1,
        lines: this.lines.map(l => l.custom
          ? { productId: 0, unitId: 0, qty: l.qty, discount: l.discountPct, custom: { ...l.custom, price: this.unitOf(l).price } }
          : { productId: l.product.id, unitId: l.unitId, qty: l.qty, batchId: l.batchId, discount: l.discountPct }),
        orderDiscount: this.orderDiscount,
        payments,
      }
    },
  },
})
