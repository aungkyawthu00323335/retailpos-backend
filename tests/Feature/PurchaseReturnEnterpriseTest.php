<?php

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\ReferenceCounter;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $warehouse;
    private Supplier $supplier;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Outlet::create([
            'name' => 'Central Warehouse',
            'is_warehouse' => true,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Golden Dragon Wholesale',
            'phone' => '0912345678',
            'balance_due' => 0.0,
        ]);

        $this->user = User::factory()->create([
            'name' => 'Purchasing Manager',
            'role' => 'Admin',
        ]);

        $this->product = Product::create([
            'name' => 'Specialty Arabica Coffee',
            'code' => 'COF-ARA-01',
            'stock_quantity' => 0.0,
            'purchase_price' => 5000.0,
            'cost_price' => 5000.0,
            'retail_price' => 8000.0,
        ]);

        StockBalance::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0.0,
        ]);
    }

    public function test_purchase_return_depletes_specific_purchase_fifo_batches_and_no_fake_sales_allocations(): void
    {
        // 1. First Purchase (Batch A): 10 units @ 5,000 MMK
        $poA = Purchase::create([
            'reference_no' => 'PO-2026-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->subDays(5)->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 50000.0,
            'paid_amount' => 50000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $poA->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 5000.0,
            'subtotal' => 50000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 5000.0,
        ]);
        $batchA = InventoryBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'source_type' => 'purchase',
            'source_reference' => $poA->reference_no,
            'batch_number' => 'BATCH-A',
            'initial_quantity' => 10.0,
            'remaining_quantity' => 10.0,
            'unit_cost' => 5000.0,
            'received_date' => now()->subDays(5),
            'status' => 'active',
        ]);

        // 2. Second Purchase (Batch B): 10 units @ 6,000 MMK
        $poB = Purchase::create([
            'reference_no' => 'PO-2026-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 60000.0,
            'paid_amount' => 60000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $poB->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 6000.0,
            'subtotal' => 60000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 6000.0,
        ]);
        $batchB = InventoryBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'source_type' => 'purchase',
            'source_reference' => $poB->reference_no,
            'batch_number' => 'BATCH-B',
            'initial_quantity' => 10.0,
            'remaining_quantity' => 10.0,
            'unit_cost' => 6000.0,
            'received_date' => now(),
            'status' => 'active',
        ]);

        // Update initial stock: 20 total
        $this->product->update(['stock_quantity' => 20.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 20.0]);

        // 3. Return 5 units from PO-2026-002 (Batch B)
        $response = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-2026-001',
            'purchase_reference_no' => 'PO-2026-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 30000.0,
            'paid_amount' => 30000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 5.0,
                    'unit_cost' => 6000.0,
                    'subtotal' => 30000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 5.0,
                    'base_unit_cost' => 6000.0,
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Verify Batch B was depleted, Batch A was untouched!
        $this->assertEquals(10.0, (float)$batchA->fresh()->remaining_quantity, 'Oldest Batch A must not be touched');
        $this->assertEquals(5.0, (float)$batchB->fresh()->remaining_quantity, 'Linked PO Batch B must be depleted');

        // Verify total stock
        $this->assertEquals(15.0, (float)$this->product->fresh()->stock_quantity);
        $stockBal = StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
        $this->assertEquals(15.0, (float)$stockBal);

        // Verify no fake sales records were created in sale_item_batch_allocations
        if (DB::getSchemaBuilder()->hasTable('sale_item_batch_allocations')) {
            $fakeSalesCount = DB::table('sale_item_batch_allocations')
                ->where('sale_id', $response->json('data.id'))
                ->count();
            $this->assertEquals(0, $fakeSalesCount, 'Purchase return must never generate fake sales allocations');
        }
    }

    public function test_purchase_return_fails_when_insufficient_warehouse_stock(): void
    {
        // Warehouse only has 3 units
        $this->product->update(['stock_quantity' => 3.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 3.0]);

        $response = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-INSUFFICIENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 25000.0,
            'paid_amount' => 25000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 5.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 25000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 5.0,
                    'base_unit_cost' => 5000.0,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['status', 'message']);

        $this->assertStringContainsString('Insufficient stock', $response->json('message'));
        $this->assertEquals(3.0, (float)$this->product->fresh()->stock_quantity);
    }

    public function test_purchase_return_fails_when_exceeding_purchase_order_quantity(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO-EXCEED-TEST',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 50000.0,
            'paid_amount' => 50000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 5000.0,
            'subtotal' => 50000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 5000.0,
        ]);

        // Warehouse has 20 units
        $this->product->update(['stock_quantity' => 20.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 20.0]);

        // Attempting to return 15 units against PO of 10 units must fail!
        $response = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-EXCEED-01',
            'purchase_reference_no' => 'PO-EXCEED-TEST',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 75000.0,
            'paid_amount' => 75000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 15.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 75000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 15.0,
                    'base_unit_cost' => 5000.0,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('remaining available', $response->json('message'));
    }

    public function test_purchase_return_credit_note_offsets_accounts_payable_and_purchase_due(): void
    {
        // Unpaid Purchase of 50,000 MMK
        $po = Purchase::create([
            'reference_no' => 'PO-AP-OFFSET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 50000.0,
            'paid_amount' => 0.0,
            'due_amount' => 50000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 5000.0,
            'subtotal' => 50000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 5000.0,
        ]);
        $this->supplier->update(['balance_due' => 50000.0]);
        $this->product->update(['stock_quantity' => 10.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 10.0]);

        // Return 4 units @ 5000 = 20,000 MMK as Credit Note (due_amount = 20000, paid_amount = 0)
        $response = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-AP-OFFSET-01',
            'purchase_reference_no' => 'PO-AP-OFFSET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 20000.0,
            'paid_amount' => 0.0,
            'due_amount' => 20000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 4.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 20000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 4.0,
                    'base_unit_cost' => 5000.0,
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Assert Supplier AP balance is reduced to 30,000 MMK
        $this->assertEquals(30000.0, (float)$this->supplier->fresh()->balance_due);

        // Assert PO due amount is reduced to 30,000 MMK
        $this->assertEquals(30000.0, (float)$po->fresh()->due_amount);
        $this->assertEquals('Partial', $po->fresh()->payment_status);
    }

    public function test_purchase_return_status_transitions_and_destroy_sync_stock_and_ledger(): void
    {
        $this->supplier->update(['balance_due' => 50000.0]);
        $this->product->update(['stock_quantity' => 10.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 10.0]);

        $po = Purchase::create([
            'reference_no' => 'PO-TRANSITION',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 50000.0,
            'paid_amount' => 0.0,
            'due_amount' => 50000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 5000.0,
            'subtotal' => 50000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 5000.0,
        ]);

        // 1. Create Return as 'Pending' -> Stock should NOT be deducted!
        $response = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-PENDING-01',
            'purchase_reference_no' => 'PO-TRANSITION',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'grand_total' => 15000.0,
            'paid_amount' => 0.0,
            'due_amount' => 15000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 3.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 15000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 3.0,
                    'base_unit_cost' => 5000.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $returnId = $response->json('data.id');

        // Verify Pending did not deduct stock or modify supplier balance
        $this->assertEquals(10.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(50000.0, (float)$this->supplier->fresh()->balance_due);

        // 2. Update to 'Completed' -> Stock should now be deducted and AP balance reduced!
        $updateResp = $this->actingAs($this->user)->putJson("/api/purchase-returns/{$returnId}", [
            'reference_no' => 'PR-PENDING-01',
            'purchase_reference_no' => 'PO-TRANSITION',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 15000.0,
            'paid_amount' => 0.0,
            'due_amount' => 15000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 3.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 15000.0,
                    'conversion_rate' => 1.0,
                    'base_quantity' => 3.0,
                    'base_unit_cost' => 5000.0,
                ],
            ],
        ]);
        $updateResp->assertStatus(200);

        $this->assertEquals(7.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(35000.0, (float)$this->supplier->fresh()->balance_due);
        $this->assertEquals(35000.0, (float)$po->fresh()->due_amount);

        // 3. Delete (destroy) the Return -> Stock and AP balance must be cleanly restored!
        $delResp = $this->actingAs($this->user)->deleteJson("/api/purchase-returns/{$returnId}");
        $delResp->assertStatus(200);

        $this->assertEquals(10.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(50000.0, (float)$this->supplier->fresh()->balance_due);
        $this->assertEquals(50000.0, (float)$po->fresh()->due_amount);
    }

    public function test_purchase_return_export_streams_csv_with_filters_and_summary_row(): void
    {
        // Create 2 test purchase returns
        PurchaseReturn::create([
            'reference_no' => 'PR-EXP-001',
            'purchase_reference_no' => 'PO-2026-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-09-20 10:00:00',
            'status' => 'Completed',
            'subtotal' => 25000.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 25000.0,
            'paid_amount' => 25000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'return_reason' => 'Defective / Damaged Product',
            'created_by' => 'Super Admin',
            'notes' => 'Damaged during transit',
            'items_payload' => json_encode([
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->name,
                    'quantity' => 5.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 25000.0,
                ],
            ]),
        ]);

        PurchaseReturn::create([
            'reference_no' => 'PR-EXP-002',
            'purchase_reference_no' => 'PO-2026-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Other Supplier Asia',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => 'Downtown Branch',
            'date' => '2026-09-21 14:30:00',
            'status' => 'Pending',
            'subtotal' => 15000.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 15000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 10000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Bank Transfer',
            'return_reason' => 'Wrong Item Received',
            'created_by' => 'Purchasing Manager',
            'notes' => 'Awaiting replacement',
            'items_payload' => json_encode([
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->name,
                    'quantity' => 3.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 15000.0,
                ],
            ]),
        ]);

        // 1. Unfiltered Export
        $response = $this->actingAs($this->user)->get('/api/purchase-returns/export');
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        // Check UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Check Metadata & Columns
        $this->assertStringContainsString('Purchase Returns List Export', $content);
        $this->assertStringContainsString('Return Reference', $content);
        $this->assertStringContainsString('Purchase Reference', $content);
        $this->assertStringContainsString('Supplier', $content);
        $this->assertStringContainsString('Warehouse', $content);
        $this->assertStringContainsString('PR-EXP-001', $content);
        $this->assertStringContainsString('PR-EXP-002', $content);

        // Check Totals Row (excludes Pending PR-EXP-002 qty 3 / 15000.00; only Completed PR-EXP-001 qty 5 / 25000.00 counts in totals)
        $this->assertStringContainsString('TOTAL,"2 Returns",,,,,,,5.00,25000.00,,0.00,0.00,0.00,25000.00,25000.00,0.00', $content);
        $this->assertStringNotContainsString('40000.00', $content);

        // 2. Filtered Export by Status: Completed
        $filteredResponse = $this->actingAs($this->user)->get('/api/purchase-returns/export?status=Completed');
        $filteredResponse->assertStatus(200);
        $filteredContent = $filteredResponse->streamedContent();
        $this->assertStringContainsString('PR-EXP-001', $filteredContent);
        $this->assertStringNotContainsString('PR-EXP-002', $filteredContent);
        $this->assertStringContainsString('TOTAL,"1 Returns"', $filteredContent);
        $this->assertStringContainsString('25000.00', $filteredContent);
    }

    public function test_purchase_return_recomputes_totals_preserves_flat_tax_clamps_overpayment_and_iso_dates(): void
    {
        // Seed 5 units so the Completed purchase return can deduct stock and clamp overpayment cleanly
        InventoryBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'source_type' => 'purchase',
            'source_reference' => 'PO-SEED-CLAMP',
            'batch_number' => 'BATCH-SEED-CLAMP',
            'initial_quantity' => 5.0,
            'remaining_quantity' => 5.0,
            'unit_cost' => 1000.0,
            'received_date' => '2026-11-10 10:00:00',
            'status' => 'active',
        ]);
        $this->product->update(['stock_quantity' => 5.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 5.0]);

        $createRes = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15T12:00:00Z',
            'status' => 'Completed',
            'subtotal' => 99999.0,
            'discount' => 200.0,
            'tax_rate' => 0.0,
            'tax_amount' => 150.0,
            'shipping' => 50.0,
            'grand_total' => 99999.0,
            'paid_amount' => 5000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 2.0,
                    'subtotal' => 99999.0,
                ],
            ],
        ])->assertStatus(201);

        $retId = $createRes->json('data.id');
        $ret = PurchaseReturn::findOrFail($retId);
        // True subtotal = 2 * 1000 = 2000; net = 2000 - 200 = 1800; + 150 flat tax + 50 shipping = 2000
        $this->assertEqualsWithDelta(2000.0, (float)$ret->subtotal, 0.01);
        $this->assertEqualsWithDelta(150.0, (float)$ret->tax_amount, 0.01);
        $this->assertEqualsWithDelta(2000.0, (float)$ret->grand_total, 0.01);
        $this->assertEqualsWithDelta(2000.0, (float)$ret->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$ret->due_amount, 0.01);
        $this->assertEquals('Paid', $ret->payment_status);

        // Update with excessive discount > subtotal and contradictory payment_status
        $this->actingAs($this->user)->putJson("/api/purchase-returns/{$retId}", [
            'discount' => 2500.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'shipping' => 40.0,
            'paid_amount' => 10.0,
            'payment_status' => 'Paid',
        ])->assertStatus(200);

        $ret->refresh();
        // True net total = max(0, 2000 - 2500 + 40) = 0.0; paid_amount clamped to 0.0
        $this->assertEqualsWithDelta(0.0, (float)$ret->grand_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$ret->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$ret->due_amount, 0.01);
        $this->assertEquals('Paid', $ret->payment_status);

        // ISO-8601 date filtering should find the record saved on 2026-11-15
        $listRes = $this->actingAs($this->user)->getJson(
            '/api/purchase-returns?from_date=2026-11-15T00:00:00Z&to_date=2026-11-15T23:59:59Z'
        )->assertStatus(200);
        $this->assertEquals(1, $listRes->json('meta.total'));
    }

    public function test_cash_refund_vs_credit_note_reconciles_linked_purchase_due_and_supplier_balance(): void
    {
        // Create an Unpaid Purchase of 10,000 MMK (10 x 1,000)
        $poRes = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Received',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 10.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ])->assertStatus(201);

        $po = Purchase::findOrFail($poRes->json('data.id'));
        $this->assertEqualsWithDelta(10000.0, (float)$po->due_amount, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float)$this->supplier->fresh()->balance_due, 0.01);

        // 1. Cash Refund Return of 2,000 MMK: PO due and Supplier balance_due MUST both stay 10,000 MMK!
        $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 11:00:00',
            'status' => 'Completed',
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 2.0,
                ],
            ],
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(10000.0, (float)$po->fresh()->due_amount, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float)$this->supplier->fresh()->balance_due, 0.01);

        // 2. Credit Note Return of 3,000 MMK (Unpaid, due_amount = 3000): Both PO due & Supplier balance drop to 7,000!
        $creditRes = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 12:00:00',
            'status' => 'Completed',
            'grand_total' => 3000.0,
            'paid_amount' => 0.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 3.0,
                ],
            ],
        ])->assertStatus(201);

        $creditId = $creditRes->json('data.id');
        $this->assertEqualsWithDelta(7000.0, (float)$po->fresh()->due_amount, 0.01);
        $this->assertEquals('Partial', $po->fresh()->payment_status);
        $this->assertEqualsWithDelta(7000.0, (float)$this->supplier->fresh()->balance_due, 0.01);

        // 3. Supplier pays 1,000 cash refund against that Credit Note -> Both PO due & Supplier AP restore to 8,000!
        $payRes = $this->actingAs($this->user)->postJson("/api/purchase-returns/{$creditId}/payments", [
            'amount' => 1000.0,
            'payment_type' => 'Cash',
        ])->assertStatus(200);
        $paymentId = $payRes->json('data.payments.0.id');

        $this->assertEqualsWithDelta(8000.0, (float)$po->fresh()->due_amount, 0.01);
        $this->assertEqualsWithDelta(8000.0, (float)$this->supplier->fresh()->balance_due, 0.01);

        // 4. Delete that 1,000 refund payment -> Both PO due & Supplier AP return to 7,000!
        $this->actingAs($this->user)->deleteJson("/api/purchase-returns/{$creditId}/payments/{$paymentId}")
            ->assertStatus(200);

        $this->assertEqualsWithDelta(7000.0, (float)$po->fresh()->due_amount, 0.01);
        $this->assertEqualsWithDelta(7000.0, (float)$this->supplier->fresh()->balance_due, 0.01);
    }

    public function test_purchases_report_cache_invalidates_after_new_purchase(): void
    {
        $first = $this->actingAs($this->user)->getJson('/api/reports/purchases?start_date=2026-11-15&end_date=2026-11-15')
            ->assertStatus(200);
        $this->assertStringNotContainsString('PO_CACHE_TEST_REF', $first->getContent());

        $this->actingAs($this->user)->postJson('/api/purchases', [
            'reference_no' => 'PO_CACHE_TEST_REF',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 14:00:00',
            'status' => 'Received',
            'paid_amount' => 1000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 1.0,
                    'subtotal' => 1000.0,
                ],
            ],
        ])->assertStatus(201);

        $second = $this->actingAs($this->user)->getJson('/api/reports/purchases?start_date=2026-11-15&end_date=2026-11-15')
            ->assertStatus(200);
        $this->assertStringContainsString('PO_CACHE_TEST_REF', $second->getContent());
    }

    public function test_failed_over_return_does_not_burn_purchase_return_sequential_code(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO_SEQ_GUARD_01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 09:00:00',
            'status' => 'Received',
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 2.0,
            'unit_cost' => 1000.0,
            'subtotal' => 2000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 2.0,
            'base_unit_cost' => 1000.0,
        ]);
        InventoryBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'source_type' => 'purchase',
            'source_reference' => $po->reference_no,
            'batch_number' => 'BATCH-SEQ-01',
            'initial_quantity' => 2.0,
            'remaining_quantity' => 2.0,
            'unit_cost' => 1000.0,
            'received_date' => now()->subDays(1),
            'status' => 'active',
        ]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 2.0]);
        $this->product->update(['stock_quantity' => 2.0]);

        $counterBefore = (int) (ReferenceCounter::where('type', 'purchase_return')->max('last_seq') ?? 0);

        // Over-return attempt without explicit reference_no must fail 422 without burning sequence
        $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Completed',
            'grand_total' => 5000.0,
            'payment_status' => 'Unpaid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 5.0,
                ],
            ],
        ])->assertStatus(422);

        $counterAfterFail = (int) (ReferenceCounter::where('type', 'purchase_return')->max('last_seq') ?? 0);
        $this->assertEquals($counterBefore, $counterAfterFail, 'Failed 422 validation must not burn purchase_return counter');

        // Valid return acquires the exact next sequence number (no gap!)
        $validRes = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 10:05:00',
            'status' => 'Completed',
            'grand_total' => 2000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 2.0,
                ],
            ],
        ])->assertStatus(201);

        $refNo = (string) $validRes->json('data.reference_no');
        $this->assertMatchesRegularExpression('/0*1$/', $refNo);
    }

    public function test_over_return_validation_handles_zero_base_quantity_fallback_and_delimiter_variants(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO_ZERO_BQ_10',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 09:00:00',
            'status' => 'Received',
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 4.0,
            'unit_cost' => 1000.0,
            'subtotal' => 4000.0,
            'conversion_rate' => 2.0,
            'base_quantity' => 0.0, // Legacy row missing precomputed base_quantity -> effective max is 4 * 2 = 8
            'base_unit_cost' => 500.0,
        ]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 10.0]);
        $this->product->update(['stock_quantity' => 10.0]);

        // First partial return using '-' instead of '_' variant succeeds for 5 base units
        $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-ZERO-BQ-10',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 11:00:00',
            'status' => 'Completed',
            'grand_total' => 2500.0,
            'payment_status' => 'Unpaid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 500.0,
                    'quantity' => 5.0,
                    'conversion_rate' => 1.0,
                ],
            ],
        ])->assertStatus(201);

        // Cumulative return 5 + 4 = 9 > effective PO max 8 must fail 422
        $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO_ZERO_BQ_10',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 11:30:00',
            'status' => 'Completed',
            'grand_total' => 2000.0,
            'payment_status' => 'Unpaid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 500.0,
                    'quantity' => 4.0,
                    'conversion_rate' => 1.0,
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_update_purchase_return_syncs_payments_payload_and_split_tenders(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO_UPD_PAY_01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 08:00:00',
            'status' => 'Received',
            'grand_total' => 10000.0,
            'paid_amount' => 0.0,
            'due_amount' => 10000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 10.0,
            'unit_cost' => 1000.0,
            'subtotal' => 10000.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 10.0,
            'base_unit_cost' => 1000.0,
        ]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 10.0]);
        $this->product->update(['stock_quantity' => 10.0]);

        $createRes = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 09:00:00',
            'status' => 'Completed',
            'grand_total' => 6000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 6.0,
                ],
            ],
        ])->assertStatus(201);

        $prId = $createRes->json('data.id');
        $this->assertEqualsWithDelta(0.0, (float) $createRes->json('data.paid_amount'), 0.01);
        $this->assertEqualsWithDelta(6000.0, (float) $createRes->json('data.due_amount'), 0.01);
        $this->assertEquals('Unpaid', $createRes->json('data.payment_status'));
        $this->assertEmpty($createRes->json('data.payments'));

        // Update with split tender array (paid_amount intentionally omitted so server derives 2500 + 1500 = 4000)
        $updRes = $this->actingAs($this->user)->putJson("/api/purchase-returns/{$prId}", [
            'grand_total' => 6000.0,
            'payment_status' => 'Partial',
            'payments' => [
                ['amount' => 2500.0, 'payment_type' => 'Cash', 'note' => 'Counter cash'],
                ['amount' => 1500.0, 'payment_type' => 'KBZPay', 'note' => 'QR transfer'],
            ],
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(4000.0, (float) $updRes->json('data.paid_amount'), 0.01);
        $this->assertEqualsWithDelta(2000.0, (float) $updRes->json('data.due_amount'), 0.01);
        $this->assertEquals('Partial', $updRes->json('data.payment_status'));
        $this->assertEquals('Split Payment', $updRes->json('data.payment_type'));
        $this->assertCount(2, $updRes->json('data.payments'));
    }

    public function test_fast_voucher_search_by_id_hash_and_delimiter_variants_on_purchases_and_returns(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO_FAST_0042',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 09:00:00',
            'status' => 'Received',
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        $pr = PurchaseReturn::create([
            'reference_no' => 'PR_FAST_0088',
            'purchase_reference_no' => 'PO_FAST_0042',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Completed',
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Search PO by #ID and hyphen variant
        $this->actingAs($this->user)->getJson("/api/purchases?search=%23{$po->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['reference_no' => 'PO_FAST_0042']);
        $this->actingAs($this->user)->getJson('/api/purchases?search=PO-FAST-0042')
            ->assertStatus(200)
            ->assertJsonFragment(['reference_no' => 'PO_FAST_0042']);

        // Search PR by purchase_reference_no (including hyphen variant), #ID, and PR hyphen variant
        $this->actingAs($this->user)->getJson('/api/purchase-returns?purchase_reference_no=PO-FAST-0042')
            ->assertStatus(200)
            ->assertJsonFragment(['reference_no' => 'PR_FAST_0088']);
        $this->actingAs($this->user)->getJson("/api/purchase-returns?search=%23{$pr->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['reference_no' => 'PR_FAST_0088']);
        $this->actingAs($this->user)->getJson('/api/purchase-returns?search=PR-FAST-0088')
            ->assertStatus(200)
            ->assertJsonFragment(['reference_no' => 'PR_FAST_0088']);
    }

    public function test_purchase_return_and_report_completed_received_parity_and_filters(): void
    {
        $secondWarehouse = Outlet::create([
            'name' => 'Branch B Outlet',
            'is_warehouse' => true,
        ]);
        $secondSupplier = Supplier::create([
            'name' => 'Silver Valley Supply',
            'phone' => '0998765432',
            'balance_due' => 0.0,
        ]);
        $secondUser = User::factory()->create([
            'name' => 'Auditor Jane',
            'role' => 'Admin',
        ]);

        $prCompleted = PurchaseReturn::create([
            'reference_no' => 'PR_RPT_001',
            'purchase_reference_no' => 'PO_REF_101',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 14:32:10',
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 500.0,
            'discount' => 200.0,
            'shipping' => 100.0,
            'grand_total' => 10400.0,
            'paid_amount' => 10400.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'return_reason' => 'Broken Seal On Arrival',
            'created_by' => $this->user->name,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'items_payload' => json_encode([[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 2.0,
                'unit_cost' => 5000.0,
                'subtotal' => 10000.0,
            ]]),
        ]);

        $prReceived = PurchaseReturn::create([
            'reference_no' => 'PR-RPT-002',
            'purchase_reference_no' => 'PO-REF-102',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 16:05:00',
            'status' => 'Received',
            'subtotal' => 5000.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 5000.0,
            'paid_amount' => 0.0,
            'due_amount' => 5000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Bank Transfer',
            'return_reason' => 'Expired Batch',
            'created_by' => $secondUser->name,
            'user_id' => $secondUser->id,
            'user_name' => $secondUser->name,
            'items_payload' => json_encode([[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 1.0,
                'unit_cost' => 5000.0,
                'subtotal' => 5000.0,
            ]]),
        ]);

        // Different supplier, warehouse, and Pending status (must be excluded by Completed/Received and warehouse/supplier filters)
        PurchaseReturn::create([
            'reference_no' => 'PR-PENDING-003',
            'supplier_id' => $secondSupplier->id,
            'supplier_name' => $secondSupplier->name,
            'warehouse_id' => $secondWarehouse->id,
            'warehouse_name' => $secondWarehouse->name,
            'date' => '2026-10-15 18:00:00',
            'status' => 'Pending',
            'grand_total' => 9000.0,
            'paid_amount' => 0.0,
            'due_amount' => 9000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'return_reason' => 'Pending Review',
        ]);

        // Status parity: Completed and Received return BOTH Completed and Received rows (and exclude Pending)
        foreach (['Completed', 'Received'] as $statusQuery) {
            $idxRes = $this->actingAs($this->user)->getJson("/api/purchase-returns?status={$statusQuery}");
            $idxRes->assertStatus(200);
            $idxRefs = array_column($idxRes->json('data'), 'reference_no');
            $this->assertContains('PR_RPT_001', $idxRefs);
            $this->assertContains('PR-RPT-002', $idxRefs);
            $this->assertNotContains('PR-PENDING-003', $idxRefs);

            $rptRes = $this->actingAs($this->user)->getJson("/api/reports/purchase-returns?status={$statusQuery}&force_refresh=1");
            $rptRes->assertStatus(200);
            $rptRefs = array_column($rptRes->json('data'), 'reference_no');
            $this->assertContains('PR_RPT_001', $rptRefs);
            $this->assertContains('PR-RPT-002', $rptRefs);
            $this->assertNotContains('PR-PENDING-003', $rptRefs);
        }

        // Warehouse alias (outlet_id / warehouse_id), supplier_id, created_by, and sort_by asc
        $filteredRpt = $this->actingAs($this->user)->getJson(
            "/api/reports/purchase-returns?outlet_id={$this->warehouse->id}&supplier_id={$this->supplier->id}&status=Completed&sort_by=grand_total&sort_order=asc&force_refresh=1"
        );
        $filteredRpt->assertStatus(200);
        $sortedRefs = array_column($filteredRpt->json('data'), 'reference_no');
        $this->assertSame(['PR-RPT-002', 'PR_RPT_001'], $sortedRefs);

        $byStaff = $this->actingAs($this->user)->getJson(
            '/api/reports/purchase-returns?created_by=' . urlencode('Auditor Jane') . '&force_refresh=1'
        );
        $byStaff->assertStatus(200);
        $this->assertSame(['PR-RPT-002'], array_column($byStaff->json('data'), 'reference_no'));

        // #ID search, hyphen/underscore search, return_reason search + full timestamp & breakdown fields
        $byId = $this->actingAs($this->user)->getJson("/api/reports/purchase-returns?search=%23{$prCompleted->id}&force_refresh=1");
        $byId->assertStatus(200);
        $this->assertCount(1, $byId->json('data'));
        $row = $byId->json('data.0');
        $this->assertSame('PR_RPT_001', $row['reference_no']);
        $this->assertSame('2026-10-15 14:32:10', $row['date']);
        $this->assertSame('Broken Seal On Arrival', $row['return_reason']);
        $this->assertEquals(10000.0, (float) $row['subtotal']);
        $this->assertEquals(5.0, (float) $row['tax_rate']);
        $this->assertEquals(500.0, (float) $row['tax_amount']);
        $this->assertEquals(200.0, (float) $row['discount']);
        $this->assertEquals(100.0, (float) $row['shipping']);

        $byHyphen = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns?search=PR-RPT-001&force_refresh=1');
        $byHyphen->assertStatus(200);
        $this->assertSame(['PR_RPT_001'], array_column($byHyphen->json('data'), 'reference_no'));

        $byReason = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns?search=' . urlencode('Expired Batch') . '&force_refresh=1');
        $byReason->assertStatus(200);
        $this->assertSame(['PR-RPT-002'], array_column($byReason->json('data'), 'reference_no'));
    }

    public function test_purchase_return_report_splits_multi_tender_payments_and_aggregates_all_products(): void
    {
        $secondProduct = Product::create([
            'name' => 'Matcha Powder Tin',
            'code' => 'MAT-TIN-02',
            'stock_quantity' => 50.0,
            'purchase_price' => 2000.0,
            'cost_price' => 2000.0,
            'retail_price' => 3500.0,
        ]);

        // Return 1: Split Payment (Cash 6,000 + Bank Transfer 4,000 = 10,000 paid of 12,000)
        PurchaseReturn::create([
            'reference_no' => 'PR-SPLIT-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 10:00:00',
            'status' => 'Completed',
            'grand_total' => 12000.0,
            'paid_amount' => 10000.0,
            'due_amount' => 2000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Split Payment',
            'payments_payload' => json_encode([
                ['reference_no' => 'PAY-S1', 'amount' => 6000.0, 'payment_type' => 'Cash'],
                ['reference_no' => 'PAY-S2', 'amount' => 4000.0, 'payment_type' => 'Bank Transfer'],
            ]),
            'items_payload' => json_encode([
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 2.0,
                    'unit_cost' => 5000.0,
                    'subtotal' => 10000.0,
                ],
                [
                    'product_id' => $secondProduct->id,
                    'product_code' => $secondProduct->code,
                    'product_name' => $secondProduct->name,
                    'quantity' => 1.0,
                    'unit_cost' => 2000.0,
                    'subtotal' => 2000.0,
                ],
            ]),
        ]);

        // Return 2: Pure Cash 5,000
        PurchaseReturn::create([
            'reference_no' => 'PR-CASH-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 11:00:00',
            'status' => 'Completed',
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([
                [
                    'product_id' => $secondProduct->id,
                    'product_code' => $secondProduct->code,
                    'product_name' => $secondProduct->name,
                    'quantity' => 2.5,
                    'unit_cost' => 2000.0,
                    'subtotal' => 5000.0,
                ],
            ]),
        ]);

        // Both purchase-returns list and report must match Split Payment row when filtering by component tender
        $idxBank = $this->actingAs($this->user)->getJson('/api/purchase-returns?payment_type=' . urlencode('Bank Transfer'));
        $idxBank->assertStatus(200);
        $this->assertSame(['PR-SPLIT-001'], array_column($idxBank->json('data'), 'reference_no'));

        $rptBank = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns?payment_type=' . urlencode('Bank Transfer') . '&force_refresh=1');
        $rptBank->assertStatus(200);
        $this->assertSame(['PR-SPLIT-001'], array_column($rptBank->json('data'), 'reference_no'));

        // Full report: by_refund_tender must break Split Payment into Cash (6k + 5k = 11k) and Bank Transfer (4k)
        $fullRpt = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns?force_refresh=1');
        $fullRpt->assertStatus(200);

        $summaryPaid = (float) $fullRpt->json('summary.total_paid_amount');
        $this->assertEquals(15000.0, $summaryPaid);

        $tenders = [];
        foreach ($fullRpt->json('by_refund_tender') as $t) {
            $tenders[$t['payment_type']] = (float) $t['total_amount'];
        }
        $this->assertArrayNotHasKey('Split Payment', $tenders);
        $this->assertEquals(11000.0, $tenders['Cash'] ?? 0.0);
        $this->assertEquals(4000.0, $tenders['Bank Transfer'] ?? 0.0);
        $this->assertEquals($summaryPaid, array_sum($tenders), 'Refund tender breakdown must reconcile to total_paid_amount');

        // Product breakdown aggregates all items across returns
        $products = [];
        foreach ($fullRpt->json('by_product') as $p) {
            $products[$p['product_code']] = $p;
        }
        $this->assertEquals(2.0, (float) ($products['COF-ARA-01']['quantity'] ?? 0));
        $this->assertEquals(10000.0, (float) ($products['COF-ARA-01']['total_amount'] ?? 0));
        $this->assertEquals(3.5, (float) ($products['MAT-TIN-02']['quantity'] ?? 0));
        $this->assertEquals(7000.0, (float) ($products['MAT-TIN-02']['total_amount'] ?? 0));
    }

    public function test_purchase_return_mutations_invalidate_report_cache_and_destroy_legacy_received_reverts_stock(): void
    {
        $po = Purchase::create([
            'reference_no' => 'PO-CACHE-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 09:00:00',
            'status' => 'Received',
            'grand_total' => 50000.0,
            'paid_amount' => 30000.0,
            'due_amount' => 20000.0,
            'returned_amount' => 0.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'source_type' => 'purchase',
            'source_reference' => $po->reference_no,
            'batch_number' => 'BATCH-CACHE-1',
            'initial_quantity' => 10.0,
            'remaining_quantity' => 10.0,
            'unit_cost' => 5000.0,
            'received_date' => '2026-10-16 09:00:00',
            'status' => 'active',
        ]);
        $this->product->update(['stock_quantity' => 10.0]);
        StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 10.0]);
        $this->supplier->update(['balance_due' => 20000.0]);

        // Warm report cache with 0 returns
        $initialRpt = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns');
        $initialRpt->assertStatus(200);
        $this->assertEquals(0, (int) $initialRpt->json('summary.total_returns'));

        // Store a Completed return (2 units @ 5000 = 10000, Unpaid -> reduces supplier balance_due)
        $storeRes = $this->actingAs($this->user)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-CACHE-001',
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 12:00:00',
            'status' => 'Completed',
            'grand_total' => 10000.0,
            'paid_amount' => 0.0,
            'due_amount' => 10000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'return_reason' => 'Damaged carton',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 2.0,
                'unit_cost' => 5000.0,
                'subtotal' => 10000.0,
                'conversion_rate' => 1.0,
                'base_quantity' => 2.0,
                'base_unit_cost' => 5000.0,
            ]],
        ]);
        $storeRes->assertStatus(201);
        $returnId = $storeRes->json('data.id');

        // Without force_refresh: cache MUST have been invalidated on store()
        $afterStoreRpt = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns');
        $afterStoreRpt->assertStatus(200);
        $this->assertEquals(1, (int) $afterStoreRpt->json('summary.total_returns'));
        $this->assertEquals(10000.0, (float) $afterStoreRpt->json('summary.total_returned_amount'));

        // Simulate legacy 'Received' status row on update(), then verify update() invalidates cache
        $updateRes = $this->actingAs($this->user)->putJson("/api/purchase-returns/{$returnId}", [
            'status' => 'Received',
            'grand_total' => 15000.0,
            'paid_amount' => 0.0,
            'due_amount' => 15000.0,
            'payment_status' => 'Unpaid',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 3.0,
                'unit_cost' => 5000.0,
                'subtotal' => 15000.0,
                'conversion_rate' => 1.0,
                'base_quantity' => 3.0,
                'base_unit_cost' => 5000.0,
            ]],
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals(7.0, (float) $batch->fresh()->remaining_quantity);

        // Without force_refresh: cache MUST reflect updated amount 15000.0
        $afterUpdateRpt = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns');
        $afterUpdateRpt->assertStatus(200);
        $this->assertEquals(15000.0, (float) $afterUpdateRpt->json('summary.total_returned_amount'));

        // Delete the 'Received' status return: must revert stock (10), batch (10), supplier balance (20000), PO returned_amount (0), and invalidate cache!
        $delRes = $this->actingAs($this->user)->deleteJson("/api/purchase-returns/{$returnId}");
        $delRes->assertStatus(200);

        $this->assertEquals(10.0, (float) $this->product->fresh()->stock_quantity);
        $this->assertEquals(10.0, (float) $batch->fresh()->remaining_quantity);
        $this->assertEquals(20000.0, (float) $this->supplier->fresh()->balance_due);
        $this->assertEquals(0.0, (float) $po->fresh()->returned_amount);

        $afterDelRpt = $this->actingAs($this->user)->getJson('/api/reports/purchase-returns');
        $afterDelRpt->assertStatus(200);
        $this->assertEquals(0, (int) $afterDelRpt->json('summary.total_returns'));
    }
}
