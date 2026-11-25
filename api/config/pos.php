<?php

/*
 * POS domain configuration. Permission names match the Nuxt POS navigation
 * (pos/app/config/navigation.ts); the API enforces them on every route.
 */
return [
    'permissions' => [
        'dashboard.view',
        'pos.sell', 'pos.custom_item', 'pos.override_batch',
        'sales.view', 'sales.void', 'returns.view', 'returns.create',
        'products.view', 'products.create', 'products.edit', 'products.price', 'products.print_barcode', 'catalog.manage',
        'inventory.view', 'inventory.adjust', 'inventory.count', 'inventory.transfer', 'reorder.manage',
        'purchases.view', 'purchases.create', 'purchase_returns.view', 'purchase_returns.create',
        'customers.view', 'customers.manage', 'customer_groups.manage', 'suppliers.view', 'suppliers.manage',
        'payments.collect', 'payments.pay_supplier',
        'orders.view', 'orders.fulfil', 'riders.manage', 'zones.manage',
        'loyalty.view', 'loyalty.manage',
        'accounts.view', 'accounts.transfer', 'accounts.reports', 'expenses.view', 'income.view',
        'hr.view', 'hr.manage', 'hr.attendance', 'hr.payroll',
        'reports.sales', 'reports.purchases', 'reports.stock', 'reports.profit', 'reports.payments',
        'reports.customers', 'reports.suppliers', 'reports.users',
        'registers.view', 'users.view', 'roles.manage', 'warehouses.manage', 'settings.manage',
    ],

    // Admin gets every permission through Gate::before; other roles are explicit.
    'roles' => [
        'Manager' => ['*except' => ['roles.manage', 'settings.manage', 'hr.payroll']],
        'Cashier' => [
            'dashboard.view', 'pos.sell', 'sales.view', 'returns.view', 'returns.create',
            'products.view', 'inventory.view', 'customers.view', 'customers.manage', 'payments.collect', 'registers.view',
        ],
        'Inventory' => [
            'dashboard.view', 'products.view', 'products.create', 'products.edit', 'inventory.view', 'inventory.adjust',
            'inventory.count', 'inventory.transfer', 'reorder.manage', 'purchases.view', 'purchases.create',
            'purchase_returns.view', 'purchase_returns.create', 'suppliers.view', 'suppliers.manage', 'reports.stock', 'reports.purchases',
        ],
    ],

    // Administration › Roles: every permission above, grouped and labelled. Keep in step with 'permissions'.
    'permission_groups' => [
        'Dashboard' => ['dashboard.view' => 'View dashboard'],
        'POS' => ['pos.sell' => 'Sell at the counter', 'pos.custom_item' => 'Sell custom (non-catalogue) items', 'pos.override_batch' => 'Choose a batch instead of FEFO'],
        'Sales' => ['sales.view' => 'View sales', 'sales.void' => 'Void a sale (same session)', 'returns.view' => 'View returns', 'returns.create' => 'Process returns and refunds'],
        'Products' => ['products.view' => 'View products', 'products.create' => 'Create products', 'products.edit' => 'Edit products', 'products.price' => 'Change selling prices',
            'products.print_barcode' => 'Print barcode labels', 'catalog.manage' => 'Manage generics, manufacturers, forms, categories'],
        'Inventory' => ['inventory.view' => 'View stock and batches', 'inventory.adjust' => 'Post adjustments', 'inventory.count' => 'Stock counts', 'inventory.transfer' => 'Transfers', 'reorder.manage' => 'Reorder and purchase-order drafts'],
        'Purchases' => ['purchases.view' => 'View purchases', 'purchases.create' => 'Receive and order purchases', 'purchase_returns.view' => 'View purchase returns', 'purchase_returns.create' => 'Post and void purchase returns'],
        'People' => ['customers.view' => 'View customers', 'customers.manage' => 'Manage customers', 'customer_groups.manage' => 'Manage customer groups and discounts',
            'payments.collect' => 'Collect customer dues', 'suppliers.view' => 'View suppliers', 'suppliers.manage' => 'Manage suppliers', 'payments.pay_supplier' => 'Pay suppliers'],
        'Online orders' => ['orders.view' => 'View online orders', 'orders.fulfil' => 'Place and fulfil orders', 'riders.manage' => 'Manage riders and COD cash', 'zones.manage' => 'Manage shipping zones'],
        'Loyalty' => ['loyalty.view' => 'View rewards and memberships', 'loyalty.manage' => 'Adjust points, plans and members'],
        'Finance' => ['registers.view' => 'Cash registers', 'accounts.view' => 'Accounts', 'accounts.transfer' => 'Money transfers', 'expenses.view' => 'Expenses', 'income.view' => 'Income', 'accounts.reports' => 'Balance sheet'],
        'HR' => ['hr.view' => 'View employees', 'hr.manage' => 'Manage employees and holidays', 'hr.attendance' => 'Attendance', 'hr.payroll' => 'Payroll and salary advances'],
        'Reports' => ['reports.sales' => 'Sales', 'reports.profit' => 'Profit and loss', 'reports.stock' => 'Stock and expiry', 'reports.purchases' => 'Purchases',
            'reports.customers' => 'Customers', 'reports.suppliers' => 'Suppliers', 'reports.payments' => 'Payments', 'reports.users' => 'Cashiers and registers'],
        'Administration' => ['users.view' => 'View users', 'roles.manage' => 'Users, roles and permissions', 'warehouses.manage' => 'Warehouses', 'settings.manage' => 'Settings'],
    ],

    // Roles that cannot be renamed, edited or deleted.
    'system_roles' => ['Admin'],
];
