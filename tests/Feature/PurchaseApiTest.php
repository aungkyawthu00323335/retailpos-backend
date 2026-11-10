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

        // 1. Check Grand total: 50,000 - 1,000 + 2,000 + 5,000 = 56,000
        $this->assertEquals(56000.0, (float)$purchase->grand_total);
        $this->assertEquals(20000.0, (float)$purchase->paid_amount);
        $this->assertEquals(36000.0, (float)$purchase->due_amount);
        $this->assertEquals('Partial', $purchase->payment_status);

        // 2. Check Supplier balance_due incremented by due_amount (36,000)
        $this->assertEquals(36000.0, (float)$this->supplier->fresh()->balance_due);

        // 3. Check stock increased by 10 (10 + 10 = 20)
        $this->assertEquals(20.0, (float)StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));

        // 4. Check FIFO batch created with Landed Cost (5,600 / unit)
        $batch = InventoryBatch::where('purchase_item_id', $purchase->items->first()->id)->first();
        $this->assertNotNull($batch);
        $this->assertEquals(10.0, (float)$batch->initial_quantity);
        $this->assertEquals(10.0, (float)$batch->remaining_quantity);
        $this->assertEquals(5600.0, (float)$batch->unit_cost);
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
}
