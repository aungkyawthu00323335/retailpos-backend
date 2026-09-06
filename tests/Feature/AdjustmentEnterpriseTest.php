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

    /**
     * Test 7: Store adjustment with both Single product and Variation product updates StockBalance per warehouse
     */
    public function test_store_adjustment_with_single_and_variation_items_updates_warehouse_stock_balances(): void
    {
        $warehouse = \App\Models\Outlet::create([
            'name' => 'Main Warehouse Branch',
            'is_active' => true,
        ]);

        // 1. Single Product
        $singleProduct = Product::create([
            'code' => 'PROD-SINGLE-001',
            'name' => 'Standard Backpack',
            'cost_price' => 20.0,
            'price' => 45.0,
            'stock_quantity' => 10.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
            'has_variants' => false,
        ]);

        // 2. Variable Product with Variation
        $variableProduct = Product::create([
            'code' => 'PROD-VAR-001',
            'name' => 'Cotton T-Shirt',
            'cost_price' => 10.0,
            'price' => 25.0,
            'stock_quantity' => 30.0,
            'low_qty' => 5.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
            'has_variants' => true,
        ]);

        $variant = \App\Models\ProductVariant::create([
            'product_id' => $variableProduct->id,
            'name' => 'Red / XL',
            'sku' => 'TSHIRT-RED-XL',
            'barcode' => '893000000001',
            'cost_price' => 12.0,
            'selling_price' => 28.0,
            'is_active' => true,
        ]);

        // Seed initial warehouse balances
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $singleProduct->id,
            'product_variant_id' => null,
            'quantity' => 10.0,
        ]);

        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variableProduct->id,
            'product_variant_id' => $variant->id,
            'quantity' => 30.0,
        ]);

        // Create adjustment: Single product (+5), Variation product (-8)
        $payload = [
            'reference_no' => 'ADJ-COMBO-001',
            'warehouse_name' => $warehouse->name,
            'date' => '2026-09-05',
            'type' => 'Addition',
            'notes' => 'Testing combo adjustment of single and variation items',
            'items' => [
                [
                    'product_id' => $singleProduct->id,
                    'product_code' => $singleProduct->code,
                    'product_name' => $singleProduct->name,
                    'quantity' => 5.0,
                    'type' => 'Addition',
                    'unit_cost' => 20.0,
                ],
                [
                    'product_id' => $variableProduct->id,
                    'product_variant_id' => $variant->id,
                    'variant_name' => $variant->name,
                    'product_code' => $variant->sku,
                    'product_name' => $variableProduct->name,
                    'quantity' => 8.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 12.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/adjustments', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(2, 'data.items');

        // Verify Single Product warehouse balance (10 + 5 = 15)
        $singleBalance = \App\Models\StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $singleProduct->id)
            ->whereNull('product_variant_id')
            ->first();
        $this->assertNotNull($singleBalance);
        $this->assertEquals(15.0, (float)$singleBalance->quantity);

        // Verify Variation Product warehouse balance (30 - 8 = 22)
        $variantBalance = \App\Models\StockBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $variableProduct->id)
            ->where('product_variant_id', $variant->id)
            ->first();
        $this->assertNotNull($variantBalance);
        $this->assertEquals(22.0, (float)$variantBalance->quantity);

        // Verify StockMovements created
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $singleProduct->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => 'adjustment',
            'reference_type' => 'stock_adjustment',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $variableProduct->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => 'adjustment',
            'reference_type' => 'stock_adjustment',
        ]);
    }

    /**
     * Test 10: Store adjustment with string created_by resolves to integer or null for StockMovement
     */
    public function test_store_adjustment_with_string_created_by_resolves_integer_stock_movement(): void
    {
        $user = \App\Models\User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@pos.com',
        ]);

        $product = Product::create([
            'code' => 'ADJ-USER-TEST',
            'name' => 'Adjustment User Test Product',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 10.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $payload = [
            'reference_no' => 'ADJ-USER-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-09-05',
            'type' => 'Addition',
            'created_by' => 'Admin User',
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

        $movement = \App\Models\StockMovement::where('product_id', $product->id)
            ->where('movement_type', 'adjustment')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals($user->id, $movement->created_by);
        $this->assertIsNumeric($movement->created_by);
    }

    /**
     * Test 9: Store adjustment persists created_by and user_role and returns them in index
     */
    public function test_store_adjustment_persists_user_name_and_role_and_returns_in_index(): void
    {
        $product = Product::create([
            'code' => 'ADJ-ROLE-01',
            'name' => 'Adjustment Role Test Product',
            'cost_price' => 30.0,
            'price' => 60.0,
            'stock_quantity' => 10.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $payload = [
            'reference_no' => 'ADJ-ROLE-REF-01',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-09-06 14:30:00',
            'type' => 'Addition',
            'created_by' => 'David Manager',
            'user_role' => 'Store Manager',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 2.0,
                    'type' => 'Addition',
                    'unit_cost' => 30.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/adjustments', $payload);
        $res->assertStatus(201);
        $res->assertJsonPath('data.created_by', 'David Manager');
        $res->assertJsonPath('data.user_role', 'Store Manager');

        $indexRes = $this->getJson('/api/adjustments?search=David');
        $indexRes->assertStatus(200);
        $this->assertCount(1, $indexRes->json('data'));
        $this->assertEquals('David Manager', $indexRes->json('data.0.created_by'));
        $this->assertEquals('Store Manager', $indexRes->json('data.0.user_role'));
    }

    /**
     * Test 10: Index filters by date_from and date_to accurately including timestamps
     */
    public function test_index_filters_by_date_from_and_date_to_accurately(): void
    {
        StockAdjustment::create([
            'reference_no' => 'ADJ-DATE-01',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-01 10:00:00',
            'total_products' => 1,
            'total_quantity' => 1.0,
            'type' => 'Addition',
            'notes' => 'August early',
        ]);

        StockAdjustment::create([
            'reference_no' => 'ADJ-DATE-02',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-08-15 19:30:00',
            'total_products' => 1,
            'total_quantity' => 2.0,
            'type' => 'Addition',
            'notes' => 'August mid',
        ]);

        StockAdjustment::create([
            'reference_no' => 'ADJ-DATE-03',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-09-06 22:00:00',
            'total_products' => 1,
            'total_quantity' => 3.0,
            'type' => 'Addition',
            'notes' => 'September record',
        ]);

        // Filter date range: August 10 to August 15 (should only return ADJ-DATE-02)
        $res = $this->getJson('/api/adjustments?date_from=2026-08-10&date_to=2026-08-15');
        $res->assertStatus(200);
        $refs = collect($res->json('data'))->pluck('reference_no')->all();
        $this->assertContains('ADJ-DATE-02', $refs);
        $this->assertNotContains('ADJ-DATE-01', $refs);
        $this->assertNotContains('ADJ-DATE-03', $refs);

        // Filter full August range
        $augRes = $this->getJson('/api/adjustments?date_from=2026-08-01&date_to=2026-08-31');
        $augRes->assertStatus(200);
        $augRefs = collect($augRes->json('data'))->pluck('reference_no')->all();
        $this->assertContains('ADJ-DATE-01', $augRefs);
        $this->assertContains('ADJ-DATE-02', $augRefs);
        $this->assertNotContains('ADJ-DATE-03', $augRefs);
    }
}



