<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\SupplierCategory;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Default Supplier Category
        SupplierCategory::firstOrCreate(
            ['name' => 'General'],
            [
                'color' => '#1D5DE8',
                'description' => 'General supplier category for retail procurement',
                'status' => 'Active',
            ]
        );

        // 2. Default Walk-in Supplier
        Supplier::updateOrCreate(
            ['name' => 'Walk-in Supplier'],
            [
                'contact_person' => 'Walk-in Supplier',
                'phone' => '0900000000',
                'email' => 'supplier@pos.com',
                'category' => 'General',
                'country' => 'Myanmar',
                'city' => 'Yangon',
                'address' => 'Direct Retail Procurement',
                'balance_due' => 0.00,
                'payment_terms' => 'Cash On Delivery',
                'status' => 'Active',
                'notes' => 'Default Walk-in supplier for spot purchases and cash goods receipts',
            ]
        );
    }
}
