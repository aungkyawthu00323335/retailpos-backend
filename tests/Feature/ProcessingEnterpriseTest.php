<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Processing;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessingEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_process_bulk_raw_material_into_retail_finished_goods()
    {
        // Example: 1 bulk 100lb rice bag (cost 100,000 Ks) -> 10 retail 10lb rice bags (cost 10,000 Ks each)
        $warehouse = Outlet::firstOrCreate(['name' => 'Test Factory Warehouse'], [
            'address' => 'Yangon',
            'phone' => '0912345678',
        ]);

        // 1. Raw Material (Bulk 100lb rice)
        $rawRice = Product::create([
            'name' => 'ဆန် ပေါင် ၁၀၀ အိတ် (Bulk Rice)',
            'code' => 'RAW_RICE_100',
            'product_cost' => 100000,
            'product_price' => 120000,
            'stock_quantity' => 5,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $rawRice->id,
            'quantity' => 5, // 5 bags available
        ]);

        // 2. Finished Good (Retail 10lb rice bag)
        $retailRice = Product::create([
            'name' => 'ဆန် ၁၀ ပေါင် အိတ် (Retail Rice)',
            'code' => 'RETAIL_RICE_10',
            'product_cost' => 0,
            'product_price' => 12500,
            'stock_quantity' => 0,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $retailRice->id,
            'quantity' => 0,
        ]);

        // Execute Processing API: Use 1 bulk bag + 500 Ks packaging cost -> produce 10 retail bags
        $payload = [
            'reference_no' => 'PR_TEST_' . time(),
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            
            // Output: 10 retail bags
            'output_product_id' => $retailRice->id,
            'output_product_code' => $retailRice->code,
            'output_product_name' => $retailRice->name,
            'output_unit_name' => 'အိတ်',
            'output_conversion_rate' => 1.0,
            'output_quantity' => 10,

            // Input: 1 bulk bag @ 100,000 Ks
            'items' => [
                [
                    'product_id' => $rawRice->id,
                    'product_code' => $rawRice->code,
                    'product_name' => $rawRice->name,
                    'unit_name' => 'အိတ်',
                    'conversion_rate' => 1.0,
                    'quantity' => 1,
                    'unit_cost' => 100000,
                    'subtotal_cost' => 100000,
                ],
            ],

            'additional_cost' => 500, // Packaging bag cost
            'status' => 'completed',
            'notes' => 'ဆန် ၁၀၀ ပေါင် ၁ အိတ်ကို ၁၀ ပေါင် ၁၀ အိတ် ခွဲထုတ်ခြင်း',
        ];

        $response = $this->postJson('/api/processings', $payload);
        $response->assertStatus(201);
        $json = $response->json();
        $this->assertEquals('success', $json['status']);

        // Accounting Verifications:
        // Total cost = 100,000 + 500 = 100,500
        // Output unit cost = 100,500 / 10 = 10,050 Ks per bag
        $this->assertEquals(100500, $json['data']['total_cost']);
        $this->assertEquals(10050, $json['data']['output_unit_cost']);

        // Stock Deductions & Additions:
        // Raw material stock decreased from 5 to 4
        $rawBalance = StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $rawRice->id)->value('quantity');
        $this->assertEquals(4, (float)$rawBalance);

        // Finished good stock increased from 0 to 10
        $retailBalance = StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $retailRice->id)->value('quantity');
        $this->assertEquals(10, (float)$retailBalance);

        // Verify StockMovements created
        $movements = StockMovement::where('reference_type', 'processing')
            ->where('reference_id', $json['data']['id'])
            ->get();
        $this->assertCount(2, $movements);
        $this->assertTrue($movements->contains('movement_type', 'processing_out'));
        $this->assertTrue($movements->contains('movement_type', 'processing_in'));
    }

    public function test_rejects_processing_when_raw_materials_insufficient()
    {
        $warehouse = Outlet::firstOrCreate(['name' => 'Test Factory Warehouse 2'], [
            'address' => 'Yangon',
            'phone' => '0912345678',
        ]);

        $rawFlour = Product::create([
            'name' => 'ဂျုံ (Flour)',
            'code' => 'FLOUR_01',
            'product_cost' => 5000,
            'stock_quantity' => 2,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $rawFlour->id,
            'quantity' => 2, // only 2 bags in stock
        ]);

        $cake = Product::create([
            'name' => 'ကိတ်မုန့် (Birthday Cake)',
            'code' => 'CAKE_01',
            'product_cost' => 0,
            'stock_quantity' => 0,
        ]);

        // Attempt to use 5 bags of flour when only 2 exist
        $payload = [
            'reference_no' => 'PR_FAIL_' . time(),
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'output_product_id' => $cake->id,
            'output_product_code' => $cake->code,
            'output_product_name' => $cake->name,
            'output_unit_name' => 'ခု',
            'output_conversion_rate' => 1.0,
            'output_quantity' => 1,
            'items' => [
                [
                    'product_id' => $rawFlour->id,
                    'product_code' => $rawFlour->code,
                    'product_name' => $rawFlour->name,
                    'quantity' => 5, // Exceeds 2
                    'unit_cost' => 5000,
                ],
            ],
            'status' => 'completed',
        ];

        $response = $this->postJson('/api/processings', $payload);
        $response->assertStatus(422);
        $response->assertJsonFragment(['status' => 'error']);
    }
}
