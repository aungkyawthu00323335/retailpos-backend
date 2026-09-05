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
        // 1. Seed Retail Roles & InfyPOS Permissions
        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Full unconstrained access to all retail POS modules, multi-warehouses, finance, and system settings.',
                'color' => '#7C3AED',
                'permissions' => [
                    'Dashboard',
                    'POS Terminal',
                    'Products & Barcode',
                    'Brands & Categories',
                    'Sales & Returns',
                    'Quotations',
                    'Purchases & Returns',
                    'Inventory Transfers & Adjustments',
                    'Expenses',
                    'Customers & CRM',
                    'Suppliers',
                    'Executive Reports',
                    'Settings & Multi-Currency',
                    'User & Roles RBAC',
                ],
                'is_default' => true,
                'status' => 'Active',
            ]
        );

        $manager = Role::updateOrCreate(
            ['slug' => 'manager'],
            [
                'name' => 'Store Manager',
                'description' => 'Branch operations, store staff management, retail pricing, purchasing & sales analytics.',
                'color' => '#2563EB',
                'permissions' => [
                    'Dashboard',
                    'POS Terminal',
                    'Products & Barcode',
                    'Brands & Categories',
                    'Sales & Returns',
                    'Quotations',
                    'Purchases & Returns',
                    'Inventory Transfers & Adjustments',
                    'Expenses',
                    'Customers & CRM',
                    'Suppliers',
                    'Executive Reports',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        $cashier = Role::updateOrCreate(
            ['slug' => 'cashier'],
            [
                'name' => 'Cashier',
                'description' => 'POS counter cashier terminal, barcode scanning, tender payments checkout & receipt printing.',
                'color' => '#059669',
                'permissions' => [
                    'POS Terminal',
                    'Sales Receipts',
                    'Quotations',
                    'Customers & CRM',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        $warehouseManager = Role::updateOrCreate(
            ['slug' => 'warehouse_manager'],
            [
                'name' => 'Inventory & Warehouse Manager',
                'description' => 'Multi-warehouse stock counts, physical audits, inter-branch transfers & barcode labels.',
                'color' => '#D97706',
                'permissions' => [
                    'Dashboard',
                    'Products & Barcode',
                    'Brands & Categories',
                    'Purchases & Returns',
                    'Inventory Transfers & Adjustments',
                    'Suppliers',
                    'Executive Reports',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        $purchasing = Role::updateOrCreate(
            ['slug' => 'purchasing_officer'],
            [
                'name' => 'Procurement Officer',
                'description' => 'Vendor purchase orders, goods receiving restock & supplier account balances.',
                'color' => '#E11D48',
                'permissions' => [
                    'Purchases & Returns',
                    'Suppliers',
                    'Products & Barcode',
                    'Executive Reports',
                ],
                'is_default' => false,
                'status' => 'Active',
            ]
        );

        // 2. Seed ONLY ONE Super Admin User (as requested)
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
