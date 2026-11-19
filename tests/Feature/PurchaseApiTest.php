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
use Tests\TestCase;

class PurchaseApiTest extends TestCase
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
            'name' => 'Test Coffee Beans',
            'code' => 'TCB-PUR-01',
            'stock_quantity' => 10.0,
            'purchase_price' => 5000.0,
            'cost_price' => 5000.0,
            'retail_price' => 8000.0,
        ]);

        StockBalance::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10.0,
        ]);
    }

    public function test_purchase_store_calculates_landed_cost_and_supplier_balance_due(): void
    {
        // 10 units @ 5,000 = 50,000 subtotal
        // shipping = 5,000, tax = 2,000, discount = 1,000
        // Net extra = 6,000. Landed cost total = 56,000 -> 5,600 / unit!
        // Paid = 20,000. Due = 36,000.
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Received',
            'shipping' => 5000.0,
            'tax_amount' => 2000.0,
            'discount' => 1000.0,
            'paid_amount' => 20000.0,
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 10.0,
                    'subtotal' => 50000.0,
                ],
            ],
        ]);

        $res->assertStatus(201);
        $purchaseId = $res->json('data.id');
        $purchase = Purchase::find($purchaseId);

        // 1. Check Grand total: 50,000 - 1,000 + 2,000 + 5,000 = 56,000 (supplier invoice includes tax)
        $this->assertEquals(56000.0, (float)$purchase->grand_total);
        $this->assertEquals(20000.0, (float)$purchase->paid_amount);
        $this->assertEquals(36000.0, (float)$purchase->due_amount);
        $this->assertEquals('Partial', $purchase->payment_status);

        // 2. Check Supplier balance_due incremented by due_amount (36,000)
        $this->assertEquals(36000.0, (float)$this->supplier->fresh()->balance_due);

        // 3. Check stock increased by 10 (10 + 10 = 20)
        $this->assertEquals(20.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));

        // 4. Default Non-Recoverable Tax (default false): tax (2,000) IS capitalized into inventory landed cost:
        //    (50,000 + 5,000 shipping - 1,000 discount + 2,000 tax) / 10 = 5,600 / unit
        $this->assertFalse((bool)$purchase->is_tax_recoverable);
        $batch = InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->first();
        $this->assertNotNull($batch);
        $this->assertEquals(10.0, (float)$batch->initial_quantity);
        $this->assertEquals(10.0, (float)$batch->remaining_quantity);
        $this->assertEquals(5600.0, (float)$batch->unit_cost);

        // 5. Explicit Recoverable Tax (is_tax_recoverable = true): tax (2,000) is excluded from inventory landed cost (5,400 / unit)
        $recRes = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Received',
            'shipping' => 5000.0,
            'tax_amount' => 2000.0,
            'is_tax_recoverable' => true,
            'discount' => 1000.0,
            'paid_amount' => 56000.0,
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 10.0,
                    'subtotal' => 50000.0,
                ],
            ],
        ]);
        $recRes->assertStatus(201);
        $recPurchase = Purchase::findOrFail($recRes->json('data.id'));
        $this->assertTrue((bool)$recPurchase->is_tax_recoverable);
        $recBatch = InventoryBatch::where('purchase_item_id', $recPurchase->items->first()->id)->first();
        $this->assertNotNull($recBatch);
        $this->assertEquals(5400.0, (float)$recBatch->unit_cost);
    }

    public function test_purchase_payments_adjust_supplier_balance_due_and_prevent_overpayment(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Received',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 2.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $purchase = Purchase::find($res->json('data.id'));

        $this->assertEquals(10000.0, (float)$this->supplier->fresh()->balance_due);

        // Overpayment test
        $overRes = $this->postJson("/api/purchases/{$purchase->id}/payments", [
            'amount' => 15000.0,
            'payment_type' => 'Cash',
        ]);
        $overRes->assertStatus(422);

        // Valid Payment 6,000
        $payRes = $this->postJson("/api/purchases/{$purchase->id}/payments", [
            'amount' => 6000.0,
            'payment_type' => 'Bank Transfer',
        ]);
        $payRes->assertStatus(200);

        // Supplier balance_due decreased to 4,000 (10,000 - 6,000)
        $this->assertEquals(4000.0, (float)$this->supplier->fresh()->balance_due);

        // Delete the payment
        $paymentRef = $purchase->fresh()->payments[0]['reference_no'];
        $delRes = $this->deleteJson("/api/purchases/{$purchase->id}/payments/{$paymentRef}");
        $delRes->assertStatus(200);

        // Supplier balance_due restored to 10,000
        $this->assertEquals(10000.0, (float)$this->supplier->fresh()->balance_due);
    }

    public function test_purchase_update_transitions_pending_to_received_and_adds_stock_and_batches(): void
    {
        // 1. Create Pending Purchase (No stock added yet)
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Pending',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 5.0,
                    'subtotal' => 25000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $purchase = Purchase::find($res->json('data.id'));

        // Initial stock should remain 10
        $this->assertEquals(10.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->assertEquals(0, InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->count());

        // 2. Goods arrive: Update status to Received
        $updateRes = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'Received',
        ]);
        $updateRes->assertStatus(200);

        // Stock now 15 (10 + 5)
        $this->assertEquals(15.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
        // FIFO batch now created
        $this->assertEquals(1, InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->count());
    }

    public function test_purchase_destroy_prevented_when_batches_are_already_consumed(): void
    {
        // 1. Create Received Purchase
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Received',
            'paid_amount' => 10000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 2.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $purchase = Purchase::find($res->json('data.id'));

        $batch = InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->first();
        $this->assertNotNull($batch);

        // Simulate a sale partially depleting this batch (remaining 2.0 -> 1.0)
        $batch->update(['remaining_quantity' => 1.0]);

        // Attempt to delete purchase -> must be rejected with 422
        $delRes = $this->deleteJson("/api/purchases/{$purchase->id}");
        $delRes->assertStatus(422);

        // Restore remaining quantity back to 2.0 (untouched batch)
        $batch->update(['remaining_quantity' => 2.0]);

        // Now deletion should succeed
        $delOkRes = $this->deleteJson("/api/purchases/{$purchase->id}");
        $delOkRes->assertStatus(200);
        $this->assertNull(Purchase::find($purchase->id));
        $this->assertEquals(0, InventoryBatch::where('id', $batch->id)->count());
    }

    public function test_purchase_return_uses_dedicated_fifo_depletion_and_supplier_credit(): void
    {
        // 1. Create Purchase of 5 items
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19',
            'status' => 'Received',
            'paid_amount' => 0.0, // unpaid -> balance_due = 25,000
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 5.0,
                    'subtotal' => 25000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $purchase = Purchase::find($res->json('data.id'));

        $this->assertEquals(25000.0, (float)$this->supplier->fresh()->balance_due);

        // 2. Return 2 items back to supplier on Credit (due_amount = 10,000)
        $retRes = $this->postJson('/api/purchase-returns', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'purchase_reference_no' => $purchase->reference_no,
            'date' => '2026-10-19',
            'status' => 'Completed',
            'grand_total' => 10000.0,
            'paid_amount' => 0.0, // credit note
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 2.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ]);
        $retRes->assertStatus(201);

        // 3. Check stock reduced by 2
        $this->assertEquals(13.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));

        // 4. Check Supplier balance_due reduced to 15,000 (25,000 - 10,000)
        $this->assertEquals(15000.0, (float)$this->supplier->fresh()->balance_due);

        // 5. Check the specific purchase batch was depleted directly (5 - 2 = 3 remaining)
        $batch = InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->first();
        $this->assertEquals(3.0, (float)$batch->remaining_quantity);
    }

    public function test_purchase_update_transfers_stock_between_warehouses_atomically(): void
    {
        $warehouse2 = Outlet::create([
            'name' => 'Secondary Branch',
            'is_warehouse' => false,
        ]);

        // Create Received purchase in warehouse 1 for 4 units
        $storeRes = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20',
            'status' => 'Received',
            'paid_amount' => 20000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 4.0,
                    'subtotal' => 20000.0,
                ],
            ],
        ]);
        $storeRes->assertStatus(201);
        $purchase = Purchase::find($storeRes->json('data.id'));

        $this->assertEquals(14.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));

        // Transfer purchase warehouse without re-sending items (Case C warehouse move)
        $moveRes = $this->putJson("/api/purchases/{$purchase->id}", [
            'warehouse_id' => $warehouse2->id,
            'warehouse_name' => $warehouse2->name,
        ]);
        $moveRes->assertStatus(200);

        $this->assertEquals(10.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->assertEquals(4.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $warehouse2->id)->value('quantity'));

        $batch = InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->first();
        $this->assertNotNull($batch);
        $this->assertEquals($warehouse2->id, $batch->warehouse_id);
    }

    public function test_purchase_store_recomputes_tax_base_without_tax_on_tax_and_clamps_overpayment(): void
    {
        // Line: 2 * 1000 + line tax 200 - line disc 0 = 2200 item subtotal
        // Order discount = 200, Order tax_rate = 10%
        // Taxable base must exclude line tax & order discount: 2200 - 200 - 200 = 1800 -> taxAmount = 180
        // Grand total = 2200 - 200 + 180 + 50 (shipping) = 2230
        // Paid sent = 5000 -> clamped to 2230, due = 0, payment_status = Paid
        $res = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-21',
            'status' => 'Received',
            'tax_rate' => 10.0,
            'tax_amount' => 9999.0, // tampered client value, server recomputes from tax_rate
            'discount' => 200.0,
            'shipping' => 50.0,
            'paid_amount' => 5000.0, // overpayment attempt
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 1000.0,
                    'quantity' => 2.0,
                    'tax' => 200.0,
                    'discount' => 0.0,
                    'subtotal' => 99999.0, // tampered item subtotal
                ],
            ],
        ]);
        $res->assertStatus(201);
        $data = $res->json('data');

        $this->assertEquals(2200.0, (float)$data['items'][0]['subtotal']);
        $this->assertEquals(180.0, (float)$data['tax_amount']);
        $this->assertEquals(2230.0, (float)$data['grand_total']);
        $this->assertEquals(2230.0, (float)$data['paid_amount']);
        $this->assertEquals(0.0, (float)$data['due_amount']);
        $this->assertEquals('Paid', $data['payment_status']);
        $this->assertEquals(0.0, (float)$this->supplier->fresh()->balance_due);
    }

    public function test_purchase_update_syncs_payments_payload_and_delete_payment_returns_404_when_missing(): void
    {
        $storeRes = $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-22',
            'status' => 'Received',
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 1.0,
                    'subtotal' => 5000.0,
                ],
            ],
        ]);
        $storeRes->assertStatus(201);
        $purchaseId = $storeRes->json('data.id');
        $this->assertEquals(4000.0, (float)$this->supplier->fresh()->balance_due);

        // Update with split payments payload
        $updateRes = $this->putJson("/api/purchases/{$purchaseId}", [
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 1500.0],
                ['payment_type' => 'KBZPay', 'amount' => 1500.0],
            ],
        ]);
        $updateRes->assertStatus(200);
        $updated = $updateRes->json('data');
        $this->assertEquals(3000.0, (float)$updated['paid_amount']);
        $this->assertEquals(2000.0, (float)$updated['due_amount']);
        $this->assertEquals('Split Payment', $updated['payment_type']);
        $this->assertCount(2, $updated['payments']);
        $this->assertEquals(2000.0, (float)$this->supplier->fresh()->balance_due);

        // Deleting non-existent payment on this purchase must return 404
        $missingRes = $this->deleteJson("/api/purchases/{$purchaseId}/payments/NON_EXISTENT_PAY");
        $missingRes->assertStatus(404);

        // Deleting via single-arg payment route when non-existent must return 404
        $missingGlobalRes = $this->deleteJson('/api/purchases/payments/NON_EXISTENT_PAY');
        $missingGlobalRes->assertStatus(404);
    }

    public function test_purchase_index_and_export_filter_by_created_by_and_iso8601_dates(): void
    {
        $cashier = User::factory()->create([
            'name' => 'Aye Aye',
            'role' => 'Manager',
        ]);

        $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-25T17:00:00Z', // Converts UTC -> Asia/Yangon (+06:30) = 2026-10-25 23:30:00
            'status' => 'Received',
            'paid_amount' => 5000.0,
            'created_by' => 'Purchasing Manager (Admin)',
            'user_name' => 'Purchasing Manager',
            'user_role' => 'Admin',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 5000.0,
                    'quantity' => 1.0,
                    'subtotal' => 5000.0,
                ],
            ],
        ])->assertStatus(201);

        $this->actingAs($cashier)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-26T10:15:00',
            'status' => 'Pending',
            'paid_amount' => 0.0,
            'created_by' => 'Aye Aye (Manager)',
            'user_name' => 'Aye Aye',
            'user_role' => 'Manager',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 8000.0,
                    'quantity' => 1.0,
                    'subtotal' => 8000.0,
                ],
            ],
        ])->assertStatus(201);

        $indexRes = $this->actingAs($this->user)->getJson('/api/purchases?created_by=Aye%20Aye&date_from=2026-10-26&date_to=2026-10-26&sort_by=paid_amount&sort_order=asc');
        $indexRes->assertStatus(200);
        $this->assertEquals(1, $indexRes->json('total'));
        $this->assertEquals('Aye Aye', $indexRes->json('data.0.user_name'));

        $isoDayRes = $this->actingAs($this->user)->getJson('/api/purchases?date_from=2026-10-25&date_to=2026-10-25');
        $isoDayRes->assertStatus(200);
        $this->assertEquals(1, $isoDayRes->json('total'));
        $this->assertEquals('2026-10-25 23:30:00', $isoDayRes->json('data.0.date'));

        $exportRes = $this->actingAs($this->user)->get('/api/purchases/export?created_by=Aye%20Aye&date_from=2026-10-26&date_to=2026-10-26');
        $exportRes->assertStatus(200);
        $csv = $exportRes->streamedContent();
        $this->assertStringContainsString('Created By Filter', $csv);
        $this->assertStringContainsString('"Aye Aye",Manager', $csv);
        $this->assertStringContainsString('PO_0010002', $csv);
        $this->assertStringNotContainsString('Purchasing Manager', $csv);
    }

    public function test_purchase_export_uses_total_row_and_true_items_subtotal(): void
    {
        $this->actingAs($this->user)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-11-15',
            'status' => 'Received',
            'discount' => 700.0,
            'shipping' => 50.0,
            'paid_amount' => 50.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 500.0,
                    'quantity' => 1.0,
                    'subtotal' => 500.0,
                ],
            ],
        ])->assertStatus(201);

        $exportRes = $this->actingAs($this->user)->get('/api/purchases/export');
        $exportRes->assertStatus(200);
        $csv = $exportRes->streamedContent();

        $this->assertStringContainsString('TOTAL,"1 Purchases"', $csv);
        $this->assertStringNotContainsString('TOTALS,', $csv);
        // Item subtotal is 500, discount is 700, shipping is 50, clamped grand_total is 0 (paid clamped to 0)
        $this->assertStringContainsString(',0,700,50,500,0,0,0,Paid,', $csv);
    }
}
