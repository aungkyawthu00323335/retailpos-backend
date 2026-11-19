<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. All system permission keys organized by the 12 ERP business modules
        $allPermissions = [
            // 1. Dashboard
            'dashboard.view',

            // 2. Products
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost', 'products.export', 'products.import',
            'barcode.view', 'barcode.create',
            'product_categories.view', 'product_categories.create', 'product_categories.update', 'product_categories.delete',
            'sub_categories.view', 'sub_categories.create', 'sub_categories.update', 'sub_categories.delete',
            'variations.view', 'variations.create', 'variations.update', 'variations.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'units.view', 'units.create', 'units.update', 'units.delete',

            // 3. Adjustments
            'adjustments.view', 'adjustments.create', 'adjustments.update', 'adjustments.delete', 'adjustments.approve', 'adjustments.export',

            // 4. Quotations
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.delete', 'quotations.convert_sale', 'quotations.export',

            // 5. Purchases
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.pay', 'purchases.export',
            'purchase_returns.view', 'purchase_returns.create', 'purchase_returns.update', 'purchase_returns.delete', 'purchase_returns.pay', 'purchase_returns.export',

            // 6. Sales & POS
            'sales.view', 'sales.create', 'sales.update', 'sales.delete', 'sales.void', 'sales.refund', 'sales.pay', 'sales.export',
            'pos.view', 'pos.create', 'pos.checkout', 'pos.discount',
            'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop', 'cash_register.view_expected',
            'sale_returns.view', 'sale_returns.create', 'sale_returns.update', 'sale_returns.delete', 'sale_returns.pay', 'sale_returns.export',

            // 7. Transfers
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete', 'transfers.receive', 'transfers.pay', 'transfers.export',

            // 8. Expenses
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete', 'expenses.pay', 'expenses.export',
            'expense_categories.view', 'expense_categories.create', 'expense_categories.update', 'expense_categories.delete',

            // 9. Processing
            'processings.view', 'processings.create', 'processings.update', 'processings.delete', 'processings.export',

            // 10. Peoples
            'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.export', 'customers.import',
            'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.export', 'suppliers.import',
            'supplier_categories.view', 'supplier_categories.create', 'supplier_categories.update', 'supplier_categories.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',

            // 11. Reports
            'reports.view', 'reports.export',
            'report_sales.view', 'report_sales.export', 'reports.sales',
            'report_purchases.view', 'report_purchases.export', 'reports.purchases',
            'report_stock.view', 'report_stock.export', 'reports.stock',
            'report_stock_alert.view', 'report_stock_alert.export', 'reports.stock_alert',
            'report_stock_ledger.view', 'report_stock_ledger.export', 'reports.stock_ledger',
            'report_profit_loss.view', 'report_profit_loss.export', 'reports.profit_loss',
            'report_cashier_audit.view', 'report_cashier_audit.export', 'reports.cashier_audit',
            'report_expense.view', 'report_expense.export', 'reports.expense',
            'report_top_selling.view', 'report_top_selling.export', 'reports.top_selling',
            'report_dead_stock.view', 'report_dead_stock.export', 'reports.dead_stock',
            'report_cash_flow.view', 'report_cash_flow.export', 'reports.cash_flow',
            'report_register.view', 'report_register.export', 'reports.register',
            'report_z_report.view', 'report_z_report.export', 'reports.z_report',
            'report_ar_aging.view', 'report_ar_aging.export', 'reports.ar_aging',
            'report_ap_aging.view', 'report_ap_aging.export', 'reports.ap_aging',
            'report_product_margins.view', 'report_product_margins.export', 'reports.product_margins',
            'report_sales_return.view', 'report_sales_return.export', 'reports.sales_return',
            'report_purchase_return.view', 'report_purchase_return.export', 'reports.purchase_return',
            'report_commercial_tax.view', 'report_commercial_tax.export', 'reports.commercial_tax',
            'report_customer.view', 'report_customer.export', 'reports.customer',
            'report_supplier.view', 'report_supplier.export', 'reports.supplier',

            // 12. Settings / System
            'outlets.view', 'outlets.create', 'outlets.update', 'outlets.delete', 'outlets.manage',
            'currencies.view', 'currencies.create', 'currencies.update', 'currencies.delete', 'currencies.manage',
            'payment_methods.view', 'payment_methods.create', 'payment_methods.update', 'payment_methods.delete', 'payment_methods.manage',
            'prefix_settings.view', 'prefix_settings.update',
            'voucher_settings.view', 'voucher_settings.update',
            'general_settings.view', 'general_settings.update',
        ];

        // Legacy compatibility aliases for Super Admin
        $legacySuperAdmin = [
            '*',
            'Dashboard', 'POS Terminal', 'Product List', 'Create Product', 'Product Details',
            'Print Barcode', 'Product Categories', 'Sub Categories', 'Variations', 'Brands',
            'Base Units', 'Sale Units', 'Purchase Units', 'Stock Adjustments', 'Create Adjustment',
            'Stock Transfers', 'Create Transfer', 'Sales Orders', 'Create Sale', 'Sales Returns',
            'Create Sales Return', 'Purchases List', 'Create Purchase', 'Purchase Returns',
            'Create Purchase Return', 'Quotations List', 'Create Quotation', 'Expenses List',
            'Expense Categories', 'Customers List', 'Customer Groups', 'Suppliers List',
            'Supplier Categories', 'Payment Methods', 'Currencies', 'Executive Reports',
            'Warehouses & Outlets', 'General Settings', 'Prefix Settings', 'Voucher Settings',
            'Users & Staff', 'Roles & Permissions',
        ];

        // 1. Super Admin Role (Fully Access)
        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Full unconstrained access to all retail POS modules, multi-warehouses, finance, and system settings.',
                'color' => '#7C3AED',
                'permissions' => array_values(array_unique(array_merge($allPermissions, $legacySuperAdmin))),
                'is_default' => true,
                'status' => 'Active',
            ]
        );

        // 2. Store Manager Role
        $storeManagerPermissions = [
            'dashboard.view',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost', 'products.export', 'products.import',
            'barcode.view', 'barcode.create',
            'product_categories.view', 'product_categories.create', 'product_categories.update', 'product_categories.delete',
            'sub_categories.view', 'sub_categories.create', 'sub_categories.update', 'sub_categories.delete',
            'variations.view', 'variations.create', 'variations.update', 'variations.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'units.view', 'units.create', 'units.update', 'units.delete',
            'adjustments.view', 'adjustments.create', 'adjustments.update', 'adjustments.delete', 'adjustments.approve', 'adjustments.export',
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.delete', 'quotations.convert_sale', 'quotations.export',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.pay', 'purchases.export',
            'purchase_returns.view', 'purchase_returns.create', 'purchase_returns.update', 'purchase_returns.delete', 'purchase_returns.pay', 'purchase_returns.export',
            'sales.view', 'sales.create', 'sales.update', 'sales.delete', 'sales.void', 'sales.refund', 'sales.pay', 'sales.export',
            'pos.view', 'pos.create', 'pos.checkout', 'pos.discount',
            'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop', 'cash_register.view_expected',
            'sale_returns.view', 'sale_returns.create', 'sale_returns.update', 'sale_returns.delete', 'sale_returns.pay', 'sale_returns.export',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete', 'transfers.receive', 'transfers.pay', 'transfers.export',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.pay', 'expenses.export',
            'expense_categories.view', 'expense_categories.create', 'expense_categories.update',
            'processings.view', 'processings.create', 'processings.update', 'processings.export',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.export', 'customers.import',
            'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.export', 'suppliers.import',
            'supplier_categories.view', 'supplier_categories.create', 'supplier_categories.update', 'supplier_categories.delete',
            'users.view', 'users.create', 'users.update',
            'roles.view',
            'reports.view', 'reports.export', 'report_sales.view', 'report_purchases.view', 'report_stock.view',
            'report_profit_loss.view', 'report_cashier_audit.view', 'report_expense.view', 'report_top_selling.view',
        ];
        Role::updateOrCreate(
            ['slug' => 'store_manager'],
            [
                'name' => 'Store Manager',
                'description' => 'Comprehensive store operations, staff coordination, inventory control, and retail performance analytics.',
                'color' => '#2563EB',
                'permissions' => $storeManagerPermissions,
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 3. Cashier Role
        $cashierPermissions = [
            'dashboard.view',
            'pos.view', 'pos.create', 'pos.checkout',
            'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop',
            'sales.view', 'sales.create',
            'sale_returns.view', 'sale_returns.create',
            'customers.view', 'customers.create',
            'products.view', 'barcode.view',
            'report_sales.view', 'report_cashier_audit.view',
        ];
        Role::updateOrCreate(
            ['slug' => 'cashier'],
            [
                'name' => 'Cashier',
                'description' => 'Fast front-desk checkout, customer billing, and daily cash drawer reconciliation.',
                'color' => '#059669',
                'permissions' => $cashierPermissions,
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 4. Inventory Clerk Role
        $inventoryPermissions = [
            'dashboard.view',
            'products.view', 'products.create', 'products.update',
            'barcode.view', 'barcode.create',
            'product_categories.view', 'product_categories.create', 'product_categories.update',
            'sub_categories.view', 'sub_categories.create', 'sub_categories.update',
            'variations.view', 'variations.create', 'variations.update',
            'brands.view', 'brands.create', 'brands.update',
            'units.view', 'units.create', 'units.update',
            'adjustments.view', 'adjustments.create', 'adjustments.update',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.receive',
            'processings.view', 'processings.create', 'processings.update',
            'purchases.view', 'purchases.create', 'purchases.update',
            'purchase_returns.view', 'purchase_returns.create',
            'suppliers.view', 'suppliers.create', 'suppliers.update',
            'supplier_categories.view',
            'report_stock.view', 'report_stock_alert.view', 'report_stock_ledger.view',
        ];
        Role::updateOrCreate(
            ['slug' => 'inventory_clerk'],
            [
                'name' => 'Inventory Clerk',
                'description' => 'Stock warehouse control, purchase receiving, batch adjustments, and inventory tracking.',
                'color' => '#D97706',
                'permissions' => $inventoryPermissions,
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 5. Accountant Role
        $accountantPermissions = [
            'dashboard.view',
            'products.view', 'products.view_cost', 'products.export',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.pay', 'expenses.export',
            'expense_categories.view', 'expense_categories.create', 'expense_categories.update',
            'sales.view', 'sales.pay', 'sales.export',
            'sale_returns.view', 'sale_returns.pay', 'sale_returns.export',
            'purchases.view', 'purchases.pay', 'purchases.export',
            'purchase_returns.view', 'purchase_returns.pay', 'purchase_returns.export',
            'transfers.view', 'transfers.pay', 'transfers.export',
            'cash_register.view', 'cash_register.view_expected',
            'customers.view', 'customers.export', 'suppliers.view', 'suppliers.export',
            'currencies.view', 'payment_methods.view',
            'reports.view', 'reports.export',
            'report_sales.view', 'report_purchases.view', 'report_expense.view',
            'report_profit_loss.view', 'report_cash_flow.view',
            'report_ar_aging.view', 'report_ap_aging.view', 'report_product_margins.view',
            'report_cashier_audit.view', 'report_register.view', 'report_z_report.view',
            'report_sales_return.view', 'report_purchase_return.view',
            'report_commercial_tax.view', 'report_customer.view', 'report_supplier.view',
        ];
        Role::updateOrCreate(
            ['slug' => 'accountant'],
            [
                'name' => 'Accountant',
                'description' => 'Expense tracking, accounts receivable/payable, taxation reports, and general financial audit.',
                'color' => '#0284C7',
                'permissions' => $accountantPermissions,
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 2. Super Admin User
        User::where('email', 'admin@infypos.com')->delete();
        $admin = User::updateOrCreate(
            ['email' => 'admin@pos.com'],
            [
                'user_id' => '100001',
                'name' => 'Super Admin',
                'phone' => '+95 9 790 123 456',
                'password' => Hash::make('password'),
                'role_id' => $superAdmin->id,
                'role' => 'Super Admin',
                'branch' => 'defaultwarehouse',
                'avatar' => null,
                'status' => 'Active',
            ]
        );

        $activeOutletIds = Outlet::where('is_active', true)->pluck('id')->toArray();
        $admin->outlets()->sync($activeOutletIds);
    }
}