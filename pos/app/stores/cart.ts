import { defineStore } from 'pinia'
import type { Customer, PaymentLine, Product } from '~/types/domain'

export interface CartLine {
  key: string
  product: Product
  unitId: number
  qty: number
  discountPct: number
  batchId?: number // cashier override (non-expired only)
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
  }),
  getters: {
    unitOf: () => (l: CartLine) => l.product.units.find(u => u.id === l.unitId) ?? l.product.units[0]!,
    qtyBase(): (l: CartLine) => number {
      return l => l.qty * this.unitOf(l).factor
    },
    /** FEFO across sellable batches, or the override batch first. */
    allocation(): (l: CartLine) => { parts: Allocation[]; short: number } {
      return (l) => {
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
        lines: this.lines.map(l => ({ productId: l.product.id, unitId: l.unitId, qty: l.qty, batchId: l.batchId, discount: l.discountPct })),
        orderDiscount: this.orderDiscount,
        payments,
      }
    },
  },
})
