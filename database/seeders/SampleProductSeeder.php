<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\InventoryBatch;

class SampleProductSeeder extends Seeder
{
    public function run(): void
    {
        $cat = ProductCategory::firstOrCreate(
            ['name' => 'အလှကုန်'],
            ['description' => 'Cosmetics & Skincare', 'status' => 'Active']
        );

        $unit = ProductUnit::firstOrCreate(
            ['name' => 'ခု'],
            ['base_unit' => 'ခု', 'operator' => '*', 'operation_value' => 1]
        );

        $wh = Outlet::firstOrCreate(
            ['name' => 'defaultwarehouse'],
            ['code' => 'WH001', 'address' => 'Default Address', 'is_active' => true]
        );

        $products = [
            [
                'name' => 'သဘာဝ ရှားစောင်းလက်ပတ် ဂျယ်လ် (Aloe Vera Gel)',
                'code' => 'PROD-001',
                'sku' => 'PROD-001',
                'purchase_price' => 5000,
                'cost_price' => 5000,
                'retail_price' => 8500,
                'selling_price' => 8500,
                'bot_price' => 7500,
                'stock' => 50,
                'desc' => 'အသားအရေကို ရေဓာတ်ဖြည့်ပေးပြီး အေးမြစေသော သဘာဝ ရှားစောင်းလက်ပတ်ဂျယ်လ်',
            ],
            [
                'name' => 'ရေဓာတ်ဖြည့် မျက်နှာသစ်ဆေး (Hydrating Facial Foam)',
                'code' => 'PROD-002',
                'sku' => 'PROD-002',
                'purchase_price' => 7000,
                'cost_price' => 7000,
                'retail_price' => 12000,
                'selling_price' => 12000,
                'bot_price' => 10500,
                'stock' => 30,
                'desc' => 'အညစ်အကြေးများကို သန့်စင်ပေးပြီး စိုပြေဝင်းပစေသော မျက်နှာသစ်ဆေး',
            ],
            [
                'name' => 'ဗီတာမင်စီ မျက်နှာလိမ်းဆီရမ် (Vitamin C Serum)',
                'code' => 'PROD-003',
                'sku' => 'PROD-003',
                'purchase_price' => 15000,
                'cost_price' => 15000,
                'retail_price' => 25000,
                'selling_price' => 25000,
                'bot_price' => 22000,
                'stock' => 25,
                'desc' => 'တင်းတိတ်၊ အမဲစက် သက်သာစေပြီး မျက်နှာကို ဝင်းပကြည်လင်စေသော ဆီရမ်',
            ],
        ];

        foreach ($products as $pData) {
            $p = Product::updateOrCreate(
                ['code' => $pData['code']],
                [
                    'name' => $pData['name'],
                    'sku' => $pData['sku'],
                    'category_id' => $cat->id,
                    'category_name' => $cat->name,
                    'base_unit_id' => $unit->id,
                    'unit_name' => $unit->name,
                    'purchase_price' => $pData['purchase_price'],
                    'cost_price' => $pData['cost_price'],
                    'retail_price' => $pData['retail_price'],
                    'selling_price' => $pData['selling_price'],
                    'stock_quantity' => $pData['stock'],
                    'is_active' => true,
                    'status' => 'Active',
                    'is_bot_available' => true,
                    'bot_price' => $pData['bot_price'],
                    'description' => $pData['desc'],
                ]
            );

            StockBalance::updateOrCreate(
                ['warehouse_id' => $wh->id, 'product_id' => $p->id],
                ['quantity' => $pData['stock']]
            );

            InventoryBatch::updateOrCreate(
                ['batch_number' => 'BATCH-' . $pData['code'], 'product_id' => $p->id],
                [
                    'warehouse_id' => $wh->id,
                    'source_type' => 'initial_stock',
                    'source_reference' => 'INIT-' . $pData['code'],
                    'received_date' => now()->format('Y-m-d'),
                    'unit_cost' => $pData['cost_price'],
                    'initial_quantity' => $pData['stock'],
                    'remaining_quantity' => $pData['stock'],
                    'status' => 'active',
                ]
            );
        }
    }
}
