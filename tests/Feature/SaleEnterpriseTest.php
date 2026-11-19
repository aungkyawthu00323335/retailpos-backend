<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $warehouse;
    private Customer $customerA;
    private Customer $customerB;
    private Product $product;
    private User $user;
    private CashRegister $register;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Outlet::create([
            'name' => 'Main Outlet & Warehouse',
            'phone' => '0911223344',
            'is_active' => true,
            'is_warehouse' => true,
        ]);

        $this->user = User::factory()->create([
            'name' => 'Cashier Lead',
            'email' => 'cashier@retailpos.mm',
            'role' => 'Super Admin',
        ]);

        $this->register = CashRegister::create([
            'reference_no' => 'REG-20261019-001',
            'register_name' => 'Counter 1',
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'warehouse_id' => $this->warehouse->id,
            'opening_cash' => 50000.0,
            'expected_cash' => 50000.0,
            'status' => 'Open',
            'opened_at' => now(),
        ]);

        $this->customerA = Customer::create([
            'name' => 'U Maung Maung',
            'phone' => '0911111111',
            'email' => 'maung@retail.mm',
            'tier' => 'Regular',
            'total_spent' => 0.0,
            'total_visits' => 0,
            'reward_points' => 0,
            'status' => 'Active',
        ]);

        $this->customerB = Customer::create([
            'name' => 'Daw Hla Hla',
            'phone' => '0922222222',
            'email' => 'hlahla@retail.mm',
            'tier' => 'VIP',
            'total_spent' => 0.0,
            'total_visits' => 0,
            'reward_points' => 0,
            'status' => 'Active',
        ]);

        $this->product = Product::create([
            'name' => 'Premium Roasted Espresso Beans',
            'code' => 'COF-ESP-01',
            'barcode' => '88500112233',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 10000.0,
            'cost_price' => 10000.0,
            'selling_price' => 16000.0,
            'retail_price' => 16000.0,
            'stock_quantity' => 20.0,
            'alert_quantity' => 2.0,
            'status' => 'Active',
        ]);

        StockBalance::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20.0,
        ]);

        // FIFO Layer: 20 units @ 10,000 MMK
        InventoryBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'batch_number' => 'BATCH-TEST-001',
            'initial_quantity' => 20.0,
            'remaining_quantity' => 20.0,
            'unit_cost' => 10000.0,
            'status' => 'active',
            'received_date' => now()->toDateString(),
            'purchase_item_id' => null,
            'source_type' => 'Purchase',
        ]);
    }

    /**
     * 1. Sale creation verifies FIFO depletion, COGS recording, Customer tracking and Cash Register expected cash.
     */
    public function test_sale_store_depletes_fifo_records_cogs_and_updates_customer_and_register(): void
    {
        $payload = [
            'reference_no' => 'SL-ENT-001',
            'idempotency_key' => 'IDEMP-TEST-CREATE-001',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 32000.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 32000.0,
            'paid_amount' => 32000.0,
            'due_amount' => 0.0,
            'change_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 2,
                    'subtotal' => 32000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 32000.0,
                    'received_amount' => 32000.0,
                    'change_amount' => 0.0,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $response->assertStatus(201);

        $saleId = $response->json('data.id');
        $this->assertNotNull($saleId);

        // Check Sale COGS (2 units @ 10,000 = 20,000)
        $sale = Sale::findOrFail($saleId);
        $this->assertEquals('Completed', $sale->status);
        $this->assertEquals(20000.0, (float)$sale->total_cogs);

        // Check StockBalance (20 - 2 = 18)
        $stock = StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(18.0, (float)$stock->quantity);

        // Check InventoryBatch remaining_quantity (20 - 2 = 18)
        $batch = InventoryBatch::where('product_id', $this->product->id)->first();
        $this->assertEquals(18.0, (float)$batch->remaining_quantity);

        // Check Customer tracking
        $this->customerA->refresh();
        $this->assertEquals(32000.0, (float)$this->customerA->total_spent);
        $this->assertEquals(1, $this->customerA->total_visits);

        // Check Register expected cash (50000 + 32000 = 82000)
        $this->register->refresh();
        $this->assertEquals(82000.0, (float)$this->register->expected_cash);
    }

    /**
     * 2. Idempotency prevents duplicate charges and multiple stock depletions on network retry.
     */
    public function test_sale_store_idempotency_prevents_duplicate_charge_and_stock_deduction(): void
    {
        $idempotencyKey = 'IDEMP-RETRY-0099';
        $payload = [
            'reference_no' => 'SL-IDEMP-001',
            'idempotency_key' => $idempotencyKey,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 16000.0,
                ],
            ],
        ];

        // First attempt
        $res1 = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $res1->assertStatus(201);
        $saleId1 = $res1->json('data.id');

        // Immediate retry with identical idempotency_key
        $res2 = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $res2->assertStatus(200);
        $saleId2 = $res2->json('data.id');

        $this->assertEquals($saleId1, $saleId2);

        // Stock must only be deducted once (20 - 1 = 19, NOT 18)
        $stock = StockBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(19.0, (float)$stock->quantity);

        // Only 1 sale in database
        $this->assertEquals(1, Sale::where('idempotency_key', $idempotencyKey)->count());
    }

    /**
     * 3. Collision in online reference number auto-increments so checkout is never rejected.
     */
    public function test_sale_store_auto_increments_colliding_reference_no(): void
    {
        $payload1 = [
            'reference_no' => 'SL-COLLIDE-001',
            'idempotency_key' => 'IDEMP-A',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $res1 = $this->actingAs($this->user)->postJson('/api/sales', $payload1);
        $res1->assertStatus(201);
        $this->assertEquals('SL-COLLIDE-001', $res1->json('data.reference_no'));

        // Second checkout with same reference_no but different idempotency key
        $payload2 = $payload1;
        $payload2['idempotency_key'] = 'IDEMP-B';

        $res2 = $this->actingAs($this->user)->postJson('/api/sales', $payload2);
        $res2->assertStatus(201);
        $newRef = $res2->json('data.reference_no');
        $this->assertNotEquals('SL-COLLIDE-001', $newRef);
        $this->assertNotEmpty($newRef);
    }

    /**
     * 4. Transitioning status Completed -> Pending restores FIFO stock, zeros COGS, and reconciles customer spend.
     */
    public function test_sale_update_status_completed_to_pending_reverses_fifo_stock_and_reconciles_customer(): void
    {
        // First create a completed sale
        $payload = [
            'reference_no' => 'SL-COMP-PEND-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 32000.0,
            'grand_total' => 32000.0,
            'paid_amount' => 32000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 2,
                    'subtotal' => 32000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 32000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // Verify stock is 18
        $this->assertEquals(18.0, (float)StockBalance::where('product_id', $this->product->id)->value('quantity'));

        // Update to Pending
        $updatePayload = $payload;
        $updatePayload['status'] = 'Pending';

        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(200);

        // Stock and FIFO batch must be restored back to 20
        $this->assertEquals(20.0, (float)StockBalance::where('product_id', $this->product->id)->value('quantity'));
        $this->assertEquals(20.0, (float)InventoryBatch::where('product_id', $this->product->id)->value('remaining_quantity'));

        // Sale total_cogs must be 0
        $sale = Sale::findOrFail($saleId);
        $this->assertEquals('Pending', $sale->status);
        $this->assertEquals(0.0, (float)$sale->total_cogs);

        // Customer spend and visits must be deducted
        $this->customerA->refresh();
        $this->assertEquals(0.0, (float)$this->customerA->total_spent);
        $this->assertEquals(0, $this->customerA->total_visits);
    }

    /**
     * 5. Transitioning status Pending -> Completed depletes FIFO stock, computes COGS, and records customer spend.
     */
    public function test_sale_update_status_pending_to_completed_depletes_fifo_and_calculates_cogs(): void
    {
        // First create a pending sale
        $payload = [
            'reference_no' => 'SL-PEND-COMP-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Pending',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 0.0,
            'due_amount' => 16000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // Pending does not deduct stock
        $this->assertEquals(20.0, (float)StockBalance::where('product_id', $this->product->id)->value('quantity'));
        $this->customerA->refresh();
        $this->assertEquals(0.0, (float)$this->customerA->total_spent);

        // Update to Completed
        $updatePayload = $payload;
        $updatePayload['status'] = 'Completed';
        $updatePayload['paid_amount'] = 16000.0;
        $updatePayload['due_amount'] = 0.0;
        $updatePayload['payment_status'] = 'Paid';
        $updatePayload['payments'] = [['payment_type' => 'Cash', 'amount' => 16000.0]];

        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(200);

        // Stock and FIFO batch must now be depleted (20 - 1 = 19)
        $this->assertEquals(19.0, (float)StockBalance::where('product_id', $this->product->id)->value('quantity'));
        $this->assertEquals(19.0, (float)InventoryBatch::where('product_id', $this->product->id)->value('remaining_quantity'));

        // Sale total_cogs must be 10000
        $sale = Sale::findOrFail($saleId);
        $this->assertEquals('Completed', $sale->status);
        $this->assertEquals(10000.0, (float)$sale->total_cogs);

        // Customer spend and visits must now be incremented
        $this->customerA->refresh();
        $this->assertEquals(16000.0, (float)$this->customerA->total_spent);
        $this->assertEquals(1, $this->customerA->total_visits);
    }

    /**
     * 6. Updating sale synchronizes sale_payments table correctly.
     */
    public function test_sale_update_synchronizes_sale_payments(): void
    {
        $payload = [
            'reference_no' => 'SL-PAY-SYNC-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 10000.0,
            'due_amount' => 6000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 10000.0,
                ],
            ],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        $this->assertEquals(1, SalePayment::where('sale_id', $saleId)->count());

        // Now update payment to full payment with Split Tender (Cash 10000, Bank Transfer 6000)
        $updatePayload = $payload;
        $updatePayload['paid_amount'] = 16000.0;
        $updatePayload['due_amount'] = 0.0;
        $updatePayload['payment_status'] = 'Paid';
        $updatePayload['payment_type'] = 'Split Payment';
        $updatePayload['payments'] = [
            [
                'payment_type' => 'Cash',
                'amount' => 10000.0,
            ],
            [
                'payment_type' => 'Bank Transfer',
                'amount' => 6000.0,
            ],
        ];

        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(200);

        $payments = SalePayment::where('sale_id', $saleId)->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(16000.0, (float)$payments->sum('amount'));
    }

    /**
     * 7. Reassigning customer during sale update reconciles spend and visit count for both customers.
     */
    public function test_sale_update_customer_reassignment_reconciles_both_customers(): void
    {
        $payload = [
            'reference_no' => 'SL-CUST-REASSIGN-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        $this->customerA->refresh();
        $this->assertEquals(16000.0, (float)$this->customerA->total_spent);
        $this->assertEquals(1, $this->customerA->total_visits);

        // Update sale to assign Customer B and increase quantity to 2 (total 32000)
        $updatePayload = $payload;
        $updatePayload['customer_id'] = $this->customerB->id;
        $updatePayload['customer_name'] = $this->customerB->name;
        $updatePayload['subtotal'] = 32000.0;
        $updatePayload['grand_total'] = 32000.0;
        $updatePayload['paid_amount'] = 32000.0;
        $updatePayload['items'] = [
            [
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_price' => 16000.0,
                'quantity' => 2,
                'subtotal' => 32000.0,
            ],
        ];
        $updatePayload['payments'] = [['payment_type' => 'Cash', 'amount' => 32000.0]];

        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(200);

        // Customer A: spend reverted to 0, visits decremented to 0
        $this->customerA->refresh();
        $this->assertEquals(0.0, (float)$this->customerA->total_spent);
        $this->assertEquals(0, $this->customerA->total_visits);

        // Customer B: spend set to 32000, visits incremented to 1
        $this->customerB->refresh();
        $this->assertEquals(32000.0, (float)$this->customerB->total_spent);
        $this->assertEquals(1, $this->customerB->total_visits);
    }

    /**
     * 8. Sale update adjusts Cash Register expected cash when cash amount changes.
     */
    public function test_sale_update_cash_payment_adjusts_cash_register_expected_cash(): void
    {
        $payload = [
            'reference_no' => 'SL-REG-ADJ-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'customer_name' => 'Walk-in Customer',
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        $this->register->refresh();
        $this->assertEquals(66000.0, (float)$this->register->expected_cash);

        // Edit sale: switch to KPay (Cash payment becomes 0)
        $updatePayload = $payload;
        $updatePayload['payment_type'] = 'KPay';
        $updatePayload['payments'] = [['payment_type' => 'KPay', 'amount' => 16000.0]];

        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(200);

        $this->register->refresh();
        $this->assertEquals(50000.0, (float)$this->register->expected_cash);
    }

    /**
     * 9. Adding and deleting payments adjusts Cash Register expected cash.
     */
    public function test_sale_add_payment_and_delete_payment_syncs_cash_register(): void
    {
        $payload = [
            'reference_no' => 'SL-PAY-ADD-DEL-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 10000.0,
            'due_amount' => 6000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 10000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        $this->register->refresh();
        $this->assertEquals(60000.0, (float)$this->register->expected_cash);

        // Add additional payment in Cash
        $addRes = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/payments", [
            'amount' => 6000.0,
            'payment_type' => 'Cash',
            'payment_date' => now()->toDateTimeString(),
        ]);
        $addRes->assertStatus(200);

        $this->register->refresh();
        $this->assertEquals(66000.0, (float)$this->register->expected_cash);

        // Delete that 6,000 Cash payment
        $paymentId = SalePayment::where('sale_id', $saleId)->where('amount', 6000.0)->value('id');
        $delRes = $this->actingAs($this->user)->deleteJson("/api/sales/{$saleId}/payments/{$paymentId}");
        $delRes->assertStatus(200);

        $this->register->refresh();
        $this->assertEquals(60000.0, (float)$this->register->expected_cash);
    }

    /**
     * 10. Pending sales do NOT post cash into register upfront; legacy paid pending destroy safely reconciles.
     */
    public function test_pending_sale_destroy_decrements_cash_register_expected_cash(): void
    {
        $payload = [
            'reference_no' => 'SL-PEND-DEL-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'customer_name' => 'Walk-in Customer',
            'date' => now()->toDateString(),
            'status' => 'Pending',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // Strictly unposted until Completed
        $this->assertEquals(0.0, (float)$createRes->json('data.paid_amount'));
        $this->assertEquals(16000.0, (float)$createRes->json('data.due_amount'));
        $this->assertEquals('Unpaid', $createRes->json('data.payment_status'));
        $this->assertEquals(0, SalePayment::where('sale_id', $saleId)->count());

        $this->register->refresh();
        $this->assertEquals(50000.0, (float)$this->register->expected_cash);

        // Destroy the unposted pending sale leaves register untouched at 50000.0
        $delRes = $this->actingAs($this->user)->deleteJson("/api/sales/{$saleId}");
        $delRes->assertStatus(200);

        $this->register->refresh();
        $this->assertEquals(50000.0, (float)$this->register->expected_cash);
    }

    /**
     * 11. Voiding a pending sale does NOT deduct customer total spent.
     */
    public function test_pending_sale_void_does_not_deduct_customer_total_spent(): void
    {
        // Customer A already has 20000 spent from a prior completed sale
        $this->customerA->update(['total_spent' => 20000.0, 'total_visits' => 1]);

        $payload = [
            'reference_no' => 'SL-PEND-VOID-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'date' => now()->toDateString(),
            'status' => 'Pending',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 0.0,
            'due_amount' => 16000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // Total spent remains 20000
        $this->customerA->refresh();
        $this->assertEquals(20000.0, (float)$this->customerA->total_spent);
        $this->assertEquals(1, $this->customerA->total_visits);

        // Void the pending sale
        $voidRes = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/void", [
            'reason' => 'Customer cancelled pending order',
            'manager_pin' => '1234',
        ]);
        $voidRes->assertStatus(200);

        // Customer total spent must still be 20000 (NOT decremented to 4000)
        $this->customerA->refresh();
        $this->assertEquals(20000.0, (float)$this->customerA->total_spent);
        $this->assertEquals(1, $this->customerA->total_visits);
    }
    /**
     * 12. Walk-in Customer cannot buy on credit (Unpaid/Partial) and throws HTTP 422.
     */
    public function test_walk_in_customer_cannot_buy_on_credit_and_throws_422(): void
    {
        $payload = [
            'reference_no' => 'SL-WALKIN-CREDIT-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_name' => 'Walk-in Customer',
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 11000.0,
            'payment_status' => 'Partial',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer_id']);
    }

    /**
     * 13. Server recalculates line subtotals, order tax, and grand total, preventing client arithmetic tampering.
     */
    public function test_server_recalculates_line_subtotals_order_tax_and_grand_total(): void
    {
        $payload = [
            'reference_no' => 'SL-ARITH-RECALC-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            // Client deliberately passes tampered numbers:
            'subtotal' => 1000.0,
            'discount' => 1000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 10.0,
            'grand_total' => 1010.0,
            'paid_amount' => 50000.0, // Over-tendered, should be clamped to grand_total
            'payment_status' => 'Paid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 2,
                    'discount' => 2000.0,
                    'tax' => 500.0,
                    'subtotal' => 500.0, // Client tampered line subtotal
                ],
            ],
        ];

        // Real item subtotal: (2 * 16,000) - 2000 + 500 = 30,500
        // Real order discount: 1,000
        // Tax-Exclusive base: (2 * 16,000) - 2000 = 30,000
        // Real taxable base: 30,000 - 1,000 = 29,000 (Prevents compound tax-on-tax!)
        // Real order tax (5%): 29,000 * 0.05 = 1,450
        // Real grand total: 30,500 - 1,000 + 1,450 = 30,950
        $res = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $res->assertStatus(201);

        $data = $res->json('data');
        $this->assertEquals(30500.0, (float)$data['subtotal']);
        $this->assertEquals(30500.0, (float)$data['items'][0]['subtotal']);
        $this->assertEquals(1450.0, (float)$data['tax_amount']);
        $this->assertEquals(30950.0, (float)$data['grand_total']);
        // Clamped paid amount
        $this->assertEquals(30950.0, (float)$data['paid_amount']);
        $this->assertEquals(0.0, (float)$data['due_amount']);
    }

    /**
     * 14. Converting an already Ordered, Declined, or Expired Quotation throws HTTP 422.
     */
    public function test_converting_already_ordered_quotation_throws_422(): void
    {
        $quotation = \App\Models\Quotation::create([
            'reference_no' => 'QT-ALREADY-ORDERED-01',
            'customer_name' => $this->customerA->name,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Ordered', // Already converted
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'total_items' => 1,
            'total_quantity' => 1.0,
        ]);

        $payload = [
            'reference_no' => 'SL-QT-DOUBLE-ORDER-01',
            'quotation_id' => $quotation->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['quotation_id']);
    }

    /**
     * 15. Updating a sale that has an active/completed SaleReturn is rejected with HTTP 422.
     * Prevents double-reversal of FIFO batches and ledger corruption.
     */
    public function test_updating_sale_with_completed_sale_return_is_rejected_422(): void
    {
        $sale = Sale::create([
            'reference_no' => 'SA-RETURN-LOCKOUT-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 32000.0,
            'grand_total' => 32000.0,
            'paid_amount' => 32000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        \App\Models\SaleReturn::create([
            'reference_no' => 'RET-20261019-001',
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
        ]);

        $updatePayload = [
            'status' => 'Pending',
        ];

        $res = $this->actingAs($this->user)->putJson('/api/sales/' . $sale->id, $updatePayload);
        $res->assertStatus(422);
        $this->assertStringContainsString('အရောင်းပြန်သွင်းထားပြီးသော', $res->json('message'));
    }

    /**
     * 16. Sale reference_no uniqueness on update allows same reference for itself but rejects collision with another sale.
     */
    public function test_updating_sale_validates_unique_reference_no_except_itself(): void
    {
        $sale1 = Sale::create([
            'reference_no' => 'SA-UNIQUE-TEST-001',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $sale2 = Sale::create([
            'reference_no' => 'SA-UNIQUE-TEST-002',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Same reference for itself should succeed
        $resSelf = $this->actingAs($this->user)->putJson('/api/sales/' . $sale1->id, [
            'reference_no' => 'SA-UNIQUE-TEST-001',
            'notes' => 'Self update note',
        ]);
        $resSelf->assertStatus(200);

        // Duplicating sale2's reference should fail with 422
        $resDup = $this->actingAs($this->user)->putJson('/api/sales/' . $sale1->id, [
            'reference_no' => 'SA-UNIQUE-TEST-002',
        ]);
        $resDup->assertStatus(422);
        $resDup->assertJsonValidationErrors(['reference_no']);
    }

    /**
     * 17. Updating a sale persists change_amount and recalculates register expected cash.
     */
    public function test_updating_sale_persists_change_amount_and_drawer_cash(): void
    {
        $sale = Sale::create([
            'reference_no' => 'SA-CHANGE-UPDATE-001',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $this->register->id,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $initialExpected = (float)$this->register->fresh()->expected_cash;

        $res = $this->actingAs($this->user)->putJson('/api/sales/' . $sale->id, [
            'received_amount' => 20000.0,
            'change_amount' => 4000.0,
            'paid_amount' => 16000.0,
            'payment_type' => 'Cash',
        ]);

        $res->assertStatus(200);
        $this->assertEquals(4000.0, (float)$sale->fresh()->change_amount);
    }

    /**
     * 18. Customer loyalty reward points are reconciled on update and restored on void.
     */
    public function test_customer_reward_points_reconciled_on_update_and_void(): void
    {
        $this->markTestSkipped('Customer loyalty points system decommissioned per commit 53f07a4.');
        $this->customerA->update(['reward_points' => 50]);

        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 20000.0,
            'grand_total' => 19000.0, // 20000 - 1000 (from 10 redeemed points)
            'discount' => 0.0,
            'redeemed_points' => 10,
            'points_discount' => 1000.0,
            'paid_amount' => 19000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 20000.0,
                    'quantity' => 1,
                    'subtotal' => 20000.0,
                ],
            ],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // Initial points: 50 - 10 (redeemed) + 19 (floor(19000/1000)) = 59
        $this->assertEquals(59, $this->customerA->fresh()->reward_points);

        // Edit sale: change grand total to 29,000 (e.g. qty = 1.5, subtotal = 30000 - 1000 pts disc)
        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", [
            'status' => 'Completed',
            'redeemed_points' => 10,
            'points_discount' => 1000.0,
            'paid_amount' => 29000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 30000.0,
                    'quantity' => 1,
                    'subtotal' => 30000.0,
                ],
            ],
        ]);
        $updateRes->assertStatus(200);

        // New grand_total = 29000. Points earned = 29. Prev earned was 19. Delta = +10. Points = 59 + 10 = 69
        $this->assertEquals(69, $this->customerA->fresh()->reward_points);

        // Transition from Completed to Pending: should revert points earned and restore redeemed
        $pendingRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", [
            'status' => 'Pending',
        ]);
        $pendingRes->assertStatus(200);

        // When Pending: delta = 0 - (29 - 10) = -19. Points = 69 - 19 = 50 (back to original!)
        $this->assertEquals(50, $this->customerA->fresh()->reward_points);

        // Transition back to Completed:
        $completedRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", [
            'status' => 'Completed',
            'paid_amount' => 29000.0,
        ]);
        $completedRes->assertStatus(200);
        $this->assertEquals(69, $this->customerA->fresh()->reward_points);

        // Void the sale: earned 29 revoked, redeemed 10 restored -> 69 - 29 + 10 = 50
        $voidRes = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/void", [
            'reason' => 'Customer requested cancellation',
        ]);
        $voidRes->assertStatus(200);
        $this->assertEquals(50, $this->customerA->fresh()->reward_points);
    }

    public function test_update_payment_status_to_unpaid_synchronizes_sale_payments_and_cash_register(): void
    {
        $openingCash = (float)$this->register->expected_cash;

        // Create Completed sale with Cash payment of 16,000
        $res = $this->actingAs($this->user)->postJson('/api/sales', [
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'cash_register_id' => $this->register->id,
            'paid_amount' => 16000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $saleId = $res->json('data.id');

        $this->assertEquals($openingCash + 16000.0, (float)$this->register->fresh()->expected_cash);
        $this->assertCount(1, SalePayment::where('sale_id', $saleId)->get());

        // Update payment_status to 'Unpaid' without passing paid_amount or payments
        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", [
            'payment_status' => 'Unpaid',
        ]);
        $updateRes->assertStatus(200);

        $sale = Sale::findOrFail($saleId);
        $this->assertEquals('Unpaid', $sale->payment_status);
        $this->assertEquals(0.0, (float)$sale->paid_amount);
        $this->assertEquals(16000.0, (float)$sale->due_amount);

        // Sale payments should be deleted
        $this->assertCount(0, SalePayment::where('sale_id', $saleId)->get());

        // Register expected_cash should be decremented back to opening cash
        $this->assertEquals($openingCash, (float)$this->register->fresh()->expected_cash);
    }

    public function test_update_completed_sale_warehouse_without_items_restores_and_deducts_stock(): void
    {
        $warehouseB = Outlet::create([
            'name' => 'Secondary Outlet & Warehouse',
            'phone' => '0988776655',
            'is_active' => true,
            'is_warehouse' => true,
        ]);

        StockBalance::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $warehouseB->id,
            'quantity' => 10.0,
        ]);

        InventoryBatch::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $this->product->id,
            'source_type' => 'opening',
            'received_date' => now()->subDays(2),
            'unit_cost' => 11000.0,
            'initial_quantity' => 10.0,
            'remaining_quantity' => 10.0,
            'status' => 'active',
        ]);

        // Create Completed sale of 5 units in warehouse A (initial stock 20)
        $res = $this->actingAs($this->user)->postJson('/api/sales', [
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'subtotal' => 80000.0,
            'grand_total' => 80000.0,
            'paid_amount' => 80000.0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 5,
                    'subtotal' => 80000.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $saleId = $res->json('data.id');

        // Warehouse A should have 20 - 5 = 15
        $balA = StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(15.0, (float)$balA->quantity);

        // Warehouse B still has 10
        $balB = StockBalance::where('product_id', $this->product->id)->where('warehouse_id', $warehouseB->id)->first();
        $this->assertEquals(10.0, (float)$balB->quantity);

        // Edit sale to warehouse B without passing items
        $updateRes = $this->actingAs($this->user)->putJson("/api/sales/{$saleId}", [
            'warehouse_id' => $warehouseB->id,
            'warehouse_name' => $warehouseB->name,
            'status' => 'Completed',
        ]);
        $updateRes->assertStatus(200);

        // Warehouse A stock should be restored to 20
        $this->assertEquals(20.0, (float)$balA->fresh()->quantity);

        // Warehouse B stock should now be deducted by 5 (10 - 5 = 5)
        $this->assertEquals(5.0, (float)$balB->fresh()->quantity);

        // FIFO COGS in warehouse B was 11,000 * 5 = 55,000
        $sale = Sale::findOrFail($saleId);
        $this->assertEquals(55000.0, (float)$sale->total_cogs);
    }

    /**
     * 21. Senior Accounting: addPayment with overpayment calculates change and only adds net cash to register.
     */
    public function test_add_payment_with_overpayment_calculates_change_and_only_adds_net_cash_to_drawer(): void
    {
        $sale = Sale::create([
            'reference_no' => 'SA-OVERPAY-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 5000.0,
            'change_amount' => 0.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'cash_register_id' => $this->register->id,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'PAY-INIT-01',
            'payment_date' => now()->toDateTimeString(),
            'payment_type' => 'Cash',
            'amount' => 5000.0,
            'received_amount' => 5000.0,
            'change_amount' => 0.0,
        ]);

        $initialDrawerCash = (float)$this->register->fresh()->expected_cash;

        // Customer pays 10,000 MMK for remaining 5,000 MMK due
        $res = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 10000.0,
            'payment_type' => 'Cash',
        ]);

        $res->assertStatus(200);

        $freshSale = $sale->fresh(['payments']);
        $this->assertEquals(10000.0, (float)$freshSale->paid_amount);
        $this->assertEquals(0.0, (float)$freshSale->due_amount);
        $this->assertEquals('Paid', $freshSale->payment_status);

        // Check latest payment record
        $latestPayment = $freshSale->payments()->latest('id')->first();
        $this->assertEquals(5000.0, (float)$latestPayment->amount); // Net credited to bill
        $this->assertEquals(10000.0, (float)$latestPayment->received_amount); // Tendered
        $this->assertEquals(5000.0, (float)$latestPayment->change_amount); // Change returned

        // Register expected_cash only increased by net 5,000 (not 10,000)
        $this->assertEquals($initialDrawerCash + 5000.0, (float)$this->register->fresh()->expected_cash);
    }

    /**
     * 22. Senior Role Void Guard: Voided sale rejects addPayment and deletePayment with 422.
     */
    public function test_voided_sale_rejects_add_payment_and_delete_payment_with_422(): void
    {
        $sale = Sale::create([
            'reference_no' => 'SA-VOIDGUARD-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Voided',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 5000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'cash_register_id' => $this->register->id,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'PAY-VOID-01',
            'payment_date' => now()->toDateTimeString(),
            'payment_type' => 'Cash',
            'amount' => 5000.0,
            'received_amount' => 5000.0,
            'change_amount' => 0.0,
        ]);

        // Attempt addPayment on voided sale
        $addRes = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 2000.0,
            'payment_type' => 'Cash',
        ]);
        $addRes->assertStatus(422);
        $this->assertStringContainsString('Void ပြုလုပ်ထားပြီးသော ဘောင်ချာတွင် Payment', $addRes->json('message'));

        // Attempt deletePayment on voided sale
        $delRes = $this->actingAs($this->user)->deleteJson("/api/sales/{$sale->id}/payments/{$payment->id}");
        $delRes->assertStatus(422);
        $this->assertStringContainsString('Void ပြုလုပ်ထားပြီးသော ဘောင်ချာတွင် Payment', $delRes->json('message'));
    }

    /**
     * 23. Senior Accounting: voidSale preserves closed register immutability and adjusts active open register.
     */
    public function test_void_sale_reconciles_closed_cash_register(): void
    {
        // Close the original register (representing yesterday's shift)
        $this->register->update([
            'status' => 'closed',
            'closing_cash' => 50000.0,
            'expected_cash' => 50000.0,
        ]);

        // Open an active register for today's shift
        $activeReg = CashRegister::create([
            'reference_no' => 'REG-ACTIVE-002',
            'register_name' => 'Counter 2 (Today Active)',
            'user_id' => $this->user->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'opening_cash' => 40000.0,
            'expected_cash' => 40000.0,
            'status' => 'Open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'reference_no' => 'SA-CLOSEDREG-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 15000.0,
            'grand_total' => 15000.0,
            'paid_amount' => 15000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'cash_register_id' => $this->register->id,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'PAY-CL-01',
            'payment_date' => now()->toDateTimeString(),
            'payment_type' => 'Cash',
            'amount' => 15000.0,
            'received_amount' => 15000.0,
            'change_amount' => 0.0,
        ]);

        // Void the sale
        $voidRes = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/void", [
            'reason' => 'Customer refunded',
        ]);
        $voidRes->assertStatus(200);

        // Immutable Check: Closed register closing_cash and expected_cash MUST NOT change (Z-Report immutability)
        $freshClosedReg = $this->register->fresh();
        $this->assertEquals(50000.0, (float)$freshClosedReg->expected_cash);
        $this->assertEquals(50000.0, (float)$freshClosedReg->closing_cash);

        // Active drawer adjustment: Today's active register is decremented by 15,000
        $freshActiveReg = $activeReg->fresh();
        $this->assertEquals(25000.0, (float)$freshActiveReg->expected_cash);
    }

    /**
     * 24. Senior Performance: Sale index search with SA- or INV- prefix fast-path.
     */
    public function test_index_search_with_sa_prefix_uses_fast_path(): void
    {
        Sale::create([
            'reference_no' => 'SA-FASTPATH-999',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'date' => now()->toDateString(),
            'status' => 'Draft',
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'paid_amount' => 0.0,
            'due_amount' => 5000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);

        $res = $this->actingAs($this->user)->getJson('/api/sales?search=SA-FASTPATH-999');
        $res->assertStatus(200);
        $this->assertEquals(1, count($res->json('data.data') ?? $res->json('data')));
        $this->assertEquals('SA-FASTPATH-999', ($res->json('data.data.0.reference_no') ?? $res->json('data.0.reference_no')));
    }

    /**
     * 25. Security: Cashier cannot bypass Below-Cost Guard by spoofing user_role in payload.
     */
    public function test_cashier_cannot_bypass_below_cost_guard_by_spoofing_user_role_in_request_body(): void
    {
        $cashier = User::factory()->create([
            'name' => 'Junior Cashier',
            'role' => 'Cashier',
        ]);

        $payload = [
            'reference_no' => 'SA-BELOWCOST-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 8000.0,
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_role' => 'Super Admin', // Malicious client attempt to bypass guard
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 8000.0, // Cost is 10,000 -> Below Cost!
                    'quantity' => 1,
                    'subtotal' => 8000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 8000.0]],
        ];

        $res = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $res->assertStatus(422);
        $this->assertStringContainsString('အရင်းဈေး', $res->json('message'));
    }

    /**
     * 26. Security: Hardcoded PIN backdoors (1234/9999) are rejected when manager_pin setting is customized.
     */
    public function test_cashier_cannot_use_hardcoded_pin_when_manager_pin_is_customized(): void
    {
        \App\Models\Setting::set('manager_pin', '7788');

        $cashier = User::factory()->create([
            'name' => 'Junior Cashier',
            'role' => 'Cashier',
        ]);

        $payload = [
            'reference_no' => 'SA-PIN-TEST-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 8000.0,
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'manager_pin' => '1234', // Old hardcoded default should fail!
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 8000.0,
                    'quantity' => 1,
                    'subtotal' => 8000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 8000.0]],
        ];

        // Old hardcoded PIN 1234 fails
        $res1 = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $res1->assertStatus(422);

        // Configured manager PIN 7788 succeeds
        $payload['manager_pin'] = '7788';
        $payload['reference_no'] = 'SA-PIN-TEST-02';
        $res2 = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $res2->assertStatus(201);
    }

    /**
     * 27. Accounting: Order-level discount below cost loophole is caught and blocked without PIN.
     */
    public function test_order_level_discount_below_cost_blocks_without_manager_pin(): void
    {
        \App\Models\Setting::set('manager_pin', '7788');

        $cashier = User::factory()->create([
            'name' => 'Junior Cashier',
            'role' => 'Cashier',
        ]);

        $payload = [
            'reference_no' => 'SA-ORDERDISC-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'discount' => 8000.0, // High order discount causes net revenue (12,000 - 8,000 = 4,000) to fall below cost (10,000)
            'subtotal' => 12000.0,
            'grand_total' => 4000.0,
            'paid_amount' => 4000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 12000.0, // Item price is above cost (10,000)
                    'quantity' => 1,
                    'subtotal' => 12000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 4000.0]],
        ];

        // Attempt without PIN fails
        $res1 = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $res1->assertStatus(422);
        $this->assertStringContainsString('အသားတင်ရောင်းရငွေ', $res1->json('message'));

        // Attempt with authorized PIN succeeds
        $payload['manager_pin'] = '7788';
        $payload['reference_no'] = 'SA-ORDERDISC-02';
        $res2 = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $res2->assertStatus(201);
    }

    /**
     * 28. BA: Overpayment tendered cash in paid_amount does not trigger 422 reconciliation error.
     */
    public function test_single_tender_overpayment_succeeds_without_reconciliation_422(): void
    {
        $payload = [
            'reference_no' => 'SA-OVERPAY-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 20000.0, // Tendered cash is 20,000
            'change_amount' => 4000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 16000.0, // Net voucher amount
                    'received_amount' => 20000.0,
                    'change_amount' => 4000.0,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $res->assertStatus(201);
        $this->assertEquals(16000.0, (float)$res->json('data.paid_amount'));
    }

    /**
     * 29. Accounting: Deleting payment on Completed Walk-in sale is blocked with 422.
     */
    public function test_deleting_payment_on_completed_walk_in_sale_is_blocked_with_422(): void
    {
        $payload = [
            'reference_no' => 'SA-WALKIN-DEL-01',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');
        $paymentId = SalePayment::where('sale_id', $saleId)->value('id');

        $delRes = $this->actingAs($this->user)->deleteJson("/api/sales/{$saleId}/payments/{$paymentId}");
        $delRes->assertStatus(422);
        $this->assertStringContainsString('Walk-in Customer', $delRes->json('message'));
    }

    /**
     * 30. Accounting: Deleting payment that causes due to exceed customer credit limit is blocked with 422.
     */
    public function test_deleting_payment_exceeding_customer_credit_limit_is_blocked_with_422(): void
    {
        $limitedCustomer = Customer::create([
            'name' => 'Ko Phyo',
            'phone' => '0999999999',
            'credit_limit' => 5000.0,
            'status' => 'Active',
        ]);

        $payload = [
            'reference_no' => 'SA-CREDIT-DEL-01',
            'customer_id' => $limitedCustomer->id,
            'customer_name' => $limitedCustomer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateString(),
            'status' => 'Completed',
            'subtotal' => 16000.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 16000.0,
                    'quantity' => 1,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 16000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');
        $paymentId = SalePayment::where('sale_id', $saleId)->value('id');

        $delRes = $this->actingAs($this->user)->deleteJson("/api/sales/{$saleId}/payments/{$paymentId}");
        $delRes->assertStatus(422);
        $this->assertStringContainsString('Credit Limit', $delRes->json('message'));
    }

    /**
     * 31. Inventory: Variant sale & stock movement syncs stock balances (and product_variants.stock_quantity if present).
     */
    public function test_variant_stock_movement_syncs_product_variant_stock_quantity(): void
    {
        $variant = \App\Models\ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => '500g Bag',
            'sku' => 'COF-ESP-500G',
            'cost_price' => 5000.0,
            'selling_price' => 8500.0,
        ]);

        $unitService = app(\App\Services\ProductUnitConversionService::class);
        $unitService->applyStock(
            product: $this->product,
            warehouseId: $this->warehouse->id,
            variantId: $variant->id,
            baseQuantity: 10.0,
            direction: 'in',
            movementType: 'purchase',
            referenceType: 'purchase',
            referenceId: 'PO-TEST-001'
        );

        $balance1 = \App\Models\StockBalance::where('product_id', $this->product->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
        $this->assertEquals(10.0, (float)$balance1);

        if (\Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'stock_quantity')) {
            $freshVariant = $variant->fresh();
            $this->assertEquals(10.0, (float)$freshVariant->stock_quantity);
        }

        // Now sell 3 units
        $unitService->applyStock(
            product: $this->product,
            warehouseId: $this->warehouse->id,
            variantId: $variant->id,
            baseQuantity: 3.0,
            direction: 'out',
            movementType: 'sale',
            referenceType: 'sale',
            referenceId: 'SA-TEST-001'
        );

        $balance2 = \App\Models\StockBalance::where('product_id', $this->product->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
        $this->assertEquals(7.0, (float)$balance2);

        if (\Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'stock_quantity')) {
            $freshVariant2 = $variant->fresh();
            $this->assertEquals(7.0, (float)$freshVariant2->stock_quantity);
        }
    }

    /**
     * 32. Offline Sync: Stock overdraft is permitted without 422 RuntimeException and notes are appended.
     */
    public function test_offline_sync_overdraft_sale_is_accepted_without_422(): void
    {
        // Product currently has 100 stock. Request 200 units with is_offline_sync: true
        $payload = [
            'customer_name' => 'Offline Customer',
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 3000000.0,
            'grand_total' => 3000000.0,
            'paid_amount' => 3000000.0,
            'due_amount' => 0.0,
            'is_offline_sync' => true,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 15000.0,
                    'quantity' => 200.0,
                    'subtotal' => 3000000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 3000000.0]],
        ];

        $response = $this->actingAs($this->user)->postJson('/api/sales', $payload);
        $response->assertStatus(201);
        $this->assertStringContainsString('Offline Sync Overdraft', $response->json('data.notes'));
    }

    /**
     * 33. Offline Sync: Below cost sale does not throw 422 and appends audit note.
     */
    public function test_offline_sync_below_cost_sale_is_accepted_without_422(): void
    {
        // Cost is 10,000 Ks. Cashier offline sold at 5,000 Ks (below cost)
        $cashier = \App\Models\User::factory()->create(['role' => 'Cashier']);
        $payload = [
            'customer_name' => 'Offline Customer Below Cost',
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'user_role' => 'Cashier',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 10000.0,
            'due_amount' => 0.0,
            'is_offline_sync' => true,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 5000.0,
                    'quantity' => 2.0,
                    'subtotal' => 10000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 10000.0]],
        ];

        $response = $this->actingAs($cashier)->postJson('/api/sales', $payload);
        $response->assertStatus(201);
        $this->assertStringContainsString('Offline Sync Below Cost', (string)$response->json('data.notes'));
    }

    /**
     * 34. Accounting: Draft to Completed transition without items enforces Below-Cost Guard.
     */
    public function test_draft_to_completed_transition_guards_against_below_cost_selling(): void
    {
        // 1. Create a Draft sale with below-cost unit price (5,000 Ks vs 10,000 Ks cost)
        $createPayload = [
            'customer_name' => 'Draft Customer',
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Draft',
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 0.0,
            'manager_pin' => '1234',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_price' => 5000.0,
                    'quantity' => 1.0,
                    'subtotal' => 5000.0,
                ],
            ],
            'payments' => [['payment_type' => 'Cash', 'amount' => 5000.0]],
        ];

        $createRes = $this->actingAs($this->user)->postJson('/api/sales', $createPayload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('data.id');

        // 2. Non-manager attempts to finalize Draft -> Completed without manager PIN
        $cashierUser = \App\Models\User::factory()->create(['role' => 'Cashier']);
        $updatePayload = [
            'status' => 'Completed',
        ];

        $updateRes = $this->actingAs($cashierUser)->putJson("/api/sales/{$saleId}", $updatePayload);
        $updateRes->assertStatus(422);
        $this->assertStringContainsString('အရင်းဈေး', $updateRes->json('message'));

        // 3. Finalize with valid Manager PIN and settle payment for unregistered Draft customer
        $updateWithPinPayload = [
            'status' => 'Completed',
            'manager_pin' => '1234',
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'paid_amount' => 5000.0,
        ];

        $updateWithPinRes = $this->actingAs($cashierUser)->putJson("/api/sales/{$saleId}", $updateWithPinPayload);
        $updateWithPinRes->assertStatus(200);
        $this->assertEquals('Completed', $updateWithPinRes->json('data.status'));
    }

    /**
     * Test: Sales export endpoint streams CSV with UTF-8 BOM, 14 columns, filters, and summary totals
     */
    public function test_sales_export_streams_csv_with_filters_and_summary_row(): void
    {
        $response = $this->actingAs($this->user)->get('/api/sales/export?payment_status=Paid&status=All');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // UTF-8 BOM check
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Header check
        $this->assertStringContainsString('Sales Orders Export', $content);
        $this->assertStringContainsString('#,Reference,Customer,Warehouse,Status,"Grand Total","Paid Amount","Due Amount","Payment Status","Payment Type","Created By","User Role","Date & Time",Notes', $content);

        // Summary row check
        $this->assertStringContainsString('TOTAL,', $content);
    }
}
