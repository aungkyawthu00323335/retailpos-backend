<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Outlet;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Outlet::updateOrCreate(
            ['name' => 'defaultwarehouse'],
            [
                'name' => 'defaultwarehouse',
                'phone' => '+95 9 1234 5678',
                'email' => 'warehouse@retailpos.com',
                'address' => 'Yangon, Myanmar',
                'manager_name' => 'Super Admin',
                'logo' => null,
                'is_active' => true,
            ]
        );
    }
}
