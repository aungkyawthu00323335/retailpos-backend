<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemCostLayer;
use App\Models\SaleReturn;
use App\Models\StockBalance;
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

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        $batch = InventoryBatch::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'initial_quantity' => 22,
            'remaining_quantity' => 20,
            'unit_cost' => 5000,
            'source_type' => 'purchase',
            'source_reference' => 'PO-BASE-01',
            'received_date' => '2026-09-10 10:00:00',
            'status' => 'active',
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

        $sale = Sale::create([
            'reference_no' => 'SL_20260920_9999',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->subDay()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 17000.0,
            'grand_total' => 17000.0,
            'paid_amount' => 17000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'unit_price' => 8500.0,
            'base_unit_price' => 8500.0,
            'base_unit_cost' => 5000.0,
            'total_cogs' => 10000.0,
            'subtotal' => 17000.0,
        ]);

        SaleItemCostLayer::create([
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItem->id,
            'inventory_batch_id' => $batch->id,
            'base_quantity' => 2.0,
            'unit_cost' => 5000.0,
            'total_cost' => 10000.0,
        ]);

        $payload = [
            'reference_no'      => 'SR_20260922_0001',
            'sale_reference_no' => 'SL_20260920_9999',
            'customer_id'       => $customer->id,
            'customer_name'     => $customer->name,
            'warehouse_name'    => $outlet->name,
            'date'              => now()->toDateTimeString(),
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
        $this->assertEquals(5000.0, (float)$created->return_cost_total);
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

    public function test_rejects_sale_return_without_items_or_valid_sale(): void
    {
        $payload = [
            'reference_no'   => 'SR_20260922_NOITEMS',
            'customer_name'  => 'General Walk-in',
            'warehouse_name' => 'Main Branch',
            'date'           => now()->toDateTimeString(),
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
        $response->assertStatus(422);
    }

    public function test_cannot_exceed_cumulative_return_quantity_for_same_sale(): void
    {
        $outlet = Outlet::create(['name' => 'Main Warehouse', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Duplicate Check Coffee',
            'code' => 'PRD-DUP-01',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 4000,
            'cost_price' => 4000,
            'selling_price' => 10000,
            'quantity' => 10,
            'alert_quantity' => 2,
        ]);

        $sale = Sale::create([
            'reference_no' => 'SL_DUP_CHECK_001',
            'customer_name' => 'Customer Test',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->subDay()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 10000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'unit_price' => 10000.0,
            'base_unit_price' => 10000.0,
            'subtotal' => 10000.0,
        ]);

        SaleReturn::create([
            'reference_no'      => 'SR_ORIGINAL',
            'sale_id'           => $sale->id,
            'sale_reference_no' => 'SL_DUP_CHECK_001',
            'customer_name'     => 'Customer Test',
            'warehouse_id'      => $outlet->id,
            'warehouse_name'    => $outlet->name,
            'date'              => now()->toDateTimeString(),
            'grand_total'       => 10000.0,
            'paid_amount'       => 10000.0,
            'status'            => 'Completed',
            'items_payload'     => json_encode([[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'base_quantity' => 1.0,
                'unit_price' => 10000.0,
                'subtotal' => 10000.0,
            ]]),
        ]);

        $payload = [
            'reference_no'      => 'SR_SECOND_ATTEMPT',
            'sale_reference_no' => 'SL_DUP_CHECK_001',
            'customer_name'     => 'Customer Test',
            'warehouse_name'    => $outlet->name,
            'date'              => now()->toDateTimeString(),
            'status'            => 'Completed',
            'grand_total'       => 10000.0,
            'paid_amount'       => 10000.0,
            'items'             => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'unit_price' => 10000.0,
            ]],
        ];

        $response = $this->postJson('/api/sale-returns', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }
}
