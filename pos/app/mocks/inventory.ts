/**
 * DEVELOPMENT-ONLY MOCK DATA for catalog masters, inventory operations and purchasing.
 * Loaded only when runtimeConfig.public.useMocks is true. Never shipped as real data.
 * Arrays are module singletons so mock writes persist while navigating the SPA.
 */
import { products, purchases, customers as _c } from './seed'
import { suppliers as supplierSeed } from './extra'
import type {
  CategoryRow, CountLine, CountSession, DosageForm, Manufacturer, PurchaseReturnSummary, ReorderLine,
  StockRow, SupplierLedgerEntry, SupplierRow, TransferSummary,
} from '~/types/inventory'

void _c
const DAY = 864e5
const now = Date.now()
const iso = (offsetDays: number) => new Date(now + offsetDays * DAY).toISOString()
const round2 = (n: number) => Math.round(n * 100) / 100
const today = () => new Date().toISOString().slice(0, 10)

// ---- Catalog masters --------------------------------------------------------
const countries: Record<string, string> = { 'Sanofi Bangladesh': 'France', 'GSK Bangladesh': 'United Kingdom', Roche: 'Switzerland' }
export const manufacturers: Manufacturer[] = [...new Set(products.map(p => p.manufacturer))].sort().map((name, i) => ({
  id: i + 1,
  name,
  shortName: name.split(' ')[0]!.toUpperCase().slice(0, 6),
  country: countries[name] ?? 'Bangladesh',
  products: products.filter(p => p.manufacturer === name).length,
  isActive: true,
}))

export const dosageForms: DosageForm[] = [...new Set(products.map(p => p.dosageForm))].sort().map((name, i) => ({
  id: i + 1, name, products: products.filter(p => p.dosageForm === name).length, isActive: true,
}))

const tree: [string, string[]][] = [
  ['Medicine', ['Tablets', 'Capsules', 'Liquids', 'Injectables', 'Topicals']],
  ['Baby care', ['Diapers', 'Baby food']],
  ['Personal care', ['Skin care', 'Oral care']],
  ['Devices', ['Glucometers', 'BP monitors']],
]
export const categories: CategoryRow[] = (() => {
  const rows: CategoryRow[] = []
  let id = 1
  for (const [parent, kids] of tree) {
    const pid = id++
    rows.push({ id: pid, name: parent, parentId: null, path: parent, products: 0, sortOrder: pid, isActive: true })
    kids.forEach((k, i) => rows.push({ id: id++, name: k, parentId: pid, path: `${parent} › ${k}`, products: products.filter(p => p.category === k).length, sortOrder: i + 1, isActive: true }))
  }
  for (const r of rows.filter(r => r.parentId === null)) r.products = rows.filter(c => c.parentId === r.id).reduce((s, c) => s + c.products, 0)
  return rows
})()

// ---- Stock overview -----------------------------------------------------------
export function stockRows(): StockRow[] {
  const t = today()
  return products.map((p) => {
    const sellable = p.batches.filter(b => b.expiryDate >= t)
    const sellableBase = sellable.reduce((s, b) => s + b.qtyBase, 0)
    return {
      id: p.id, sku: p.sku, name: p.name, strength: p.strength, manufacturer: p.manufacturer, dosageForm: p.dosageForm, units: p.units,
      sellableBase,
      expiredBase: p.batches.filter(b => b.expiryDate < t).reduce((s, b) => s + b.qtyBase, 0),
      valueAtCost: round2(sellable.reduce((s, b) => s + b.qtyBase * b.unitCost, 0)),
      reorderLevelBase: p.reorderLevelBase,
      status: sellableBase <= 0 ? 'out' : sellableBase <= p.reorderLevelBase ? 'low' : 'ok',
      expiringSoon: sellable.filter(b => (new Date(b.expiryDate).getTime() - now) / DAY <= 90).length,
      batches: p.batches,
    }
  })
}

// ---- Stock counts ----------------------------------------------------------------
export const countSessions: CountSession[] = [
  { id: 7, number: 'SC-DHK1-2026-0007', name: 'Monthly count · Tablets', warehouse: 'Red Pharma · Dhanmondi', scope: 'Dosage form: Tablet', status: 'counting', startedBy: 'Sabbir', startedAt: iso(-0.2), postedAt: null, lines: 0, counted: 0, varianceQty: 0, varianceValue: 0 },
  { id: 6, number: 'SC-DHK1-2026-0006', name: 'Antibiotics spot check', warehouse: 'Red Pharma · Dhanmondi', scope: 'Generic contains: mycin / cillin', status: 'posted', startedBy: 'Rakib', startedAt: iso(-9), postedAt: iso(-9), lines: 34, counted: 34, varianceQty: -6, varianceValue: -212.4 },
  { id: 5, number: 'SC-DHK1-2026-0005', name: 'Liquids & topicals', warehouse: 'Red Pharma · Dhanmondi', scope: 'Category: Liquids, Topicals', status: 'posted', startedBy: 'Sabbir', startedAt: iso(-23), postedAt: iso(-23), lines: 18, counted: 18, varianceQty: 2, varianceValue: 96 },
  { id: 4, number: 'SC-DHK1-2026-0004', name: 'Full count (cancelled, power cut)', warehouse: 'Red Pharma · Dhanmondi', scope: 'All products', status: 'cancelled', startedBy: 'Admin', startedAt: iso(-31), postedAt: null, lines: 61, counted: 12, varianceQty: 0, varianceValue: 0 },
]
const countLineStore = new Map<number, CountLine[]>()

export function countLinesFor(session: CountSession): CountLine[] {
  if (!countLineStore.has(session.id)) {
    const scoped = session.scope.startsWith('Dosage form: Tablet') ? products.filter(p => p.dosageForm.includes('Tablet')) : products.slice(0, 18)
    const lines: CountLine[] = []
    scoped.forEach((p, pi) => p.batches.forEach((b, bi) => {
      // Posted sessions show final counts; the open one is partially counted.
      const counted = session.status === 'posted' ? b.qtyBase + ((pi + bi) % 7 === 0 ? -1 : 0)
        : session.status === 'counting' && pi < 6 ? b.qtyBase + ((pi + bi) % 5 === 0 ? -2 : 0) : null
      lines.push({ key: `${b.batchId}`, productId: p.id, productName: `${p.name} ${p.strength ?? ''}`.trim(), manufacturer: p.manufacturer, batchId: b.batchId, batchNo: b.batchNo, expiryDate: b.expiryDate, systemQty: b.qtyBase, countedQty: counted, unitCost: b.unitCost })
    }))
    countLineStore.set(session.id, lines)
  }
  return countLineStore.get(session.id)!
}
export function summarise(session: CountSession) {
  const lines = countLinesFor(session)
  session.lines = lines.length
  session.counted = lines.filter(l => l.countedQty !== null).length
  session.varianceQty = lines.reduce((s, l) => s + (l.countedQty === null ? 0 : l.countedQty - l.systemQty), 0)
  session.varianceValue = round2(lines.reduce((s, l) => s + (l.countedQty === null ? 0 : (l.countedQty - l.systemQty) * l.unitCost), 0))
}
countSessions.filter(s => s.status === 'counting').forEach(summarise)

// ---- Transfers -------------------------------------------------------------------
const tline = (pi: number, qty: number) => {
  const p = products[pi]!
  const b = p.batches.find(x => x.expiryDate >= today()) ?? p.batches[0]!
  return { productId: p.id, productName: `${p.name} ${p.strength ?? ''}`.trim(), batchId: b.batchId, batchNo: b.batchNo, expiryDate: b.expiryDate, qtyBase: qty }
}
export const transfers: TransferSummary[] = [
  { id: 14, number: 'TR-2026-00014', date: iso(-0.1), from: 'Red Pharma · Dhanmondi', to: 'Red Pharma · Uttara', lines: [tline(0, 200), tline(4, 50)], qty: 250, status: 'sent', createdBy: 'Rakib', note: 'Weekend restock' },
  { id: 13, number: 'TR-2026-00013', date: iso(-2), from: 'Red Pharma · Uttara', to: 'Red Pharma · Dhanmondi', lines: [tline(8, 40)], qty: 40, status: 'received', createdBy: 'Rakib', note: '' },
  { id: 12, number: 'TR-2026-00012', date: iso(-5), from: 'Red Pharma · Dhanmondi', to: 'Red Pharma · Uttara', lines: [tline(2, 120), tline(10, 30), tline(13, 20)], qty: 170, status: 'received', createdBy: 'Admin', note: '' },
  { id: 11, number: 'TR-2026-00011', date: iso(-6), from: 'Red Pharma · Dhanmondi', to: 'Red Pharma · Uttara', lines: [tline(5, 60)], qty: 60, status: 'void', createdBy: 'Sabbir', note: 'Wrong destination' },
]

// ---- Purchase returns ----------------------------------------------------------------
export const purchaseReturns: PurchaseReturnSummary[] = Array.from({ length: 9 }, (_, i) => ({
  id: 20 - i,
  number: `PR-DHK1-2026-${String(20 - i).padStart(5, '0')}`,
  date: iso(-i * 4 - 1),
  supplier: supplierSeed[i % supplierSeed.length]!.name,
  creditNote: `CN-${48210 + i * 37}`,
  lines: 1 + (i % 4),
  qty: 20 + ((i * 17) % 90),
  total: round2(400 + ((i * 1373) % 6200)),
  reason: ['Expired', 'Near expiry', 'Damaged in transit', 'Wrong item supplied'][i % 4]!,
  status: i === 6 ? 'void' : 'posted',
}))

// ---- Reorder suggestions -------------------------------------------------------------------
const supplierFor = (manufacturer: string) => {
  const key = manufacturer.split(' ')[0]!
  return supplierSeed.find(s => s.name.startsWith(key)) ?? supplierSeed[0]!
}
/**
 * Suggest only products whose stock will not cover the target period
 * (3-day lead time + 21 days of demand) or that are at/below the reorder level.
 * The quantity tops stock up to the target, rounded up to whole supplier packs.
 */
export function reorderSuggestions(): ReorderLine[] {
  const leadDays = 3
  const coverDays = 21
  const lines: ReorderLine[] = []
  for (const p of products) {
    const strip = p.units.find(u => u.code === 'strip')?.factor ?? 1
    const box = p.units.find(u => u.code === 'box')?.factor ?? strip
    // Demand scaled to the product's typical pack so the mock forecast is plausible
    const sold = Math.max(10, Math.round(p.reorderLevelBase * (2 + ((p.id * 37) % 9) / 2)))
    const daily = sold / 30
    const cover = Math.round(p.stockBase / Math.max(daily, 0.1))
    const target = Math.ceil(daily * (leadDays + coverDays))
    const belowLevel = p.stockBase <= p.reorderLevelBase
    if (!belowLevel && p.stockBase >= target) continue // enough stock: nothing to order
    const pack = box > 1 ? box : strip
    const suggestedBase = Math.max(pack, Math.ceil((target - p.stockBase) / pack) * pack)
    const sup = supplierFor(p.manufacturer)
    const unit: ReorderLine['unit'] = box > strip ? 'box' : strip > 1 ? 'strip' : 'piece'
    const f = unit === 'box' ? box : unit === 'strip' ? strip : 1
    const rationale = belowLevel
      ? `Stock (${p.stockBase} pcs) is at or below the reorder level (${p.reorderLevelBase} pcs). Topping up to ${leadDays + coverDays} days of demand.`
      : `Sold ${sold} pcs in 30 days; ${p.stockBase} pcs covers about ${cover} days, less than the ${leadDays}-day lead time plus ${coverDays} days of cover.`
    lines.push({
      key: `${p.id}`, productId: p.id, productName: `${p.name} ${p.strength ?? ''}`.trim(), manufacturer: p.manufacturer,
      supplierId: sup.id, supplierName: sup.name, soldQty: sold, inStock: p.stockBase, reorderLevel: p.reorderLevelBase,
      stripFactor: strip, boxFactor: box, suggestedBase, unit, finalQty: Math.ceil(suggestedBase / f),
      rationale, source: belowLevel ? 'low_stock' : 'ai', selected: true,
    })
  }
  return lines.slice(0, 24)
}

// ---- Suppliers & ledger --------------------------------------------------------------------
export const supplierRows: SupplierRow[] = supplierSeed.map((s, i) => {
  const own = purchases.filter(p => p.supplier === s.name)
  return {
    id: s.id, name: s.name, companyName: `${s.name.split(' ')[0]} Pharmaceuticals Ltd.`, phone: s.phone,
    email: `orders@${s.name.split(' ')[0]!.toLowerCase()}.example.test`, address: ['Tejgaon I/A, Dhaka', 'Mohakhali, Dhaka', 'Gazipur', 'Savar, Dhaka'][i % 4]!,
    paymentTermsDays: [15, 30, 7, 30, 15, 0, 30][i] ?? 15, dueBalance: s.dueBalance, purchases: own.length,
    lastPurchase: own[0]?.date ?? null, isActive: true,
  }
})

export function supplierLedger(supplier: SupplierRow): SupplierLedgerEntry[] {
  const own = purchases.filter(p => p.supplier === supplier.name).slice().reverse()
  const entries: Omit<SupplierLedgerEntry, 'balance'>[] = []
  let id = 1
  entries.push({ id: id++, date: iso(-120), type: 'opening', ref: 'Opening balance (migrated)', increase: 0, decrease: 0 })
  for (const p of own) {
    entries.push({ id: id++, date: p.date, type: 'invoice', ref: `${p.number} · inv ${p.supplierInvoiceNo}`, increase: p.grandTotal, decrease: 0 })
    if (p.paidTotal) entries.push({ id: id++, date: p.date, type: 'payment', ref: `Paid with ${p.number}`, increase: 0, decrease: p.paidTotal })
  }
  const ret = purchaseReturns.find(r => r.supplier === supplier.name && r.status === 'posted')
  if (ret) entries.push({ id: id++, date: ret.date, type: 'return', ref: `${ret.number} · ${ret.creditNote}`, increase: 0, decrease: ret.total })
  entries.sort((a, b) => a.date.localeCompare(b.date))
  // Opening balance makes the ledger end exactly at the supplier's current due.
  const movement = entries.reduce((s, e) => s + e.increase - e.decrease, 0)
  entries[0]!.increase = Math.max(0, round2(supplier.dueBalance - movement))
  entries[0]!.decrease = Math.max(0, round2(movement - supplier.dueBalance))
  let bal = 0
  return entries.map((e) => { bal = round2(bal + e.increase - e.decrease); return { ...e, balance: bal } })
}
