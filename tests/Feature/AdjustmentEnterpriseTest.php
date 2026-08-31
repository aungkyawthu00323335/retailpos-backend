<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;

class AdjustmentEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: List Adjustments API with pagination and filters
     */
    public function test_index_returns_paginated_adjustments_and_filters(): void
    {
        StockAdjustment::create([
            'reference_no' => 'ADJ-LIST-01',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'total_products' => 1,
            'total_quantity' => 5.0,
            'type' => 'Addition',
            'notes' => 'Test',
        ]);

        $response = $this->getJson('/api/adjustments?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data',
            'pagination' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);
        $response->assertJsonPath('status', 'success');
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * Test 2: Store Stock Adjustment (Addition) increments product stock quantity accurately
     */
    public function test_store_addition_adjustment_increments_product_stock(): void
    {
        $product = Product::create([
            'code' => 'ADJ-TEST-ADD-01',
            'name' => 'Adjustment Test Product Addition',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 10.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $payload = [
            'reference_no' => 'ADJ-ENT-TEST-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'type' => 'Addition',
            'notes' => 'Inventory count surplus test',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 5.0,
                    'type' => 'Addition',
                    'unit_cost' => 50.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/adjustments', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.reference_no', 'ADJ-ENT-TEST-001');

        $product->refresh();
        $this->assertEquals(15.0, (float)$product->stock_quantity);
    }

    /**
     * Test 3: Store Stock Adjustment (Subtraction) decrements product stock quantity accurately
     */
    public function test_store_subtraction_adjustment_decrements_product_stock(): void
    {
        $product = Product::create([
            'code' => 'ADJ-TEST-SUB-01',
            'name' => 'Adjustment Test Product Subtraction',
            'cost_price' => 40.0,
            'price' => 80.0,
            'stock_quantity' => 20.0,
            'low_qty' => 5.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $payload = [
            'reference_no' => 'ADJ-ENT-TEST-002',
            'warehouse_name' => 'Downtown Store Branch',
            'date' => '2026-08-29',
            'type' => 'Subtraction',
            'notes' => 'Damaged inventory write-off',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 4.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 40.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/adjustments', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');

        $product->refresh();
        $this->assertEquals(16.0, (float)$product->stock_quantity);
    }

    /**
     * Test 4: Show Adjustment details by ID
     */
    public function test_show_adjustment_returns_details_with_items(): void
    {
        $adj = StockAdjustment::create([
            'reference_no' => 'ADJ-SHOW-TEST-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'total_products' => 1,
            'total_quantity' => 2.0,
            'type' => 'Addition',
            'notes' => 'Show test',
        ]);

        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adj->id,
            'product_code' => 'TEST-001',
            'product_name' => 'Item 1',
            'quantity' => 2.0,
            'type' => 'Addition',
        ]);

        $response = $this->getJson("/api/adjustments/{$adj->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.reference_no', 'ADJ-SHOW-TEST-001');
        $response->assertJsonCount(1, 'data.items');
    }

    /**
     * Test 5: Update Adjustment reverses old stock quantities and applies new adjustments
     */
    public function test_update_adjustment_reverses_old_and_applies_new_quantities(): void
    {
        $product = Product::create([
            'code' => 'ADJ-TEST-UPD-01',
            'name' => 'Adjustment Update Test Product',
            'cost_price' => 30.0,
            'price' => 60.0,
            'stock_quantity' => 50.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        // Step 1: Create initial Addition (+10) -> stock becomes 60
        $createRes = $this->postJson('/api/adjustments', [
            'reference_no' => 'ADJ-UPD-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 30.0,
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $adjId = $createRes->json('data.id');

        $product->refresh();
        $this->assertEquals(60.0, (float)$product->stock_quantity);

        // Step 2: Update to Subtraction (-5) -> stock should reverse +10 and then -5 => 45
        $updateRes = $this->putJson("/api/adjustments/{$adjId}", [
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'type' => 'Subtraction',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 5.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 30.0,
                ],
            ],
        ]);
        $updateRes->assertStatus(200);

        $product->refresh();
        $this->assertEquals(45.0, (float)$product->stock_quantity);

        // Step 3: Delete adjustment -> should reverse -5 => stock returns to 50
        $deleteRes = $this->deleteJson("/api/adjustments/{$adjId}");
        $deleteRes->assertStatus(200);

        $product->refresh();
        $this->assertEquals(50.0, (float)$product->stock_quantity);
    }

    /**
     * Test 6: Validation prevents adjustments with empty items or invalid types
     */
    public function test_validation_fails_for_empty_items_or_invalid_type(): void
    {
        $res = $this->postJson('/api/adjustments', [
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-29',
            'type' => 'InvalidType',
            'items' => [],
        ]);

        $res->assertStatus(422);
    }
}
