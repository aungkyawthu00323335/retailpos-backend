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

    /**
     * Test 11: Mixed Addition and Subtraction items in a single voucher calculate net quantity and net total value accurately
     */
    public function test_mixed_addition_and_subtraction_computes_net_quantity_and_net_total_value(): void
    {
        $p1 = Product::create([
            'code' => 'ADJ-MIX-01',
            'name' => 'Product Mixed 1',
            'cost_price' => 100.0,
            'price' => 150.0,
            'stock_quantity' => 10.0,
            'low_qty' => 2.0,
        ]);

        $p2 = Product::create([
            'code' => 'ADJ-MIX-02',
            'name' => 'Product Mixed 2',
            'cost_price' => 80.0,
            'price' => 120.0,
            'stock_quantity' => 20.0,
            'low_qty' => 5.0,
        ]);

        $payload = [
            'reference_no' => 'ADJ-MIXED-VOUCHER-01',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-10-19',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $p1->id,
                    'product_code' => $p1->code,
                    'product_name' => $p1->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 100.0,
                ],
                [
                    'product_id' => $p2->id,
                    'product_code' => $p2->code,
                    'product_name' => $p2->name,
                    'quantity' => 4.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 80.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/adjustments', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');

        // Net quantity: 10 - 4 = 6
        $this->assertEquals(6.0, (float)$response->json('data.total_quantity'));
        $this->assertEquals(6.0, (float)$response->json('data.net_quantity'));
        $this->assertEquals(14.0, (float)$response->json('data.gross_quantity'));
        $this->assertEquals(2, $response->json('data.total_products'));
        // Net total calculated from items: (10 * 100) - (4 * 80) = 1000 - 320 = 680
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $netValue = 0.0;
        foreach ($items as $it) {
            $val = (float)$it['quantity'] * (float)$it['unit_cost'];
            $netValue += ($it['type'] === 'Subtraction') ? -$val : $val;
        }
        $this->assertEquals(680.0, $netValue);
        $this->assertEquals('Mixed', $response->json('data.type'));

        $p1->refresh();
        $this->assertEquals(20.0, (float)$p1->stock_quantity);

        $p2->refresh();
        $this->assertEquals(16.0, (float)$p2->stock_quantity);
    }

    /**
     * Test 12: Rollback of Addition adjustment when subsequent stock is insufficient throws 422 and prevents negative stock
     */
    public function test_rollback_of_addition_adjustment_prevents_negative_stock_and_returns_422(): void
    {
        $product = Product::create([
            'code' => 'ADJ-NEG-CHECK-01',
            'name' => 'Negative Stock Guard Product',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 0.0,
            'low_qty' => 1.0,
        ]);

        $payload = [
            'reference_no' => 'ADJ-ADD-GUARD-01',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-10-19',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 50.0,
                ],
            ],
        ];

        $createRes = $this->postJson('/api/adjustments', $payload);
        $createRes->assertStatus(201);
        $adjId = $createRes->json('data.id');

        $product->refresh();
        $this->assertEquals(10.0, (float)$product->stock_quantity);

        // Simulate subsequent stock depletion (e.g. 7 items sold, 3 left)
        $product->update(['stock_quantity' => 3.0]);
        \App\Models\StockBalance::where('product_id', $product->id)->update(['quantity' => 3.0]);

        // Attempt to delete the addition adjustment (+10) -> requires subtracting 10, but only 3 available!
        $deleteRes = $this->deleteJson("/api/adjustments/{$adjId}");
        $deleteRes->assertStatus(422);
        $deleteRes->assertJsonValidationErrors(['rollback']);

        // Stock must remain unchanged at 3.0, never negative!
        $product->refresh();
        $this->assertEquals(3.0, (float)$product->stock_quantity);
    }

    /**
     * Test 13: Rollback of Addition adjustment when batch was partially sold depletes deficit from other active batches
     */
    public function test_rollback_of_addition_batch_with_partial_depletion_depletes_deficit_from_other_batches(): void
    {
        $outlet = \App\Models\Outlet::first() ?? \App\Models\Outlet::create(['name' => 'Central Warehouse', 'address' => 'Main St']);
        $product = Product::create([
            'code' => 'ADJ-FIFO-DEFICIT-01',
            'name' => 'FIFO Deficit Product',
            'cost_price' => 10.0,
            'price' => 20.0,
            'stock_quantity' => 0.0,
            'low_qty' => 1.0,
        ]);

        $fifoService = app(\App\Services\FifoInventoryService::class);

        // 1. Initial purchase batch: 15 units @ $10
        $fifoService->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 15.0,
            baseUnitCost: 10.0,
            sourceType: 'purchase',
            sourceReference: 'PO-TEST-01',
            receivedDate: '2026-10-18 10:00:00'
        );
        $product->update(['stock_quantity' => 15.0]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 15.0,
        ]);

        // 2. Stock adjustment addition: 10 units @ $12 (Total stock = 25)
        $payload = [
            'reference_no' => 'ADJ-FIFO-ADD-01',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-19',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 12.0,
                ],
            ],
        ];
        $res = $this->postJson('/api/adjustments', $payload);
        $res->assertStatus(201);
        $adjId = $res->json('data.id');

        // 3. Subsequent purchase batch: 10 units @ $14 (Total stock = 35)
        $fifoService->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 10.0,
            baseUnitCost: 14.0,
            sourceType: 'purchase',
            sourceReference: 'PO-TEST-02',
            receivedDate: '2026-10-19 12:00:00'
        );
        $product->increment('stock_quantity', 10.0);
        \App\Models\StockBalance::where('warehouse_id', $outlet->id)->where('product_id', $product->id)->increment('quantity', 10.0);

        // 4. Deplete 19 units (15 from initial PO, 4 from Adjustment addition batch)
        $fifoService->depleteForAdjustment(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQty: 19.0,
            referenceNo: 'SIMULATED-DEPLETION'
        );
        $product->decrement('stock_quantity', 19.0);
        \App\Models\StockBalance::where('warehouse_id', $outlet->id)->where('product_id', $product->id)->decrement('quantity', 19.0);

        // Current stock balance: 35 - 19 = 16 units.
        $product->refresh();
        $this->assertEquals(16.0, (float)$product->stock_quantity);

        // 5. Delete the Adjustment (+10).
        // 10 units will be rolled back from stock balance (16 - 10 = 6).
        // From FIFO batches: 6 units remain in the adjustment batch -> eliminated.
        // Deficit of 4 units (10 - 6) is depleted from the subsequent batch (PO-TEST-02: 10 - 4 = 6).
        $delRes = $this->deleteJson("/api/adjustments/{$adjId}");
        $delRes->assertStatus(200);

        $product->refresh();
        $balance = \App\Models\StockBalance::where('warehouse_id', $outlet->id)->where('product_id', $product->id)->first();

        // Product stock and StockBalance must be 6.0 units
        $this->assertEquals(6.0, (float)$product->stock_quantity);
        $this->assertEquals(6.0, (float)$balance->quantity);

        // Sum of all remaining FIFO batches must strictly equal 6.0 units
        $totalBatchQty = \App\Models\InventoryBatch::where('warehouse_id', $outlet->id)
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->sum('remaining_quantity');
        $this->assertEquals(6.0, (float)$totalBatchQty);
    }

    /**
     * Test 14: Updating an Addition Adjustment when stock was partially depleted/sold
     * ensures same-warehouse net sufficiency allows editing notes/costs or safe qty changes,
     * while rejecting excessive decreases or moving to another warehouse with insufficient stock.
     */
    public function test_update_addition_adjustment_when_partially_sold(): void
    {
        $outletA = \App\Models\Outlet::first() ?? \App\Models\Outlet::create(['name' => 'Outlet A', 'address' => 'Street A']);
        $outletB = \App\Models\Outlet::create(['name' => 'Outlet B', 'address' => 'Street B']);

        $product = Product::create([
            'code' => 'ADJ-PARTIAL-01',
            'name' => 'Partial Depletion Product',
            'cost_price' => 10.0,
            'price' => 20.0,
            'stock_quantity' => 0.0,
            'low_qty' => 1.0,
        ]);

        // 1. Create an Addition Adjustment of 10 units in Outlet A
        $createPayload = [
            'reference_no' => 'ADJ-PARTIAL-001',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-19 09:30:00',
            'type' => 'Addition',
            'notes' => 'Original addition',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 10.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/adjustments', $createPayload);
        $res->assertStatus(201);
        $adjId = $res->json('data.id');

        $product->refresh();
        $this->assertEquals(10.0, (float)$product->stock_quantity);

        // 2. Simulate 4 units sold/depleted. Remaining stock is 6 units.
        $fifoService = app(\App\Services\FifoInventoryService::class);
        $fifoService->depleteForAdjustment(
            warehouseId: $outletA->id,
            productId: $product->id,
            variantId: null,
            baseQty: 4.0,
            referenceNo: 'SALE-001'
        );
        $product->decrement('stock_quantity', 4.0);
        \App\Models\StockBalance::where('warehouse_id', $outletA->id)->where('product_id', $product->id)->decrement('quantity', 4.0);

        $product->refresh();
        $this->assertEquals(6.0, (float)$product->stock_quantity);

        // 3. Case A: User edits notes and unit_cost without changing quantity (10 units).
        // In the past, this failed because rollback tried to subtract 10 from 6.
        // Now it must succeed!
        $updatePayloadSameQty = [
            'reference_no' => 'ADJ-PARTIAL-001',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-19', // Date without time component
            'type' => 'Addition',
            'notes' => 'Updated notes without changing quantity',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 10.0,
                    'type' => 'Addition',
                    'unit_cost' => 11.0,
                ],
            ],
        ];

        $updateResA = $this->putJson("/api/adjustments/{$adjId}", $updatePayloadSameQty);
        $updateResA->assertStatus(200);

        $product->refresh();
        $this->assertEquals(6.0, (float)$product->stock_quantity);
        $balanceA = \App\Models\StockBalance::where('warehouse_id', $outletA->id)->where('product_id', $product->id)->first();
        $this->assertEquals(6.0, (float)$balanceA->quantity);

        // Verify date preserved time component
        $adjObj = StockAdjustment::find($adjId);
        $this->assertStringContainsString('09:30:00', $adjObj->date);

        // 4. Case B: User reduces quantity from 10 to 8 (net change = -2 units).
        // Since current balance is 6, reducing by 2 leaves 4 units. Must succeed!
        $updatePayloadReduceTo8 = [
            'reference_no' => 'ADJ-PARTIAL-001',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-19 14:00:00',
            'type' => 'Addition',
            'notes' => 'Reduced addition to 8',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 8.0,
                    'type' => 'Addition',
                    'unit_cost' => 10.0,
                ],
            ],
        ];

        $updateResB = $this->putJson("/api/adjustments/{$adjId}", $updatePayloadReduceTo8);
        $updateResB->assertStatus(200);

        $product->refresh();
        $this->assertEquals(4.0, (float)$product->stock_quantity);
        $balanceA->refresh();
        $this->assertEquals(4.0, (float)$balanceA->quantity);

        // 5. Case C: User tries to reduce quantity from 8 to 1 (net change = -7 units).
        // Current balance is 4, which is less than 7. Must be rejected with 422!
        $updatePayloadExcessiveReduce = [
            'reference_no' => 'ADJ-PARTIAL-001',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-19 15:00:00',
            'type' => 'Addition',
            'notes' => 'Excessive reduction',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 1.0,
                    'type' => 'Addition',
                    'unit_cost' => 10.0,
                ],
            ],
        ];

        $updateResC = $this->putJson("/api/adjustments/{$adjId}", $updatePayloadExcessiveReduce);
        $updateResC->assertStatus(422);

        // Stock remains untouched at 4.0
        $product->refresh();
        $this->assertEquals(4.0, (float)$product->stock_quantity);

        // 6. Case D: User tries to change warehouse from Outlet A to Outlet B.
        // Outlet A only has 4.0 remaining, but needs to rollback 8.0.
        // Must be rejected with 422!
        $updatePayloadChangeWarehouse = [
            'reference_no' => 'ADJ-PARTIAL-001',
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => '2026-10-19 16:00:00',
            'type' => 'Addition',
            'notes' => 'Attempting to change warehouse',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 8.0,
                    'type' => 'Addition',
                    'unit_cost' => 10.0,
                ],
            ],
        ];

        $updateResD = $this->putJson("/api/adjustments/{$adjId}", $updatePayloadChangeWarehouse);
        $updateResD->assertStatus(422);

        // Stock remains untouched at 4.0
        $product->refresh();
        $this->assertEquals(4.0, (float)$product->stock_quantity);
    }

    /**
     * Test 15: Reason code is normalized and stored, filterable, and net/gross quantities are calculated accurately
     */
    public function test_adjustment_with_reason_code_and_gross_net_quantities(): void
    {
        $product = Product::create([
            'code' => 'ADJ-REASON-01',
            'name' => 'Expired Milk Pack',
            'cost_price' => 50.0,
            'price' => 75.0,
            'stock_quantity' => 50.0,
            'low_qty' => 5.0,
        ]);

        $payload = [
            'reference_no' => 'ADJ-EXP-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-10-20 10:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Expired',
            'notes' => '5 packs expired past shelf date',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 5.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 50.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/adjustments', $payload);
        $res->assertStatus(201);
        $res->assertJsonPath('data.reason_code', 'Expired');
        $this->assertEquals(-5.0, (float)$res->json('data.net_quantity'));
        $this->assertEquals(5.0, (float)$res->json('data.gross_quantity'));
        $this->assertEquals(-5.0, (float)$res->json('data.total_quantity'));

        // Query with reason_code filter
        $listRes = $this->getJson('/api/adjustments?reason_code=Expired');
        $listRes->assertStatus(200);
        $listRefs = collect($listRes->json('data'))->pluck('reference_no')->all();
        $this->assertContains('ADJ-EXP-001', $listRefs);
    }

    /**
     * Test 16: Code generation produces sequential format with no collision
     */
    public function test_generate_code_sequential_and_collision_free(): void
    {
        $res1 = $this->getJson('/api/adjustments/generate-code');
        $res1->assertStatus(200);
        $code1 = $res1->json('data.reference_no');
        $this->assertNotEmpty($code1);

        // Create adjustment using code1
        $p = Product::create([
            'code' => 'ADJ-SEQ-01',
            'name' => 'Sequence Product',
            'cost_price' => 10.0,
            'price' => 20.0,
            'stock_quantity' => 10.0,
            'low_qty' => 1.0,
        ]);
        $this->postJson('/api/adjustments', [
            'reference_no' => $code1,
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-10-20 11:00:00',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $p->id,
                    'product_code' => $p->code,
                    'product_name' => $p->name,
                    'quantity' => 1.0,
                    'type' => 'Addition',
                    'unit_cost' => 10.0,
                ],
            ],
        ])->assertStatus(201);

        // Next generateCode must be strictly greater than code1
        $res2 = $this->getJson('/api/adjustments/generate-code');
        $res2->assertStatus(200);
        $code2 = $res2->json('data.reference_no');
        $this->assertNotEquals($code1, $code2);
    }

    /**
     * Test 17: Zero-cost FIFO guard falls back to product/variant cost price
     */
    public function test_store_addition_with_zero_unit_cost_falls_back_to_product_cost(): void
    {
        $prod = Product::create([
            'code' => 'ADJ-ZERO-COST-01',
            'name' => 'Zero Cost Product',
            'cost_price' => 45.0,
            'price' => 90.0,
            'stock_quantity' => 10.0,
        ]);

        $res = $this->postJson('/api/adjustments', [
            'reference_no' => 'ADJ-ZC-001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-10-20 12:00:00',
            'type' => 'Addition',
            'items' => [
                [
                    'product_id' => $prod->id,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'quantity' => 2.0,
                    'type' => 'Addition',
                    'unit_cost' => 0.0, // Frontend passed 0
                ],
            ],
        ]);

        $res->assertStatus(201);
        $savedItem = StockAdjustmentItem::where('stock_adjustment_id', $res->json('data.id'))->first();
        $this->assertNotNull($savedItem);
        $this->assertEquals(45.0, (float)$savedItem->unit_cost);
        $this->assertEquals(90.0, (float)$savedItem->subtotal);

        // Verify FIFO batch recorded with cost 45
        $batch = \App\Models\InventoryBatch::where('product_id', $prod->id)
            ->where('source_type', 'adjustment_addition')
            ->first();
        $this->assertNotNull($batch);
        $this->assertEquals(45.0, (float)$batch->unit_cost);
    }

    /**
     * Test 20: Adjustments export endpoint streams CSV with UTF-8 BOM, 14 columns, filters, and summary totals
     */
    public function test_adjustments_export_streams_csv_with_filters_and_summary_row(): void
    {
        $response = $this->get('/api/adjustments/export?reason_code=Normal+%2F+Other&type=All');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // UTF-8 BOM check
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Header check
        $this->assertStringContainsString('Stock Adjustments Export', $content);
        $this->assertStringContainsString('SN,"Reference No","Warehouse / Outlet",Date,Time,"Adjustment Type","Reason Code","Total Items","Gross Qty","Net Qty","Total Estimated Value (MMK)","Created By","User Role",Notes', $content);

        // Summary row check
        $this->assertStringContainsString('TOTAL,', $content);
    }
}
