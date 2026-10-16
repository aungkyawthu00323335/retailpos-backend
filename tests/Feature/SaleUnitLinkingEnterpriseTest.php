<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\ProductUnitLink;
use App\Models\ProductUnit;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\FifoInventoryService;

class SaleUnitLinkingEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_with_linked_unit_deducts_stock_and_depletes_fifo_in_base_quantity(): void
    {
        $outlet = Outlet::create([
            'name' => 'Downtown Branch',
            'code' => 'OUT-DT',
        ]);

        $baseUnit = ProductUnit::create(['name' => 'Pound', 'short_name' => 'lb']);
        $bagUnit = ProductUnit::create(['name' => 'Bag (80 lb)', 'short_name' => 'bag80']);

        $product = Product::create([
            'name' => 'Golden Jasmine Rice',
            'code' => 'RICE-01',
            'unit_name' => 'Pound',
            'base_unit_id' => $baseUnit->id,
            'cost_price' => 100.0, // 100 Ks per pound
            'selling_price' => 120.0, // 120 Ks per pound
            'stock_quantity' => 1000.0,
            'category_name' => 'Grains',
        ]);

        // Create unit link: 1 Bag = 80 Pounds
        ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $bagUnit->id,
            'unit_name' => 'Bag (80 lb)',
            'conversion_rate' => 80.0,
            'unit_type' => 'sale',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Establish stock balance of 800 pounds in warehouse
        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 800.0,
        ]);

        // Establish FIFO batch: 800 lbs @ 100 Ks/lb
        app(FifoInventoryService::class)->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 800.0,
            baseUnitCost: 100.0,
            sourceType: 'purchase',
            sourceReference: 'PO-001',
            receivedDate: '2026-03-01 09:00:00'
        );

        // Sell 2 Bags (80 lb each) = 160 lbs total
        // Unit price per bag = 120 * 80 = 9,600 Ks. Subtotal = 2 * 9,600 = 19,200 Ks
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'customer_name' => 'Retail Buyer',
            'date' => '2026-03-10 14:00:00',
            'status' => 'Completed',
            'subtotal' => 19200.0,
            'grand_total' => 19200.0,
            'paid_amount' => 19200.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_id' => $bagUnit->id,
                    'unit_name' => 'Bag (80 lb)',
                    'conversion_rate' => 80.0,
                    'unit_price' => 9600.0,
                    'quantity' => 2.0,
                    'subtotal' => 19200.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 19200.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('data.id');

        $saleItem = SaleItem::where('sale_id', $saleId)->first();
        $this->assertNotNull($saleItem);
        $this->assertEquals(2.0, (float)$saleItem->quantity);
        $this->assertEquals(80.0, (float)$saleItem->conversion_rate);
        $this->assertEquals(160.0, (float)$saleItem->base_quantity);
        $this->assertEquals(9600.0, (float)$saleItem->unit_price);
        $this->assertEquals(120.0, (float)$saleItem->base_unit_price);
        $this->assertEquals(16000.0, (float)$saleItem->total_cogs); // 160 lbs * 100 Ks/lb = 16,000 Ks

        // Verify StockBalance deduction: 800 - 160 = 640 lbs
        $balance = StockBalance::where('warehouse_id', $outlet->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertEquals(640.0, (float)$balance);
    }

    public function test_sale_with_purchase_unit_link_resolves_cross_type(): void
    {
        $outlet = Outlet::create([
            'name' => 'Wholesale Depot',
            'code' => 'OUT-WD',
        ]);

        $boxUnit = ProductUnit::create(['name' => 'Carton (24 pcs)', 'short_name' => 'ctn24']);

        $product = Product::create([
            'name' => 'Energy Drink 250ml',
            'code' => 'DRINK-01',
            'unit_name' => 'Piece',
            'cost_price' => 500.0,
            'selling_price' => 800.0,
            'stock_quantity' => 500.0,
            'category_name' => 'Beverages',
        ]);

        // Link configured under 'purchase' unit type
        ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'unit_name' => 'Carton (24 pcs)',
            'conversion_rate' => 24.0,
            'unit_type' => 'purchase',
            'is_default' => false,
            'is_active' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 240.0,
        ]);

        app(FifoInventoryService::class)->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 240.0,
            baseUnitCost: 500.0,
            sourceType: 'purchase',
            sourceReference: 'PO-002',
            receivedDate: '2026-03-01 09:00:00'
        );

        // Sell 2 Cartons in POS/Sale without explicit conversion_rate in payload
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'customer_name' => 'Gym Cafe',
            'date' => '2026-03-11 11:00:00',
            'status' => 'Completed',
            'subtotal' => 38400.0, // 2 cartons * (24 * 800) = 38,400
            'grand_total' => 38400.0,
            'paid_amount' => 38400.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_id' => $boxUnit->id,
                    'unit_name' => 'Carton (24 pcs)',
                    'unit_price' => 19200.0, // 24 * 800
                    'quantity' => 2.0,
                    'subtotal' => 38400.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 38400.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('data.id');

        $saleItem = SaleItem::where('sale_id', $saleId)->first();
        $this->assertEquals(2.0, (float)$saleItem->quantity);
        $this->assertEquals(24.0, (float)$saleItem->conversion_rate);
        $this->assertEquals(48.0, (float)$saleItem->base_quantity); // 2 * 24 = 48 pieces

        // Verify remaining stock: 240 - 48 = 192 pieces
        $balance = StockBalance::where('warehouse_id', $outlet->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertEquals(192.0, (float)$balance);
    }

    public function test_sale_in_base_unit_resolves_rate_one(): void
    {
        $outlet = Outlet::create([
            'name' => 'Uptown Branch',
            'code' => 'OUT-UP',
        ]);

        $baseUnit = ProductUnit::create(['name' => 'Pound', 'short_name' => 'lb']);
        $bagUnit = ProductUnit::create(['name' => 'Bag (50 lb)', 'short_name' => 'bag50']);

        $product = Product::create([
            'name' => 'Wheat Flour',
            'code' => 'FLOUR-01',
            'unit_name' => 'Pound',
            'base_unit_id' => $baseUnit->id,
            'cost_price' => 50.0,
            'selling_price' => 70.0,
            'stock_quantity' => 500.0,
            'category_name' => 'Baking',
        ]);

        ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $bagUnit->id,
            'unit_name' => 'Bag (50 lb)',
            'conversion_rate' => 50.0,
            'unit_type' => 'sale',
            'is_default' => true,
            'is_active' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 200.0,
        ]);

        app(FifoInventoryService::class)->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 200.0,
            baseUnitCost: 50.0,
            sourceType: 'purchase',
            sourceReference: 'PO-003',
            receivedDate: '2026-03-01 09:00:00'
        );

        // Sell 5 loose pounds
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'customer_name' => 'Local Bakery',
            'date' => '2026-03-12 16:00:00',
            'status' => 'Completed',
            'subtotal' => 350.0, // 5 * 70 = 350
            'grand_total' => 350.0,
            'paid_amount' => 350.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_id' => $baseUnit->id,
                    'unit_name' => 'Pound',
                    'unit_price' => 70.0,
                    'quantity' => 5.0,
                    'subtotal' => 350.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 350.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('data.id');

        $saleItem = SaleItem::where('sale_id', $saleId)->first();
        $this->assertEquals(5.0, (float)$saleItem->quantity);
        $this->assertEquals(1.0, (float)$saleItem->conversion_rate);
        $this->assertEquals(5.0, (float)$saleItem->base_quantity);

        // Verify remaining stock: 200 - 5 = 195 pounds
        $balance = StockBalance::where('warehouse_id', $outlet->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertEquals(195.0, (float)$balance);
    }
}
