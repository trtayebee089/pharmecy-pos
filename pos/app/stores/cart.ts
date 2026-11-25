import { defineStore } from 'pinia'
import type { Customer, PaymentLine, Product } from '~/types/domain'
import type { HeldSaleSummary } from '~/types/orders'
import type { RewardPointConfig } from '~/services/settings'

/** POST /sales/held/{id}/resume */
interface ResumedHeldSale {
  id: string
  customer: Customer | null
  orderDiscount: number
  lines: { product: Product | null; unitId: number; qty: number; discountPct: number; batchId?: number | null; custom?: { name: string; code: string; price: number } }[]
  skipped: string[]
}

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
 * A parked cart. With the sales domain live it is stored on the server (`server`)
 * and only its summary is kept here until it is resumed; in mock mode the
 * lines stay in this store (this browser only).
 */
export interface HeldCart {
  id: string
  at: string
  lines: CartLine[]
  customer: Customer | null
  customerName?: string | null
  orderDiscount: number
  items: number
  total: number
  heldBy?: string
  heldById?: number
  register?: string
  server?: boolean
}

/** A free-text line (legacy custom product): a synthetic product with one piece unit at the typed price. */
function customLine(name: string, code: string, price: number, qty: number, discountPct = 0): CartLine {
  const id = -Date.now() - Math.floor(Math.random() * 1000)
  const product: Product = {
    id, sku: code || 'CUSTOM', name, strength: null, generic: null, manufacturer: 'Custom item', dosageForm: 'Custom', category: 'Custom',
    requiresPrescription: false, isActive: true, reorderLevelBase: 0,
    units: [{ id, code: 'piece', label: 'Piece', factor: 1, price, isSaleDefault: true }], batches: [], stockBase: Number.MAX_SAFE_INTEGER,
  }
  return { key: `custom-${-id}`, product, unitId: id, qty, discountPct, custom: { name, code } }
}

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
    held: [] as HeldCart[],
    lastReceipt: null as ReceiptSnapshot | null,
    /** Reward rules (points at checkout, membership discount mode); loaded by the counter. */
    rewardRules: null as RewardPointConfig | null,
    /** Cash rounding step from General settings (0, 0.5 or 1 taka); the API rounds the same way. */
    roundTo: 0,
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
    /** The customer's group discount (catalogue lines only). */
    groupPct(): number { return this.customer?.isWalkIn ? 0 : (this.customer?.groupDiscountPct ?? 0) },
    /**
     * Automatic catalogue-line discount: the group % combined with the membership % per the admin's
     * mode (off = group only, larger = the bigger one, stack = both). Same rule as the API.
     */
    autoPct(): number {
      const member = this.customer?.isWalkIn ? 0 : (this.customer?.membershipDiscountPct ?? 0)
      const mode = this.rewardRules?.membershipDiscountMode ?? 'off'
      if (mode === 'larger') return Math.max(this.groupPct, member)
      if (mode === 'stack') return Math.round(100 * (1 - (1 - this.groupPct / 100) * (1 - member / 100)) * 10000) / 10000
      return this.groupPct
    },
    /** Discount that applies to a line: the larger of the manual and the automatic discount (the API applies the same rule). */
    effectivePct(): (l: CartLine) => number {
      return l => l.custom ? (Number(l.discountPct) || 0) : Math.max(Number(l.discountPct) || 0, this.autoPct)
    },
    lineTotal(): (l: CartLine) => number {
      return l => Math.round(this.lineGross(l) * (1 - this.effectivePct(l) / 100) * 100) / 100
    },
    subtotal(): number { return this.lines.reduce((s, l) => s + this.lineGross(l), 0) },
    lineDiscounts(): number { return this.lines.reduce((s, l) => s + (this.lineGross(l) - this.lineTotal(l)), 0) },
    /** Total before cash rounding. */
    exactTotal(): number { return Math.max(0, Math.round((this.subtotal - this.lineDiscounts - this.orderDiscount) * 100) / 100) },
    /** Payable total, rounded like the server (nearest 0.50 or 1 taka when set). */
    total(): number { return this.roundTo > 0 ? Math.round(Math.round(this.exactTotal / this.roundTo) * this.roundTo * 100) / 100 : this.exactTotal },
    rounding(): number { return Math.round((this.total - this.exactTotal) * 100) / 100 },
    itemCount: s => s.lines.length,
    hasShortage(): boolean { return this.lines.some(l => this.allocation(l).short > 0) },
  },
  actions: {
    /** Loads the reward rules once per session; the counter works without them (no points, group discount only). */
    async loadRewardRules(force = false) {
      if (this.rewardRules && !force) return
      try { this.rewardRules = await useSettingsApi().rewards() }
      catch { this.rewardRules = null }
      try { this.roundTo = Number((await useAdminApi().settings()).roundTo) || 0 }
      catch { this.roundTo = 0 }
    },
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
      this.lines.unshift(customLine(name, code, price, qty))
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
    /** Parks the cart (on the server when the sales domain is live) and clears the counter. */
    async hold(): Promise<HeldCart | null> {
      if (!this.lines.length) return null
      const api = useApi('sales')
      let entry: HeldCart
      if (!api.useMocks) {
        const { payments: _p, ...cart } = this.payload(useAuthStore().warehouseId, [])
        const s = await api.post<HeldSaleSummary>('/sales/held', { ...cart, customerId: this.customer?.id ?? null }, crypto.randomUUID())
        entry = { id: s.id, at: s.heldAt, lines: [], customer: this.customer, customerName: s.customer, orderDiscount: this.orderDiscount, items: s.items, total: s.total, heldBy: s.heldBy, heldById: s.heldById, register: s.register, server: true }
      }
      else {
        entry = { id: `H${Date.now().toString().slice(-5)}`, at: new Date().toISOString(), lines: this.lines, customer: this.customer, customerName: this.customer?.name ?? null, orderDiscount: this.orderDiscount, items: this.lines.length, total: this.total }
      }
      this.held.unshift(entry)
      this.lines = []; this.customer = null; this.orderDiscount = 0
      return entry
    },
    /** Refreshes the server-held carts of the current warehouse (local ones are kept). */
    async loadHeld() {
      const api = useApi('sales')
      if (api.useMocks) return
      const rows = await api.get<HeldSaleSummary[]>('/sales/held', { warehouse_id: useAuthStore().warehouseId })
      this.held = [
        ...rows.map(s => ({ id: s.id, at: s.heldAt, lines: [], customer: null, customerName: s.customer, orderDiscount: 0, items: s.items, total: s.total, heldBy: s.heldBy, heldById: s.heldById, register: s.register, server: true })),
        ...this.held.filter(h => !h.server),
      ]
    },
    /**
     * Puts a held cart back on the counter (parking the current one first).
     * Server carts come back with live products and prices; returns the names
     * of lines that could not be restored (product deactivated).
     */
    async resume(id: string): Promise<string[]> {
      const h = this.held.find(x => x.id === id)
      if (!h) return []
      let skipped: string[] = []
      if (h.server) {
        const r = await useApi('sales').post<ResumedHeldSale>(`/sales/held/${encodeURIComponent(id)}/resume`, {}, crypto.randomUUID())
        if (this.lines.length) await this.hold()
        this.lines = r.lines.map((l, i) => l.custom
          ? customLine(l.custom.name, l.custom.code, l.custom.price, l.qty, l.discountPct)
          : { key: `${l.product!.id}-${l.unitId}-${Date.now()}-${i}`, product: l.product!, unitId: l.unitId, qty: l.qty, discountPct: l.discountPct, batchId: l.batchId ?? undefined })
        this.customer = r.customer; this.orderDiscount = r.orderDiscount
        skipped = r.skipped
      }
      else {
        if (this.lines.length) await this.hold()
        this.lines = h.lines; this.customer = h.customer; this.orderDiscount = h.orderDiscount
      }
      this.held = this.held.filter(x => x.id !== id)
      return skipped
    },
    /** Discards a held cart; nothing was sold or reserved, so nothing else changes. */
    async discardHeld(id: string) {
      const h = this.held.find(x => x.id === id)
      if (h?.server) await useApi('sales').del(`/sales/held/${encodeURIComponent(id)}`)
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
