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
                    'Product List',
                    'Create Product',
                    'Product Details',
                    'Print Barcode',
                    'Product Categories',
                    'Sub Categories',
                    'Variations',
                    'Brands',
                    'Base Units',
                    'Sale Units',
                    'Purchase Units',
                    'Stock Adjustments',
                    'Create Adjustment',
                    'Stock Transfers',
                    'Create Transfer',
                    'Sales Orders',
                    'Create Sale',
                    'Sales Returns',
                    'Create Sales Return',
                    'Purchases List',
                    'Create Purchase',
                    'Purchase Returns',
                    'Create Purchase Return',
                    'Quotations List',
                    'Create Quotation',
                    'Expenses List',
                    'Expense Categories',
                    'Customers List',
                    'Customer Groups',
                    'Suppliers List',
                    'Supplier Categories',
                    'Payment Methods',
                    'Currencies',
                    'Executive Reports',
                    'Warehouses & Outlets',
                    'General Settings',
                    'Prefix Settings',
                    'Users & Staff',
                    'Roles & Permissions',
                ],
                'is_default' => true,
                'status' => 'Active',
            ]
        );

        // Delete any legacy non-super-admin roles so only Super Admin exists initially
        Role::where('slug', '!=', 'super_admin')->delete();

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
