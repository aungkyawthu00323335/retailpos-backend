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
                    '*' => ['id', 'reference_no', 'customer_name', 'warehouse_name', 'grand_total', 'status', 'items'],
                ],
                'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertEquals(1, count($response->json('data')));
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
}
