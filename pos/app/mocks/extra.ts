/**
 * DEVELOPMENT-ONLY MOCK DATA (second part): lookups, suppliers, sale detail,
 * register sessions, adjustments, users, roles, settings.
 * Loaded only when runtimeConfig.public.useMocks is true.
 */
import { customers, products, sales } from './seed'
import type {
  AdjustmentSummary, CatalogLookups, PermissionGroup, PosSettings, RegisterSession, Role, SaleDetail, StaffUser, Supplier,
} from '~/types/domain'

const uniq = (xs: string[]) => [...new Set(xs)].sort().map((name, i) => ({ id: i + 1, name }))

export const lookups: CatalogLookups = {
  generics: uniq(products.map(p => p.generic ?? '').filter(Boolean)),
  manufacturers: uniq(products.map(p => p.manufacturer)),
  dosageForms: uniq(products.map(p => p.dosageForm)),
  categories: uniq(['Tablets', 'Capsules', 'Liquids', 'Topicals', 'Injectables', 'Baby care', 'Personal care', 'Devices']),
}

export const suppliers: Supplier[] = [
  { id: 1, name: 'Square Distribution', phone: '01711000101', dueBalance: 184_300 },
  { id: 2, name: 'Beximco Pharma Depot', phone: '01711000202', dueBalance: 92_150 },
  { id: 3, name: 'Incepta Sales Point', phone: '01711000303', dueBalance: 0 },
  { id: 4, name: 'Renata Depot', phone: '01711000404', dueBalance: 41_800 },
  { id: 5, name: 'ACME Distribution', phone: '01711000505', dueBalance: 12_400 },
  { id: 6, name: 'Healthcare Depot', phone: '01711000606', dueBalance: 0 },
  { id: 7, name: 'Eskayef Distribution', phone: '01711000707', dueBalance: 27_900 },
]

/** Builds a plausible detail record for any sale id from the list mock. */
export function saleDetail(id: number): SaleDetail | null {
  const s = sales.find(x => x.id === id)
  if (!s) return null
  const n = Math.min(s.items, 6)
  const picks = products.filter((_, i) => (i * 7 + id) % products.length < n * 2).slice(0, n)
  let itemSeq = id * 10
  const lines = picks.map((p) => {
    const unit = p.units.find(u => u.isSaleDefault) ?? p.units[0]!
    const qty = 1 + ((id + p.id) % 3)
    const b = p.batches[0]!
    return {
      id: ++itemSeq, productName: `${p.name} ${p.strength ?? ''}`.trim(), unitLabel: unit.label, qty, qtyBase: qty * unit.factor,
      unitPrice: unit.price, discount: 0, lineTotal: Math.round(qty * unit.price * 100) / 100,
      batches: [{ saleItemBatchId: itemSeq * 10, batchNo: b.batchNo, expiryDate: b.expiryDate, qtyBase: qty * unit.factor, returnedQtyBase: 0 }],
    }
  })
  const subtotal = lines.reduce((a, l) => a + l.lineTotal, 0)
  const c = customers.find(x => x.name === s.customer)
  return {
    ...s, grandTotal: Math.round(subtotal * 100) / 100, warehouse: 'Red Pharma · Dhanmondi', customerPhone: c?.phone ?? null,
    subtotal: Math.round(subtotal * 100) / 100, discountTotal: 0, lines,
    paidTotal: s.paymentStatus === 'paid' ? Math.round(subtotal * 100) / 100 : s.paidTotal,
    dueTotal: s.paymentStatus === 'paid' ? 0 : Math.max(0, Math.round((subtotal - s.paidTotal) * 100) / 100),
    payments: s.status === 'void' ? [] : [{ method: 'Cash', amount: s.paymentStatus === 'paid' ? Math.round(subtotal * 100) / 100 : s.paidTotal, paidAt: s.date }],
    returns: [],
  }
}

const now = Date.now()
export const sessions: RegisterSession[] = [
  {
    id: 312, register: 'Counter 1', status: 'open', openedBy: 'Sabbir', openedAt: new Date(now - 6.5 * 3600e3).toISOString(),
    openingFloat: 5000, cashSales: 48_210.5, cashRefunds: 320, cashIn: 0, cashOut: 1200, expectedCash: 51_690.5,
    countedCash: null, difference: null, closedAt: null, nonCash: [{ method: 'bKash', amount: 16_440 }, { method: 'Card', amount: 6_920 }, { method: 'Nagad', amount: 3_150 }],
  },
  ...Array.from({ length: 9 }, (_, i): RegisterSession => {
    const sales = 38_000 + ((i * 7919) % 21_000)
    const expected = 5000 + sales - 1000
    const diff = [0, 0, -20, 0, 50, 0, 0, -110, 0][i]!
    const opened = now - (i + 1) * 86400e3 - 9 * 3600e3
    return {
      id: 311 - i, register: i % 2 ? 'Counter 2' : 'Counter 1', status: 'closed', openedBy: i % 2 ? 'Moushumi' : 'Sabbir',
      openedAt: new Date(opened).toISOString(), openingFloat: 5000, cashSales: sales, cashRefunds: 0, cashIn: 0, cashOut: 1000,
      expectedCash: expected, countedCash: expected + diff, difference: diff, closedAt: new Date(opened + 12 * 3600e3).toISOString(),
      nonCash: [{ method: 'bKash', amount: 12_000 + i * 300 }, { method: 'Card', amount: 4_000 + i * 150 }],
    }
  }),
]

const reasons: AdjustmentSummary['reason'][] = ['damage', 'expiry', 'correction', 'lost', 'found']
export const adjustments: AdjustmentSummary[] = Array.from({ length: 24 }, (_, i) => {
  const reason = reasons[i % reasons.length]!
  const inbound = reason === 'found' || (reason === 'correction' && i % 2 === 0)
  const qty = 4 + ((i * 13) % 60)
  return {
    id: 90 - i, number: `ADJ-DHK1-2026-${String(90 - i).padStart(5, '0')}`, date: new Date(now - i * 2.3 * 86400e3).toISOString(),
    reason, lines: 1 + (i % 4), qtyIn: inbound ? qty : 0, qtyOut: inbound ? 0 : qty, value: Math.round(qty * (3 + (i % 9)) * 100) / 100,
    status: i === 0 ? 'draft' : 'posted', createdBy: i % 3 ? 'Sabbir' : 'Admin',
  }
})

export const permissionGroups: PermissionGroup[] = [
  { module: 'Dashboard', permissions: [{ key: 'dashboard.view', label: 'View dashboard' }] },
  { module: 'POS', permissions: [{ key: 'pos.sell', label: 'Sell at counter' }, { key: 'pos.override_batch', label: 'Override FEFO batch' }, { key: 'pos.price_override', label: 'Override price' }] },
  { module: 'Sales', permissions: [{ key: 'sales.view', label: 'View sales' }, { key: 'sales.void', label: 'Void sale (same session)' }, { key: 'returns.view', label: 'View returns' }, { key: 'returns.create', label: 'Process returns / refunds' }] },
  { module: 'Products', permissions: [{ key: 'products.view', label: 'View products' }, { key: 'products.create', label: 'Create products' }, { key: 'products.edit', label: 'Edit products & prices' }, { key: 'catalog.manage', label: 'Manage generics, manufacturers…' }, { key: 'products.print_barcode', label: 'Print barcode labels' }] },
  { module: 'Inventory', permissions: [{ key: 'inventory.view', label: 'View stock & batches' }, { key: 'inventory.adjust', label: 'Post adjustments' }, { key: 'inventory.count', label: 'Stock counts' }, { key: 'inventory.transfer', label: 'Transfers' }] },
  { module: 'Purchases', permissions: [{ key: 'purchases.view', label: 'View purchases' }, { key: 'purchases.create', label: 'Receive purchases' }, { key: 'purchase_returns.view', label: 'View purchase returns' }, { key: 'purchase_returns.create', label: 'Post and void purchase returns' }, { key: 'reorder.manage', label: 'Reorder drafts (AI)' }] },
  { module: 'People', permissions: [{ key: 'customers.view', label: 'View customers' }, { key: 'customers.manage', label: 'Manage customers' }, { key: 'payments.collect', label: 'Collect dues' }, { key: 'suppliers.view', label: 'View suppliers' }, { key: 'suppliers.manage', label: 'Manage suppliers' }, { key: 'payments.pay_supplier', label: 'Pay suppliers' }] },
  { module: 'Finance', permissions: [{ key: 'registers.view', label: 'Cash registers' }, { key: 'accounts.view', label: 'Accounts' }, { key: 'accounts.transfer', label: 'Money transfers' }, { key: 'expenses.view', label: 'Expenses' }, { key: 'income.view', label: 'Income' }, { key: 'accounts.reports', label: 'Balance sheet' }] },
  { module: 'Reports', permissions: [{ key: 'reports.sales', label: 'Sales' }, { key: 'reports.profit', label: 'Profit & loss' }, { key: 'reports.stock', label: 'Stock & expiry' }, { key: 'reports.purchases', label: 'Purchases' }, { key: 'reports.customers', label: 'Customers' }, { key: 'reports.suppliers', label: 'Suppliers' }, { key: 'reports.payments', label: 'Payments' }, { key: 'reports.users', label: 'Cashiers' }] },
  { module: 'Administration', permissions: [{ key: 'users.view', label: 'Users' }, { key: 'roles.manage', label: 'Roles & permissions' }, { key: 'warehouses.manage', label: 'Warehouses' }, { key: 'settings.manage', label: 'Settings' }] },
]

const all = permissionGroups.flatMap(g => g.permissions.map(p => p.key))
export const roles: Role[] = [
  { id: 1, name: 'Admin', description: 'Full access to every module and setting', users: 2, permissions: all, maxDiscountPct: 100, system: true },
  { id: 2, name: 'Owner', description: 'Business owner: everything except system administration', users: 1, permissions: all.filter(p => !['roles.manage', 'settings.manage'].includes(p)), maxDiscountPct: 50 },
  { id: 3, name: 'Pharmacist', description: 'Counter sales, returns, stock and expiry handling', users: 3, permissions: ['dashboard.view', 'pos.sell', 'pos.override_batch', 'sales.view', 'returns.view', 'returns.create', 'products.view', 'inventory.view', 'inventory.count', 'customers.view', 'payments.collect', 'registers.view'], maxDiscountPct: 10 },
  { id: 4, name: 'Cashier', description: 'Counter sales only', users: 4, permissions: ['pos.sell', 'sales.view', 'products.view', 'customers.view', 'registers.view'], maxDiscountPct: 5 },
  { id: 5, name: 'Store keeper', description: 'Purchases, receiving and stock', users: 1, permissions: ['dashboard.view', 'products.view', 'products.create', 'products.edit', 'inventory.view', 'inventory.adjust', 'inventory.count', 'inventory.transfer', 'purchases.view', 'purchases.create', 'purchase_returns.view', 'purchase_returns.create', 'reorder.manage', 'suppliers.view', 'suppliers.manage'], maxDiscountPct: 0 },
]

export const users: StaffUser[] = [
  { id: 1, name: 'Fahim Rahman', username: 'fahim', email: 'owner@example.test', role: 'Admin', warehouses: ['Dhanmondi', 'Uttara'], isActive: true, lastLoginAt: new Date(now - 6e5).toISOString(), mustChangePassword: false },
  { id: 2, name: 'Sabbir Hossain', username: 'sabbir', email: 'sabbir@example.test', role: 'Pharmacist', warehouses: ['Dhanmondi'], isActive: true, lastLoginAt: new Date(now - 7e6).toISOString(), mustChangePassword: false },
  { id: 3, name: 'Moushumi Akter', username: 'moushumi', email: 'moushumi@example.test', role: 'Cashier', warehouses: ['Dhanmondi'], isActive: true, lastLoginAt: new Date(now - 9e7).toISOString(), mustChangePassword: true },
  { id: 4, name: 'Rakib Uddin', username: 'rakib', email: 'rakib@example.test', role: 'Store keeper', warehouses: ['Dhanmondi', 'Uttara'], isActive: true, lastLoginAt: new Date(now - 3e8).toISOString(), mustChangePassword: true },
  { id: 5, name: 'Tania Islam', username: 'tania', email: 'tania@example.test', role: 'Cashier', warehouses: ['Uttara'], isActive: false, lastLoginAt: null, mustChangePassword: true },
]

export const settings: PosSettings = {
  nearExpiryDays: 90, criticalExpiryDays: 30, allowNegativeStock: false, blockExpiredSale: true, roundTo: 0,
  receiptWidth: '80mm', receiptFooter: 'Medicines once sold are returnable only as per pharmacy policy. Thank you!',
  printOnComplete: true, rxReminder: true, defaultCustomerId: 1, pointsPerTaka: 0.01, pointValue: 1,
}
