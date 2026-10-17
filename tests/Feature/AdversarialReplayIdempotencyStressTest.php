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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdversarialReplayIdempotencyStressTest extends TestCase
{
    use RefreshDatabase;

    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create([
            'name' => 'Adversarial Test Outlet',
            'code' => 'ADV-01',
            'is_active' => true,
        ]);
    }

    /**
     * Adversarially stress test: Replaying Master Data batch submissions produces zero duplicate rows.
     */
    public function test_adversarial_master_data_replay_stress_produces_zero_duplicates(): void
    {
        $categories = [];
        $brands = [];
        $units = [];
        $suppliers = [];

        for ($i = 1; $i <= 10; $i++) {
            $categories[] = [
                'uuid' => (string) Str::uuid(),
                'name' => "Stress Category $i",
                'description' => "Category description $i",
                'is_active' => 1,
            ];
            $brands[] = [
                'uuid' => (string) Str::uuid(),
                'name' => "Stress Brand $i",
                'code' => "SB$i",
                'is_active' => 1,
            ];
            $units[] = [
                'uuid' => (string) Str::uuid(),
                'name' => "Stress Unit $i",
                'code' => "SU$i",
                'is_active' => 1,
            ];
            $suppliers[] = [
                'uuid' => (string) Str::uuid(),
                'name' => "Stress Supplier $i",
                'phone' => "099887766$i",
                'is_active' => 1,
            ];
        }

        $payload = [
            'categories' => $categories,
            'brands' => $brands,
            'units' => $units,
            'suppliers' => $suppliers,
        ];

        // Initial submission
        $initialResponse = $this->postJson('/api/master-data/sync-batch', $payload);
        $initialResponse->assertStatus(200);
        $initialIdMap = $initialResponse->json('id_map');

        $this->assertEquals(10, ProductCategory::count());
        $this->assertEquals(10, Brand::count());
        $this->assertEquals(10, ProductUnit::count());
        $this->assertEquals(10, Supplier::count());

        // Replay the exact batch 5 times consecutively
        for ($replay = 1; $replay <= 5; $replay++) {
            $replayResponse = $this->postJson('/api/master-data/sync-batch', $payload);
            $replayResponse->assertStatus(200);
            $replayIdMap = $replayResponse->json('id_map');

            // Verify mapping consistency
            foreach ($categories as $cat) {
                $uuid = $cat['uuid'];
                $this->assertEquals($initialIdMap['categories'][$uuid], $replayIdMap['categories'][$uuid]);
            }
            foreach ($brands as $brand) {
                $uuid = $brand['uuid'];
                $this->assertEquals($initialIdMap['brands'][$uuid], $replayIdMap['brands'][$uuid]);
            }

            // Verify ZERO duplicate rows created
            $this->assertEquals(10, ProductCategory::count(), "Categories row count increased on replay #$replay");
            $this->assertEquals(10, Brand::count(), "Brands row count increased on replay #$replay");
            $this->assertEquals(10, ProductUnit::count(), "ProductUnit row count increased on replay #$replay");
            $this->assertEquals(10, Supplier::count(), "Supplier row count increased on replay #$replay");
        }
    }

    /**
     * Adversarially stress test: Replaying Product batch submissions produces zero duplicate rows
     * and zero double-increments in stock balances, purchases, stock movements, or inventory batches.
     */
    public function test_adversarial_product_replay_stress_produces_zero_duplicate_rows_or_double_increments(): void
    {
        $products = [];
        $expectedQuantities = [];

        for ($i = 1; $i <= 10; $i++) {
            $uuid = (string) Str::uuid();
            $qty = 15.5000 + $i;
            $products[] = [
                'uuid' => $uuid,
                'name' => "Adversarial Stress Product $i",
                'sku' => "ADV-SKU-$i",
                'barcode' => "88099887766$i",
                'cost_price' => 10.0000 + $i,
                'selling_price' => 20.0000 + $i,
                'opening_stock' => $qty,
                'warehouse_id' => $this->outlet->id,
                'created_at_local' => '2026-10-16 12:00:00',
            ];
            $expectedQuantities[$uuid] = $qty;
        }

        $payload = [
            'branch_code' => 'ADV-01',
            'products' => $products,
        ];

        // 1st Run
        $res1 = $this->postJson('/api/products/sync-batch', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'success');
        $idMap1 = $res1->json('id_map');

        // Check baseline counts
        $this->assertEquals(10, Product::count());
        $this->assertEquals(10, StockBalance::count());
        $this->assertEquals(10, StockMovement::count());
        $this->assertEquals(10, Purchase::count());
        $this->assertEquals(10, InventoryBatch::count());

        $expectedTotalStock = array_sum($expectedQuantities);
        $this->assertEquals($expectedTotalStock, (float) StockBalance::sum('quantity'));

        // Replay 10 times consecutively
        for ($replay = 1; $replay <= 10; $replay++) {
            $res = $this->postJson('/api/products/sync-batch', $payload);
            $res->assertStatus(200);

            // Every item in results must be 'already_synced' and marked 'is_replay'
            $results = $res->json('results');
            $this->assertCount(10, $results);
            foreach ($results as $itemResult) {
                $this->assertEquals('already_synced', $itemResult['status']);
                $this->assertTrue($itemResult['is_replay']);
                $uuid = $itemResult['uuid'];
                $this->assertEquals($idMap1[$uuid], $itemResult['server_id']);
            }

            // Invariant 1: Zero duplicate products
            $this->assertEquals(10, Product::count(), "Product row count increased on replay #$replay");

            // Invariant 2: Zero duplicate stock balance rows and ZERO double-increments
            $this->assertEquals(10, StockBalance::count(), "StockBalance row count changed on replay #$replay");
            $this->assertEquals($expectedTotalStock, (float) StockBalance::sum('quantity'), "StockBalance sum inflated on replay #$replay");

            // Invariant 3: Zero double ledger postings (StockMovement)
            $this->assertEquals(10, StockMovement::count(), "StockMovement count increased on replay #$replay");

            // Invariant 4: Zero duplicate purchases or purchase items
            $this->assertEquals(10, Purchase::count(), "Purchase count increased on replay #$replay");

            // Invariant 5: Zero duplicate inventory FIFO batches
            $this->assertEquals(10, InventoryBatch::count(), "InventoryBatch count increased on replay #$replay");
        }
    }
}
