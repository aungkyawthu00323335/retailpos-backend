<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Outlet;

class OutletSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $outlets = [
            [
                'name' => 'Main Branch Outlet',
                'phone' => '+95 9 4500 1122',
                'email' => 'mainbranch@dreamspos.com',
                'address' => 'No. 124, Pyay Road, Kamayut Township, Yangon',
                'logo' => 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=150',
                'is_active' => true,
            ],
            [
                'name' => 'Downtown Bistro Outlet',
                'phone' => '+95 9 4211 8899',
                'email' => 'downtown@dreamspos.com',
                'address' => 'Level 2, Junction City, Pabedan Township, Yangon',
                'logo' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=150',
                'is_active' => true,
            ],
            [
                'name' => 'Airport Express Outlet',
                'phone' => '+95 9 7900 5544',
                'email' => 'airport@dreamspos.com',
                'address' => 'Terminal 1 Departure Hall, Yangon International Airport',
                'logo' => 'https://images.unsplash.com/photo-1552566626-52f8b828add9?w=150',
                'is_active' => true,
            ],
            [
                'name' => 'Mall Food Court Outlet',
                'phone' => '+95 9 9600 3311',
                'email' => 'mallfc@dreamspos.com',
                'address' => 'Food Court #4, Myanmar Plaza, Bahan Township, Yangon',
                'logo' => 'https://images.unsplash.com/photo-1537047902294-62a40c20a6ae?w=150',
                'is_active' => false,
            ],
        ];

        foreach ($outlets as $outletData) {
            Outlet::create($outletData);
        }
    }
}
