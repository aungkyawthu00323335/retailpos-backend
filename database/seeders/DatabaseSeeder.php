<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            OutletSeeder::class,
            RoleAndUserSeeder::class,
            RetailSeeder::class,
            Product200Seeder::class,
            PurchaseSeeder::class,
            SaleSeeder::class,
        ]);
    }
}
