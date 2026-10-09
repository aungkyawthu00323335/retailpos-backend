<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Default customer group
        CustomerGroup::firstOrCreate(
            ['name' => 'General'],
            [
                'discount_percentage' => 0.00,
                'color' => '#1D5DE8',
                'description' => 'Default customer group for retail walk-in sales',
                'status' => 'Active',
            ]
        );

        // 2. Default Walk-in Customer (Walkincustomer)
        Customer::updateOrCreate(
            ['name' => 'Walk-in Customer'],
            [
                'customer_id' => 'CUST-001',
                'phone' => '0900000000',
                'email' => 'walkin@pos.com',
                'tier' => 'General',
                'reward_points' => 0,
                'total_spent' => 0.00,
                'total_visits' => 1,
                'avatar' => null,
                'status' => 'Active',
                'notes' => 'Default Walk-in customer for retail sales (Walkincustomer)',
            ]
        );
    }
}
