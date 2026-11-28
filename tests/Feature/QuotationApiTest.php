<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_quotations_with_pagination_and_headers(): void
    {
        $quotation = Quotation::create([
            'reference_no' => 'QUO-TEST-001',
            'customer_name' => 'John Doe',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 250.00,
            'status' => 'Sent',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'P001',
            'product_name' => 'Test Product',
            'unit_price' => 250.00,
            'quantity' => 1.0,
            'subtotal' => 250.00,
        ]);

        $response = $this->getJson('/api/quotations?per_page=10&sort_by=id&sort_order=desc');

        $response->assertStatus(200)
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('ETag'));
        $response->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['id', 'reference_no', 'customer_name', 'warehouse_name', 'grand_total', 'status', 'items_count'],
                ],
                'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertEquals(1, count($response->json('data')));
        $this->assertEquals(1, $response->json('data.0.items_count'));

        // Verify ?include_items=1 includes items relation
        $responseWithItems = $this->getJson('/api/quotations?per_page=10&include_items=1');
        $responseWithItems->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['items'],
                ],
            ]);
        $this->assertCount(1, $responseWithItems->json('data.0.items'));
    }

    public function test_can_create_quotation_with_items(): void
    {
        $payload = [
            'reference_no' => 'QUO-20260829-001',
            'customer_name' => 'Jane Smith',
            'warehouse_name' => 'Downtown Branch',
            'date' => '2026-08-29 12:00:00',
            'tax_rate' => 5.0,
            'tax_amount' => 10.0,
            'discount' => 5.0,
            'shipping' => 0.0,
            'grand_total' => 205.0,
            'status' => 'Sent',
            'notes' => 'Urgent quote',
            'items' => [
                [
                    'product_code' => 'PRD-001',
                    'product_name' => 'Nike Shoes',
                    'unit_price' => 100.0,
                    'quantity' => 2.0,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => 200.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/quotations', $payload);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('quotations', [
            'reference_no' => 'QUO-20260829-001',
            'customer_name' => 'Jane Smith',
        ]);

        $this->assertDatabaseHas('quotation_items', [
            'product_code' => 'PRD-001',
            'quantity' => 2.0,
        ]);
    }

    public function test_can_update_quotation_and_sync_items(): void
    {
        $quotation = Quotation::create([
            'reference_no' => 'QUO-OLD-001',
            'customer_name' => 'Old Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Pending',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'OLD-001',
            'product_name' => 'Old Product',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        $updatePayload = [
            'reference_no' => 'QUO-OLD-001',
            'customer_name' => 'Updated Customer',
            'warehouse_name' => 'Updated Warehouse',
            'date' => '2026-08-30',
            'grand_total' => 300.00,
            'status' => 'Accepted',
            'items' => [
                [
                    'product_code' => 'NEW-001',
                    'product_name' => 'New Product',
                    'unit_price' => 150.00,
                    'quantity' => 2.0,
                    'subtotal' => 300.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/quotations/{$quotation->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'customer_name' => 'Updated Customer',
            'status' => 'Accepted',
        ]);

        // Old item should be deleted, new item present
        $this->assertDatabaseMissing('quotation_items', ['product_code' => 'OLD-001']);
        $this->assertDatabaseHas('quotation_items', ['product_code' => 'NEW-001']);
    }

    public function test_can_delete_quotation_and_cascade_items(): void
    {
        $quotation = Quotation::create([
            'reference_no' => 'QUO-DEL-001',
            'customer_name' => 'To Delete',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 50.00,
            'status' => 'Sent',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'DEL-001',
            'product_name' => 'To Delete Product',
            'unit_price' => 50.00,
            'quantity' => 1.0,
            'subtotal' => 50.00,
        ]);

        $response = $this->deleteJson("/api/quotations/{$quotation->id}");

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseMissing('quotations', ['id' => $quotation->id]);
        $this->assertDatabaseMissing('quotation_items', ['quotation_id' => $quotation->id]);
    }

    public function test_can_update_status_to_accepted_and_declined(): void
    {
        $quotation = Quotation::create([
            'reference_no' => 'QUO-STAT-001',
            'customer_name' => 'Status Test Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Pending',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'STAT-001',
            'product_name' => 'Status Test Item',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        // Update to Accepted
        $resAccepted = $this->putJson("/api/quotations/{$quotation->id}", [
            'customer_name' => 'Status Test Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Accepted',
            'items' => [
                [
                    'product_code' => 'STAT-001',
                    'product_name' => 'Status Test Item',
                    'unit_price' => 100.00,
                    'quantity' => 1.0,
                    'subtotal' => 100.00,
                ],
            ],
        ]);
        $resAccepted->assertStatus(200);
        $this->assertEquals('Accepted', $quotation->fresh()->status);

        // Update to Declined
        $resDeclined = $this->putJson("/api/quotations/{$quotation->id}", [
            'customer_name' => 'Status Test Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Declined',
            'items' => [
                [
                    'product_code' => 'STAT-001',
                    'product_name' => 'Status Test Item',
                    'unit_price' => 100.00,
                    'quantity' => 1.0,
                    'subtotal' => 100.00,
                ],
            ],
        ]);
        $resDeclined->assertStatus(200);
        $this->assertEquals('Declined', $quotation->fresh()->status);
    }

    public function test_cannot_update_or_delete_already_ordered_quotation(): void
    {
        $quotation = Quotation::create([
            'reference_no' => 'QUO-ORDERED-001',
            'customer_name' => 'Ordered Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Ordered',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'ORD-001',
            'product_name' => 'Ordered Item',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        // Guard against update
        $resUpdate = $this->putJson("/api/quotations/{$quotation->id}", [
            'customer_name' => 'Hacker Update',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-30',
            'grand_total' => 500.00,
            'status' => 'Pending',
            'items' => [
                [
                    'product_code' => 'ORD-001',
                    'product_name' => 'Ordered Item',
                    'unit_price' => 500.00,
                    'quantity' => 1.0,
                    'subtotal' => 500.00,
                ],
            ],
        ]);
        $resUpdate->assertStatus(422);

        // Guard against delete
        $resDelete = $this->deleteJson("/api/quotations/{$quotation->id}");
        $resDelete->assertStatus(422);

        // Ensure record is intact
        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'status' => 'Ordered']);
    }

    public function test_cannot_convert_declined_or_expired_quotation(): void
    {
        // Declined
        $declined = Quotation::create([
            'reference_no' => 'QUO-DEC-001',
            'customer_name' => 'Declined Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Declined',
        ]);
        QuotationItem::create([
            'quotation_id' => $declined->id,
            'product_code' => 'DEC-001',
            'product_name' => 'Dec Item',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        $resDec = $this->postJson("/api/quotations/{$declined->id}/convert-to-sale");
        $resDec->assertStatus(422);

        // Expired
        $expired = Quotation::create([
            'reference_no' => 'QUO-EXP-001',
            'customer_name' => 'Expired Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-08-01',
            'expiry_date' => '2026-08-10',
            'grand_total' => 100.00,
            'status' => 'Expired',
        ]);
        QuotationItem::create([
            'quotation_id' => $expired->id,
            'product_code' => 'EXP-001',
            'product_name' => 'Exp Item',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        $resExp = $this->postJson("/api/quotations/{$expired->id}/convert-to-sale");
        $resExp->assertStatus(422);
    }

    public function test_convert_to_sale_tracks_fifo_cogs_customer_spent_and_register(): void
    {
        $warehouse = \App\Models\Outlet::create([
            'name' => 'Central Warehouse',
            'is_warehouse' => true,
        ]);

        $user = \App\Models\User::factory()->create([
            'name' => 'Cashier David',
            'role' => 'Cashier',
        ]);

        $register = \App\Models\CashRegister::create([
            'reference_no' => 'CR-TEST-001',
            'register_name' => 'Main POS Register',
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => 1000.0,
            'expected_cash' => 1000.0,
        ]);

        $customer = \App\Models\Customer::create([
            'name' => 'VIP Customer',
            'email' => 'vip@customer.com',
            'phone' => '09123456789',
            'total_spent' => 500.0,
            'total_visits' => 2,
        ]);

        $product = \App\Models\Product::create([
            'code' => 'QUO-PROD-01',
            'name' => 'Quotation FIFO Product',
            'cost_price' => 40.0,
            'price' => 100.0,
            'stock_quantity' => 20.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        // StockBalance setup
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        // FIFO Batch layer setup (10 pcs @ 40.0)
        app(\App\Services\FifoInventoryService::class)->recordBatch(
            warehouseId: $warehouse->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 10.0,
            baseUnitCost: 40.0,
            sourceType: 'opening_stock',
            sourceReference: 'INIT-QUO-01',
            receivedDate: '2026-08-01',
            notes: 'Initial Batch'
        );

        $quotation = Quotation::create([
            'reference_no' => 'QUO-CONVERT-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'user_id' => $user->id,
            'date' => '2026-08-29',
            'grand_total' => 200.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 'Sent',
        ]);

        $qItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 100.00,
            'quantity' => 2.0,
            'subtotal' => 200.00,
        ]);

        // Call Convert to Sale
        $response = $this->actingAs($user)->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
        ]);

        $response->assertStatus(201);

        // 1. Quotation status marked Ordered
        $this->assertEquals('Ordered', $quotation->fresh()->status);

        // 2. Sale created and has quotation_id and cash_register_id
        $sale = \App\Models\Sale::where('quotation_id', $quotation->id)->first();
        $this->assertNotNull($sale);
        $this->assertEquals($warehouse->id, $sale->warehouse_id);
        $this->assertEquals($register->id, $sale->cash_register_id);
        $this->assertEquals(200.0, (float)$sale->grand_total);
        // COGS for 2 pcs @ 40.0 = 80.0
        $this->assertEquals(80.0, (float)$sale->total_cogs);

        // 3. SaleItem has accurate COGS and unit cost
        $saleItem = \App\Models\SaleItem::where('sale_id', $sale->id)->first();
        $this->assertNotNull($saleItem);
        $this->assertEquals(40.0, (float)$saleItem->base_unit_cost);
        $this->assertEquals(80.0, (float)$saleItem->total_cogs);

        // 4. Customer total_spent incremented (500 + 200 = 700)
        $this->assertEquals(700.0, (float)$customer->fresh()->total_spent);
        $this->assertEquals(3, (int)$customer->fresh()->total_visits);

        // 5. Cash Register expected_cash incremented (1000 + 200 = 1200)
        $this->assertEquals(1200.0, (float)$register->fresh()->expected_cash);
    }

    public function test_convert_to_sale_handles_overpayment_and_change_correctly(): void
    {
        $warehouse = \App\Models\Outlet::create([
            'name' => 'Main Outlet',
            'is_warehouse' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'Cashier']);
        $register = \App\Models\CashRegister::create([
            'reference_no' => 'CR-TEST-OVERPAY',
            'register_name' => 'POS Register',
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => 500.0,
            'expected_cash' => 500.0,
        ]);
        $product = \App\Models\Product::create([
            'code' => 'PROD-OVERPAY',
            'name' => 'Product Overpay',
            'cost_price' => 20.0,
            'price' => 100.0,
            'stock_quantity' => 10.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10.0,
        ]);

        $quotation = Quotation::create([
            'reference_no' => 'QUO-OVERPAY-01',
            'customer_name' => 'General Customer',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        // Customer pays 150 for 100 grand total (50 change)
        $response = $this->actingAs($user)->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 150.0,
            'received_amount' => 150.0,
            'payment_type' => 'Cash',
        ]);

        $response->assertStatus(201);
        $sale = \App\Models\Sale::where('quotation_id', $quotation->id)->first();
        $this->assertNotNull($sale);
        $this->assertEquals(100.00, (float)$sale->paid_amount);
        $this->assertEquals(0.00, (float)$sale->due_amount);
        $this->assertEquals(50.00, (float)$sale->change_amount);

        // Cash register must ONLY be incremented by the actual sale paid_amount (100.0), NOT 150.0!
        $this->assertEquals(600.0, (float)$register->fresh()->expected_cash);
    }

    public function test_convert_to_sale_walk_in_credit_guard_blocks_unpaid(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Branch A', 'is_warehouse' => true]);
        $quotation = Quotation::create([
            'reference_no' => 'QUO-WALKIN-01',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'P1',
            'product_name' => 'Item 1',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        // Attempting to convert walk-in with paid_amount = 0 (credit)
        $res = $this->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 0.0,
            'payment_type' => 'Cash',
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_convert_to_sale_customer_credit_limit_guard(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Branch B', 'is_warehouse' => true]);
        $customer = \App\Models\Customer::create([
            'name' => 'Credit Limited Customer',
            'credit_limit' => 200.0,
        ]);
        // Existing unpaid sale of 150
        \App\Models\Sale::create([
            'reference_no' => 'SA-EXIST-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-08-29 10:00:00',
            'status' => 'Completed',
            'grand_total' => 150.0,
            'paid_amount' => 0.0,
            'due_amount' => 150.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
            'subtotal' => 150.0,
        ]);

        $quotation = Quotation::create([
            'reference_no' => 'QUO-CREDIT-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-08-29',
            'grand_total' => 100.00,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_code' => 'P2',
            'product_name' => 'Item 2',
            'unit_price' => 100.00,
            'quantity' => 1.0,
            'subtotal' => 100.00,
        ]);

        // Attempting to convert quotation on credit where 150 + 100 = 250 > 200 limit
        $res = $this->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 0.0,
            'payment_type' => 'Cash',
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['credit_limit']);
    }

    public function test_convert_to_sale_below_cost_guard_blocks_without_pin(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Branch C', 'is_warehouse' => true]);
        $product = \App\Models\Product::create([
            'code' => 'P-BELOW',
            'name' => 'Costly Item',
            'cost_price' => 80.0,
            'price' => 100.0,
            'stock_quantity' => 10.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10.0,
        ]);

        // Quotation discounted unit_price to 50 (below cost of 80)
        $quotation = Quotation::create([
            'reference_no' => 'QUO-BELOW-01',
            'customer_name' => 'Normal Customer',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'user_role' => 'Cashier',
            'date' => '2026-08-29',
            'grand_total' => 50.00,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 50.00,
            'quantity' => 1.0,
            'subtotal' => 50.00,
        ]);

        // Non-manager cashier without manager_pin
        $user = \App\Models\User::factory()->create(['role' => 'Cashier']);
        $res = $this->actingAs($user)->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 50.0,
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['below_cost']);
    }

    public function test_expiry_date_today_does_not_immediately_expire(): void
    {
        $today = date('Y-m-d');
        $payload = [
            'reference_no' => 'QUO-TODAY-01',
            'customer_name' => 'Today Customer',
            'warehouse_name' => 'Main Branch',
            'date' => $today . ' 10:00:00',
            'expiry_date' => $today,
            'grand_total' => 100.0,
            'items' => [
                [
                    'product_code' => 'PTODAY',
                    'product_name' => 'Today Item',
                    'unit_price' => 100.0,
                    'quantity' => 1.0,
                    'subtotal' => 100.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/quotations', $payload);
        $res->assertStatus(201);
        $this->assertEquals('Sent', $res->json('data.status'));
    }

    public function test_quotations_list_returns_live_no_store_response_without_304_caching(): void
    {
        Quotation::create([
            'reference_no' => 'QUO-LIVE-001',
            'customer_name' => 'Live Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-21 10:00:00',
            'grand_total' => 100.00,
            'status' => 'Sent',
        ]);

        $res1 = $this->getJson('/api/quotations?per_page=10');
        $res1->assertStatus(200);
        $this->assertNull($res1->headers->get('ETag'));
        $this->assertStringContainsString('no-store', (string) $res1->headers->get('Cache-Control'));

        Quotation::create([
            'reference_no' => 'QUO-LIVE-002',
            'customer_name' => 'Live Customer 2',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-22 10:00:00',
            'grand_total' => 250.00,
            'status' => 'Sent',
        ]);

        $res2 = $this->withHeaders(['If-None-Match' => '"stale-etag"'])->getJson('/api/quotations?per_page=10');
        $res2->assertStatus(200);
        $this->assertSame(2, $res2->json('total'));
    }

    public function test_can_export_quotations_to_streamed_csv_with_utf8_bom_and_totals(): void
    {
        Quotation::create([
            'reference_no' => 'QUO-EXP-001',
            'customer_name' => 'Alice Customer',
            'warehouse_name' => 'Yangon Central',
            'date' => '2026-10-21 10:00:00',
            'tax_rate' => 5.0,
            'tax_amount' => 500.0,
            'discount' => 100.0,
            'shipping' => 50.0,
            'grand_total' => 10450.0,
            'status' => 'Sent',
        ]);

        Quotation::create([
            'reference_no' => 'QUO-EXP-002',
            'customer_name' => 'Bob Customer',
            'warehouse_name' => 'Mandalay Hub',
            'date' => '2026-10-21 11:00:00',
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 5000.0,
            'status' => 'Ordered',
        ]);

        // 1. Full export without filters
        $response = $this->get('/api/quotations/export');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('quotations_export_', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"), 'Export must start with UTF-8 BOM for Excel');
        $this->assertStringContainsString('Quotations / Estimates Export', $content);
        $this->assertStringContainsString('QUO-EXP-001', $content);
        $this->assertStringContainsString('QUO-EXP-002', $content);
        $this->assertStringContainsString('TOTAL,"2 Quotations",—,—,—,—,500,100,50,15450,—,—,—,—,—,—', $content);

        // 2. Filtered export with status=Sent
        $filteredResponse = $this->get('/api/quotations/export?status=Sent');
        $filteredResponse->assertStatus(200);
        $filteredContent = $filteredResponse->streamedContent();
        $this->assertStringContainsString('QUO-EXP-001', $filteredContent);
        $this->assertStringNotContainsString('QUO-EXP-002', $filteredContent);
        $this->assertStringContainsString('TOTAL,"1 Quotations",—,—,—,—,500,100,50,10450,—,—,—,—,—,—', $filteredContent);
    }

    public function test_sequential_quotation_code_generation_and_concurrent_collision_reallocation(): void
    {
        $gen1 = $this->getJson('/api/quotations/generate-code');
        $gen1->assertStatus(200);
        $previewRef = $gen1->json('reference_no');
        $this->assertNotEmpty($previewRef);

        $basePayload = [
            'customer_name' => 'Walk-in Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-22',
            'grand_total' => 100.0,
            'status' => 'Sent',
            'items' => [
                [
                    'product_code' => 'ITEM-A',
                    'product_name' => 'Item A',
                    'unit_price' => 100.0,
                    'quantity' => 1.0,
                    'subtotal' => 100.0,
                ],
            ],
        ];

        // First client uses previewed sequential code
        $res1 = $this->postJson('/api/quotations', array_merge($basePayload, [
            'reference_no' => $previewRef,
        ]));
        $res1->assertStatus(201);
        $this->assertEquals($previewRef, $res1->json('data.reference_no'));

        // Concurrent second client submits same stale previewed code: must NOT 422, must reallocate next sequential code
        $res2 = $this->postJson('/api/quotations', array_merge($basePayload, [
            'reference_no' => $previewRef,
        ]));
        $res2->assertStatus(201);
        $secondRef = $res2->json('data.reference_no');
        $this->assertNotEquals($previewRef, $secondRef);

        // Third client omits reference_no: must auto-allocate sequentially after second
        $res3 = $this->postJson('/api/quotations', $basePayload);
        $res3->assertStatus(201);
        $thirdRef = $res3->json('data.reference_no');
        $this->assertNotEquals($secondRef, $thirdRef);

        preg_match('/(\d+)$/', $previewRef, $m1);
        preg_match('/(\d+)$/', $secondRef, $m2);
        preg_match('/(\d+)$/', $thirdRef, $m3);
        $this->assertEquals((int) $m1[1] + 1, (int) $m2[1]);
        $this->assertEquals((int) $m2[1] + 1, (int) $m3[1]);
    }

    public function test_senior_accounting_item_and_order_tax_discount_and_zero_subtotal_conversion(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Accounting WH', 'is_warehouse' => true]);
        $user = \App\Models\User::factory()->create(['name' => 'Acct User', 'role' => 'Admin']);
        $customer = \App\Models\Customer::create([
            'name' => 'Enterprise Client',
            'email' => 'ent@client.com',
            'phone' => '0999888777',
        ]);
        $product = \App\Models\Product::create([
            'code' => 'ACCT-01',
            'name' => 'Enterprise Unit',
            'cost_price' => 30.0,
            'price' => 100.0,
            'stock_quantity' => 20.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        // Paid item: 2 * 100 - 20 disc = 180 tax-exclusive (+ 9 item tax = 189 subtotal);
        // Free promo item: 1 * 50 - 50 disc = 0 subtotal.
        // Tax-exclusive Order Base: (180 + 0) - 19 orderDisc = 161.00 taxable base;
        // 10% Order Tax (16.10) + Shipping (13.00) -> Grand Total = (189 + 0) - 19 + 16.10 + 13 = 199.10
        $res = $this->actingAs($user)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-22',
            'discount' => 19.0,
            'tax_rate' => 10.0,
            'tax_amount' => 16.1,
            'shipping' => 13.0,
            'grand_total' => 199.1,
            'status' => 'Sent',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_price' => 100.0,
                    'quantity' => 2.0,
                    'discount' => 20.0,
                    'tax' => 9.0,
                    'subtotal' => 189.0,
                ],
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => 'Free Bonus Unit',
                    'unit_price' => 50.0,
                    'quantity' => 1.0,
                    'discount' => 50.0,
                    'tax' => 0.0,
                    'subtotal' => 0.0,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $quoId = (int) $res->json('data.id');
        $this->assertEquals(16.1, (float) $res->json('data.tax_amount'));
        $this->assertEquals(199.1, (float) $res->json('data.grand_total'));

        $convertRes = $this->actingAs($user)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'paid_amount' => 199.1,
            'payment_type' => 'Cash',
        ]);
        $convertRes->assertStatus(201);

        $sale = \App\Models\Sale::where('quotation_id', $quoId)->first();
        $this->assertNotNull($sale);
        $this->assertEquals(199.1, (float) $sale->grand_total);
        $this->assertEquals(19.0, (float) $sale->discount);
        $this->assertEquals(10.0, (float) $sale->tax_rate);
        $this->assertEquals(16.1, (float) $sale->tax_amount);
        $this->assertEquals(13.0, (float) $sale->shipping);

        $saleItems = \App\Models\SaleItem::where('sale_id', $sale->id)->orderBy('id')->get();
        $this->assertCount(2, $saleItems);
        $this->assertEquals('Enterprise Unit', $saleItems[0]->product_name);
        $this->assertEquals(189.0, (float) $saleItems[0]->subtotal);
        $this->assertEquals('Free Bonus Unit', $saleItems[1]->product_name);
        $this->assertEquals(0.0, (float) $saleItems[1]->subtotal);
    }

    public function test_convert_to_sale_enforces_warehouse_isolated_stock_balance(): void
    {
        $whA = \App\Models\Outlet::create(['name' => 'Warehouse Alpha', 'is_warehouse' => true]);
        $whB = \App\Models\Outlet::create(['name' => 'Warehouse Beta', 'is_warehouse' => true]);
        $user = \App\Models\User::factory()->create(['role' => 'Admin']);
        $product = \App\Models\Product::create([
            'code' => 'ISO-WH-01',
            'name' => 'Isolated Stock Item',
            'cost_price' => 20.0,
            'price' => 50.0,
            'stock_quantity' => 10.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        // Stock exists ONLY in Warehouse Beta (none in Alpha)
        \App\Models\StockBalance::create([
            'warehouse_id' => $whB->id,
            'product_id' => $product->id,
            'quantity' => 10.0,
        ]);

        $quotation = Quotation::create([
            'reference_no' => 'QUO-ISO-WH-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $whA->id,
            'warehouse_name' => $whA->name,
            'date' => '2026-10-22',
            'grand_total' => 100.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 50.0,
            'quantity' => 2.0,
            'subtotal' => 100.0,
        ]);

        $res = $this->actingAs($user)->postJson("/api/quotations/{$quotation->id}/convert-to-sale", [
            'paid_amount' => 100.0,
        ]);
        $res->assertStatus(422);
    }

    public function test_filtered_csv_export_with_customer_search_creator_sort_and_iso_date(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Export Buyer Co',
            'email' => 'buyer@export.com',
            'phone' => '0955544433',
        ]);

        Quotation::create([
            'reference_no' => 'QUO-ISO-MATCH',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_name' => 'Yangon Central',
            'user_name' => 'Alice Creator',
            'user_role' => 'Admin',
            'date' => '2026-10-21T23:30:00Z',
            'grand_total' => 8800.0,
            'status' => 'Sent',
        ]);

        Quotation::create([
            'reference_no' => 'QUO-ISO-OTHER',
            'customer_name' => 'Other Customer',
            'warehouse_name' => 'Yangon Central',
            'user_name' => 'Bob Creator',
            'user_role' => 'Cashier',
            'date' => '2026-10-22T01:00:00Z',
            'grand_total' => 1200.0,
            'status' => 'Ordered',
        ]);

        $res = $this->get(
            '/api/quotations/export?status=Sent&customer_id=' . $customer->id
            . '&warehouse=Yangon%20Central&user_role=Admin&to_date=2026-10-21&sort_by=creator&sort_order=asc'
        );
        $res->assertStatus(200);
        $csv = $res->streamedContent();
        $this->assertStringContainsString('QUO-ISO-MATCH', $csv);
        $this->assertStringNotContainsString('QUO-ISO-OTHER', $csv);
        $this->assertStringContainsString('TOTAL,"1 Quotations",—,—,—,—,0,0,0,8800,—,—,—,—,—,—', $csv);

        $sortedRes = $this->get('/api/quotations/export?sort_by=total&sort_order=asc');
        $sortedRes->assertStatus(200);
        $sortedCsv = $sortedRes->streamedContent();
        $this->assertLessThan(
            strpos($sortedCsv, 'QUO-ISO-MATCH'),
            strpos($sortedCsv, 'QUO-ISO-OTHER'),
            'Export should sort ascending by grand_total when sort_by=total'
        );
    }

    public function test_sale_store_from_quotation_preserves_flat_tax_and_blocks_declined_or_reconversion(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Sale Sync Store', 'is_warehouse' => false]);
        $customer = \App\Models\Customer::create(['name' => 'Accepted Buyer', 'email' => 'acc@test.com']);
        $user = \App\Models\User::factory()->create(['role' => 'Admin']);
        $product = \App\Models\Product::create([
            'code' => 'SALE-QUO-01',
            'name' => 'Convertible SKU',
            'cost_price' => 40.0,
            'price' => 100.0,
            'stock_quantity' => 50.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 50.0,
        ]);

        // Accepted quotation with flat tax (tax_rate = 0, tax_amount = 500)
        $acceptedQuo = Quotation::create([
            'reference_no' => 'QUO-FLAT-TAX-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-23',
            'tax_rate' => 0.0,
            'tax_amount' => 500.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 2500.0,
            'status' => 'Accepted',
        ]);
        QuotationItem::create([
            'quotation_id' => $acceptedQuo->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 1000.0,
            'quantity' => 2.0,
            'subtotal' => 2000.0,
        ]);

        $salePayload = [
            'quotation_id' => $acceptedQuo->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-23 12:00:00',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'tax_rate' => 0.0,
            'tax_amount' => 500.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'subtotal' => 2000.0,
            'grand_total' => 2500.0,
            'paid_amount' => 2500.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_price' => 1000.0,
                    'quantity' => 2.0,
                    'discount' => 0.0,
                    'tax' => 0.0,
                    'subtotal' => 2000.0,
                ],
            ],
        ];

        $res = $this->actingAs($user)->postJson('/api/sales', $salePayload);
        $res->assertStatus(201);
        $this->assertEquals(500.0, (float) $res->json('data.tax_amount'));
        $this->assertEquals(2500.0, (float) $res->json('data.grand_total'));
        $this->assertEquals('Ordered', $acceptedQuo->fresh()->status);

        // Duplicate conversion via POST /api/sales must be blocked (422)
        $dupRes = $this->actingAs($user)->postJson('/api/sales', $salePayload);
        $dupRes->assertStatus(422);

        // Declined quotation conversion must be blocked (422)
        $declinedQuo = Quotation::create([
            'reference_no' => 'QUO-DECLINED-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-23',
            'grand_total' => 1000.0,
            'status' => 'Declined',
        ]);
        $salePayload['quotation_id'] = $declinedQuo->id;
        $declinedRes = $this->actingAs($user)->postJson('/api/sales', $salePayload);
        $declinedRes->assertStatus(422);
    }

    public function test_manual_ordered_status_is_rejected_and_ordered_quotes_are_immutable(): void
    {
        $createRes = $this->postJson('/api/quotations', [
            'reference_no' => 'QUO-MAN-ORD-01',
            'customer_name' => 'Walk-in',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-25',
            'grand_total' => 500.0,
            'status' => 'Ordered',
            'items' => [
                [
                    'product_code' => 'P-01',
                    'product_name' => 'Item 1',
                    'unit_price' => 500.0,
                    'quantity' => 1.0,
                    'subtotal' => 500.0,
                ],
            ],
        ]);
        $createRes->assertStatus(422);

        $quo = Quotation::create([
            'reference_no' => 'QUO-OPEN-01',
            'customer_name' => 'Walk-in',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-25',
            'grand_total' => 500.0,
            'status' => 'Sent',
        ]);

        $updateToOrdered = $this->putJson("/api/quotations/{$quo->id}", [
            'status' => 'Ordered',
            'grand_total' => 500.0,
            'items' => [
                [
                    'product_code' => 'P-01',
                    'product_name' => 'Item 1',
                    'unit_price' => 500.0,
                    'quantity' => 1.0,
                    'subtotal' => 500.0,
                ],
            ],
        ]);
        $updateToOrdered->assertStatus(422);

        $quo->update(['status' => 'Ordered']);

        $this->putJson("/api/quotations/{$quo->id}", [
            'status' => 'Accepted',
            'grand_total' => 500.0,
        ])->assertStatus(422);

        $this->deleteJson("/api/quotations/{$quo->id}")
            ->assertStatus(422);
    }

    public function test_expired_quotation_conversion_blocked_and_sale_void_restores_expired_or_sent(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Expiry WH', 'is_warehouse' => true]);
        $customer = \App\Models\Customer::create(['name' => 'Expiry Client', 'phone' => '09111222']);
        $product = \App\Models\Product::create([
            'code' => 'EXP-PROD-01',
            'name' => 'Expiry Product',
            'cost_price' => 40.0,
            'price' => 100.0,
            'stock_quantity' => 20.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        // Past-due quote still labeled 'Sent' must be blocked on both conversion endpoints
        $expiredSentQuo = Quotation::create([
            'reference_no' => 'QUO-PAST-DUE-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'shipping' => 0.0,
            'grand_total' => 200.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $expiredSentQuo->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'unit_price' => 100.0,
            'quantity' => 2.0,
            'subtotal' => 200.0,
        ]);

        $this->postJson("/api/quotations/{$expiredSentQuo->id}/convert-to-sale", [
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        $this->postJson('/api/sales', [
            'quotation_id' => $expiredSentQuo->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'payment_type' => 'Cash',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_price' => 100.0,
                    'quantity' => 2.0,
                    'subtotal' => 200.0,
                ],
            ],
        ])->assertStatus(422);

        // Declined quote must NOT be overwritten by quotations:expire; overdue Sent becomes Expired
        $declinedPastQuo = Quotation::create([
            'reference_no' => 'QUO-PAST-DEC-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'grand_total' => 100.0,
            'status' => 'Declined',
        ]);
        $this->artisan('quotations:expire')->assertExitCode(0);
        $this->assertEquals('Expired', $expiredSentQuo->fresh()->status);
        $this->assertEquals('Declined', $declinedPastQuo->fresh()->status);

        // Future-valid quotation converts cleanly, and voiding sale sets Sent or Expired based on expiry_date
        $validQuo = Quotation::create([
            'reference_no' => 'QUO-VOID-REV-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'expiry_date' => now()->addDays(7)->toDateString(),
            'grand_total' => 100.0,
            'status' => 'Accepted',
        ]);
        QuotationItem::create([
            'quotation_id' => $validQuo->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'unit_price' => 100.0,
            'quantity' => 1.0,
            'subtotal' => 100.0,
        ]);

        $convRes = $this->postJson("/api/quotations/{$validQuo->id}/convert-to-sale", [
            'paid_amount' => 100.0,
            'payment_type' => 'Cash',
        ]);
        $convRes->assertStatus(201);
        $saleId = (int) (\App\Models\Sale::where('quotation_id', $validQuo->id)->value('id'));
        $this->assertGreaterThan(0, $saleId);
        $this->assertEquals('Ordered', $validQuo->fresh()->status);

        // Simulate quote expiry occurring before sale void
        $validQuo->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/sales/{$saleId}/void", ['reason' => 'Customer cancelled'])
            ->assertStatus(200);
        $this->assertEquals('Expired', $validQuo->fresh()->status);
    }

    public function test_summary_segregates_open_pipeline_from_ordered_declined_and_past_due(): void
    {
        $futureDate = now()->addDays(14)->toDateString();
        $pastDate = now()->subDays(2)->toDateString();

        Quotation::create([
            'reference_no' => 'QUO-PIPE-1',
            'customer_name' => 'C1',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'expiry_date' => $futureDate,
            'grand_total' => 1000.0,
            'status' => 'Sent',
        ]);
        Quotation::create([
            'reference_no' => 'QUO-PIPE-2',
            'customer_name' => 'C2',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'expiry_date' => $futureDate,
            'grand_total' => 500.0,
            'status' => 'Accepted',
        ]);
        Quotation::create([
            'reference_no' => 'QUO-PAST-OPEN',
            'customer_name' => 'C3',
            'warehouse_name' => 'Main',
            'date' => '2026-10-01',
            'expiry_date' => $pastDate,
            'grand_total' => 400.0,
            'status' => 'Pending',
        ]);
        Quotation::create([
            'reference_no' => 'QUO-ORD-1',
            'customer_name' => 'C4',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'grand_total' => 2000.0,
            'status' => 'Ordered',
        ]);
        Quotation::create([
            'reference_no' => 'QUO-DEC-1',
            'customer_name' => 'C5',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'grand_total' => 300.0,
            'status' => 'Declined',
        ]);

        $res = $this->getJson('/api/quotations');
        $res->assertStatus(200);

        $this->assertEquals(1500.0, (float) $res->json('summary.pipeline_total'));
        $this->assertEquals(2, (int) $res->json('summary.pipeline_count'));
        $this->assertEquals(2000.0, (float) $res->json('summary.converted_total'));
        $this->assertEquals(1, (int) $res->json('summary.converted_count'));
        $this->assertEquals(700.0, (float) $res->json('summary.lost_total'));
        $this->assertEquals(2, (int) $res->json('summary.lost_count'));
    }

    public function test_rbac_convert_only_and_cost_masking_and_variant_and_cash_register_controls(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'RBAC WH', 'is_warehouse' => true]);
        $customer = \App\Models\Customer::create(['name' => 'RBAC Buyer', 'phone' => '09887766']);

        $simpleProduct = \App\Models\Product::create([
            'code' => 'COST-01',
            'name' => 'Confidential Cost Product',
            'cost_price' => 450.0,
            'purchase_price' => 440.0,
            'price' => 800.0,
            'stock_quantity' => 30.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $simpleProduct->id,
            'quantity' => 30.0,
        ]);

        $variableProduct = \App\Models\Product::create([
            'code' => 'VAR-PARENT-01',
            'name' => 'Variable Shirt',
            'has_variants' => true,
            'cost_price' => 100.0,
            'price' => 300.0,
            'stock_quantity' => 10.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        // Role with quotation read+convert ONLY (no sales.create, no products.view_cost)
        $convertRole = \App\Models\Role::create([
            'name' => 'Quote Converter',
            'slug' => 'quote_converter',
            'permissions' => [
                'quotations.view',
                'quotations.convert_sale',
                'sales.view',
            ],
            'is_default' => false,
            'status' => 'active',
        ]);
        $converterUser = \App\Models\User::create([
            'user_id' => 'USR-CONV-01',
            'name' => 'Converter Staff',
            'email' => 'converter@retailpos.test',
            'role' => 'Quote Converter',
            'role_id' => $convertRole->id,
            'status' => 'active',
            'password' => bcrypt('secret123'),
            'api_token' => 'converter_token_12345',
        ]);
        $converterUser->outlets()->sync([$warehouse->id]);

        $register = \App\Models\CashRegister::create([
            'reference_no' => 'CR-TEST-01',
            'register_name' => 'Front Desk',
            'user_id' => $converterUser->id,
            'user_name' => $converterUser->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'opening_cash' => 1000.0,
            'expected_cash' => 1000.0,
            'status' => 'Open',
            'opened_at' => now(),
        ]);

        $quo = Quotation::create([
            'reference_no' => 'QUO-RBAC-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'expiry_date' => now()->addDays(5)->toDateString(),
            'grand_total' => 800.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quo->id,
            'product_id' => $simpleProduct->id,
            'product_code' => $simpleProduct->code,
            'product_name' => $simpleProduct->name,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'unit_price' => 800.0,
            'quantity' => 1.0,
            'subtotal' => 800.0,
        ]);

        // Cost fields must be masked on show and index when caller lacks products.view_cost
        $showRes = $this->withToken($converterUser->api_token)->getJson("/api/quotations/{$quo->id}");
        $showRes->assertStatus(200);
        $this->assertArrayNotHasKey('cost_price', $showRes->json('data.items.0.product'));
        $this->assertArrayNotHasKey('purchase_price', $showRes->json('data.items.0.product'));

        $indexRes = $this->withToken($converterUser->api_token)->getJson('/api/quotations?include_items=1');
        $indexRes->assertStatus(200);
        $indexProduct = $indexRes->json('data.0.items.0.product');
        $this->assertTrue($indexProduct === null || !array_key_exists('cost_price', $indexProduct));

        // Caller WITH products.view_cost receives cost_price normally
        $costViewerRole = \App\Models\Role::create([
            'name' => 'Cost Viewer',
            'slug' => 'cost_viewer',
            'permissions' => ['quotations.view', 'products.view_cost'],
            'is_default' => false,
            'status' => 'active',
        ]);
        $costViewer = \App\Models\User::create([
            'user_id' => 'USR-COST-01',
            'name' => 'Senior Accountant',
            'email' => 'accountant@retailpos.test',
            'role' => 'Cost Viewer',
            'role_id' => $costViewerRole->id,
            'status' => 'active',
            'password' => bcrypt('secret123'),
            'api_token' => 'cost_viewer_token_12345',
        ]);
        $costViewer->outlets()->sync([$warehouse->id]);
        $adminShowRes = $this->withToken($costViewer->api_token)->getJson("/api/quotations/{$quo->id}");
        $adminShowRes->assertStatus(200);
        $this->assertEquals(450.0, (float) $adminShowRes->json('data.items.0.product.cost_price'));

        // Direct sale without quotation_id must be 403 for convert-only role
        $this->withToken($converterUser->api_token)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'payment_type' => 'Cash',
            'subtotal' => 800.0,
            'grand_total' => 800.0,
            'paid_amount' => 800.0,
            'items' => [
                [
                    'product_id' => $simpleProduct->id,
                    'product_code' => $simpleProduct->code,
                    'product_name' => $simpleProduct->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_price' => 800.0,
                    'quantity' => 1.0,
                    'subtotal' => 800.0,
                ],
            ],
        ])->assertStatus(403);

        // Conversion with valid quotation_id succeeds and increments 'Open' CashRegister
        $convViaSale = $this->withToken($converterUser->api_token)->postJson('/api/sales', [
            'quotation_id' => $quo->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'payment_type' => 'Cash',
            'subtotal' => 800.0,
            'grand_total' => 800.0,
            'paid_amount' => 800.0,
            'items' => [
                [
                    'product_id' => $simpleProduct->id,
                    'product_code' => $simpleProduct->code,
                    'product_name' => $simpleProduct->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_price' => 800.0,
                    'quantity' => 1.0,
                    'subtotal' => 800.0,
                ],
            ],
        ]);
        $convViaSale->assertStatus(201);
        $this->assertEquals(1800.0, (float) $register->fresh()->expected_cash);

        // Ad-hoc / uncataloged product conversion must be rejected (422)
        $adhocQuo = Quotation::create([
            'reference_no' => 'QUO-ADHOC-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'grand_total' => 500.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $adhocQuo->id,
            'product_id' => null,
            'product_code' => 'UNLISTED-999',
            'product_name' => 'Custom Service Only',
            'unit_price' => 500.0,
            'quantity' => 1.0,
            'subtotal' => 500.0,
        ]);
        $this->withToken($converterUser->api_token)
            ->postJson("/api/quotations/{$adhocQuo->id}/convert-to-sale", [
                'paid_amount' => 500.0,
                'payment_type' => 'Cash',
            ])->assertStatus(422);

        // Variable parent product without concrete product_variant_id must be rejected (422)
        $parentVarQuo = Quotation::create([
            'reference_no' => 'QUO-VAR-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'grand_total' => 300.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $parentVarQuo->id,
            'product_id' => $variableProduct->id,
            'product_variant_id' => null,
            'product_code' => $variableProduct->code,
            'product_name' => $variableProduct->name,
            'unit_price' => 300.0,
            'quantity' => 1.0,
            'subtotal' => 300.0,
        ]);
        $this->withToken($converterUser->api_token)
            ->postJson("/api/quotations/{$parentVarQuo->id}/convert-to-sale", [
                'paid_amount' => 300.0,
                'payment_type' => 'Cash',
            ])->assertStatus(422);
    }

    public function test_export_csv_uses_dynamic_currency_and_appends_segregated_breakdown_rows(): void
    {
        \App\Models\Currency::create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'symbol' => '$',
            'rate' => 1.0,
            'is_default' => true,
            'status' => 'active',
        ]);

        Quotation::create([
            'reference_no' => 'QUO-CSV-USD-1',
            'customer_name' => 'Alpha Co',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'expiry_date' => now()->addDays(10)->toDateString(),
            'grand_total' => 120.0,
            'status' => 'Sent',
        ]);
        Quotation::create([
            'reference_no' => 'QUO-CSV-USD-2',
            'customer_name' => 'Beta Co',
            'warehouse_name' => 'Main',
            'date' => '2026-10-20',
            'grand_total' => 80.0,
            'status' => 'Ordered',
        ]);

        $res = $this->get('/api/quotations/export');
        $res->assertStatus(200);
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Grand Total (USD)', $csv);
        $this->assertStringNotContainsString('(MMK)', $csv);
        $this->assertStringContainsString('TOTAL,"2 Quotations"', $csv);
        $this->assertStringContainsString('PIPELINE (Active Open)', $csv);
        $this->assertStringContainsString('ORDERED (Converted)', $csv);
        $this->assertStringContainsString('CLOSED LOST', $csv);
    }

    public function test_p0_p1_p2_cache_invalidation_split_payments_void_reconversion_variant_cap_and_csv(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Quo Audit WH', 'is_warehouse' => true]);
        $customer = \App\Models\Customer::create(['name' => 'Enterprise Buyer', 'phone' => '0911122233']);
        $user = \App\Models\User::factory()->create(['role' => 'Admin']);
        $product = \App\Models\Product::create([
            'code' => 'QUO-AUD-01',
            'name' => 'Audit Gadget',
            'cost_price' => 200.0,
            'price' => 500.0,
            'stock_quantity' => 20.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        // P1: Parent variable product without product_variant_id is blocked at creation
        $varParent = \App\Models\Product::create([
            'code' => 'QUO-VAR-P',
            'name' => 'Variable Parent',
            'has_variants' => true,
            'cost_price' => 100.0,
            'price' => 200.0,
            'stock_quantity' => 5.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        $this->actingAs($user)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'grand_total' => 200.0,
            'items' => [[
                'product_id' => $varParent->id,
                'product_code' => $varParent->code,
                'product_name' => $varParent->name,
                'unit_price' => 200.0,
                'quantity' => 1.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(422);

        // P1: Over-discount is capped cleanly at items subtotal
        $overDiscRes = $this->actingAs($user)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'discount' => 999.0,
            'grand_total' => 0.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 200.0,
                'quantity' => 1.0,
                'subtotal' => 200.0,
            ]],
        ]);
        $overDiscRes->assertStatus(201);
        $this->assertEquals(200.0, (float) $overDiscRes->json('data.discount'));
        $this->assertEquals(0.0, (float) $overDiscRes->json('data.grand_total'));

        // P2: Export CSV sanitizes formulas and displays effective Expired status for past-due Sent quote
        Quotation::create([
            'reference_no' => 'QUO-EXP-CSV',
            'customer_name' => 'Buyer',
            'warehouse_name' => $warehouse->name,
            'date' => now()->subDays(5)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'grand_total' => 150.0,
            'status' => 'Sent',
            'notes' => '=SUM(A1)',
        ]);
        $csvRes = $this->actingAs($user)->get('/api/quotations/export');
        $csvRes->assertStatus(200);
        $csvContent = $csvRes->streamedContent();
        $this->assertStringContainsString("'=SUM(A1)", $csvContent);
        $this->assertStringContainsString('QUO-EXP-CSV', $csvContent);
        $this->assertStringContainsString(',Expired,', $csvContent);

        // Create convertible quotation with item-level tax & order-level tax
        $quoRes = $this->actingAs($user)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => now()->toDateString(),
            'expiry_date' => now()->addDays(5)->toDateString(),
            'tax_rate' => 10.0,
            'tax_amount' => 50.0,
            'grand_total' => 560.0,
            'status' => 'Sent',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 500.0,
                'quantity' => 1.0,
                'tax' => 10.0,
                'discount' => 0.0,
                'subtotal' => 510.0,
            ]],
        ]);
        $quoRes->assertStatus(201);
        $quoId = (int) $quoRes->json('data.id');

        // P0: Prime dashboard & profit-loss caches BEFORE conversion
        $today = now()->toDateString();
        $beforeDash = $this->getJson("/api/reports/dashboard-summary?date_filter=custom&start_date={$today}&end_date={$today}");
        $beforeDash->assertStatus(200);
        $this->assertEquals(0.0, (float) $beforeDash->json('data.total_sales'));

        $openReg = \App\Models\CashRegister::create([
            'reference_no' => 'CR-OPEN-Q1',
            'register_name' => 'Main Drawer',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'opening_cash' => 100.0,
            'expected_cash' => 100.0,
            'status' => 'Open',
            'opened_at' => now(),
        ]);
        $closedReg = \App\Models\CashRegister::create([
            'reference_no' => 'CR-CLOSED-Q2',
            'register_name' => 'Closed Drawer',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'opening_cash' => 50.0,
            'expected_cash' => 50.0,
            'status' => 'Closed',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subHours(2),
        ]);

        // P1: Converting into explicit closed cash register must be rejected (422)
        $this->actingAs($user)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'cash_register_id' => $closedReg->id,
            'payment_type' => 'Cash',
            'paid_amount' => 560.0,
        ])->assertStatus(422);

        // P1: Convert with Split Payment (Cash 300 + KPay 260) on Open Register -> only +300 goes to expected_cash
        $conv1 = $this->actingAs($user)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'cash_register_id' => $openReg->id,
            'payment_type' => 'Split Payment',
            'paid_amount' => 560.0,
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 300.0],
                ['payment_type' => 'KPay', 'amount' => 260.0],
            ],
        ]);
        $conv1->assertStatus(201);
        $sale1Id = (int) $conv1->json('data.sale.id');
        $this->assertEquals(400.0, (float) $openReg->fresh()->expected_cash);
        $this->assertEquals(2, \App\Models\SalePayment::where('sale_id', $sale1Id)->count());

        // P0: Dashboard cache MUST already reflect new sale WITHOUT force_refresh!
        // GAAP tax-exclusive total_sales = 500.0 (560.0 gross_invoiced_sales - 60.0 commercial tax)
        $afterDash = $this->getJson("/api/reports/dashboard-summary?date_filter=custom&start_date={$today}&end_date={$today}");
        $afterDash->assertStatus(200);
        $this->assertEquals(500.0, (float) $afterDash->json('data.total_sales'));
        $this->assertEquals(560.0, (float) $afterDash->json('data.gross_invoiced_sales'));

        // P2: Commercial Tax Report includes BOTH order tax (50) and line item tax (10) = 60, taxable sales = 500
        $taxRes = $this->getJson("/api/reports/commercial-tax?start_date={$today}&end_date={$today}&force_refresh=1");
        $taxRes->assertStatus(200);
        $this->assertEquals(60.0, (float) $taxRes->json('output_tax.gross_sales_tax'));
        $this->assertEquals(500.0, (float) $taxRes->json('output_tax.taxable_sales_amount'));

        // P1: Void Sale #1 and re-convert Quotation -> Quotation::sale() must return Sale #2, NOT Voided Sale #1!
        $this->actingAs($user)->postJson("/api/sales/{$sale1Id}/void", ['reason' => 'Re-issue test'])->assertStatus(200);
        $conv2 = $this->actingAs($user)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'cash_register_id' => $openReg->id,
            'payment_type' => 'KPay',
            'paid_amount' => 560.0,
        ]);
        $conv2->assertStatus(201);
        $sale2Id = (int) $conv2->json('data.sale.id');
        $sale2Ref = (string) $conv2->json('data.sale.reference_no');
        $this->assertNotEquals($sale1Id, $sale2Id);

        $quoShow = $this->actingAs($user)->getJson("/api/quotations/{$quoId}");
        $quoShow->assertStatus(200);
        $this->assertEquals($sale2Id, (int) $quoShow->json('data.sale_id'));
        $this->assertEquals($sale2Ref, (string) $quoShow->json('data.sale_reference_no'));

        // P2: Live cost sensitivity differs cleanly between cost-viewer and non-cost-viewer without cached responses
        $costViewerRole = \App\Models\Role::create([
            'name' => 'Cost Viewer Live',
            'slug' => 'cost_viewer_live',
            'permissions' => ['quotations.view', 'products.view_cost'],
            'status' => 'active',
        ]);
        $costViewerUser = \App\Models\User::create([
            'name' => 'CostViewer',
            'email' => 'costviewer-live@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $costViewerRole->id,
            'status' => 'active',
            'api_token' => 'quo_live_costviewer_tok',
        ]);
        $noCostRole = \App\Models\Role::create([
            'name' => 'No Cost Live',
            'slug' => 'no_cost_live',
            'permissions' => ['quotations.view'],
            'status' => 'active',
        ]);
        $noCostUser = \App\Models\User::create([
            'name' => 'NoCost',
            'email' => 'nocost-live@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $noCostRole->id,
            'status' => 'active',
            'api_token' => 'quo_live_nocost_tok',
        ]);
        $adminRes = $this->withToken($costViewerUser->api_token)->getJson('/api/quotations?include_items=1');
        $adminRes->assertStatus(200);
        $restrictedRes = $this->withToken($noCostUser->api_token)->getJson('/api/quotations?include_items=1');
        $restrictedRes->assertStatus(200);
        $this->assertNull($adminRes->headers->get('ETag'));
        $this->assertNull($restrictedRes->headers->get('ETag'));
        $this->assertStringContainsString('no-store', (string) $adminRes->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $restrictedRes->headers->get('Cache-Control'));
    }

    public function test_p2_5_clears_expiry_date_to_null_and_prevents_authenticated_actor_spoofing(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Quote WH P2-5', 'is_warehouse' => true]);
        $customer = \App\Models\Customer::create(['name' => 'Quote Client P2-5', 'phone' => '09111999']);
        $product = \App\Models\Product::create([
            'code' => 'QUO-P2-5-01',
            'name' => 'Quote Product P2-5',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 20.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
        \App\Models\StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        $role = \App\Models\Role::create([
            'name' => 'Sales Rep P2-5',
            'slug' => 'sales_rep_p2_5',
            'permissions' => ['quotations.view', 'quotations.create', 'quotations.update', 'quotations.convert_sale'],
            'status' => 'active',
        ]);
        $salesUser = \App\Models\User::create([
            'name' => 'Real Salesperson',
            'email' => 'sales-p2-5@test.local',
            'password' => bcrypt('Secret123!'),
            'role' => 'Sales Rep P2-5',
            'role_id' => $role->id,
            'status' => 'active',
            'api_token' => 'p2_5_real_sales_tok',
        ]);
        $salesUser->outlets()->sync([$warehouse->id]);
        $approverUser = \App\Models\User::create([
            'name' => 'Real Approver',
            'email' => 'approver-p2-5@test.local',
            'password' => bcrypt('Secret123!'),
            'role' => 'Sales Rep P2-5',
            'role_id' => $role->id,
            'status' => 'active',
            'api_token' => 'p2_5_real_approver_tok',
        ]);
        $approverUser->outlets()->sync([$warehouse->id]);

        $storeRes = $this->withToken($salesUser->api_token)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-20 10:00:00',
            'expiry_date' => '2026-12-31',
            'grand_total' => 200.0,
            'status' => 'Sent',
            'created_by' => 'Spoofed Actor',
            'user_name' => 'Spoofed Actor',
            'user_id' => 99999,
            'user_role' => 'Fake Role',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 100.0,
                'quantity' => 2.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(201);

        $quoId = (int)$storeRes->json('data.id');
        $quo = Quotation::findOrFail($quoId);
        $this->assertSame('Real Salesperson', $quo->created_by);
        $this->assertSame('Real Salesperson', $quo->user_name);
        $this->assertEquals($salesUser->id, (int)$quo->user_id);
        $this->assertStringStartsWith('2026-12-31', (string)$quo->expiry_date);

        // PUT with expiry_date => null clears expiry_date, and preserves original creator despite spoofed fields
        $updRes = $this->withToken($approverUser->api_token)->putJson("/api/quotations/{$quoId}", [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-20 10:00:00',
            'expiry_date' => null,
            'grand_total' => 200.0,
            'status' => 'Sent',
            'created_by' => 'Spoofed Updater',
            'user_name' => 'Spoofed Updater',
            'user_id' => 99999,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 100.0,
                'quantity' => 2.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(200);

        $freshQuo = $quo->fresh();
        $this->assertNull($freshQuo->expiry_date);
        $this->assertSame('Real Salesperson', $freshQuo->created_by);
        $this->assertEquals($salesUser->id, (int)$freshQuo->user_id);

        // convertToSale ignores spoofed created_by/user_id and credits authenticated converter
        $convRes = $this->withToken($approverUser->api_token)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
            'created_by' => 'Spoofed Cashier',
            'user_id' => 99999,
        ])->assertStatus(201);

        $sale = \App\Models\Sale::where('quotation_id', $quoId)->firstOrFail();
        $this->assertSame('Real Approver', $sale->created_by);
        $this->assertEquals($approverUser->id, (int)$sale->user_id);
    }

    public function test_p2_quotation_normalizes_iso_utc_date_to_app_timezone(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'TZ Quote WH', 'is_warehouse' => true]);
        $product = \App\Models\Product::create([
            'code' => 'QUO-TZ-01',
            'name' => 'TZ Quote Item',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 10.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $isoUtc = '2026-10-21T23:30:00Z';
        $expectedLocal = \Carbon\Carbon::parse($isoUtc)
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');

        $res = $this->postJson('/api/quotations', [
            'customer_name' => 'TZ Client',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => $isoUtc,
            'grand_total' => 100.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 100.0,
                'quantity' => 1.0,
                'subtotal' => 100.0,
            ]],
        ])->assertStatus(201);

        $this->assertSame($expectedLocal, (string)$res->json('data.date'));
    }

    public function test_p0_p2_quotation_convert_hardening_and_date_only_expiry_serialization(): void
    {
        $warehouse = \App\Models\Outlet::create(['name' => 'Convert Hardening WH', 'is_warehouse' => true]);
        $product = \App\Models\Product::create([
            'code' => 'QUO-HRD-01',
            'name' => 'Hardening Item',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 20.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $otherRole = \App\Models\Role::create([
            'name' => 'Other Cashier',
            'slug' => 'other_cashier_q',
            'permissions' => ['sales.create'],
            'status' => 'active',
        ]);
        $otherCashier = \App\Models\User::create([
            'name' => 'Other Cashier',
            'email' => 'other-cashier-q@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        $otherCashier->outlets()->sync([$warehouse->id]);
        $converterRole = \App\Models\Role::create([
            'name' => 'Converter User',
            'slug' => 'converter_user_q',
            'permissions' => ['quotations.view', 'quotations.create', 'quotations.convert_sale', 'sales.create'],
            'status' => 'active',
        ]);
        $converter = \App\Models\User::create([
            'name' => 'Converter User',
            'email' => 'converter-q@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $converterRole->id,
            'status' => 'active',
        ]);
        $converter->outlets()->sync([$warehouse->id]);

        // Open register belongs strictly to otherCashier (NOT converter)
        $otherReg = \App\Models\CashRegister::create([
            'reference_no' => 'CR-OTHER-Q1',
            'user_id' => $otherCashier->id,
            'user_name' => $otherCashier->name,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'opening_cash' => 100.0,
            'expected_cash' => 100.0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. Empty items quotation cannot convert to sale (422)
        $emptyQuo = \App\Models\Quotation::create([
            'reference_no' => 'QT-EMPTY-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-20 10:00:00',
            'grand_total' => 500.0,
            'status' => 'Sent',
        ]);
        $this->actingAs($converter)->postJson("/api/quotations/{$emptyQuo->id}/convert-to-sale", [
            'paid_amount' => 500.0,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        // 2. Expiry date serializes strictly as YYYY-MM-DD (never UTC Z midnight)
        $quoRes = $this->actingAs($converter)->postJson('/api/quotations', [
            'customer_name' => 'Valid Client',
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'date' => '2026-10-20 10:00:00',
            'expiry_date' => '2026-12-15',
            'grand_total' => 200.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 100.0,
                'quantity' => 2.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(201);
        $this->assertSame('2026-12-15', (string)$quoRes->json('data.expiry_date'));
        $quoId = (int)$quoRes->json('data.id');

        // 3. Explicit paid_amount: null rejected (422)
        $this->actingAs($converter)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'paid_amount' => null,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        // 4. Stale quotation grand_total is recomputed from items, change_amount clamped, and otherCashier register is NOT hijacked
        \App\Models\Quotation::whereKey($quoId)->update(['grand_total' => 9999.0]);
        $convRes = $this->actingAs($converter)->postJson("/api/quotations/{$quoId}/convert-to-sale", [
            'payment_type' => 'Cash',
            'paid_amount' => 200.0,
            'received_amount' => 250.0,
            'change_amount' => 999.0,
        ])->assertStatus(201);

        $sale = \App\Models\Sale::findOrFail((int)$convRes->json('data.sale.id'));
        $this->assertEqualsWithDelta(200.0, (float)$sale->grand_total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float)$sale->paid_amount, 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$sale->change_amount, 0.01);
        $this->assertNull($sale->cash_register_id);
        $this->assertEqualsWithDelta(100.0, (float)$otherReg->fresh()->expected_cash, 0.01);
        $this->assertArrayNotHasKey('total_cogs', $convRes->json('data.sale'));
    }

    /**
     * Regression guard for MySQL 8.0+ Error 1525 (Incorrect DATE value: ''):
     * Native DATE column `quotations.expiry_date` must NEVER be compared against empty string `''`
     * in summary stats, status filters, or the auto-expire artisan command.
     */
    public function test_quotation_queries_never_compare_date_column_to_empty_string(): void
    {
        Quotation::create([
            'reference_no' => 'QT-M8-OPEN',
            'customer_name' => 'Alpha Co',
            'warehouse_name' => 'Main Warehouse',
            'date' => now()->toDateTimeString(),
            'expiry_date' => null,
            'grand_total' => 1000.0,
            'status' => 'Sent',
        ]);
        Quotation::create([
            'reference_no' => 'QT-M8-FUTURE',
            'customer_name' => 'Beta Co',
            'warehouse_name' => 'Main Warehouse',
            'date' => now()->toDateTimeString(),
            'expiry_date' => now()->addDays(5)->toDateString(),
            'grand_total' => 2500.0,
            'status' => 'Pending',
        ]);
        Quotation::create([
            'reference_no' => 'QT-M8-PAST',
            'customer_name' => 'Gamma Co',
            'warehouse_name' => 'Main Warehouse',
            'date' => now()->subDays(10)->toDateTimeString(),
            'expiry_date' => now()->subDays(2)->toDateString(),
            'grand_total' => 500.0,
            'status' => 'Sent',
        ]);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $indexRes = $this->getJson('/api/quotations?page=1&per_page=50&no_items=1&sort_by=date&sort_order=desc');
        $indexRes->assertStatus(200);
        $this->assertEquals(3, $indexRes->json('summary.total_records'));
        $this->assertEquals(2, $indexRes->json('summary.pipeline_count'));
        $this->assertEqualsWithDelta(3500.0, (float) $indexRes->json('summary.pipeline_total'), 0.01);
        $this->assertEquals(1, $indexRes->json('summary.lost_count'));
        $this->assertEqualsWithDelta(500.0, (float) $indexRes->json('summary.lost_total'), 0.01);

        $expiredRes = $this->getJson('/api/quotations?status=Expired&page=1&per_page=50&no_items=1');
        $expiredRes->assertStatus(200);
        $this->assertCount(1, $expiredRes->json('data'));
        $this->assertSame('QT-M8-PAST', $expiredRes->json('data.0.reference_no'));

        $sentRes = $this->getJson('/api/quotations?status=Sent&page=1&per_page=50&no_items=1');
        $sentRes->assertStatus(200);
        $this->assertCount(1, $sentRes->json('data'));
        $this->assertSame('QT-M8-OPEN', $sentRes->json('data.0.reference_no'));

        $this->artisan('quotations:expire')->assertExitCode(0);
        $this->assertSame('Expired', Quotation::where('reference_no', 'QT-M8-PAST')->value('status'));

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        foreach ($queries as $entry) {
            $sql = strtolower((string) ($entry['query'] ?? ''));
            $bindings = $entry['bindings'] ?? [];
            if (str_contains($sql, 'expiry_date')) {
                $this->assertDoesNotMatchRegularExpression(
                    '/expiry_date[`"\s]*(!?=|<>)\s*\'\'/',
                    $sql,
                    "Unsafe MySQL 8 DATE comparison against empty string in SQL: {$sql}"
                );
                foreach ($bindings as $binding) {
                    $this->assertNotSame(
                        '',
                        $binding,
                        "Unsafe empty-string binding on expiry_date query: {$sql}"
                    );
                }
            }
        }
    }
}
