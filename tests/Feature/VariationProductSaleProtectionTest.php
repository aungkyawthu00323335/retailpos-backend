<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariationProductSaleProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $warehouse;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->warehouse = Outlet::create([
            'name' => 'Main Outlet',
            'code' => 'MO-01',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'name' => 'Walk-in Customer (General)',
            'phone' => '09123456789',
        ]);
    }

    public function test_cannot_sell_parent_variable_product_directly_online(): void
    {
        $parent = Product::create([
            'name' => 'i max',
            'code' => 'IMAX10000',
            'sku' => 'IMAX10000',
            'has_variants' => true,
            'cost_price' => 5000,
            'selling_price' => 10000,
            'retail_price' => 10000,
            'status' => 'Active',
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $parent->id,
            'name' => 'Size 33',
            'sku' => '1000020',
            'selling_price' => 10000,
            'cost_price' => 5000,
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $parent->id,
            'product_variant_id' => $v1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        $payload = [
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'items' => [
                [
                    'product_id' => $parent->id,
                    'product_variant_id' => null, // Parent without variant!
                    'product_code' => $parent->code,
                    'product_name' => $parent->name,
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'subtotal' => 10000,
                ],
            ],
            'paid_amount' => 10000,
            'payment_type' => 'Cash',
        ];

        $res = $this->actingAs($this->admin)->postJson('/api/sales', $payload);

        $this->assertTrue(
            $res->status() >= 400,
            'Expected server to reject direct sale of parent variable product, got: ' . $res->status()
        );
    }

    public function test_can_sell_variation_product_with_variant_id(): void
    {
        $parent = Product::create([
            'name' => 'i max',
            'code' => 'IMAX10000',
            'sku' => 'IMAX10000',
            'has_variants' => true,
            'cost_price' => 5000,
            'selling_price' => 10000,
            'retail_price' => 10000,
            'status' => 'Active',
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $parent->id,
            'name' => 'Size 33',
            'sku' => '1000020',
            'selling_price' => 10000,
            'cost_price' => 5000,
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $parent->id,
            'product_variant_id' => $v1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        $payload = [
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'items' => [
                [
                    'product_id' => $parent->id,
                    'product_variant_id' => $v1->id, // Specific variant!
                    'product_code' => $v1->sku,
                    'product_name' => $parent->name,
                    'variant_name' => $v1->name,
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'subtotal' => 10000,
                ],
            ],
            'paid_amount' => 10000,
            'payment_type' => 'Cash',
        ];

        $res = $this->actingAs($this->admin)->postJson('/api/sales', $payload);

        if (!$res->isSuccessful()) {
            $this->fail('Sale creation failed: ' . json_encode($res->json()));
        }
        $this->assertTrue($res->isSuccessful());
        $this->assertDatabaseHas('sale_items', [
            'product_id' => $parent->id,
            'product_variant_id' => $v1->id,
        ]);
    }

    public function test_offline_bulk_sync_succeeds_and_is_idempotent(): void
    {
        $parent = Product::create([
            'name' => 'i max',
            'code' => 'IMAX10000',
            'sku' => 'IMAX10000',
            'has_variants' => true,
            'cost_price' => 5000,
            'selling_price' => 10000,
            'retail_price' => 10000,
            'status' => 'Active',
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $parent->id,
            'name' => 'Size 33',
            'sku' => '1000020',
            'selling_price' => 10000,
            'cost_price' => 5000,
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $parent->id,
            'product_variant_id' => $v1->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        $offlineSale = [
            'reference_no' => 'SA_0010001',
            'idempotency_key' => 'pos_uuid_test_12345',
            'is_offline_sync' => true,
            'allow_offline_sync' => true,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'items' => [
                [
                    'product_id' => $parent->id,
                    'product_variant_id' => $v1->id,
                    'product_code' => $v1->sku,
                    'product_name' => $parent->name,
                    'variant_name' => $v1->name,
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'subtotal' => 10000,
                ],
            ],
            'paid_amount' => 10000,
            'payment_type' => 'Cash',
        ];

        // 1. First bulk sync call
        $res1 = $this->actingAs($this->admin)->postJson('/api/sales/bulk-sync', [
            'sales' => [$offlineSale],
        ]);
        $this->assertTrue($res1->isSuccessful());
        $data1 = $res1->json();
        $this->assertEquals('success', $data1['status']);
        $this->assertCount(1, $data1['synced']);

        // 2. Second bulk sync call with exact same idempotency_key must be idempotent (zero duplicate, zero error)
        $res2 = $this->actingAs($this->admin)->postJson('/api/sales/bulk-sync', [
            'sales' => [$offlineSale],
        ]);
        $this->assertTrue($res2->isSuccessful());
        $data2 = $res2->json();
        $this->assertEquals('success', $data2['status']);
        $this->assertCount(1, $data2['synced']);
        $this->assertEquals('already_synced', $data2['synced'][0]['status']);

        // Ensure database only has 1 record
        $this->assertEquals(1, \App\Models\Sale::where('idempotency_key', 'pos_uuid_test_12345')->count());
    }
}
