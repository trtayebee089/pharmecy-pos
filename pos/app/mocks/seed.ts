/**
 * DEVELOPMENT-ONLY MOCK DATA.
 * Used only when runtimeConfig.public.useMocks is true (NUXT_PUBLIC_USE_MOCKS).
 * Deterministic so screenshots and demos are reproducible. Never shipped as
 * real data: production builds must run with useMocks=false against the API.
 */
import type {
  BatchRow, Customer, DashboardSummary, Product, ProductUnit, PurchaseSummary, SaleSummary,
} from '~/types/domain'

// Small deterministic PRNG (mulberry32)
function rng(seed: number) {
  return () => {
    seed |= 0; seed = (seed + 0x6D2B79F5) | 0
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}
const r = rng(20261123)
const pick = <T>(a: T[]): T => a[Math.floor(r() * a.length)]!
const int = (min: number, max: number) => Math.floor(r() * (max - min + 1)) + min
const round2 = (n: number) => Math.round(n * 100) / 100
const isoDaysFromNow = (d: number) => {
  const x = new Date(); x.setDate(x.getDate() + d); return x.toISOString().slice(0, 10)
}

// [brand, strength, generic, form, manufacturer, piece price, pieces/strip, strips/box]
const catalog: [string, string, string, string, string, number, number, number][] = [
  ['Napa', '500 mg', 'Paracetamol', 'Tablet', 'Beximco Pharmaceuticals', 1.2, 10, 20],
  ['Napa Extra', '500 mg+65 mg', 'Paracetamol + Caffeine', 'Tablet', 'Beximco Pharmaceuticals', 2.5, 10, 12],
  ['Ace', '500 mg', 'Paracetamol', 'Tablet', 'Square Pharmaceuticals', 1.2, 10, 20],
  ['Ace Plus', '500 mg+65 mg', 'Paracetamol + Caffeine', 'Tablet', 'Square Pharmaceuticals', 2.5, 10, 12],
  ['Seclo', '20 mg', 'Omeprazole', 'Capsule', 'Square Pharmaceuticals', 6, 10, 10],
  ['Maxpro', '20 mg', 'Esomeprazole', 'Tablet', 'Renata Limited', 7, 10, 10],
  ['Sergel', '20 mg', 'Esomeprazole', 'Capsule', 'Healthcare Pharmaceuticals', 7, 10, 10],
  ['Fexo', '120 mg', 'Fexofenadine', 'Tablet', 'Square Pharmaceuticals', 8, 10, 5],
  ['Alatrol', '10 mg', 'Cetirizine', 'Tablet', 'Square Pharmaceuticals', 3, 10, 10],
  ['Monas', '10 mg', 'Montelukast', 'Tablet', 'ACME Laboratories', 16, 10, 3],
  ['Montair', '10 mg', 'Montelukast', 'Tablet', 'Incepta Pharmaceuticals', 16, 10, 3],
  ['Azithrocin', '500 mg', 'Azithromycin', 'Tablet', 'Beximco Pharmaceuticals', 40, 3, 4],
  ['Zimax', '500 mg', 'Azithromycin', 'Tablet', 'Square Pharmaceuticals', 40, 3, 4],
  ['Ciprocin', '500 mg', 'Ciprofloxacin', 'Tablet', 'Square Pharmaceuticals', 15, 10, 5],
  ['Flagyl', '400 mg', 'Metronidazole', 'Tablet', 'Sanofi Bangladesh', 1.5, 10, 20],
  ['Amodis', '400 mg', 'Metronidazole', 'Tablet', 'Square Pharmaceuticals', 1.5, 10, 20],
  ['Losectil', '20 mg', 'Omeprazole', 'Capsule', 'Eskayef Pharmaceuticals', 5, 10, 10],
  ['Pantonix', '40 mg', 'Pantoprazole', 'Tablet', 'Incepta Pharmaceuticals', 7, 10, 10],
  ['Bizoran', '5 mg+20 mg', 'Amlodipine + Olmesartan', 'Tablet', 'Beximco Pharmaceuticals', 12, 10, 3],
  ['Amdocal', '5 mg', 'Amlodipine', 'Tablet', 'Beximco Pharmaceuticals', 5, 10, 10],
  ['Osartil', '50 mg', 'Losartan Potassium', 'Tablet', 'Incepta Pharmaceuticals', 8, 10, 5],
  ['Comet', '500 mg', 'Metformin', 'Tablet', 'Square Pharmaceuticals', 4, 10, 10],
  ['Secrin', '2 mg', 'Glimepiride', 'Tablet', 'Healthcare Pharmaceuticals', 6, 10, 10],
  ['Rosuva', '10 mg', 'Rosuvastatin', 'Tablet', 'Square Pharmaceuticals', 15, 10, 3],
  ['Ecosprin', '75 mg', 'Aspirin', 'Tablet', 'ACI Limited', 0.6, 14, 20],
  ['Thyrox', '50 mcg', 'Levothyroxine', 'Tablet', 'Renata Limited', 1.6, 30, 1],
  ['Calbo-D', '500 mg+200 IU', 'Calcium + Vitamin D3', 'Tablet', 'Square Pharmaceuticals', 8, 15, 2],
  ['Neuro-B', '100 mg+200 mg+200 mcg', 'Vitamin B1+B6+B12', 'Tablet', 'Square Pharmaceuticals', 8, 10, 10],
  ['Ceevit', '250 mg', 'Vitamin C', 'Chewable Tablet', 'Square Pharmaceuticals', 1.5, 10, 20],
  ['Tofen', '1 mg', 'Ketotifen', 'Tablet', 'Beximco Pharmaceuticals', 2.5, 10, 10],
  ['Deslor', '5 mg', 'Desloratadine', 'Tablet', 'Square Pharmaceuticals', 4, 10, 10],
  ['Esoral', '20 mg', 'Esomeprazole', 'Tablet', 'Eskayef Pharmaceuticals', 7, 10, 10],
  ['Ketorolac', '10 mg', 'Ketorolac Tromethamine', 'Tablet', 'Opsonin Pharma', 10, 10, 5],
  ['Tamen', '500 mg', 'Paracetamol', 'Tablet', 'Opsonin Pharma', 1, 10, 20],
  ['Domperon', '10 mg', 'Domperidone', 'Tablet', 'Beximco Pharmaceuticals', 3, 10, 10],
  ['Emistat', '8 mg', 'Ondansetron', 'Tablet', 'Square Pharmaceuticals', 10, 10, 3],
  ['Fluclox', '500 mg', 'Flucloxacillin', 'Capsule', 'ACI Limited', 10, 8, 4],
  ['Cef-3', '200 mg', 'Cefixime', 'Capsule', 'Square Pharmaceuticals', 35, 6, 1],
  ['Tryptin', '10 mg', 'Amitriptyline', 'Tablet', 'Square Pharmaceuticals', 0.85, 10, 20],
  ['Rivotril', '0.5 mg', 'Clonazepam', 'Tablet', 'Roche', 7, 10, 3],
]
const liquids: [string, string, string, string, string, number, 'bottle' | 'tube' | 'vial'][] = [
  ['Napa Syrup', '120 mg/5 ml', 'Paracetamol', 'Syrup', 'Beximco Pharmaceuticals', 40, 'bottle'],
  ['Alatrol Syrup', '5 mg/5 ml', 'Cetirizine', 'Syrup', 'Square Pharmaceuticals', 45, 'bottle'],
  ['Tusca Plus', '100 ml', 'Guaiphenesin + combination', 'Syrup', 'Beximco Pharmaceuticals', 85, 'bottle'],
  ['Flagyl Suspension', '200 mg/5 ml', 'Metronidazole', 'Oral Suspension', 'Sanofi Bangladesh', 60, 'bottle'],
  ['Zimax Suspension', '200 mg/5 ml', 'Azithromycin', 'Powder for Suspension', 'Square Pharmaceuticals', 180, 'bottle'],
  ['Betnovate-N', '15 g', 'Betamethasone + Neomycin', 'Cream', 'GSK Bangladesh', 95, 'tube'],
  ['Neosporin', '10 g', 'Neomycin + Polymyxin B', 'Ointment', 'GSK Bangladesh', 120, 'tube'],
  ['Ceftron', '1 g/vial', 'Ceftriaxone', 'Injection', 'Square Pharmaceuticals', 210, 'vial'],
  ['Omidon Drops', '15 ml', 'Domperidone', 'Pediatric Drops', 'Incepta Pharmaceuticals', 55, 'bottle'],
  ['Orsaline-N', '10.25 g', 'Oral Rehydration Salts', 'Oral Powder', 'SMC Enterprise', 6, 'bottle'],
]

const PRESCRIPTION = new Set(['Azithromycin', 'Ciprofloxacin', 'Clonazepam', 'Cefixime', 'Ceftriaxone', 'Flucloxacillin', 'Amitriptyline'])

let batchSeq = 1
function makeBatches(prefix: string, pieces: number, unitCost: number) {
  const n = int(1, 3)
  const out = []
  for (let i = 0; i < n; i++) {
    // Mix: a few expired / critical / soon so the expiry UX is visible
    const roll = r()
    const days = roll < 0.06 ? -int(3, 60) : roll < 0.16 ? int(5, 30) : roll < 0.3 ? int(31, 90) : int(120, 900)
    out.push({
      batchId: batchSeq++,
      batchNo: `${prefix}${int(10, 99)}${String.fromCharCode(65 + int(0, 25))}${int(100, 999)}`,
      expiryDate: isoDaysFromNow(days),
      qtyBase: i === 0 && r() < 0.12 ? int(1, 8) : int(20, Math.max(40, pieces)),
      unitCost: round2(unitCost),
    })
  }
  return out.sort((a, b) => a.expiryDate.localeCompare(b.expiryDate))
}

let unitSeq = 1
export const products: Product[] = []
catalog.forEach(([brand, strength, generic, form, mfr, piece, perStrip, stripsPerBox], i) => {
  const units: ProductUnit[] = [
    { id: unitSeq++, code: 'piece', label: 'Piece', factor: 1, price: piece },
    { id: unitSeq++, code: 'strip', label: `Strip (${perStrip})`, factor: perStrip, price: round2(piece * perStrip), isSaleDefault: true },
  ]
  if (stripsPerBox > 1) units.push({ id: unitSeq++, code: 'box', label: `Box (${stripsPerBox}×${perStrip})`, factor: perStrip * stripsPerBox, price: round2(piece * perStrip * stripsPerBox) })
  const batches = makeBatches(brand.slice(0, 2).toUpperCase(), perStrip * stripsPerBox * 3, piece * 0.82)
  products.push({
    id: i + 1, sku: String(1000 + i * 7), name: brand, strength, generic, manufacturer: mfr, dosageForm: form,
    category: form.includes('Capsule') ? 'Capsules' : 'Tablets',
    requiresPrescription: PRESCRIPTION.has(generic), isActive: true,
    reorderLevelBase: perStrip * 5, units, batches,
    stockBase: batches.filter(b => b.expiryDate >= isoDaysFromNow(0)).reduce((s, b) => s + b.qtyBase, 0),
  })
})
liquids.forEach(([brand, strength, generic, form, mfr, price, unit], i) => {
  const batches = makeBatches(brand.slice(0, 2).toUpperCase(), 60, price * 0.8)
  products.push({
    id: catalog.length + i + 1, sku: String(5000 + i * 11), name: brand, strength, generic, manufacturer: mfr, dosageForm: form,
    category: form === 'Cream' || form === 'Ointment' ? 'Topicals' : form === 'Injection' ? 'Injectables' : 'Liquids',
    requiresPrescription: PRESCRIPTION.has(generic), isActive: true, reorderLevelBase: 10,
    units: [{ id: unitSeq++, code: unit, label: unit[0]!.toUpperCase() + unit.slice(1), factor: 1, price, isSaleDefault: true }],
    batches,
    stockBase: batches.filter(b => b.expiryDate >= isoDaysFromNow(0)).reduce((s, b) => s + b.qtyBase, 0),
  })
})

const firstNames = ['Rahim', 'Karim', 'Nusrat', 'Farhana', 'Tanvir', 'Sadia', 'Arif', 'Mitu', 'Rakib', 'Sharmin', 'Imran', 'Jannat', 'Shuvo', 'Tania', 'Habib', 'Rumana']
const lastNames = ['Ahmed', 'Hossain', 'Islam', 'Rahman', 'Chowdhury', 'Akter', 'Khan', 'Sarker', 'Uddin', 'Begum']
export const customers: Customer[] = [
  { id: 1, code: 'C-0001', name: 'Walk-in customer', phone: null, group: null, dueBalance: 0, points: 0, membership: null, isWalkIn: true, totalSpent: 0, lastVisit: null },
  ...Array.from({ length: 42 }, (_, i): Customer => {
    const spent = int(800, 95000)
    return {
      id: i + 2, code: `C-${String(i + 2).padStart(4, '0')}`,
      name: `${pick(firstNames)} ${pick(lastNames)}`,
      phone: `01${pick(['3', '5', '6', '7', '8', '9'])}${String(int(10000000, 99999999))}`,
      group: r() < 0.2 ? 'Regular patient' : null,
      dueBalance: r() < 0.25 ? int(50, 4200) : 0,
      points: int(0, 900),
      membership: spent > 60000 ? 'Gold' : spent > 25000 ? 'Silver' : null,
      totalSpent: spent, lastVisit: isoDaysFromNow(-int(0, 120)),
    }
  }),
]

const cashiers = ['Sabbir (Counter 1)', 'Moushumi (Counter 2)', 'Admin']
export const sales: SaleSummary[] = Array.from({ length: 160 }, (_, i): SaleSummary => {
  const grand = round2(int(40, 6800) + r())
  const status = r() < 0.03 ? 'void' : 'completed'
  const paidAll = r() > 0.12
  const paid = status === 'void' ? 0 : paidAll ? grand : round2(grand * pick([0, 0.5, 0.7]))
  const d = new Date(); d.setMinutes(d.getMinutes() - i * int(9, 55))
  const c = r() < 0.7 ? customers[0]! : pick(customers)
  return {
    id: 120000 + 160 - i, number: `S-DHK1-2026-${String(120000 + 160 - i).padStart(6, '0')}`,
    date: d.toISOString(), customer: c.name, channel: r() < 0.08 ? 'website' : 'pos', items: int(1, 9),
    grandTotal: grand, paidTotal: paid, dueTotal: round2(grand - paid), status: status as SaleSummary['status'],
    paymentStatus: paid >= grand ? 'paid' : paid > 0 ? 'partial' : 'unpaid', cashier: pick(cashiers),
  }
})

const suppliers = ['Square Distribution', 'Beximco Pharma Depot', 'Incepta Sales Point', 'Renata Depot', 'ACME Distribution', 'Healthcare Depot', 'Eskayef Distribution']
export const purchases: PurchaseSummary[] = Array.from({ length: 60 }, (_, i): PurchaseSummary => {
  const grand = round2(int(3000, 185000))
  const paid = r() < 0.55 ? grand : round2(grand * pick([0, 0.3, 0.6]))
  return {
    id: 8600 - i, number: `P-DHK1-2026-${String(8600 - i).padStart(5, '0')}`, date: isoDaysFromNow(-Math.floor(i / 2)),
    supplier: pick(suppliers), supplierInvoiceNo: `INV-${int(10000, 99999)}`, items: int(4, 60),
    grandTotal: grand, paidTotal: paid, dueTotal: round2(grand - paid), status: i < 2 ? 'draft' : 'received',
  }
})

export const batchRows: BatchRow[] = products.flatMap(p => p.batches.map(b => ({
  batchId: b.batchId, batchNo: b.batchNo, productId: p.id, productName: `${p.name} ${p.strength ?? ''}`.trim(),
  manufacturer: p.manufacturer, expiryDate: b.expiryDate, qtyBase: b.qtyBase, value: round2(b.qtyBase * b.unitCost), status: 'active' as const,
})))

export function dashboard(): DashboardSummary {
  const today = sales.filter(s => s.status === 'completed' && new Date(s.date).toDateString() === new Date().toDateString())
  const total = today.reduce((s, x) => s + x.grandTotal, 0)
  return {
    todaySales: round2(total), todaySalesDelta: 12.4, todayInvoices: today.length,
    todayProfit: round2(total * 0.16), todayProfitDelta: 8.1,
    cashInHand: round2(total * 0.78 + 5000),
    customerDue: customers.reduce((s, c) => s + c.dueBalance, 0),
    supplierDue: purchases.reduce((s, p) => s + p.dueTotal, 0),
    lowStockCount: products.filter(p => p.stockBase <= p.reorderLevelBase).length,
    nearExpiryCount: batchRows.filter(b => { const d = (new Date(b.expiryDate).getTime() - Date.now()) / 864e5; return d >= 0 && d <= 90 }).length,
    expiredCount: batchRows.filter(b => new Date(b.expiryDate).getTime() < Date.now() - 864e5).length,
    salesSeries: Array.from({ length: 14 }, (_, i) => {
      const d = new Date(); d.setDate(d.getDate() - (13 - i))
      return { label: d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }), value: int(38000, 92000) }
    }),
    paymentMix: [
      { method: 'Cash', value: 64 }, { method: 'bKash', value: 21 }, { method: 'Card', value: 9 }, { method: 'Nagad', value: 6 },
    ],
    topProducts: products.slice(0, 6).map(p => ({ name: `${p.name} ${p.strength ?? ''}`.trim(), qty: int(80, 900), revenue: int(4000, 52000) })).sort((a, b) => b.revenue - a.revenue),
  }
}
