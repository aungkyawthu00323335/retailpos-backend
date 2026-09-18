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
        // 1. All system permission keys
        $allPermissions = [
            'dashboard.view', 'pos.view', 'pos.checkout',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost', 'products.export',
            'product_categories.view', 'product_categories.create', 'product_categories.update', 'product_categories.delete',
            'sub_categories.view', 'sub_categories.create', 'sub_categories.update', 'sub_categories.delete',
            'variations.view', 'variations.create', 'variations.update', 'variations.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'units.view', 'units.create', 'units.update', 'units.delete',
            'barcode.view', 'barcode.create',
            'adjustments.view', 'adjustments.create', 'adjustments.update', 'adjustments.delete', 'adjustments.approve',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete', 'transfers.receive',
            'sales.view', 'sales.create', 'sales.update', 'sales.delete', 'sales.void', 'sales.refund',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.pay',
            'purchase_returns.view', 'purchase_returns.create', 'purchase_returns.update', 'purchase_returns.delete',
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.delete',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            'expense_categories.view', 'expense_categories.create', 'expense_categories.update', 'expense_categories.delete',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.export',
            'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.export',
            'supplier_categories.view', 'supplier_categories.create', 'supplier_categories.update', 'supplier_categories.delete',
            'payment_methods.view', 'payment_methods.manage',
            'currencies.view', 'currencies.manage',
            'reports.view', 'reports.profit_loss', 'reports.commercial_tax', 'reports.export',
            'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop', 'cash_register.view_expected',
            'outlets.view', 'outlets.manage',
            'general_settings.view', 'general_settings.update',
            'prefix_settings.view', 'prefix_settings.update',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
        ];

        // Legacy compatibility aliases for Super Admin
        $legacySuperAdmin = [
            'Dashboard', 'POS Terminal', 'Product List', 'Create Product', 'Product Details',
            'Print Barcode', 'Product Categories', 'Sub Categories', 'Variations', 'Brands',
            'Base Units', 'Sale Units', 'Purchase Units', 'Stock Adjustments', 'Create Adjustment',
            'Stock Transfers', 'Create Transfer', 'Sales Orders', 'Create Sale', 'Sales Returns',
            'Create Sales Return', 'Purchases List', 'Create Purchase', 'Purchase Returns',
            'Create Purchase Return', 'Quotations List', 'Create Quotation', 'Expenses List',
            'Expense Categories', 'Customers List', 'Customer Groups', 'Suppliers List',
            'Supplier Categories', 'Payment Methods', 'Currencies', 'Executive Reports',
            'Warehouses & Outlets', 'General Settings', 'Prefix Settings', 'Users & Staff',
            'Roles & Permissions',
        ];

        // 1. Super Admin Role
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
        Role::updateOrCreate(
            ['slug' => 'store_manager'],
            [
                'name' => 'Store Manager',
                'description' => 'Autonomous branch management: Full control over store sales, inventory, shifts, staff, and branch reporting.',
                'color' => '#2563EB',
                'permissions' => [
                    'dashboard.view', 'pos.view', 'pos.checkout',
                    'products.view', 'products.create', 'products.update', 'products.view_cost', 'products.export',
                    'product_categories.view', 'product_categories.create', 'product_categories.update',
                    'sub_categories.view', 'sub_categories.create', 'sub_categories.update',
                    'variations.view', 'variations.create', 'variations.update',
                    'brands.view', 'brands.create', 'brands.update',
                    'units.view', 'units.create', 'units.update',
                    'barcode.view', 'barcode.create',
                    'adjustments.view', 'adjustments.create', 'adjustments.approve',
                    'transfers.view', 'transfers.create', 'transfers.update', 'transfers.receive',
                    'sales.view', 'sales.create', 'sales.update', 'sales.void', 'sales.refund',
                    'purchases.view', 'purchases.create', 'purchases.update', 'purchases.pay',
                    'purchase_returns.view', 'purchase_returns.create',
                    'quotations.view', 'quotations.create', 'quotations.update',
                    'expenses.view', 'expenses.create', 'expenses.update',
                    'expense_categories.view', 'expense_categories.create',
                    'customers.view', 'customers.create', 'customers.update',
                    'customer_groups.view', 'customer_groups.create',
                    'suppliers.view', 'suppliers.create', 'suppliers.update',
                    'supplier_categories.view', 'supplier_categories.create',
                    'payment_methods.view', 'currencies.view',
                    'reports.view', 'reports.export', 'reports.commercial_tax',
                    'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop', 'cash_register.view_expected',
                    'outlets.view', 'users.view', 'users.create', 'users.update',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 3. Floor Supervisor Role
        Role::updateOrCreate(
            ['slug' => 'floor_supervisor'],
            [
                'name' => 'Floor Supervisor',
                'description' => 'Shift oversight: Cashier support, Sale Voiding with PIN, Safe Drops, Stock Transfer receiving, and customer service.',
                'color' => '#0D9488',
                'permissions' => [
                    'dashboard.view', 'pos.view', 'pos.checkout',
                    'products.view', 'barcode.view', 'barcode.create',
                    'transfers.view', 'transfers.receive',
                    'sales.view', 'sales.create', 'sales.void', 'sales.refund',
                    'quotations.view', 'quotations.create',
                    'customers.view', 'customers.create', 'customers.update',
                    'cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 4. Cashier Role
        Role::updateOrCreate(
            ['slug' => 'cashier'],
            [
                'name' => 'Cashier',
                'description' => 'Front-counter checkout: Fast POS sales, receipt checkout, customer search/add, quotations, and blind till closing.',
                'color' => '#059669',
                'permissions' => [
                    'dashboard.view', 'pos.view', 'pos.checkout',
                    'products.view', // Retail selling price only, cost hidden
                    'sales.view', 'sales.create',
                    'quotations.view', 'quotations.create',
                    'customers.view', 'customers.create',
                    'cash_register.open_close', // Blind close: expected cash hidden
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 5. Inventory / Warehouse Clerk Role
        Role::updateOrCreate(
            ['slug' => 'inventory_clerk'],
            [
                'name' => 'Inventory Clerk',
                'description' => 'Stockroom control: Products catalog, barcode label printing, stock transfers dispatch/receive, and PO goods receipt.',
                'color' => '#D97706',
                'permissions' => [
                    'products.view', 'products.create', 'products.update',
                    'product_categories.view', 'sub_categories.view', 'variations.view', 'brands.view', 'units.view',
                    'barcode.view', 'barcode.create',
                    'adjustments.view', 'adjustments.create',
                    'transfers.view', 'transfers.create', 'transfers.receive',
                    'purchases.view', 'suppliers.view',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 6. Procurement Specialist Role
        Role::updateOrCreate(
            ['slug' => 'procurement_officer'],
            [
                'name' => 'Procurement Officer',
                'description' => 'Purchasing & supply: Supplier relationships, Purchase Orders (PO), Inbound tracking, and Purchase Returns.',
                'color' => '#EA580C',
                'permissions' => [
                    'products.view', 'products.view_cost',
                    'units.view', 'units.create', 'units.update',
                    'purchases.view', 'purchases.create', 'purchases.update',
                    'purchase_returns.view', 'purchase_returns.create',
                    'suppliers.view', 'suppliers.create', 'suppliers.update',
                    'supplier_categories.view', 'supplier_categories.create',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 7. Accountant / Auditor Role
        Role::updateOrCreate(
            ['slug' => 'accountant_auditor'],
            [
                'name' => 'Accountant / Auditor',
                'description' => 'Financial audits & compliance: Read-only access to all 15+ Reports, P&L, Commercial Tax VAT, Cash Flow, and Expense Ledgers.',
                'color' => '#4338CA',
                'permissions' => [
                    'dashboard.view',
                    'products.view', 'products.view_cost',
                    'sales.view', 'purchases.view', 'purchase_returns.view', 'quotations.view',
                    'expenses.view', 'expense_categories.view',
                    'customers.view', 'suppliers.view',
                    'payment_methods.view', 'currencies.view',
                    'reports.view', 'reports.profit_loss', 'reports.commercial_tax', 'reports.export',
                    'cash_register.view', 'cash_register.view_expected',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 2. Seed Super Admin User (admin@infypos.com)
        $admin = User::updateOrCreate(
            ['email' => 'admin@infypos.com'],
            [
                'name' => 'Super Admin',
                'phone' => '+95 9 790 123 456',
                'password' => Hash::make('admin123'),
                'role_id' => $superAdmin->id,
                'role' => 'Super Admin',
                'branch' => 'All Outlets & Warehouses',
                'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
                'status' => 'Active',
            ]
        );

        // Attach all active outlets to Super Admin
        $activeOutletIds = Outlet::where('is_active', true)->pluck('id')->toArray();
        $admin->outlets()->sync($activeOutletIds);
    }
}