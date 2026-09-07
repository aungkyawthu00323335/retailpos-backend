<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\User;

class AdjustmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('stock_adjustment_items')->truncate();
        DB::table('stock_adjustments')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $admin = User::first();
        $adminId = $admin ? $admin->id : 1;
        $adminName = $admin ? $admin->name : 'Super Admin';

        $outlets = Outlet::all();
        $primaryWarehouse = $outlets->first() ? $outlets->first()->name : 'Main Branch Outlet';
        $secondaryWarehouse = $outlets->skip(1)->first() ? $outlets->skip(1)->first()->name : 'Downtown Bistro Outlet';
        $tertiaryWarehouse = $outlets->skip(2)->first() ? $outlets->skip(2)->first()->name : 'Airport Express Outlet';

        $products = Product::take(10)->get();
        if ($products->isEmpty()) {
            return;
        }

        $p0 = $products[0];
        $p1 = $products[1] ?? $p0;
        $p2 = $products[2] ?? $p0;
        $p3 = $products[3] ?? $p0;
        $p4 = $products[4] ?? $p0;
        $p5 = $products[5] ?? $p0;

        $sampleAdjustments = [
            [
                'reference_no' => 'AD_11116',
                'warehouse_name' => $primaryWarehouse,
                'date' => '2026-08-15 19:08:00',
                'type' => 'Addition',
                'notes' => 'Inventory recount surplus after weekly audit',
                'created_by' => $adminName,
                'user_role' => 'Super Admin',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p0,
                        'quantity' => 5.0,
                        'type' => 'Addition',
                        'unit_cost' => (float)$p0->cost_price > 0 ? (float)$p0->cost_price : 150.0,
                    ],
                ],
            ],
            [
                'reference_no' => 'AD_11115',
                'warehouse_name' => $primaryWarehouse,
                'date' => '2026-08-11 06:23:00',
                'type' => 'Subtraction',
                'notes' => 'Damaged item write-off during shelf restocking',
                'created_by' => $adminName,
                'user_role' => 'Super Admin',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p1,
                        'quantity' => 2.0,
                        'type' => 'Subtraction',
                        'unit_cost' => (float)$p1->cost_price > 0 ? (float)$p1->cost_price : 1199.0,
                    ],
                ],
            ],
            [
                'reference_no' => 'AD_11113',
                'warehouse_name' => $secondaryWarehouse,
                'date' => '2026-07-27 23:13:00',
                'type' => 'Addition',
                'notes' => 'Found uncatalogued packaging in storage bin B-4',
                'created_by' => 'Store Manager',
                'user_role' => 'Store Manager',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p2,
                        'quantity' => 1.0,
                        'type' => 'Addition',
                        'unit_cost' => (float)$p2->cost_price > 0 ? (float)$p2->cost_price : 399.0,
                    ],
                ],
            ],
            [
                'reference_no' => 'AD_11112',
                'warehouse_name' => $tertiaryWarehouse,
                'date' => '2026-07-22 18:48:00',
                'type' => 'Subtraction',
                'notes' => 'Expired sample demo units disposal',
                'created_by' => $adminName,
                'user_role' => 'Super Admin',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p3,
                        'quantity' => 3.0,
                        'type' => 'Subtraction',
                        'unit_cost' => (float)$p3->cost_price > 0 ? (float)$p3->cost_price : 220.0,
                    ],
                ],
            ],
            [
                'reference_no' => 'AD_11111',
                'warehouse_name' => $secondaryWarehouse,
                'date' => '2026-07-21 23:00:00',
                'type' => 'Addition',
                'notes' => 'Physical inventory audit correction',
                'created_by' => 'Store Manager',
                'user_role' => 'Store Manager',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p4,
                        'quantity' => 2.0,
                        'type' => 'Addition',
                        'unit_cost' => (float)$p4->cost_price > 0 ? (float)$p4->cost_price : 88.0,
                    ],
                ],
            ],
            [
                'reference_no' => 'AD_11110',
                'warehouse_name' => $primaryWarehouse,
                'date' => '2026-07-20 23:34:00',
                'type' => 'Addition',
                'notes' => 'Vendor inbound quantity reconciliation correction',
                'created_by' => $adminName,
                'user_role' => 'Super Admin',
                'user_id' => $adminId,
                'items' => [
                    [
                        'product' => $p5,
                        'quantity' => 4.0,
                        'type' => 'Addition',
                        'unit_cost' => (float)$p5->cost_price > 0 ? (float)$p5->cost_price : 52.0,
                    ],
                ],
            ],
        ];

        foreach ($sampleAdjustments as $adjData) {
            $itemsData = $adjData['items'];
            unset($adjData['items']);

            $totalQty = 0;
            foreach ($itemsData as $i) {
                $totalQty += (float)$i['quantity'];
            }

            $adjData['total_products'] = count($itemsData);
            $adjData['total_quantity'] = $totalQty;
            $adjData['created_at'] = $adjData['date'];
            $adjData['updated_at'] = $adjData['date'];

            $adjustment = StockAdjustment::create($adjData);

            foreach ($itemsData as $i) {
                $prod = $i['product'];
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $prod->id,
                    'product_variant_id' => null,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'variant_name' => null,
                    'quantity' => (float)$i['quantity'],
                    'type' => $i['type'],
                    'unit_cost' => (float)$i['unit_cost'],
                    'created_at' => $adjData['date'],
                    'updated_at' => $adjData['date'],
                ]);
            }
        }
    }
}
