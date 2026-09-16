<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\InventoryBatch;
use App\Models\SaleItemCostLayer;

class FifoCostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_depletion_and_accurate_cogs_calculation(): void
    {
        $outlet = Outlet::create([
            'name' => 'Main Outlet',
            'code' => 'OUT-01',
        ]);

        $product = Product::create([
            'name' => 'Premium Arabica Coffee',
            'code' => 'COFFEE-01',
            'cost_price' => 100.0,
            'retail_price' => 200.0,
            'stock_quantity' => 20,
            'category_name' => 'Beverages',
            'brand_name' => 'Arabica',
        ]);

        // 1. Receive Batch 1: 10 units at $100 on 2026-01-01
        $batch1 = app(\App\Services\FifoInventoryService::class)->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 10.0,
            baseUnitCost: 100.0,
            sourceType: 'purchase',
            sourceReference: 'PO-001',
            receivedDate: '2026-01-01 10:00:00',
            notes: 'First purchase batch'
        );

        // 2. Receive Batch 2: 10 units at $150 on 2026-02-01 (price increased)
        $batch2 = app(\App\Services\FifoInventoryService::class)->recordBatch(
            warehouseId: $outlet->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 10.0,
            baseUnitCost: 150.0,
            sourceType: 'purchase',
            sourceReference: 'PO-002',
            receivedDate: '2026-02-01 10:00:00',
            notes: 'Second purchase batch with higher cost'
        );

        $this->assertEquals(10.0, $batch1->remaining_quantity);
        $this->assertEquals('active', $batch1->status);
        $this->assertEquals(10.0, $batch2->remaining_quantity);
        $this->assertEquals('active', $batch2->status);

        // 3. Make a Sale of 15 units at $200 each (Total Sales = $3,000)
        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'customer_name' => 'Walk-in Customer',
            'date' => '2026-02-15 12:00:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_price' => 200.0,
                    'quantity' => 15,
                    'subtotal' => 3000.0,
                ],
            ],
        ]);

        $saleResponse->assertStatus(201);
        $saleData = $saleResponse->json('data');

        // Verify FIFO Depletion:
        // Batch 1 (10 units @ $100 = $1,000) -> fully depleted
        // Batch 2 (5 units @ $150 = $750) -> 5 remaining
        // Total COGS = 1,000 + 750 = $1,750
        $batch1->refresh();
        $batch2->refresh();

        $this->assertEquals(0.0, $batch1->remaining_quantity);
        $this->assertEquals('exhausted', $batch1->status);
        $this->assertEquals(5.0, $batch2->remaining_quantity);
        $this->assertEquals('active', $batch2->status);

        // Verify Cost Layers
        $layers = SaleItemCostLayer::where('sale_id', $saleData['id'])->get();
        $this->assertCount(2, $layers);
        $this->assertEquals(10.0, $layers[0]->base_quantity);
        $this->assertEquals(100.0, $layers[0]->unit_cost);
        $this->assertEquals(1000.0, $layers[0]->total_cost);

        $this->assertEquals(5.0, $layers[1]->base_quantity);
        $this->assertEquals(150.0, $layers[1]->unit_cost);
        $this->assertEquals(750.0, $layers[1]->total_cost);

        // Verify Sale COGS
        $this->assertDatabaseHas('sales', [
            'id' => $saleData['id'],
            'total_cogs' => 1750.0,
        ]);

        // 4. Verify Profit & Loss Report calculates exact FIFO COGS and Gross Profit
        $plResponse = $this->getJson('/api/reports/profit-loss?force_refresh=true');
        $plResponse->assertStatus(200);

        $plData = $plResponse->json('data');
        $this->assertEquals(3000.0, $plData['net_sales']);
        $this->assertEquals(1750.0, $plData['total_cogs']);
        $this->assertEquals(1250.0, $plData['total_gross_profit']); // 3000 - 1750 = 1250

        // 5. Subsequent Purchase Batch at $250 does NOT distort historical COGS
        $product->update(['cost_price' => 250.0, 'purchase_price' => 250.0]);

        $plResponseAfterPriceChange = $this->getJson('/api/reports/profit-loss?force_refresh=true');
        $plResponseAfterPriceChange->assertStatus(200);
        $plDataAfter = $plResponseAfterPriceChange->json('data');

        // Historical COGS remains strictly $1,750! No retroactive mutation!
        $this->assertEquals(1750.0, $plDataAfter['total_cogs']);
        $this->assertEquals(1250.0, $plDataAfter['total_gross_profit']);
    }
}
