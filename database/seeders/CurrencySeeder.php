<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;
use App\Models\Setting;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Currency::updateOrCreate(
            ['code' => 'MMK'],
            [
                'name' => 'Myanmar Kyat',
                'code' => 'MMK',
                'symbol' => 'Ks',
                'rate' => 1.0000,
                'is_default' => true,
                'status' => 'Active',
            ]
        );

        // Sync system settings
        Setting::updateOrCreate(['key' => 'default_currency'], ['value' => 'MMK']);
        Setting::updateOrCreate(['key' => 'currency_symbol'], ['value' => 'Ks']);
        Setting::updateOrCreate(['key' => 'currency_position'], ['value' => 'Suffix']);
        Setting::updateOrCreate(['key' => 'default_warehouse'], ['value' => 'defaultwarehouse']);
    }
}
