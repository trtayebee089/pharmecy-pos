/**
 * DEVELOPMENT-ONLY MOCK DATA for biller, custom product, customer and
 * monthly summary reports. Loaded only when useMocks is true.
 */
import { customers, purchases, sales } from './seed'

const r2 = (n: number) => Math.round(n * 100) / 100

/** Billers = the billing identity printed on the invoice (legacy `billers`, 12 rows). */
export const billers = [
  { id: 1, name: 'Red Pharma Dhanmondi', company: 'Red Pharma', phone: '01711000001' },
  { id: 2, name: 'Red Pharma Online', company: 'Red Pharma', phone: '01711000002' },
  { id: 3, name: 'Corporate desk', company: 'Red Pharma', phone: '01711000003' },
]
export const billerOf = (saleId: number) => billers[saleId % 7 === 0 ? 2 : saleId % 4 === 0 ? 1 : 0]!

export function billerRows() {
  return sales.filter(s => s.status === 'completed').map(s => ({ ...s, biller: billerOf(s.id).name, billerId: billerOf(s.id).id }))
}

/** Free-text lines sold for items not in the catalogue (legacy custom_product_sales). */
const customNames = [
  ['Surgical mask (box)', 'MSK-50'], ['Cotton bandage 4"', 'BND-4'], ['Hand sanitizer 100 ml', 'SAN-100'], ['Glucometer strips (loose)', 'GLS-25'],
  ['Hot water bag', 'HWB-1'], ['Adult diaper (single)', 'DIA-1'], ['Thermometer digital', 'THM-D'], ['Crepe bandage 6"', 'CRP-6'],
  ['Nebulizer mask', 'NEB-M'], ['Saline bag (outside supply)', 'SAL-X'],
] as const
export function customProductRows() {
  return sales.filter(s => s.status === 'completed' && s.id % 3 === 0).map((s, i) => {
    const k = (i * 3 + Math.floor(s.id / 3)) % customNames.length
    const [name, code] = customNames[k]!
    const qty = 1 + ((s.id + i) % 4)
    const unitPrice = [120, 45, 95, 18, 350, 35, 280, 70, 150, 90][k]!
    return { date: s.date, number: s.number, saleId: s.id, productName: name, productCode: code, qty, unitPrice, total: qty * unitPrice, cashier: s.cashier, customer: s.customer }
  })
}

/** Customer statement: sales (debit), payments and returns (credit). */
export function customerStatement(customerId: number) {
  const c = customers.find(x => x.id === customerId)
  if (!c) return []
  const own = sales.filter(s => s.status === 'completed' && (s.customer === c.name || s.id % 43 === customerId))
  const entries: { date: string; type: string; reference: string; debit: number; credit: number; saleId: number }[] = []
  for (const s of own) {
    entries.push({ date: s.date, type: 'sale', reference: s.number, debit: s.grandTotal, credit: 0, saleId: s.id })
    if (s.paidTotal > 0) entries.push({ date: s.date, type: 'payment', reference: `RCV-${s.id}`, debit: 0, credit: s.paidTotal, saleId: s.id })
    if (s.id % 11 === 0) entries.push({ date: s.date, type: 'return', reference: `R-${s.id}`, debit: 0, credit: r2(s.grandTotal * 0.2), saleId: s.id })
  }
  entries.sort((a, b) => a.date.localeCompare(b.date))
  let bal = 0
  return entries.map(e => ({ ...e, balance: r2((bal += e.debit - e.credit)) }))
}

/** Month at a glance, per day. Values are deterministic per date. */
export function monthlySummary(year: number, month: number) {
  const days = new Date(year, month + 1, 0).getDate()
  const today = new Date()
  const rows = []
  for (let d = 1; d <= days; d++) {
    const dt = new Date(year, month, d)
    if (dt > today) break
    const seed = (d * 7919 + month * 104729 + year) % 1000
    const sales = 38000 + (seed * 53) % 52000
    const returns = r2(sales * ((seed % 7) / 300))
    const discounts = r2(sales * 0.018)
    const cogs = r2((sales - returns) * 0.81)
    const purchases = d % 3 === 0 ? 60000 + (seed * 91) % 90000 : 0
    const expenses = 1200 + (seed * 13) % 4800
    const income = d % 9 === 0 ? 2500 : 0
    rows.push({
      date: dt.toISOString().slice(0, 10), invoices: 90 + (seed % 70), sales, returns, discounts,
      netSales: r2(sales - returns - discounts), cogs, grossProfit: r2(sales - returns - discounts - cogs), purchases, expenses, income,
      netProfit: r2(sales - returns - discounts - cogs - expenses + income),
      cash: r2(sales * 0.64), bkash: r2(sales * 0.21), card: r2(sales * 0.09), nagad: r2(sales * 0.06),
    })
  }
  return rows
}
export const supplierPaymentsInMonth = () => purchases.slice(0, 6).reduce((s, p) => s + p.paidTotal, 0)
