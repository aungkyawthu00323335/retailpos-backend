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