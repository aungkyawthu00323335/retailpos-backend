<?php

namespace Tests\Feature;

use App\Models\AdjustmentItemCostLayer;
use App\Models\Expense;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\FifoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdjustmentSeniorAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function createOutlet(string $name = 'Outlet A'): Outlet
    {
        return Outlet::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createProduct(string $code, string $name, float $stock = 0.0, float $cost = 100.0, float $price = 150.0): Product
    {
        return Product::create([
            'code' => $code,
            'name' => $name,
            'cost_price' => $cost,
            'purchase_price' => $cost,
            'price' => $price,
            'retail_price' => $price,
            'selling_price' => $price,
            'stock_quantity' => $stock,
            'low_qty' => 5.0,
            'product_unit' => 'Piece',
            'unit_name' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
    }

    public function test_adjustment_reason_catalog_endpoint_and_rbac(): void
    {
        $res = $this->getJson('/api/adjustments/reasons');
        $res->assertStatus(200);
        $res->assertJsonPath('status', 'success');

        $reasons = collect($res->json('data'))->keyBy('code');
        $this->assertTrue($reasons->has('Opening Stock'));
        $this->assertFalse((bool)$reasons->get('Opening Stock')['affects_pnl']);
        $this->assertSame('equity_opening_stock', $reasons->get('Opening Stock')['accounting_category']);

        $this->assertTrue($reasons->has('Damaged'));
        $this->assertTrue((bool)$reasons->get('Damaged')['affects_pnl']);
        $this->assertSame('inventory_shrinkage_loss', $reasons->get('Damaged')['accounting_category']);

        $this->assertTrue($reasons->has('Stocktake Surplus'));
        $this->assertTrue((bool)$reasons->get('Stocktake Surplus')['affects_pnl']);
        $this->assertSame('cycle_count_variance', $reasons->get('Stocktake Surplus')['accounting_category']);

        // Restricted user without adjustments/profit_loss permissions gets 403
        $role = Role::create([
            'name' => 'POS Viewer Only',
            'slug' => 'pos-viewer-only',
            'permissions' => ['customers.view'],
        ]);
        $user = User::create([
            'name' => 'Limited User',
            'email' => 'limited-reasons@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson('/api/adjustments/reasons')
            ->assertStatus(403);
    }

    public function test_subtraction_cost_layers_tracked_updated_and_deleted_with_exact_fifo_restoration(): void
    {
        $outlet = $this->createOutlet('Main Branch');
        $product = $this->createProduct('SNR-LYR-01', 'Layered Product', 0.0, 120.0);

        $fifo = app(FifoInventoryService::class);
        $batch1 = $fifo->recordBatch($outlet->id, $product->id, null, 10.0, 100.0, 'purchase', 'PO-L1', '2026-10-01 10:00:00');
        $batch2 = $fifo->recordBatch($outlet->id, $product->id, null, 15.0, 150.0, 'purchase', 'PO-L2', '2026-10-02 10:00:00');

        $product->update(['stock_quantity' => 25.0]);
        StockBalance::create(['warehouse_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 25.0]);

        // 1. Subtract 15 units -> takes 10 @ 100 + 5 @ 150 = 1750
        $storeRes = $this->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-05 11:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 15.0,
                'type' => 'Subtraction',
                'unit_cost' => 120.0,
            ]],
        ]);
        $storeRes->assertStatus(201);
        $adjId = (int)$storeRes->json('data.id');

        $adj = StockAdjustment::with('items')->findOrFail($adjId);
        $this->assertTrue((bool)$adj->affects_pnl);
        $this->assertSame('inventory_shrinkage_loss', $adj->accounting_category);
        $this->assertEquals(-1750.0, (float)$adj->total_value);

        $item = $adj->items->first();
        $this->assertEquals(1750.0, (float)$item->subtotal);

        $layers = AdjustmentItemCostLayer::where('stock_adjustment_item_id', $item->id)->orderBy('id')->get();
        $this->assertCount(2, $layers);
        $this->assertEquals($batch1->id, $layers[0]->inventory_batch_id);
        $this->assertEquals(10.0, (float)$layers[0]->base_quantity);
        $this->assertEquals(1000.0, (float)$layers[0]->total_cost);
        $this->assertEquals($batch2->id, $layers[1]->inventory_batch_id);
        $this->assertEquals(5.0, (float)$layers[1]->base_quantity);
        $this->assertEquals(750.0, (float)$layers[1]->total_cost);

        $batch1->refresh();
        $batch2->refresh();
        $this->assertEquals(0.0, (float)$batch1->remaining_quantity);
        $this->assertSame('exhausted', $batch1->status);
        $this->assertEquals(10.0, (float)$batch2->remaining_quantity);

        // 2. Update Subtraction 15 -> 12 -> restores 10+5 then depletes 10@100 + 2@150 = 1300
        $updRes = $this->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-05 11:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 12.0,
                'type' => 'Subtraction',
                'unit_cost' => 120.0,
            ]],
        ]);
        $updRes->assertStatus(200);

        $adj->refresh();
        $this->assertEquals(-1300.0, (float)$adj->total_value);
        $this->assertEquals(2, AdjustmentItemCostLayer::where('stock_adjustment_id', $adjId)->count());
        $batch1->refresh();
        $batch2->refresh();
        $this->assertEquals(0.0, (float)$batch1->remaining_quantity);
        $this->assertEquals(13.0, (float)$batch2->remaining_quantity);

        // 3. Delete Subtraction -> restores exact 10 to batch1 and 2 to batch2, removes cost layers
        $this->deleteJson("/api/adjustments/{$adjId}")->assertStatus(200);

        $batch1->refresh();
        $batch2->refresh();
        $this->assertEquals(10.0, (float)$batch1->remaining_quantity);
        $this->assertSame('active', $batch1->status);
        $this->assertEquals(15.0, (float)$batch2->remaining_quantity);
        $this->assertEquals(0, AdjustmentItemCostLayer::where('stock_adjustment_id', $adjId)->count());
    }

    public function test_opening_stock_excluded_from_pnl_and_operating_adjustments_appear_in_zero_sales_month(): void
    {
        $outlet = $this->createOutlet('Main Branch');
        $product = $this->createProduct('SNR-PNL-01', 'Opening Product', 0.0, 200.0, 300.0);

        // Aug 2026: Opening Stock Addition 10 @ 200 = 2000 (Non-P&L Capital Equity)
        $openRes = $this->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-08-10 09:00:00',
            'type' => 'Addition',
            'reason_code' => 'Opening Stock',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 10.0,
                'type' => 'Addition',
                'unit_cost' => 200.0,
            ]],
        ]);
        $openRes->assertStatus(201);
        $this->assertFalse((bool)$openRes->json('data.affects_pnl'));

        // Sep 2026 (ZERO SALES MONTH): Damaged Subtraction 3 @ 200 = 600 (Operating Shrinkage Loss)
        $dmgRes = $this->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-09-15 14:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 3.0,
                'type' => 'Subtraction',
                'unit_cost' => 200.0,
            ]],
        ]);
        $dmgRes->assertStatus(201);
        $this->assertTrue((bool)$dmgRes->json('data.affects_pnl'));

        // Oct 2026: Sale 1000, COGS 400 (gross profit = 600), Expense 100
        Sale::create([
            'reference_no' => 'SL-OCT-01',
            'customer_name' => 'Walk-in',
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 12:00:00',
            'grand_total' => 1000.0,
            'total_cogs' => 400.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'status' => 'Completed',
        ]);
        Expense::create([
            'reference_no' => 'EXP-OCT-01',
            'title' => 'Electricity Expense',
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-22',
            'amount' => 100.0,
            'expense_category_name' => 'Utilities',
        ]);

        // Verify Profit & Loss
        $plRes = $this->getJson('/api/reports/profit-loss?start_date=2026-08-01&end_date=2026-10-31&force_refresh=1');
        $plRes->assertStatus(200);
        $pl = $plRes->json('data');

        // Opening Stock MUST NOT inflate inventory_gain or net_profit
        $this->assertEquals(0.0, (float)$pl['inventory_gain']);
        $this->assertEquals(600.0, (float)$pl['inventory_loss']);
        $this->assertEquals(2000.0, (float)$pl['non_pnl_additions']);
        $this->assertEquals(2000.0, (float)$pl['non_pnl_adjustments']);
        // Net Profit = 600 (gross) - 100 (exp) - 600 (damaged) = -100
        $this->assertEquals(-100.0, (float)$pl['net_profit']);

        // Monthly periods MUST include zero-sales September and reconcile sum === aggregate net_profit
        $periodsByMonth = collect($pl['periods'])->keyBy('period');
        $this->assertTrue($periodsByMonth->has('2026-09'));
        $sep = $periodsByMonth->get('2026-09');
        $this->assertEquals(0.0, (float)$sep['gross_sales']);
        $this->assertEquals(600.0, (float)$sep['inventory_loss']);
        $this->assertEquals(-600.0, (float)$sep['net_profit']);

        $sumMonthlyNetProfit = collect($pl['periods'])->sum('net_profit');
        $this->assertEquals((float)$pl['net_profit'], round((float)$sumMonthlyNetProfit, 2));

        // Verify Dashboard Summary excludes Opening Stock from P&L adjustments too
        $dashRes = $this->getJson('/api/reports/dashboard-summary?date_filter=custom&start_date=2026-08-01&end_date=2026-10-31&force_refresh=1&force_snapshot=1');
        $dashRes->assertStatus(200);
        $dash = $dashRes->json('data');
        $this->assertEquals(600.0, (float)$dash['inventory_loss']);
        $this->assertEquals(0.0, (float)$dash['inventory_gain']);
        $this->assertEquals(2000.0, (float)$dash['non_pnl_adjustments']);
        // 7 remaining units @ FIFO 200 = 1400 valuation
        $this->assertEquals(1400.0, (float)$dash['inventory_valuation_cost']);
    }

    public function test_restricted_user_edit_addition_preserves_original_historical_cost(): void
    {
        $outlet = $this->createOutlet('Main Branch');
        $product = $this->createProduct('SNR-MASK-01', 'Cost Protected Item', 0.0, 50.0);

        $createRes = $this->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-15 09:00:00',
            'type' => 'Addition',
            'reason_code' => 'Found / Unrecorded Return',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 10.0,
                'type' => 'Addition',
                'unit_cost' => 75.0,
            ]],
        ]);
        $createRes->assertStatus(201);
        $adjId = (int)$createRes->json('data.id');

        // Restricted clerk without products.view_cost or reports.view
        $role = Role::create([
            'name' => 'Stock Clerk',
            'slug' => 'stock-clerk',
            'permissions' => ['adjustments.view', 'adjustments.update'],
        ]);
        $clerk = User::create([
            'name' => 'Clerk No Cost',
            'email' => 'clerk-nocost@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        // Clerk submits update with masked unit_cost = 0, changing quantity 10 -> 12
        $updRes = $this->actingAs($clerk)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-15 09:00:00',
            'type' => 'Addition',
            'reason_code' => 'Found / Unrecorded Return',
            'notes' => 'Recounted by clerk',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 12.0,
                'type' => 'Addition',
                'unit_cost' => 0.0,
            ]],
        ]);
        $updRes->assertStatus(200);

        $savedItem = StockAdjustmentItem::where('stock_adjustment_id', $adjId)->firstOrFail();
        $this->assertEquals(75.0, (float)$savedItem->unit_cost);
        $this->assertEquals(75.0, (float)$savedItem->base_unit_cost);
        $this->assertEquals(900.0, (float)$savedItem->subtotal);

        $batch = InventoryBatch::where('product_id', $product->id)
            ->where('source_type', 'adjustment_addition')
            ->firstOrFail();
        $this->assertEquals(75.0, (float)$batch->unit_cost);
        $this->assertEquals(12.0, (float)$batch->remaining_quantity);
    }

    public function test_inventory_reconciliation_and_stock_ledger_historical_base_unit_accuracy(): void
    {
        $outletA = $this->createOutlet('Outlet A');
        $outletB = $this->createOutlet('Outlet B');
        $product = $this->createProduct('SNR-REC-01', 'Reconciled Multi-Unit SKU', 11.0, 100.0, 150.0);

        $fifo = app(FifoInventoryService::class);

        // Purchase 1 (2026-10-05): 1 Box = 12 pcs @ 1200/box (100/pc) in Outlet A
        $pur1 = Purchase::create([
            'reference_no' => 'PO-REC-01',
            'supplier_name' => 'Test Supplier',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-05 10:00:00',
            'grand_total' => 1200.0,
            'paid_amount' => 1200.0,
            'due_amount' => 0.0,
            'status' => 'Received',
        ]);
        PurchaseItem::create([
            'purchase_id' => $pur1->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_name' => 'Box',
            'conversion_rate' => 12.0,
            'quantity' => 1.0,
            'base_quantity' => 12.0,
            'unit_cost' => 1200.0,
            'base_unit_cost' => 100.0,
            'subtotal' => 1200.0,
        ]);
        $fifo->recordBatch($outletA->id, $product->id, null, 12.0, 100.0, 'purchase', 'PO-REC-01', '2026-10-05 10:00:00');

        // Transfer (2026-10-10): 4 pcs from Outlet A -> Outlet B
        $trf = StockTransfer::create([
            'reference_no' => 'TRF-REC-01',
            'from_warehouse' => $outletA->name,
            'from_warehouse_id' => $outletA->id,
            'to_warehouse' => $outletB->name,
            'to_warehouse_id' => $outletB->id,
            'date' => '2026-10-10 11:00:00',
            'total_items' => 1,
            'total_quantity' => 4.0,
            'total_cost' => 400.0,
            'status' => 'Completed',
        ]);
        StockTransferItem::create([
            'stock_transfer_id' => $trf->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 4.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 4.0,
            'unit_cost' => 100.0,
            'base_unit_cost' => 100.0,
            'subtotal' => 400.0,
        ]);
        $fifo->depleteForAdjustment($outletA->id, $product->id, null, 4.0, 'TRF-REC-01');
        $fifo->recordBatch($outletB->id, $product->id, null, 4.0, 100.0, 'transfer', 'TRF-REC-01', '2026-10-10 11:00:00');

        // Sale (2026-10-12): 2 pcs in Outlet A
        $sale = Sale::create([
            'reference_no' => 'SL-REC-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-12 15:00:00',
            'grand_total' => 300.0,
            'total_cogs' => 200.0,
            'paid_amount' => 300.0,
            'due_amount' => 0.0,
            'status' => 'Completed',
        ]);
        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 2.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 2.0,
            'unit_price' => 150.0,
            'subtotal' => 300.0,
            'total_cogs' => 200.0,
        ]);
        $fifo->depleteFifo($outletA->id, $product->id, null, 2.0, $sale->id, $saleItem->id, '2026-10-12 15:00:00', 100.0);

        // Purchase 2 (2026-10-25, AFTER query window!): 5 pcs @ 140/pc in Outlet A
        $pur2 = Purchase::create([
            'reference_no' => 'PO-REC-02',
            'supplier_name' => 'Test Supplier',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-25 10:00:00',
            'grand_total' => 700.0,
            'paid_amount' => 700.0,
            'due_amount' => 0.0,
            'status' => 'Received',
        ]);
        PurchaseItem::create([
            'purchase_id' => $pur2->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 5.0,
            'conversion_rate' => 1.0,
            'base_quantity' => 5.0,
            'unit_cost' => 140.0,
            'base_unit_cost' => 140.0,
            'subtotal' => 700.0,
        ]);
        $fifo->recordBatch($outletA->id, $product->id, null, 5.0, 140.0, 'purchase', 'PO-REC-02', '2026-10-25 10:00:00');

        // Sync live StockBalances (Outlet A: 12 - 4 - 2 + 5 = 11, Outlet B: 4, Total product = 15)
        StockBalance::create(['warehouse_id' => $outletA->id, 'product_id' => $product->id, 'quantity' => 11.0]);
        StockBalance::create(['warehouse_id' => $outletB->id, 'product_id' => $product->id, 'quantity' => 4.0]);
        $product->update(['stock_quantity' => 15.0]);

        // 1. Audit Reconciliation API must report cleanly balanced (Outlet A FIFO value: 6*100 + 5*140 = 1300 vs Master 11*100 = 1100; Outlet B: 4*100 = 400)
        $recRes = $this->getJson('/api/reports/inventory-reconciliation');
        $recRes->assertStatus(200);
        $rec = $recRes->json('data');
        $this->assertTrue((bool)$rec['is_balanced']);
        $this->assertSame('reconciled', $rec['status_health']);
        $this->assertEquals(15.0, (float)$rec['valuation_summary']['total_book_qty']);
        $this->assertEquals(1700.0, (float)$rec['valuation_summary']['fifo_batch_valuation']);
        $this->assertEquals(1500.0, (float)$rec['valuation_summary']['master_valuation']);

        // 2. Stock Ledger As-Of 2026-10-15 in Outlet A MUST compute Opening=0, Purchases=12, TransfersOut=4, SalesOut=2, Closing=6!
        $ledgerRes = $this->getJson("/api/reports/stock-ledger?warehouse_name={$outletA->name}&start_date=2026-10-01&end_date=2026-10-15&force_refresh=1");
        $ledgerRes->assertStatus(200);
        $row = collect($ledgerRes->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertEquals(0.0, (float)$row['opening_stock']);
        $this->assertEquals(12.0, (float)$row['purchases_in']);
        $this->assertEquals(4.0, (float)$row['transfers_out']);
        $this->assertEquals(2.0, (float)$row['sales_out']);
        $this->assertEquals(6.0, (float)$row['closing_stock']);
    }

    public function test_dashboard_summary_and_reconciliation_handle_product_variants_without_legacy_variant_columns(): void
    {
        $outlet = $this->createOutlet('Variant Valuation Outlet');
        $product = $this->createProduct('SNR-VAR-01', 'Variant Shirt', 0.0, 80.0);
        $product->update([
            'has_variants' => true,
            'stock_quantity' => 5.0,
        ]);

        $variantA = \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Red / M',
            'sku' => 'SNR-VAR-01-RM',
            'cost_price' => 40.0,
            'selling_price' => 80.0,
            'is_active' => true,
        ]);
        $variantB = \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Blue / L',
            'sku' => 'SNR-VAR-01-BL',
            'cost_price' => 50.0,
            'selling_price' => 95.0,
            'is_active' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 3.0,
        ]);
        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 2.0,
        ]);

        $fifo = app(FifoInventoryService::class);
        $fifo->recordBatch($outlet->id, $product->id, $variantA->id, 2.0, 38.0, 'purchase', 'PO-VAR-1', '2026-10-01 10:00:00');

        $valuation = app(\App\Services\InventoryReconciliationService::class)->computeValuation($outlet->id, [$product->id]);
        $this->assertEquals(5.0, (float)$valuation['total_book_qty']);
        $this->assertEquals(2.0, (float)$valuation['total_fifo_qty']);
        $this->assertEquals(3.0, (float)$valuation['total_unbatched_qty']);
        $this->assertEquals(76.0, (float)$valuation['fifo_batch_valuation']);
        $this->assertEquals(216.0, (float)$valuation['effective_valuation']);
        $this->assertEquals(430.0, (float)$valuation['retail_valuation']);

        $this->getJson('/api/reports/dashboard-summary?force_refresh=1&force_snapshot=1')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->getJson('/api/reports/inventory-reconciliation')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_p0_p1_p2_bearer_token_cost_masking_reconciliation_code_only_uom_and_csv_security(): void
    {
        $outlet = $this->createOutlet('Audit HQ');
        $product = $this->createProduct('AUD-BOX-01', 'Boxed Drink', 20.0, 100.0, 150.0);
        $boxUnit = \App\Models\ProductUnit::create(['name' => 'Box', 'short_name' => 'BOX']);
        \App\Models\ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'unit_name' => 'Box',
            'unit_type' => 'sale',
            'conversion_rate' => 12.0,
            'is_active' => true,
        ]);

        // Clerk with adjustment & report view ONLY (lacks products.view_cost), using Bearer token auth
        $clerkRole = Role::create([
            'name' => 'Restricted Clerk',
            'slug' => 'restricted_clerk_adj',
            'permissions' => ['adjustments.view', 'adjustments.update', 'adjustments.export', 'report_stock_ledger.view', 'report_stock.view'],
            'status' => 'active',
        ]);
        $clerk = User::create([
            'name' => 'Bearer Clerk',
            'email' => 'bearer-clerk@audit.test',
            'password' => bcrypt('Secret123!'),
            'role_id' => $clerkRole->id,
            'status' => 'active',
            'api_token' => 'adj_bearer_token_restricted',
        ]);

        $viewerRole = Role::create([
            'name' => 'Cost Viewer Role',
            'slug' => 'cost_viewer_adj',
            'permissions' => ['adjustments.view', 'adjustments.create', 'adjustments.update', 'adjustments.delete', 'adjustments.approve', 'products.view_cost', 'report_stock_ledger.view', 'report_stock.view'],
            'status' => 'active',
        ]);
        $viewer = User::create([
            'name' => 'Bearer Viewer',
            'email' => 'bearer-viewer@audit.test',
            'password' => bcrypt('Secret123!'),
            'role_id' => $viewerRole->id,
            'status' => 'active',
            'api_token' => 'adj_bearer_token_viewer',
        ]);

        // P1 + P2: Store using product_code ONLY (omitting product_id), multi-unit Box (12), ISO-Z date, formula notes, Opening Stock + explicit affects_pnl=true
        $storeRes = $this->withToken($viewer->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-15T10:00:00Z',
            'type' => 'Addition',
            'reason_code' => 'Opening Stock',
            'affects_pnl' => true,
            'notes' => '=CMD|calc!A0',
            'items' => [[
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box',
                'quantity' => 2.0,
                'type' => 'Addition',
                'unit_cost' => 1200.0,
            ]],
        ]);
        $storeRes->assertStatus(201);
        $adjId = (int) $storeRes->json('data.id');
        // Header net/gross quantities must equal 2 * 12 = 24 base units
        $this->assertEquals(24.0, (float) $storeRes->json('data.net_quantity'));
        $this->assertEquals(24.0, (float) $storeRes->json('data.gross_quantity'));
        $this->assertStringNotContainsString('T', (string) $storeRes->json('data.date'));

        // P0: Bearer-token restricted clerk receives masked index/show/export/stock-ledger/stock-product-details
        $idxRestricted = $this->withToken($clerk->api_token)->getJson('/api/adjustments?no_items=1');
        $idxRestricted->assertStatus(200);
        $this->assertNull($idxRestricted->json('data.0.total_value'));

        $showRestricted = $this->withToken($clerk->api_token)->getJson("/api/adjustments/{$adjId}");
        $showRestricted->assertStatus(200);
        $this->assertNull($showRestricted->json('data.total_value'));
        $this->assertNull($showRestricted->json('data.items.0.unit_cost'));

        // P2: Export masks cost and neutralizes formula injection
        $expRestricted = $this->withToken($clerk->api_token)->get('/api/adjustments/export');
        $expRestricted->assertStatus(200);
        $csv = $expRestricted->streamedContent();
        $this->assertStringContainsString('Protected', $csv);
        $this->assertStringContainsString("'=CMD|calc!A0", $csv);

        // P0: Editing via Bearer token with unit_cost=0 must preserve original 1200.0 cost
        $updRestricted = $this->withToken($clerk->api_token)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-15',
            'type' => 'Addition',
            'reason_code' => 'Opening Stock',
            'affects_pnl' => true,
            'items' => [[
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box',
                'quantity' => 3.0,
                'type' => 'Addition',
                'unit_cost' => 0.0,
            ]],
        ]);
        $updRestricted->assertStatus(200);
        $savedItem = StockAdjustmentItem::where('stock_adjustment_id', $adjId)->firstOrFail();
        $this->assertEquals(1200.0, (float) $savedItem->unit_cost);
        $this->assertEquals(3600.0, (float) $savedItem->subtotal);

        // P0: stock-ledger and stock-product-details mask costs for restricted Bearer token & keep separate cache per view_cost
        $ledgerRestricted = $this->withToken($clerk->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-01&end_date=2026-10-31');
        $ledgerRestricted->assertStatus(200);
        $this->assertTrue((bool) $ledgerRestricted->json('cost_masked'));
        $this->assertEquals(0.0, (float) $ledgerRestricted->json('summary.total_closing_cost'));
        $this->assertEquals(0.0, (float) $ledgerRestricted->json('movements.0.unit_cost'));

        $ledgerViewer = $this->withToken($viewer->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-01&end_date=2026-10-31');
        $ledgerViewer->assertStatus(200);
        $this->assertFalse((bool) $ledgerViewer->json('cost_masked'));
        $this->assertGreaterThan(0.0, (float) $ledgerViewer->json('summary.total_closing_cost'));

        $detailsRestricted = $this->withToken($clerk->api_token)
            ->getJson("/api/reports/stock/{$product->id}/details");
        $detailsRestricted->assertStatus(200);
        $this->assertTrue((bool) $detailsRestricted->json('cost_masked'));
        $this->assertEquals(0.0, (float) $detailsRestricted->json('data.product.cost_price'));
        $this->assertEquals(0.0, (float) $detailsRestricted->json('data.movements.adjustments.0.subtotal'));

        // P1: InventoryReconciliationService respects affects_pnl = true override matching ReportController P&L
        $audit = app(\App\Services\InventoryReconciliationService::class)->audit($outlet->id);
        $this->assertEquals(3600.0, (float) $audit['adjustment_accounting_summary']['operating_pnl_gain']);
        $this->assertEquals(0.0, (float) $audit['adjustment_accounting_summary']['non_pnl_additions']);

        // P1: Deleting legacy adjustment with multiple null product_id items resolves by product_code safely
        $legacyAdj = StockAdjustment::create([
            'reference_no' => 'ADJ-LEG-DEL',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-16 09:00:00',
            'total_products' => 1,
            'total_quantity' => 5.0,
            'type' => 'Addition',
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $legacyAdj->id,
            'product_id' => null,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 5.0,
            'base_quantity' => 5.0,
            'type' => 'Addition',
            'unit_cost' => 100.0,
            'base_unit_cost' => 100.0,
            'subtotal' => 500.0,
        ]);
        StockBalance::where('warehouse_id', $outlet->id)->where('product_id', $product->id)->update(['quantity' => 61.0]);
        $delRes = $this->withToken($viewer->api_token)->deleteJson("/api/adjustments/{$legacyAdj->id}");
        $delRes->assertStatus(200);
        $this->assertEquals(56.0, (float) StockBalance::where('warehouse_id', $outlet->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_p2_4_authenticated_actor_cannot_be_spoofed_on_adjustment_store_or_update(): void
    {
        $outlet = $this->createOutlet('Audit Branch');
        $product = $this->createProduct('P2-4-PROD', 'Audit Item', 20.0, 50.0);
        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 20.0,
        ]);

        $role = Role::create([
            'name' => 'Inventory Auditor',
            'slug' => 'inventory_auditor_p2_4',
            'permissions' => ['adjustments.view', 'adjustments.create', 'adjustments.update'],
            'status' => 'active',
        ]);
        $realClerk = User::create([
            'name' => 'Real Clerk',
            'email' => 'real-clerk-p2-4@test.local',
            'password' => bcrypt('Secret123!'),
            'role' => 'Inventory Auditor',
            'role_id' => $role->id,
            'status' => 'active',
            'api_token' => 'p2_4_real_clerk_tok',
        ]);
        $secondEditor = User::create([
            'name' => 'Second Editor',
            'email' => 'second-editor-p2-4@test.local',
            'password' => bcrypt('Secret123!'),
            'role' => 'Inventory Auditor',
            'role_id' => $role->id,
            'status' => 'active',
            'api_token' => 'p2_4_second_editor_tok',
        ]);

        $createRes = $this->withToken($realClerk->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 10:00:00',
            'type' => 'Addition',
            'created_by' => 'Spoofed Boss',
            'user_name' => 'Spoofed Boss',
            'user_id' => 99999,
            'user_role' => 'Fake Owner',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 5.0,
                'type' => 'Addition',
                'unit_cost' => 50.0,
            ]],
        ])->assertStatus(201);

        $adjId = (int)$createRes->json('data.id');
        $adj = StockAdjustment::findOrFail($adjId);
        $this->assertSame('Real Clerk', $adj->created_by);
        $this->assertSame('Real Clerk', $adj->user_name);
        $this->assertEquals($realClerk->id, (int)$adj->user_id);

        // Authenticated update preserves original creator and ignores spoofed user_name/created_by/user_id
        $this->withToken($secondEditor->api_token)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 10:00:00',
            'created_by' => 'Spoofed Editor',
            'user_name' => 'Spoofed Editor',
            'user_id' => 99999,
            'user_role' => 'Fake Owner',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 6.0,
                'type' => 'Addition',
                'unit_cost' => 50.0,
            ]],
        ])->assertStatus(200);

        $freshAdj = $adj->fresh();
        $this->assertSame('Real Clerk', $freshAdj->created_by);
        $this->assertSame('Real Clerk', $freshAdj->user_name);
        $this->assertEquals($realClerk->id, (int)$freshAdj->user_id);
    }

    public function test_p2_cost_blind_update_blocks_positive_cost_tampering_and_unpriced_new_items(): void
    {
        $outlet = $this->createOutlet('RBAC Cost WH');
        $pricedProd = $this->createProduct('ADJ-COST-1', 'Priced Product', 20.0, 200.0);
        $zeroProd = $this->createProduct('ADJ-ZERO-1', 'Zero Cost Product', 20.0, 0.0);
        StockBalance::create(['warehouse_id' => $outlet->id, 'product_id' => $pricedProd->id, 'quantity' => 20.0]);
        StockBalance::create(['warehouse_id' => $outlet->id, 'product_id' => $zeroProd->id, 'quantity' => 20.0]);

        $adminRole = Role::create([
            'name' => 'Adj Admin Cost',
            'slug' => 'adj_admin_cost_p2',
            'permissions' => ['adjustments.view', 'adjustments.create', 'adjustments.update', 'products.view_cost'],
            'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Cost Admin',
            'email' => 'adj-admin-cost@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $adminRole->id,
            'status' => 'active',
            'api_token' => 'adj_admin_cost_tok',
        ]);

        $blindRole = Role::create([
            'name' => 'Adj Blind Editor',
            'slug' => 'adj_blind_editor_p2',
            'permissions' => ['adjustments.view', 'adjustments.update'],
            'status' => 'active',
        ]);
        $blindUser = User::create([
            'name' => 'Blind Editor',
            'email' => 'adj-blind-editor@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $blindRole->id,
            'status' => 'active',
            'api_token' => 'adj_blind_editor_tok',
        ]);

        $createRes = $this->withToken($admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 10:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $pricedProd->id,
                'product_code' => $pricedProd->code,
                'product_name' => $pricedProd->name,
                'quantity' => 4.0,
                'type' => 'Addition',
                'unit_cost' => 250.0,
            ]],
        ])->assertStatus(201);
        $adjId = (int)$createRes->json('data.id');

        // 1. Even if cost-blind user tampers unit_cost=1.0 (> 0), historical 250.0 must be preserved
        $this->withToken($blindUser->api_token)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 10:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $pricedProd->id,
                'product_code' => $pricedProd->code,
                'product_name' => $pricedProd->name,
                'quantity' => 5.0,
                'type' => 'Addition',
                'unit_cost' => 1.0,
            ]],
        ])->assertStatus(200);

        $savedItem = StockAdjustmentItem::where('stock_adjustment_id', $adjId)->firstOrFail();
        $this->assertEqualsWithDelta(250.0, (float)$savedItem->unit_cost, 0.01);
        $this->assertEqualsWithDelta(1250.0, (float)$savedItem->subtotal, 0.01);

        // 2. Adding a new zero-cost product with no catalog cost by a cost-blind user fails 422
        $this->withToken($blindUser->api_token)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-20 10:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $zeroProd->id,
                'product_code' => $zeroProd->code,
                'product_name' => $zeroProd->name,
                'quantity' => 2.0,
                'type' => 'Addition',
                'unit_cost' => 0.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_p1_p2_reason_direction_rbac_approval_gate_ledger_isolation_and_stock_movement_cost(): void
    {
        $outletA = $this->createOutlet('Outlet Ledger A');
        $outletB = $this->createOutlet('Outlet Ledger B');
        $product = $this->createProduct('ADJ-P1-SKU', 'Ledger Valuation Product', 0.0, 150.0, 200.0);

        $noApproveRole = Role::create([
            'name' => 'Adj Creator No Approve',
            'slug' => 'adj_creator_no_approve',
            'permissions' => ['adjustments.view', 'adjustments.create', 'adjustments.update', 'products.view_cost', 'reports.view'],
            'status' => 'active',
        ]);
        $noApproveUser = User::create([
            'name' => 'No Approve',
            'email' => 'no-approve@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $noApproveRole->id,
            'status' => 'active',
            'api_token' => 'adj_no_approve_tok',
        ]);

        // 1. Reason direction mismatch rejected (422)
        $this->withToken($noApproveUser->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-10 10:00:00',
            'type' => 'Addition',
            'reason_code' => 'Damaged',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 2.0,
                'type' => 'Addition',
                'unit_cost' => 100.0,
            ]],
        ])->assertStatus(422);

        // 2. Non-approver overriding affects_pnl on Opening Stock is rejected (422)
        $this->withToken($noApproveUser->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-10 10:00:00',
            'type' => 'Addition',
            'reason_code' => 'Opening Stock',
            'affects_pnl' => true,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 5.0,
                'type' => 'Addition',
                'unit_cost' => 100.0,
            ]],
        ])->assertStatus(422);

        // 3. Two FIFO cost batches in Outlet A: 10 @ 100 + 10 @ 150 -> FIFO value = 2500 (not 20 * 150 = 3000)
        $fifo = app(FifoInventoryService::class);
        $fifo->recordBatch($outletA->id, $product->id, null, 10.0, 100.0, 'purchase', 'PO-FIFO-A1', '2026-10-01 10:00:00');
        $fifo->recordBatch($outletA->id, $product->id, null, 10.0, 150.0, 'purchase', 'PO-FIFO-A2', '2026-10-02 10:00:00');
        StockBalance::create(['warehouse_id' => $outletA->id, 'product_id' => $product->id, 'quantity' => 20.0]);
        StockBalance::create(['warehouse_id' => $outletB->id, 'product_id' => $product->id, 'quantity' => 7.0]);
        $product->update(['stock_quantity' => 27.0, 'cost_price' => 150.0]);

        // Record Adjustment in Outlet A using valid Stocktake Surplus -> populates StockMovement.unit_cost
        $adjRes = $this->withToken($noApproveUser->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-03 10:00:00',
            'type' => 'Addition',
            'reason_code' => 'Stocktake Surplus',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 5.0,
                'type' => 'Addition',
                'unit_cost' => 120.0,
            ]],
        ])->assertStatus(201);
        $adjId = (int)$adjRes->json('data.id');
        $mv = \App\Models\StockMovement::where('reference_type', 'stock_adjustment')
            ->where('reference_id', $adjId)
            ->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float)$mv->unit_cost, 0.01);

        // Warehouse-scoped stock-ledger: Outlet A balance_qty is 25.0 (NOT global 32.0) and FIFO closing value = 2500 + 600 = 3100
        $ledgerA = $this->withToken($noApproveUser->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$outletA->name}&start_date=2026-10-01&end_date=2026-12-31&force_refresh=1")
            ->assertStatus(200);
        $mvRowA = collect($ledgerA->json('movements'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($mvRowA);
        $this->assertEqualsWithDelta(25.0, (float)$mvRowA['balance_qty'], 0.01);
        $skuRowA = collect($ledgerA->json('data'))->firstWhere('product_id', $product->id);
        $this->assertEqualsWithDelta(3100.0, (float)$skuRowA['closing_cost_value'], 0.01);

        // stock-ledger movement_type=sale_return excludes purchase_return movements
        \App\Models\StockMovement::create([
            'warehouse_id' => $outletA->id,
            'product_id' => $product->id,
            'movement_type' => 'purchase_return',
            'reference_type' => 'purchase_return',
            'reference_id' => 999,
            'quantity_in' => 0,
            'quantity_out' => 1,
            'balance_after' => 24,
            'created_by' => 1,
            'created_at' => '2026-10-04 10:00:00',
        ]);
        $srOnly = $this->withToken($noApproveUser->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$outletA->name}&start_date=2026-10-01&end_date=2026-10-10&movement_type=sale_return&force_refresh=1")
            ->assertStatus(200);
        $this->assertCount(0, $srOnly->json('movements'));

        // reports/stock/{id}/details resolves Outlet Ledger B by string warehouse_name (balance = 7.0)
        $detB = $this->withToken($noApproveUser->api_token)
            ->getJson("/api/reports/stock/{$product->id}/details?warehouse_name=" . urlencode($outletB->name))
            ->assertStatus(200);
        $this->assertEqualsWithDelta(7.0, (float)$detB->json('data.warehouse_stocks.0.quantity'), 0.01);
    }
}
