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
            ->assertHeader('Cache-Control')
            ->assertHeader('ETag')
            ->assertJsonStructure([
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
            'status' => 'Ordered',
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
            'status' => 'Ordered',
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

    public function test_etag_returns_304_when_data_is_unmodified(): void
    {
        Quotation::create([
            'reference_no' => 'QUO-ETAG-001',
            'customer_name' => 'Etag Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => '2026-10-21 10:00:00',
            'grand_total' => 100.00,
            'status' => 'Sent',
        ]);

        $res1 = $this->getJson('/api/quotations?per_page=10');
        $res1->assertStatus(200);
        $etag = $res1->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $res2 = $this->withHeaders(['If-None-Match' => $etag])->getJson('/api/quotations?per_page=10');
        $res2->assertStatus(304);
        $this->assertEquals($etag, $res2->headers->get('ETag'));
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
}
