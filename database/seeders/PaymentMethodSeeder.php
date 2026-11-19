<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Seed default payment methods into the database.
     */
    public function run(): void
    {
        // 1. Default Cash Payment Method (Required by ERP & POS)
        PaymentMethod::updateOrCreate(
            ['code' => 'CASH'],
            [
                'name' => 'Cash',
                'icon' => 'payments_rounded',
                'is_active' => true,
                'is_default' => true,
                'account_number' => null,
                'account_name' => null,
                'description' => 'Default cash payment method for in-store POS and COD',
            ]
        );

        // 2. KBZPay (Popular Mobile Banking in Myanmar)
        PaymentMethod::firstOrCreate(
            ['name' => 'KBZPay'],
            [
                'code' => 'KPAY',
                'icon' => 'qr_code_scanner_rounded',
                'is_active' => true,
                'is_default' => false,
                'account_number' => '09123456789',
                'account_name' => 'Ah Linn Thit Store',
                'description' => 'KBZPay ဖြင့် ငွေလွှဲပြီးပါက စလစ်ပေးပို့ပေးပါရှင်',
            ]
        );

        // 3. WavePay (Mobile Money)
        PaymentMethod::firstOrCreate(
            ['name' => 'WavePay'],
            [
                'code' => 'WAVEPAY',
                'icon' => 'phone_android_rounded',
                'is_active' => true,
                'is_default' => false,
                'account_number' => '09987654321',
                'account_name' => 'Ah Linn Thit Store',
                'description' => 'WavePay ဖြင့် ငွေလွှဲပြီးပါက စလစ်ပေးပို့ပေးပါရှင်',
            ]
        );
    }
}
