import type { Component } from 'vue'
import {
  LayoutDashboard, ScanBarcode, Receipt, Pill, Boxes, Truck, Users, HeartHandshake,
  Bike, Landmark, BarChart3, UserCog, Settings2,
} from 'lucide-vue-next'

/**
 * Sidebar generated from the verified navigation inventory
 * (docs/pos-navigation-inventory.md), regrouped for the rebuilt modules
 * (docs/rebuild/rebuild-architecture.md §1). Every item carries the permission
 * that gates it; the API enforces the same permission server-side.
 *
 * `ready: false` items route to an honest "planned" page until their phase ships.
 */
export interface NavItem {
  label: string
  to: string
  permission: string
  ready?: boolean
  phase?: string
  badge?: 'nearExpiry' | 'lowStock' | 'onlineOrders'
  legacy?: string // legacy menu it replaces (for traceability)
}

export interface NavGroup {
  label: string
  icon: Component
  to?: string // single-link group
  permission?: string
  ready?: boolean
  phase?: string
  children?: NavItem[]
}

export const navigation: NavGroup[] = [
  { label: 'Dashboard', icon: LayoutDashboard, to: '/dashboard', permission: 'dashboard.view', ready: true },
  { label: 'POS Counter', icon: ScanBarcode, to: '/pos', permission: 'pos.sell', ready: true },
  {
    label: 'Sales', icon: Receipt,
    children: [
      { label: 'All sales', to: '/sales', permission: 'sales.view', ready: true, legacy: 'Sale › Sale List' },
      { label: 'Held sales', to: '/sales/held', permission: 'sales.view', phase: 'D' },
      { label: 'Sale returns', to: '/sales/returns', permission: 'returns.view', phase: 'D', legacy: 'Return › Sale' },
      { label: 'Online orders', to: '/orders', permission: 'orders.view', phase: 'E', badge: 'onlineOrders', legacy: 'Online Orders' },
    ],
  },
  {
    label: 'Products', icon: Pill,
    children: [
      { label: 'Product list', to: '/products', permission: 'products.view', ready: true, legacy: 'Product › Product List' },
      { label: 'Add product', to: '/products/new', permission: 'products.create', phase: 'B', legacy: 'Product › Add Product' },
      { label: 'Generics', to: '/catalog/generics', permission: 'catalog.manage', phase: 'B' },
      { label: 'Manufacturers', to: '/catalog/manufacturers', permission: 'catalog.manage', phase: 'B', legacy: 'Settings › Brand' },
      { label: 'Dosage forms', to: '/catalog/dosage-forms', permission: 'catalog.manage', phase: 'B', legacy: 'Product › Category' },
      { label: 'Categories', to: '/catalog/categories', permission: 'catalog.manage', phase: 'B' },
      { label: 'Barcode labels', to: '/catalog/labels', permission: 'products.print_barcode', phase: 'B', legacy: 'Product › Print Barcode' },
    ],
  },
  {
    label: 'Inventory', icon: Boxes,
    children: [
      { label: 'Batches & expiry', to: '/inventory/batches', permission: 'inventory.view', ready: true, badge: 'nearExpiry' },
      { label: 'Stock overview', to: '/inventory/stock', permission: 'inventory.view', phase: 'B', badge: 'lowStock', legacy: 'Reports › Warehouse Stock' },
      { label: 'Adjustments', to: '/inventory/adjustments', permission: 'inventory.adjust', phase: 'B', legacy: 'Product › Adjustment List' },
      { label: 'Stock counts', to: '/inventory/counts', permission: 'inventory.count', phase: 'B', legacy: 'Product › Stock Count' },
      { label: 'Transfers', to: '/inventory/transfers', permission: 'inventory.transfer', phase: 'B', legacy: 'Transfer' },
    ],
  },
  {
    label: 'Purchases', icon: Truck,
    children: [
      { label: 'All purchases', to: '/purchases', permission: 'purchases.view', ready: true, legacy: 'Purchase › Purchase List' },
      { label: 'Receive purchase', to: '/purchases/new', permission: 'purchases.create', phase: 'C', legacy: 'Purchase › Add Purchase' },
      { label: 'Purchase returns', to: '/purchases/returns', permission: 'purchase_returns.view', phase: 'C', legacy: 'Return › Purchase' },
      { label: 'Reorder drafts (AI)', to: '/purchases/reorder', permission: 'reorder.manage', phase: 'C', legacy: 'Reports › Order Qty Alert' },
    ],
  },
  {
    label: 'People', icon: Users,
    children: [
      { label: 'Customers', to: '/customers', permission: 'customers.view', ready: true, legacy: 'People › Customer List' },
      { label: 'Customer groups', to: '/customers/groups', permission: 'customers.manage', phase: 'E' },
      { label: 'Due collection', to: '/customers/due', permission: 'payments.collect', phase: 'E', legacy: 'Reports › Customer Due' },
      { label: 'Suppliers', to: '/suppliers', permission: 'suppliers.view', phase: 'C', legacy: 'People › Supplier List' },
    ],
  },
  {
    label: 'Loyalty', icon: HeartHandshake,
    children: [
      { label: 'Reward wallets', to: '/loyalty/rewards', permission: 'loyalty.view', phase: 'E', legacy: 'Customer Ecosystem › Rewards' },
      { label: 'Membership plans', to: '/loyalty/memberships', permission: 'loyalty.manage', phase: 'E', legacy: 'Customer Ecosystem › Membership' },
    ],
  },
  {
    label: 'Delivery', icon: Bike,
    children: [
      { label: 'Fulfilment board', to: '/delivery', permission: 'orders.fulfil', phase: 'E', legacy: 'Online Orders › Delivery List' },
      { label: 'Riders', to: '/delivery/riders', permission: 'riders.manage', phase: 'E', legacy: 'People › Riders' },
      { label: 'Shipping zones', to: '/delivery/zones', permission: 'zones.manage', phase: 'E', legacy: 'Settings › Shipping Zones' },
    ],
  },
  {
    label: 'Finance', icon: Landmark,
    children: [
      { label: 'Cash registers', to: '/finance/registers', permission: 'registers.view', phase: 'D', legacy: 'Cash Register' },
      { label: 'Accounts', to: '/finance/accounts', permission: 'accounts.view', phase: 'F', legacy: 'Accounting › Account List' },
      { label: 'Money transfers', to: '/finance/transfers', permission: 'accounts.transfer', phase: 'F', legacy: 'Accounting › Money Transfer' },
      { label: 'Expenses', to: '/finance/expenses', permission: 'expenses.view', phase: 'F', legacy: 'Expense' },
      { label: 'Income', to: '/finance/income', permission: 'income.view', phase: 'F', legacy: 'Income' },
      { label: 'Balance sheet', to: '/finance/balance-sheet', permission: 'accounts.reports', phase: 'F', legacy: 'Accounting › Balance Sheet' },
    ],
  },
  {
    label: 'Reports', icon: BarChart3,
    children: [
      { label: 'Sales report', to: '/reports/sales', permission: 'reports.sales', phase: 'G', legacy: 'Reports › Sale Report' },
      { label: 'Profit & loss', to: '/reports/profit', permission: 'reports.profit', phase: 'G', legacy: 'Reports › Summary / Profit' },
      { label: 'Stock & valuation', to: '/reports/stock', permission: 'reports.stock', phase: 'G', legacy: 'Reports › Product Report' },
      { label: 'Expiry report', to: '/reports/expiry', permission: 'reports.stock', phase: 'G', legacy: 'Reports › Product Expiry' },
      { label: 'Purchase report', to: '/reports/purchases', permission: 'reports.purchases', phase: 'G', legacy: 'Reports › Purchase Report' },
      { label: 'Customer & due', to: '/reports/customers', permission: 'reports.customers', phase: 'G', legacy: 'Reports › Customer Report' },
      { label: 'Supplier & due', to: '/reports/suppliers', permission: 'reports.suppliers', phase: 'G', legacy: 'Reports › Supplier Report' },
      { label: 'Payments', to: '/reports/payments', permission: 'reports.payments', phase: 'G', legacy: 'Reports › Payment Report' },
      { label: 'Cashier & register', to: '/reports/cashiers', permission: 'reports.users', phase: 'G', legacy: 'Reports › User Report' },
    ],
  },
  {
    label: 'HR', icon: UserCog,
    children: [
      { label: 'Employees', to: '/hr/employees', permission: 'hr.view', phase: 'F', legacy: 'HRM › Employee' },
      { label: 'Attendance', to: '/hr/attendance', permission: 'hr.attendance', phase: 'F', legacy: 'HRM › Attendance' },
      { label: 'Holidays', to: '/hr/holidays', permission: 'hr.manage', phase: 'F', legacy: 'HRM › Holiday' },
      { label: 'Payroll', to: '/hr/payroll', permission: 'hr.payroll', phase: 'F', legacy: 'HRM › Payroll' },
    ],
  },
  {
    label: 'Administration', icon: Settings2,
    children: [
      { label: 'Users', to: '/admin/users', permission: 'users.view', phase: 'A', legacy: 'People › User List' },
      { label: 'Roles & permissions', to: '/admin/roles', permission: 'roles.manage', phase: 'A', legacy: 'Settings › Role Permission' },
      { label: 'Warehouses', to: '/admin/warehouses', permission: 'warehouses.manage', phase: 'A', legacy: 'Settings › Warehouse' },
      { label: 'Settings', to: '/admin/settings', permission: 'settings.manage', phase: 'A', legacy: 'Settings › General / POS' },
    ],
  },
]

/** Flat lookup for breadcrumbs, the command palette and planned-page metadata. */
export function flatNav(): (NavItem & { group: string })[] {
  return navigation.flatMap(g =>
    g.children
      ? g.children.map(c => ({ ...c, group: g.label }))
      : [{ label: g.label, to: g.to!, permission: g.permission!, ready: g.ready, phase: g.phase, group: g.label }],
  )
}

export const phaseNames: Record<string, string> = {
  A: 'Foundations', B: 'Catalog & inventory', C: 'Purchasing', D: 'POS & sales',
  E: 'Parties, loyalty & online orders', F: 'Finance & HR', G: 'Reports & dashboard',
}
