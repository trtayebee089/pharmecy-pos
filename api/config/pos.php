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
        'purchases.view', 'purchases.create', 'purchase_returns.view',
        'customers.view', 'customers.manage', 'suppliers.view', 'suppliers.manage',
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
            'purchase_returns.view', 'suppliers.view', 'suppliers.manage', 'reports.stock', 'reports.purchases',
        ],
    ],

    // Days ahead that count as "near expiry" in dashboards.
    'near_expiry_days' => 90,
];
