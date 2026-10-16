<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductSyncBatchEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create([
            'name' => 'Flagship Store',
            'code' => 'B01',
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Batch sync creates multiple products atomically and returns results & id_map.
     */
    public function test_batch_sync_creates_products_atomically(): void
    {
        $pUuid1 = (string) Str::uuid();
        $pUuid2 = (string) Str::uuid();

        $payload = [
            'branch_code' => 'B01',
            'products' => [
                [
                    'uuid' => $pUuid1,
                    'name' => 'Fuji Apple 1kg',
                    'sku' => 'APP-FUJI-01',
                    'barcode' => '8801122334455',
                    'cost_price' => 3.50,
                    'selling_price' => 5.00,
                    'opening_stock' => 10.0,
                    'warehouse_id' => $this->outlet->id,
                    'created_at_local' => '2026-10-15 10:00:00',
                ],
                [
                    'uuid' => $pUuid2,
                    'name' => 'Navel Orange 1kg',
                    'sku' => 'ORG-NAVEL-01',
                    'barcode' => '8801122334466',
                    'cost_price' => 2.50,
                    'selling_price' => 4.00,
                    'opening_stock' => 15.0,
                    'warehouse_id' => $this->outlet->id,
                    'created_at_local' => '2026-10-15 10:05:00',
                ],
            ],
        ];

        $response = $this->postJson('/api/products/sync-batch', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('results.0.uuid', $pUuid1);
        $response->assertJsonPath('results.0.status', 'created');
        $response->assertJsonPath('results.1.uuid', $pUuid2);
        $response->assertJsonPath('results.1.status', 'created');

        $prod1 = Product::where('uuid', $pUuid1)->first();
        $prod2 = Product::where('uuid', $pUuid2)->first();

        $this->assertNotNull($prod1);
        $this->assertNotNull($prod2);
        $this->assertEquals($prod1->id, $response->json("id_map.{$pUuid1}"));
        $this->assertEquals($prod2->id, $response->json("id_map.{$pUuid2}"));

        // Check stock balances
        $this->assertEquals(10.0000, StockBalance::where('product_id', $prod1->id)->value('quantity'));
        $this->assertEquals(15.0000, StockBalance::where('product_id', $prod2->id)->value('quantity'));
    }

    /**
     * Test 2: Idempotent replay prevents duplicate products, duplicate purchases, and duplicate stock inflation.
     */
    public function test_batch_sync_replay_idempotency_prevents_duplicate_stock_inflation(): void
    {
        $uuid = (string) Str::uuid();

        $payload = [
            'branch_code' => 'B01',
            'products' => [
                [
                    'uuid' => $uuid,
                    'name' => 'Idempotency Test Item',
                    'sku' => 'IDEMP-01',
                    'barcode' => '7778889990001',
                    'cost_price' => 20.00,
                    'selling_price' => 30.00,
                    'opening_stock' => 50.0000,
                    'warehouse_id' => $this->outlet->id,
                    'created_at_local' => '2026-10-16 08:30:00',
                ],
            ],
        ];

        // 1st Sync Submission
        $res1 = $this->postJson('/api/products/sync-batch', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('results.0.status', 'created');
        $serverId1 = $res1->json('results.0.server_id');

        // Verify initial state
        $this->assertEquals(1, Product::where('uuid', $uuid)->count());
        $this->assertEquals(50.0000, (float) StockBalance::where('product_id', $serverId1)->value('quantity'));
        $this->assertEquals(1, StockMovement::where('product_id', $serverId1)->count());
        $this->assertEquals(1, Purchase::count());
        $this->assertEquals(1, InventoryBatch::where('product_id', $serverId1)->count());

        // 2nd Sync Submission (Replay of exact same request)
        $res2 = $this->postJson('/api/products/sync-batch', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('results.0.status', 'already_synced');
        $res2->assertJsonPath('results.0.is_replay', true);
        $res2->assertJsonPath('results.0.server_id', $serverId1);

        // Crucial verification: zero duplicate records, zero stock inflation
        $this->assertEquals(1, Product::where('uuid', $uuid)->count());
        $this->assertEquals(50.0000, (float) StockBalance::where('product_id', $serverId1)->value('quantity'));
        $this->assertEquals(1, StockMovement::where('product_id', $serverId1)->count());
        $this->assertEquals(1, Purchase::count());
        $this->assertEquals(1, InventoryBatch::where('product_id', $serverId1)->count());
    }

    /**
     * Test 3: SKU & Barcode collision across offline branches triggers deterministic branch disambiguation and audit notices.
     */
    public function test_sku_collision_triggers_branch_disambiguation_and_audit_log(): void
    {
        // 1. Existing cloud product from headquarters/main branch
        $cloudProduct = Product::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'LAPTOP-PRO',
            'sku' => 'LAPTOP-PRO',
            'barcode' => '888800001111',
            'name' => 'Cloud Laptop Pro',
            'cost_price' => 800.0,
            'selling_price' => 1200.0,
            'stock_quantity' => 5.0,
            'status' => 'Active',
        ]);

        $offlineUuid1 = (string) Str::uuid();
        $payload1 = [
            'branch_code' => 'B02',
            'products' => [
                [
                    'uuid' => $offlineUuid1,
                    'name' => 'Branch B02 Offline Laptop',
                    'sku' => 'LAPTOP-PRO', // Collides with cloud product
                    'barcode' => '888800001111', // Collides with cloud product
                    'cost_price' => 850.0,
                    'selling_price' => 1250.0,
                    'branch_code' => 'B02',
                ],
            ],
        ];

        $res1 = $this->postJson('/api/products/sync-batch', $payload1);
        $res1->assertStatus(200);
        $res1->assertJsonPath('results.0.status', 'created_disambiguated');
        $res1->assertJsonPath('results.0.disambiguated_sku', 'LAPTOP-PRO-B02');
        $res1->assertJsonPath('results.0.disambiguated_barcode', '888800001111-B02');

        $savedProduct1 = Product::where('uuid', $offlineUuid1)->first();
        $this->assertNotNull($savedProduct1);
        $this->assertEquals('LAPTOP-PRO-B02', $savedProduct1->sku);
        $this->assertEquals('LAPTOP-PRO-B02', $savedProduct1->code);
        $this->assertEquals('888800001111-B02', $savedProduct1->barcode);
        $this->assertStringContainsString('[Conflict Disambiguated]', $savedProduct1->note);

        // 2. Second colliding item from the SAME branch B02 gets sequence suffix (-2)
        $offlineUuid2 = (string) Str::uuid();
        $payload2 = [
            'branch_code' => 'B02',
            'products' => [
                [
                    'uuid' => $offlineUuid2,
                    'name' => 'Branch B02 Another Laptop',
                    'sku' => 'LAPTOP-PRO',
                    'barcode' => '888800001111',
                    'cost_price' => 850.0,
                    'selling_price' => 1250.0,
                    'branch_code' => 'B02',
                ],
            ],
        ];

        $res2 = $this->postJson('/api/products/sync-batch', $payload2);
        $res2->assertStatus(200);
        $res2->assertJsonPath('results.0.status', 'created_disambiguated');
        $res2->assertJsonPath('results.0.disambiguated_sku', 'LAPTOP-PRO-B02-2');
        $res2->assertJsonPath('results.0.disambiguated_barcode', '888800001111-B02-2');

        $savedProduct2 = Product::where('uuid', $offlineUuid2)->first();
        $this->assertEquals('LAPTOP-PRO-B02-2', $savedProduct2->sku);
        $this->assertEquals('888800001111-B02-2', $savedProduct2->barcode);
    }

    /**
     * Test 4: GAAP/IFRS opening stock persists dual timestamps and exact 4-decimal precision.
     */
    public function test_gaap_ifrs_opening_stock_persists_dual_timestamps_and_precision(): void
    {
        $uuid = (string) Str::uuid();
        $clientLocalTime = '2026-10-10 09:15:00';

        $payload = [
            'branch_code' => 'B01',
            'products' => [
                [
                    'uuid' => $uuid,
                    'name' => 'Audit Precision Bulk Item',
                    'sku' => 'AUDIT-BULK-01',
                    'cost_price' => 12.3456,
                    'selling_price' => 19.99,
                    'opening_stock' => 125.7500,
                    'warehouse_id' => $this->outlet->id,
                    'created_at_local' => $clientLocalTime,
                ],
            ],
        ];

        $response = $this->postJson('/api/products/sync-batch', $payload);
        $response->assertStatus(200);

        $product = Product::where('uuid', $uuid)->first();
        $this->assertNotNull($product);

        // Product dual timestamps
        $this->assertEquals($clientLocalTime, Carbon::parse($product->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertNotNull($product->synced_at);

        // StockBalance 4-decimal precision
        $balance = StockBalance::where('product_id', $product->id)->first();
        $this->assertEquals(125.7500, (float) $balance->quantity);

        // StockMovement dual timestamps and precision
        $movement = StockMovement::where('product_id', $product->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('opening_stock', $movement->movement_type);
        $this->assertEquals(125.7500, (float) $movement->quantity_in);
        $this->assertEquals($clientLocalTime, Carbon::parse($movement->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertNotNull($movement->synced_at);

        // FIFO InventoryBatch dual timestamps and costing precision
        $batch = InventoryBatch::where('product_id', $product->id)->first();
        $this->assertNotNull($batch);
        $this->assertEquals('opening_stock', $batch->source_type);
        $this->assertEquals(125.7500, (float) $batch->initial_quantity);
        $this->assertEquals(125.7500, (float) $batch->remaining_quantity);
        $this->assertEquals(12.3456, (float) $batch->unit_cost);
        $this->assertEquals($clientLocalTime, Carbon::parse($batch->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertNotNull($batch->synced_at);

        // Purchase audit trail dual timestamps
        $purchase = Purchase::where('id', $movement->reference_id)->first();
        $this->assertNotNull($purchase);
        $this->assertEquals($clientLocalTime, Carbon::parse($purchase->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertNotNull($purchase->synced_at);
    }

    /**
     * Test 5: Foreign key resolution by master data UUIDs.
     */
    public function test_master_data_uuid_reference_resolution(): void
    {
        $catUuid = (string) Str::uuid();
        $brandUuid = (string) Str::uuid();
        $unitUuid = (string) Str::uuid();
        $supplierUuid = (string) Str::uuid();

        $category = new ProductCategory;
        $category->forceFill([
            'uuid' => $catUuid,
            'name' => 'Snacks & Confectionery',
            'status' => 'Active',
        ]);
        $category->save();

        $brand = new Brand;
        $brand->forceFill([
            'uuid' => $brandUuid,
            'name' => 'Nestle',
            'status' => 'Active',
        ]);
        $brand->save();

        $unit = new ProductUnit;
        $unit->forceFill([
            'uuid' => $unitUuid,
            'name' => 'Box',
            'status' => 'Active',
        ]);
        $unit->save();

        $supplier = new Supplier;
        $supplier->forceFill([
            'uuid' => $supplierUuid,
            'name' => 'Nestle Myanmar Distribution',
            'phone' => '+959444555666',
            'status' => 'Active',
        ]);
        $supplier->save();

        $productUuid = (string) Str::uuid();
        $payload = [
            'products' => [
                [
                    'uuid' => $productUuid,
                    'name' => 'KitKat 4 Finger Box',
                    'sku' => 'KITKAT-4F-BOX',
                    'category_uuid' => $catUuid,
                    'brand_uuid' => $brandUuid,
                    'unit_uuid' => $unitUuid,
                    'supplier_uuid' => $supplierUuid,
                    'cost_price' => 15.00,
                    'selling_price' => 22.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/products/sync-batch', $payload);
        $response->assertStatus(200);

        $product = Product::where('uuid', $productUuid)->first();
        $this->assertNotNull($product);
        $this->assertEquals($category->id, $product->category_id);
        $this->assertEquals($category->name, $product->category_name);
        $this->assertEquals($brand->id, $product->brand_id);
        $this->assertEquals($brand->name, $product->brand_name);
        $this->assertEquals($unit->id, $product->base_unit_id);
        $this->assertEquals($unit->name, $product->unit_name);
        $this->assertEquals($supplier->id, $product->supplier_id);
        $this->assertEquals($supplier->name, $product->supplier_name);
    }
}
