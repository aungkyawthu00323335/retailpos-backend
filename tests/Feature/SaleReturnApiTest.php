<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SaleReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReturnApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_sale_return_with_items_payload(): void
    {
        $outlet = Outlet::create([
            'name' => 'Main Warehouse',
            'phone' => '0912345678',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Original Coffee Blend',
            'code' => 'PRD-COFFEE-01',
            'barcode' => '8850001',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 5000,
            'cost_price' => 5000,
            'selling_price' => 8500,
            'quantity' => 20,
            'alert_quantity' => 5,
        ]);

        $customer = Customer::create([
            'name' => 'Daw Thida',
            'phone' => '09791112233',
            'email' => 'thida@retail.mm',
            'tier' => 'Gold VIP',
            'total_spent' => 50000,
            'reward_points' => 50,
            'status' => 'Active',
            'avatar' => 'https://via.placeholder.com/150',
        ]);

        $payload = [
            'reference_no'      => 'SR_20260922_0001',
            'sale_reference_no' => 'SL_20260920_9999',
            'customer_id'       => $customer->id,
            'customer_name'     => $customer->name,
            'warehouse_name'    => $outlet->name,
            'date'              => '2026-09-22 14:00:00',
            'status'            => 'Completed',
            'tax_rate'          => 0,
            'tax_amount'        => 0,
            'discount'          => 0,
            'shipping'          => 0,
            'subtotal'          => 8500.0,
            'grand_total'       => 8500.0,
            'paid_amount'       => 8500.0,
            'due_amount'        => 0.0,
            'payment_status'    => 'Paid',
            'payment_type'      => 'Cash',
            'refund_tender'     => 'Cash',
            'return_reason'     => 'Customer Changed Mind',
            'notes'             => 'Product unopened and intact',
            'items'             => [
                [
                    'product_id'   => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity'     => 1.0,
                    'unit_price'   => 8500.0,
                    'subtotal'     => 8500.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/sale-returns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Sale return recorded successfully',
            ]);

        $this->assertDatabaseHas('sale_returns', [
            'reference_no'      => 'SR_20260922_0001',
            'sale_reference_no' => 'SL_20260920_9999',
            'customer_name'     => 'Daw Thida',
            'grand_total'       => 8500.0,
            'status'            => 'Completed',
        ]);

        $created = SaleReturn::where('reference_no', 'SR_20260922_0001')->first();
        $this->assertNotNull($created);
        $this->assertNotEmpty($created->items_payload);
        $this->assertCount(1, $created->items);
        $this->assertEquals('PRD-COFFEE-01', $created->items[0]['product_code']);
    }

    public function test_can_list_sale_returns(): void
    {
        SaleReturn::create([
            'reference_no'   => 'SR_TEST_LIST_1',
            'customer_name'  => 'U Min Lwin',
            'warehouse_name' => 'Main Warehouse',
            'date'           => '2026-09-22 10:00:00',
            'grand_total'    => 12000.0,
            'paid_amount'    => 12000.0,
            'status'         => 'Completed',
        ]);

        $response = $this->getJson('/api/sale-returns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data',
            ]);
    }

    public function test_can_create_sale_return_without_items(): void
    {
        $payload = [
            'reference_no'   => 'SR_20260922_NOITEMS',
            'customer_name'  => 'General Walk-in',
            'warehouse_name' => 'Main Branch',
            'date'           => '2026-09-22 15:30:00',
            'status'         => 'Completed',
            'subtotal'       => 5000.0,
            'grand_total'    => 5000.0,
            'paid_amount'    => 5000.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
            'refund_tender'  => 'Cash',
            'items'          => [],
        ];

        $response = $this->postJson('/api/sale-returns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Sale return recorded successfully',
            ]);

        $this->assertDatabaseHas('sale_returns', [
            'reference_no' => 'SR_20260922_NOITEMS',
            'grand_total'  => 5000.0,
        ]);
    }

    public function test_cannot_create_duplicate_completed_return_for_same_sale(): void
    {
        SaleReturn::create([
            'reference_no'      => 'SR_ORIGINAL',
            'sale_reference_no' => 'SL_DUP_CHECK_001',
            'customer_name'     => 'Customer Test',
            'warehouse_name'    => 'Main Warehouse',
            'date'              => '2026-09-22 10:00:00',
            'grand_total'       => 10000.0,
            'paid_amount'       => 10000.0,
            'status'            => 'Completed',
        ]);

        $payload = [
            'reference_no'      => 'SR_SECOND_ATTEMPT',
            'sale_reference_no' => 'SL_DUP_CHECK_001',
            'customer_name'     => 'Customer Test',
            'warehouse_name'    => 'Main Warehouse',
            'date'              => '2026-09-22 11:00:00',
            'status'            => 'Completed',
            'grand_total'       => 10000.0,
            'paid_amount'       => 10000.0,
            'items'             => [],
        ];

        $response = $this->postJson('/api/sale-returns', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }
}
