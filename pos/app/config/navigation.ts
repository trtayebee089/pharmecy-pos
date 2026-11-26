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
      { label: 'Held sales', to: '/sales/held', permission: 'sales.view', ready: true },
      { label: 'Sale returns', to: '/sales/returns', permission: 'returns.view', ready: true, legacy: 'Return › Sale' },
      { label: 'Online orders', to: '/orders', permission: 'orders.view', ready: true, badge: 'onlineOrders', legacy: 'Online Orders' },
      { label: 'Medicine requests', to: '/orders/requests', permission: 'orders.view', ready: true, legacy: 'Website › Request order' },
    ],
  },
  {
    label: 'Products', icon: Pill,
    children: [
      { label: 'Product list', to: '/products', permission: 'products.view', ready: true, legacy: 'Product › Product List' },
      { label: 'Add product', to: '/products/new', permission: 'products.create', ready: true, legacy: 'Product › Add Product' },
      { label: 'Generics', to: '/catalog/generics', permission: 'catalog.manage', ready: true },
      { label: 'Manufacturers', to: '/catalog/manufacturers', permission: 'catalog.manage', ready: true, legacy: 'Settings › Brand' },
      { label: 'Dosage forms', to: '/catalog/dosage-forms', permission: 'catalog.manage', ready: true, legacy: 'Product › Category' },
      { label: 'Categories', to: '/catalog/categories', permission: 'catalog.manage', ready: true },
      { label: 'Barcode labels', to: '/catalog/labels', permission: 'products.print_barcode', ready: true, legacy: 'Product › Print Barcode' },
    ],
  },
  {
    label: 'Inventory', icon: Boxes,
    children: [
      { label: 'Batches & expiry', to: '/inventory/batches', permission: 'inventory.view', ready: true, badge: 'nearExpiry' },
      { label: 'Stock overview', to: '/inventory/stock', permission: 'inventory.view', ready: true, badge: 'lowStock', legacy: 'Reports › Warehouse Stock' },
      { label: 'Adjustments', to: '/inventory/adjustments', permission: 'inventory.adjust', ready: true, legacy: 'Product › Adjustment List' },
      { label: 'Stock counts', to: '/inventory/counts', permission: 'inventory.count', ready: true, legacy: 'Product › Stock Count' },
      { label: 'Transfers', to: '/inventory/transfers', permission: 'inventory.transfer', ready: true, legacy: 'Transfer' },
    ],
  },
  {
    label: 'Purchases', icon: Truck,
    children: [
      { label: 'All purchases', to: '/purchases', permission: 'purchases.view', ready: true, legacy: 'Purchase › Purchase List' },
      { label: 'New purchase', to: '/purchases/new', permission: 'purchases.create', ready: true, legacy: 'Purchase › Add Purchase' },
      { label: 'Purchase orders', to: '/purchases/orders', permission: 'reorder.manage', ready: true, legacy: 'Reports › Product Order Report' },
      { label: 'Draft orders', to: '/purchases/orders/drafts', permission: 'reorder.manage', ready: true, legacy: 'Product Order Report › View Draft Orders' },
      { label: 'Purchase returns', to: '/purchases/returns', permission: 'purchase_returns.view', ready: true, legacy: 'Return › Purchase' },
    ],
  },
  {
    label: 'People', icon: Users,
    children: [
      { label: 'Customers', to: '/customers', permission: 'customers.view', ready: true, legacy: 'People › Customer List' },
      { label: 'Customer groups', to: '/customers/groups', permission: 'customer_groups.manage', ready: true },
      { label: 'Due collection', to: '/customers/due', permission: 'payments.collect', ready: true, legacy: 'Reports › Customer Due' },
      { label: 'Suppliers', to: '/suppliers', permission: 'suppliers.view', ready: true, legacy: 'People › Supplier List' },
    ],
  },
  {
    label: 'Loyalty', icon: HeartHandshake,
    children: [
      { label: 'Reward wallets', to: '/loyalty/rewards', permission: 'loyalty.view', ready: true, legacy: 'Customer Ecosystem › Rewards' },
      { label: 'Membership plans', to: '/loyalty/memberships', permission: 'loyalty.manage', ready: true, legacy: 'Customer Ecosystem › Membership' },
    ],
  },
  {
    label: 'Delivery', icon: Bike,
    children: [
      { label: 'Fulfilment board', to: '/delivery', permission: 'orders.fulfil', ready: true, legacy: 'Online Orders › Delivery List' },
      { label: 'Live map', to: '/delivery/live', permission: 'orders.view', ready: true },
      { label: 'Riders', to: '/delivery/riders', permission: 'riders.manage', ready: true, legacy: 'People › Riders' },
      { label: 'Shipping zones', to: '/delivery/zones', permission: 'zones.manage', ready: true, legacy: 'Settings › Shipping Zones' },
    ],
  },
  {
    label: 'Finance', icon: Landmark,
    children: [
      { label: 'Cash registers', to: '/finance/registers', permission: 'registers.view', ready: true, legacy: 'Cash Register' },
      { label: 'Accounts', to: '/finance/accounts', permission: 'accounts.view', ready: true, legacy: 'Accounting › Account List' },
      { label: 'Money transfers', to: '/finance/transfers', permission: 'accounts.transfer', ready: true, legacy: 'Accounting › Money Transfer' },
      { label: 'Expenses', to: '/finance/expenses', permission: 'expenses.view', ready: true, legacy: 'Expense' },
      { label: 'Income', to: '/finance/income', permission: 'income.view', ready: true, legacy: 'Income' },
      { label: 'Balance sheet', to: '/finance/balance-sheet', permission: 'accounts.reports', ready: true, legacy: 'Accounting › Balance Sheet' },
    ],
  },
  {
    label: 'Reports', icon: BarChart3,
    children: [
      { label: 'Sales report', to: '/reports/sales', permission: 'reports.sales', ready: true, legacy: 'Reports › Sale Report' },
      { label: 'Profit & loss', to: '/reports/profit', permission: 'reports.profit', ready: true, legacy: 'Reports › Summary / Profit' },
      { label: 'Stock & valuation', to: '/reports/stock', permission: 'reports.stock', ready: true, legacy: 'Reports › Product Report' },
      { label: 'Expiry report', to: '/reports/expiry', permission: 'reports.stock', ready: true, legacy: 'Reports › Product Expiry' },
      { label: 'Purchase report', to: '/reports/purchases', permission: 'reports.purchases', ready: true, legacy: 'Reports › Purchase Report' },
      { label: 'Monthly summary', to: '/reports/monthly-summary', permission: 'reports.profit', ready: true, legacy: 'Reports › Summary Report' },
      { label: 'Customer report', to: '/reports/customer', permission: 'reports.customers', ready: true, legacy: 'Reports › Customer Report' },
      { label: 'Customer due', to: '/reports/customers', permission: 'reports.customers', ready: true, legacy: 'Reports › Customer Due Report' },
      { label: 'Biller report', to: '/reports/billers', permission: 'reports.sales', ready: true, legacy: 'Reports › Biller Report' },
      { label: 'Custom product sales', to: '/reports/custom-products', permission: 'reports.sales', ready: true, legacy: 'Reports › Custom Product Sales Report' },
      { label: 'Supplier & due', to: '/reports/suppliers', permission: 'reports.suppliers', ready: true, legacy: 'Reports › Supplier Report' },
      { label: 'Payments', to: '/reports/payments', permission: 'reports.payments', ready: true, legacy: 'Reports › Payment Report' },
      { label: 'Cashier & register', to: '/reports/cashiers', permission: 'reports.users', ready: true, legacy: 'Reports › User Report' },
    ],
  },
  {
    label: 'HR', icon: UserCog,
    children: [
      { label: 'Employees', to: '/hr/employees', permission: 'hr.view', ready: true, legacy: 'HRM › Employee' },
      { label: 'Attendance', to: '/hr/attendance', permission: 'hr.attendance', ready: true, legacy: 'HRM › Attendance' },
      { label: 'Holidays', to: '/hr/holidays', permission: 'hr.manage', ready: true, legacy: 'HRM › Holiday' },
      { label: 'Payroll', to: '/hr/payroll', permission: 'hr.payroll', ready: true, legacy: 'HRM › Payroll' },
    ],
  },
  {
    label: 'Administration', icon: Settings2,
    children: [
      { label: 'Users', to: '/admin/users', permission: 'users.view', ready: true, legacy: 'People › User List' },
      { label: 'Roles & permissions', to: '/admin/roles', permission: 'roles.manage', ready: true, legacy: 'Settings › Role Permission' },
      { label: 'Warehouses', to: '/admin/warehouses', permission: 'warehouses.manage', ready: true, legacy: 'Settings › Warehouse' },
      { label: 'POS settings', to: '/admin/pos-settings', permission: 'settings.manage', ready: true, legacy: 'Settings › POS Setting' },
      { label: 'Reward points', to: '/admin/reward-points', permission: 'settings.manage', ready: true, legacy: 'Settings › Reward Point Setting' },
      { label: 'General settings', to: '/admin/settings', permission: 'settings.manage', ready: true, legacy: 'Settings › General Setting' },
      { label: 'Integrations', to: '/admin/integrations', permission: 'settings.manage', ready: true, legacy: 'Settings › Mail / SMS / Payment Gateway Setting' },
      { label: 'Website banners', to: '/admin/banners', permission: 'settings.manage', ready: true, legacy: 'Settings › Slider' },
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
