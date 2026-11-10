<?php

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
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

        // Check Totals Row (2 returns, grandTotal: 40000.00, paid: 30000.00, due: 10000.00)
        $this->assertStringContainsString('TOTAL,"2 Returns"', $content);
        $this->assertStringContainsString('40000.00', $content);
        $this->assertStringContainsString('30000.00', $content);
        $this->assertStringContainsString('10000.00', $content);

        // 2. Filtered Export by Status: Completed
        $filteredResponse = $this->actingAs($this->user)->get('/api/purchase-returns/export?status=Completed');
        $filteredResponse->assertStatus(200);
        $filteredContent = $filteredResponse->streamedContent();
        $this->assertStringContainsString('PR-EXP-001', $filteredContent);
        $this->assertStringNotContainsString('PR-EXP-002', $filteredContent);
        $this->assertStringContainsString('TOTAL,"1 Returns"', $filteredContent);
        $this->assertStringContainsString('25000.00', $filteredContent);
    }
}
