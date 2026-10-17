<?php

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial Verification Test Suite for:
 * 1. Multi-branch SKU & Barcode collision disambiguation and audit notices.
 * 2. GAAP/IFRS Dual Timestamps and 4-decimal precision on quantities and values.
 * 3. Idempotent protection against replay payload mutations.
 */
class AdversarialConflictAndAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected Outlet $outlet3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet1 = Outlet::create([
            'name' => 'Downtown Flagship',
            'code' => 'B01',
            'is_active' => true,
        ]);

        $this->outlet2 = Outlet::create([
            'name' => 'Uptown Branch',
            'code' => 'B02',
            'is_active' => true,
        ]);

        $this->outlet3 = Outlet::create([
            'name' => 'Mandalay Hub',
            'code' => 'MDY',
            'is_active' => true,
        ]);
    }

    /**
     * Challenge 1: 5 Concurrent Offline Branches colliding on identical SKU and Barcode.
     * All 5 products must be saved without data loss, with branch disambiguation suffixes and audit logs.
     */
    public function test_challenge_1_five_way_concurrent_branch_sku_and_barcode_collision(): void
    {
        $collidingSku = 'GLOBAL-PROMO-SKU';
        $collidingBarcode = '8850001112223';
        $branches = ['B01', 'B02', 'MDY', 'YGN-EAST', 'NPT-01'];

        $savedUuids = [];
        $responses = [];

        foreach ($branches as $idx => $branch) {
            $uuid = (string) Str::uuid();
            $savedUuids[$branch] = $uuid;

            $payload = [
                'branch_code' => $branch,
                'products' => [
                    [
                        'uuid' => $uuid,
                        'name' => "Promo Item from Branch {$branch}",
                        'sku' => $collidingSku,
                        'barcode' => $collidingBarcode,
                        'cost_price' => 10.0 + $idx,
                        'selling_price' => 20.0 + $idx,
                        'opening_stock' => 5.0,
                        'branch_code' => $branch,
                        'warehouse_id' => $this->outlet1->id,
                        'created_at_local' => '2026-10-16 12:00:00',
                    ],
                ],
            ];

            $res = $this->postJson('/api/products/sync-batch', $payload);
            $res->assertStatus(200);
            $responses[$branch] = $res->json();
        }

        // Assert 5 distinct products exist in the database
        $this->assertEquals(5, Product::count());

        // First branch (B01) gets the original SKU and Barcode
        $p1 = Product::where('uuid', $savedUuids['B01'])->first();
        $this->assertNotNull($p1);
        $this->assertEquals($collidingSku, $p1->sku);
        $this->assertEquals($collidingBarcode, $p1->barcode);

        // Branches 2 through 5 must have disambiguated SKUs and barcodes
        for ($i = 1; $i < count($branches); $i++) {
            $branch = $branches[$i];
            $p = Product::where('uuid', $savedUuids[$branch])->first();
            $this->assertNotNull($p, "Product for branch {$branch} must exist.");

            $expectedDisambiguatedSku = "{$collidingSku}-{$branch}";
            $expectedDisambiguatedBarcode = "{$collidingBarcode}-{$branch}";

            $this->assertEquals($expectedDisambiguatedSku, $p->sku, "Branch {$branch} SKU must be disambiguated.");
            $this->assertEquals($expectedDisambiguatedBarcode, $p->barcode, "Branch {$branch} barcode must be disambiguated.");
            $this->assertStringContainsString('[Conflict Disambiguated]', $p->note);
            $this->assertStringContainsString($collidingSku, $p->note);
            $this->assertStringContainsString($expectedDisambiguatedSku, $p->note);

            // Verify stock balances for each distinct branch product
            $balance = StockBalance::where('product_id', $p->id)->first();
            $this->assertNotNull($balance);
            $this->assertEquals(5.0000, (float) $balance->quantity);
        }
    }

    /**
     * Challenge 2: Intra-Branch Repeated Collisions Escalating Sequential Disambiguation Suffixes.
     * When the same branch creates repeated identical SKUs, suffixes must sequence: -B01, -B01-2, -B01-3.
     */
    public function test_challenge_2_intra_branch_repeated_collision_sequential_suffixes(): void
    {
        $sku = 'SPECIAL-BLEND';
        $branch = 'B01';
        $uuids = [];

        for ($i = 1; $i <= 4; $i++) {
            $uuid = (string) Str::uuid();
            $uuids[] = $uuid;

            $payload = [
                'branch_code' => $branch,
                'products' => [
                    [
                        'uuid' => $uuid,
                        'name' => "Special Blend Batch #{$i}",
                        'sku' => $sku,
                        'cost_price' => 50.0,
                        'selling_price' => 75.0,
                        'opening_stock' => 10.0,
                        'warehouse_id' => $this->outlet1->id,
                        'created_at_local' => "2026-10-16 14:0{$i}:00",
                    ],
                ],
            ];

            $res = $this->postJson('/api/products/sync-batch', $payload);
            $res->assertStatus(200);
        }

        $p1 = Product::where('uuid', $uuids[0])->first();
        $p2 = Product::where('uuid', $uuids[1])->first();
        $p3 = Product::where('uuid', $uuids[2])->first();
        $p4 = Product::where('uuid', $uuids[3])->first();

        $this->assertEquals('SPECIAL-BLEND', $p1->sku);
        $this->assertEquals('SPECIAL-BLEND-B01', $p2->sku);
        $this->assertEquals('SPECIAL-BLEND-B01-2', $p3->sku);
        $this->assertEquals('SPECIAL-BLEND-B01-3', $p4->sku);

        $this->assertNull($p1->note);
        $this->assertStringContainsString('[Conflict Disambiguated]', $p2->note);
        $this->assertStringContainsString('SPECIAL-BLEND-B01', $p2->note);
        $this->assertStringContainsString('SPECIAL-BLEND-B01-2', $p3->note);
        $this->assertStringContainsString('SPECIAL-BLEND-B01-3', $p4->note);
    }

    /**
     * Challenge 3: Extreme Multi-Day/Multi-Month GAAP/IFRS Dual Timestamp Preservation.
     * Transaction occurred physically 6 months in the past; cloud replication occurs now.
     * Dual timestamps must be immutably preserved across products, stock_movements, purchases, and FIFO batches.
     */
    public function test_challenge_3_extreme_past_gaap_ifrs_dual_timestamps_preservation(): void
    {
        $historicalTerminalDate = '2025-04-15 08:30:15';
        $uuid = (string) Str::uuid();

        $payload = [
            'branch_code' => 'B01',
            'products' => [
                [
                    'uuid' => $uuid,
                    'name' => 'Antique Teak Chair Restoration',
                    'sku' => 'TEAK-CHAIR-01',
                    'cost_price' => 450.00,
                    'selling_price' => 800.00,
                    'opening_stock' => 2.0000,
                    'warehouse_id' => $this->outlet1->id,
                    'created_at_local' => $historicalTerminalDate,
                ],
            ],
        ];

        $syncExecutionStart = now()->subSeconds(2);
        $res = $this->postJson('/api/products/sync-batch', $payload);
        $syncExecutionEnd = now()->addSeconds(2);

        $res->assertStatus(200);

        $product = Product::where('uuid', $uuid)->first();
        $this->assertNotNull($product);

        // 1. Product Dual Timestamps
        $this->assertEquals($historicalTerminalDate, Carbon::parse($product->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertTrue(Carbon::parse($product->synced_at)->between($syncExecutionStart, $syncExecutionEnd));

        // 2. StockMovement Dual Timestamps
        $movement = StockMovement::where('product_id', $product->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals($historicalTerminalDate, Carbon::parse($movement->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertTrue(Carbon::parse($movement->synced_at)->between($syncExecutionStart, $syncExecutionEnd));

        // 3. Purchase Audit Trail Dual Timestamps
        $purchase = Purchase::where('id', $movement->reference_id)->first();
        $this->assertNotNull($purchase);
        $this->assertEquals('2025-04-15', $purchase->date);
        $this->assertEquals($historicalTerminalDate, Carbon::parse($purchase->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertTrue(Carbon::parse($purchase->synced_at)->between($syncExecutionStart, $syncExecutionEnd));

        // 4. InventoryBatch (FIFO Layer) Dual Timestamps
        $batch = InventoryBatch::where('product_id', $product->id)->first();
        $this->assertNotNull($batch);
        $this->assertEquals($historicalTerminalDate, Carbon::parse($batch->created_at_local)->format('Y-m-d H:i:s'));
        $this->assertTrue(Carbon::parse($batch->synced_at)->between($syncExecutionStart, $syncExecutionEnd));
        $this->assertEquals('2025-04-15', Carbon::parse($batch->received_date)->format('Y-m-d'));
    }

    /**
     * Challenge 4: High-Precision 4-Decimal Quantities and Valuations.
     * Micro-quantities (0.0001) and fractional bulk quantities (1234.5678) must maintain 4-decimal precision
     * without float truncation or rounding drift across StockBalance, Movements, and Batches.
     */
    public function test_challenge_4_high_precision_four_decimal_quantities_and_valuations(): void
    {
        $uuidMicro = (string) Str::uuid();
        $uuidBulk = (string) Str::uuid();

        $payload = [
            'products' => [
                [
                    'uuid' => $uuidMicro,
                    'name' => 'Saffron Threads Grade A (Grams)',
                    'sku' => 'SAFFRON-001',
                    'cost_price' => 15.6789,
                    'selling_price' => 25.0000,
                    'opening_stock' => 0.0001, // micro-quantity
                    'warehouse_id' => $this->outlet1->id,
                    'created_at_local' => '2026-10-16 10:00:00',
                ],
                [
                    'uuid' => $uuidBulk,
                    'name' => 'Granulated Industrial Sugar (Tons)',
                    'sku' => 'SUGAR-BULK-001',
                    'cost_price' => 742.3456,
                    'selling_price' => 899.9999,
                    'opening_stock' => 1234.5678, // bulk fractional quantity
                    'warehouse_id' => $this->outlet1->id,
                    'created_at_local' => '2026-10-16 10:05:00',
                ],
            ],
        ];

        $res = $this->postJson('/api/products/sync-batch', $payload);
        $res->assertStatus(200);

        // Verify Micro Item
        $pMicro = Product::where('uuid', $uuidMicro)->first();
        $this->assertNotNull($pMicro);
        $this->assertEquals(0.0001, (float) $pMicro->stock_quantity);

        $balanceMicro = StockBalance::where('product_id', $pMicro->id)->first();
        $this->assertEquals(0.0001, (float) $balanceMicro->quantity);

        $batchMicro = InventoryBatch::where('product_id', $pMicro->id)->first();
        $this->assertEquals(0.0001, (float) $batchMicro->initial_quantity);
        $this->assertEquals(0.0001, (float) $batchMicro->remaining_quantity);
        $this->assertEquals(15.6789, (float) $batchMicro->unit_cost);

        // Verify Bulk Fractional Item
        $pBulk = Product::where('uuid', $uuidBulk)->first();
        $this->assertNotNull($pBulk);
        $this->assertEquals(1234.5678, (float) $pBulk->stock_quantity);

        $balanceBulk = StockBalance::where('product_id', $pBulk->id)->first();
        $this->assertEquals(1234.5678, (float) $balanceBulk->quantity);

        $movementBulk = StockMovement::where('product_id', $pBulk->id)->first();
        $this->assertEquals(1234.5678, (float) $movementBulk->quantity_in);
        $this->assertEquals(1234.5678, (float) $movementBulk->balance_after);

        $batchBulk = InventoryBatch::where('product_id', $pBulk->id)->first();
        $this->assertEquals(1234.5678, (float) $batchBulk->initial_quantity);
        $this->assertEquals(1234.5678, (float) $batchBulk->remaining_quantity);
        $this->assertEquals(742.3456, (float) $batchBulk->unit_cost);
    }

    /**
     * Challenge 5: Multi-Warehouse Split Opening Stock with Independent FIFO Batches.
     */
    public function test_challenge_5_multi_warehouse_split_opening_stock(): void
    {
        $uuid = (string) Str::uuid();

        $payload = [
            'products' => [
                [
                    'uuid' => $uuid,
                    'name' => 'Premium Olive Oil 1L',
                    'sku' => 'OLIVE-OIL-1L',
                    'cost_price' => 12.50,
                    'selling_price' => 18.00,
                    'created_at_local' => '2026-10-16 09:00:00',
                    'opening_stocks' => [
                        [
                            'warehouse_id' => $this->outlet1->id,
                            'quantity' => 10.5000,
                            'cost_price' => 12.0000,
                        ],
                        [
                            'warehouse_id' => $this->outlet2->id,
                            'quantity' => 20.2500,
                            'cost_price' => 12.7500,
                        ],
                        [
                            'warehouse_id' => $this->outlet3->id,
                            'quantity' => 15.0000,
                            'cost_price' => 13.0000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->postJson('/api/products/sync-batch', $payload);
        $res->assertStatus(200);

        $product = Product::where('uuid', $uuid)->first();
        $this->assertNotNull($product);
        $this->assertEquals(45.7500, (float) $product->stock_quantity);

        // Balances in 3 warehouses
        $b1 = StockBalance::where('product_id', $product->id)->where('warehouse_id', $this->outlet1->id)->first();
        $b2 = StockBalance::where('product_id', $product->id)->where('warehouse_id', $this->outlet2->id)->first();
        $b3 = StockBalance::where('product_id', $product->id)->where('warehouse_id', $this->outlet3->id)->first();

        $this->assertEquals(10.5000, (float) $b1->quantity);
        $this->assertEquals(20.2500, (float) $b2->quantity);
        $this->assertEquals(15.0000, (float) $b3->quantity);

        // 3 distinct movements
        $this->assertEquals(3, StockMovement::where('product_id', $product->id)->count());

        // 3 distinct FIFO batches with warehouse attribution
        $this->assertEquals(3, InventoryBatch::where('product_id', $product->id)->count());
    }

    /**
     * Challenge 6: Adversarial Replay Attack with Mutated Payload.
     * Client resubmits already synced UUID but maliciously mutates opening_stock, cost_price, and selling_price.
     * The server must short-circuit, return 'already_synced', and reject any mutation to stock or prices.
     */
    public function test_challenge_6_adversarial_replay_with_mutated_payload_rejected(): void
    {
        $uuid = (string) Str::uuid();

        // 1. Initial valid sync
        $initialPayload = [
            'products' => [
                [
                    'uuid' => $uuid,
                    'name' => 'Original Legitimate Product',
                    'sku' => 'ORIG-PROD-001',
                    'cost_price' => 100.00,
                    'selling_price' => 150.00,
                    'opening_stock' => 10.0000,
                    'warehouse_id' => $this->outlet1->id,
                    'created_at_local' => '2026-10-16 11:00:00',
                ],
            ],
        ];

        $res1 = $this->postJson('/api/products/sync-batch', $initialPayload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('results.0.status', 'created');
        $serverId = $res1->json('results.0.server_id');

        $initialProduct = Product::find($serverId);
        $this->assertEquals(100.00, (float) $initialProduct->cost_price);
        $this->assertEquals(150.00, (float) $initialProduct->selling_price);
        $this->assertEquals(10.0000, (float) $initialProduct->stock_quantity);
        $this->assertEquals(10.0000, (float) StockBalance::where('product_id', $serverId)->value('quantity'));

        // 2. Malicious replay attempt trying to inflate stock to 1000 and halve prices
        $mutatedPayload = [
            'products' => [
                [
                    'uuid' => $uuid, // SAME UUID!
                    'name' => 'HACKED MUTATED PRODUCT',
                    'sku' => 'HACKED-SKU-999',
                    'cost_price' => 1.00, // Attempted price manipulation
                    'selling_price' => 2.00,
                    'opening_stock' => 1000.0000, // Attempted inventory inflation
                    'warehouse_id' => $this->outlet1->id,
                    'created_at_local' => '2026-10-16 11:00:00',
                ],
            ],
        ];

        $res2 = $this->postJson('/api/products/sync-batch', $mutatedPayload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('results.0.status', 'already_synced');
        $res2->assertJsonPath('results.0.is_replay', true);
        $res2->assertJsonPath('results.0.server_id', $serverId);

        // Verify database state is 100% UNTOUCHED
        $recheckProduct = Product::find($serverId);
        $this->assertEquals('Original Legitimate Product', $recheckProduct->name);
        $this->assertEquals('ORIG-PROD-001', $recheckProduct->sku);
        $this->assertEquals(100.00, (float) $recheckProduct->cost_price);
        $this->assertEquals(150.00, (float) $recheckProduct->selling_price);
        $this->assertEquals(10.0000, (float) $recheckProduct->stock_quantity);

        // Verify stock balances and movements were NOT inflated
        $this->assertEquals(10.0000, (float) StockBalance::where('product_id', $serverId)->value('quantity'));
        $this->assertEquals(1, StockMovement::where('product_id', $serverId)->count());
        $this->assertEquals(1, Purchase::count());
        $this->assertEquals(1, InventoryBatch::where('product_id', $serverId)->count());
    }
}
