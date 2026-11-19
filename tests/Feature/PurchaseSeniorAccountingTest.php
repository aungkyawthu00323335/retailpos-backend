<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnCostLayer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FifoInventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PurchaseSeniorAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $warehouse;
    private Outlet $secondaryWarehouse;
    private Supplier $supplier;
    private Supplier $otherSupplier;
    private Product $product;
    private Product $secondProduct;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->warehouse = Outlet::create([
            'name' => 'Central Warehouse',
            'code' => 'WH-MAIN',
            'is_warehouse' => true,
        ]);

        $this->secondaryWarehouse = Outlet::create([
            'name' => 'Downtown Outlet',
            'code' => 'OUT-DT',
            'is_warehouse' => false,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Alpha Wholesale Co',
            'phone' => '0911122233',
            'balance_due' => 0.0,
        ]);

        $this->otherSupplier = Supplier::create([
            'name' => 'Beta Distributors',
            'phone' => '0944455566',
            'balance_due' => 0.0,
        ]);

        $this->product = Product::create([
            'name' => 'Premium Jasmine Rice',
            'code' => 'RICE-PREM-01',
            'stock_quantity' => 0.0,
            'purchase_price' => 100.0,
            'cost_price' => 100.0,
            'retail_price' => 160.0,
        ]);

        $this->secondProduct = Product::create([
            'name' => 'Cold Pressed Sesame Oil',
            'code' => 'OIL-SES-02',
            'stock_quantity' => 0.0,
            'purchase_price' => 200.0,
            'cost_price' => 200.0,
            'retail_price' => 300.0,
        ]);

        StockBalance::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0.0,
        ]);

        StockBalance::create([
            'product_id' => $this->secondProduct->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0.0,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-SNR-ADMIN',
            'name' => 'Senior Auditor',
            'email' => 'auditor@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'senior_auditor_admin_token_001',
        ]);
    }

    public function test_purchase_return_fifo_cost_layers_tracked_updated_reverted_and_deleted_with_exact_restoration(): void
    {
        $fifo = app(FifoInventoryService::class);
        $batch1 = $fifo->recordBatch(
            $this->warehouse->id,
            $this->product->id,
            null,
            10.0,
            100.0,
            'purchase',
            'PO-LYR-01',
            '2026-10-01 10:00:00'
        );
        $batch2 = $fifo->recordBatch(
            $this->warehouse->id,
            $this->product->id,
            null,
            10.0,
            150.0,
            'purchase',
            'PO-LYR-02',
            '2026-10-02 10:00:00'
        );

        $this->product->update(['stock_quantity' => 20.0]);
        StockBalance::where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 20.0]);

        // 1. Return 15 units unlinked -> depletes 10 @ 100 + 5 @ 150 = 1750 FIFO cost (credit 2000 -> variance +250)
        $createRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-LYR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 11:00:00',
            'status' => 'Completed',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 15.0,
                'unit_cost' => 133.3333,
                'subtotal' => 2000.0,
                'conversion_rate' => 1.0,
            ]],
        ]);
        $createRes->assertStatus(201);
        $prId = (int)$createRes->json('data.id');

        $pr = PurchaseReturn::findOrFail($prId);
        $this->assertEqualsWithDelta(1750.0, (float)$pr->return_cost_total, 0.05);
        $this->assertEqualsWithDelta(250.0, (float)$pr->cost_variance, 0.05);

        $layers = PurchaseReturnCostLayer::where('purchase_return_id', $prId)->orderBy('id')->get();
        $this->assertCount(2, $layers);
        $this->assertEquals($batch1->id, $layers[0]->inventory_batch_id);
        $this->assertEquals(10.0, (float)$layers[0]->base_quantity);
        $this->assertEquals(1000.0, (float)$layers[0]->total_cost);
        $this->assertEquals($batch2->id, $layers[1]->inventory_batch_id);
        $this->assertEquals(5.0, (float)$layers[1]->base_quantity);
        $this->assertEquals(750.0, (float)$layers[1]->total_cost);

        $this->assertEquals(0.0, (float)$batch1->fresh()->remaining_quantity);
        $this->assertSame('exhausted', $batch1->fresh()->status);
        $this->assertEquals(5.0, (float)$batch2->fresh()->remaining_quantity);

        // 2. Edit PR qty 15 -> 12 -> restores 10+5 then depletes 10@100 + 2@150 = 1300 FIFO cost
        $updRes = $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 11:00:00',
            'status' => 'Completed',
            'subtotal' => 1500.0,
            'grand_total' => 1500.0,
            'paid_amount' => 1500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 12.0,
                'unit_cost' => 125.0,
                'subtotal' => 1500.0,
                'conversion_rate' => 1.0,
            ]],
        ]);
        $updRes->assertStatus(200);
        $pr->refresh();
        $this->assertEquals(1300.0, (float)$pr->return_cost_total);
        $this->assertEquals(8.0, (float)$batch2->fresh()->remaining_quantity);
        $this->assertEquals(8.0, (float)$this->product->fresh()->stock_quantity);

        // 3. Revert PR status Completed -> Pending -> restores exact 10 + 10 batches & deletes cost layers
        $revertRes = $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 11:00:00',
            'status' => 'Pending',
            'subtotal' => 1500.0,
            'grand_total' => 1500.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 12.0,
                'unit_cost' => 125.0,
                'subtotal' => 1500.0,
                'conversion_rate' => 1.0,
            ]],
        ]);
        $revertRes->assertStatus(200);
        $this->assertEquals(10.0, (float)$batch1->fresh()->remaining_quantity);
        $this->assertSame('active', $batch1->fresh()->status);
        $this->assertEquals(10.0, (float)$batch2->fresh()->remaining_quantity);
        $this->assertEquals(20.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(0, PurchaseReturnCostLayer::where('purchase_return_id', $prId)->count());

        // 4. Re-complete PR Pending -> Completed and then delete -> exact restoration again
        $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 11:00:00',
            'status' => 'Completed',
            'subtotal' => 1500.0,
            'grand_total' => 1500.0,
            'paid_amount' => 1500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 12.0,
                'unit_cost' => 125.0,
                'subtotal' => 1500.0,
                'conversion_rate' => 1.0,
            ]],
        ])->assertStatus(200);

        $this->assertEquals(8.0, (float)$batch2->fresh()->remaining_quantity);

        $this->withToken($this->admin->api_token)->deleteJson("/api/purchase-returns/{$prId}")
            ->assertStatus(200);

        $this->assertEquals(10.0, (float)$batch1->fresh()->remaining_quantity);
        $this->assertSame('active', $batch1->fresh()->status);
        $this->assertEquals(10.0, (float)$batch2->fresh()->remaining_quantity);
        $this->assertEquals(20.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(0, PurchaseReturnCostLayer::where('purchase_return_id', $prId)->count());
    }

    public function test_purchase_consumption_and_active_return_guards_protect_batch_integrity(): void
    {
        // 1. Create Received PO with 10 units @ 500
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-GUARD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 10.0,
                'subtotal' => 5000.0,
            ]],
        ]);
        $poRes->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Sell 2 units via Sale -> depletes 2 from PO-GUARD-01 batch
        $saleRes = $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'subtotal' => 320.0,
            'grand_total' => 320.0,
            'paid_amount' => 320.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_price' => 160.0,
                'quantity' => 2.0,
                'subtotal' => 320.0,
            ]],
        ]);
        $saleRes->assertStatus(201);

        // Editing PO items once consumed must be blocked (422)
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 600.0,
                'quantity' => 10.0,
                'subtotal' => 6000.0,
            ]],
        ])->assertStatus(422);

        // Changing PO warehouse once consumed must also be blocked (422)
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->secondaryWarehouse->id,
            'warehouse_name' => $this->secondaryWarehouse->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
        ])->assertStatus(422);

        // Deleting PO once consumed must be blocked (422)
        $this->withToken($this->admin->api_token)->deleteJson("/api/purchases/{$poId}")
            ->assertStatus(422);

        // 2. Unconsumed Received PO can be edited/deleted, UNLESS an active PR references it
        $po2Res = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-GUARD-02',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Received',
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 200.0,
                'quantity' => 5.0,
                'subtotal' => 1000.0,
            ]],
        ]);
        $po2Res->assertStatus(201);
        $po2Id = (int)$po2Res->json('data.id');

        // Edit unconsumed PO2 from 5 -> 6 units succeeds
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po2Id}", [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Received',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 200.0,
                'quantity' => 6.0,
                'subtotal' => 1200.0,
            ]],
        ])->assertStatus(200);
        $this->assertEquals(6.0, (float)$this->secondProduct->fresh()->stock_quantity);

        // Create Completed PR referencing PO-GUARD-02 (returning 2 units)
        $pr2Res = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-GUARD-02',
            'purchase_reference_no' => 'PO-GUARD-02',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-13 10:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'quantity' => 2.0,
                'unit_cost' => 200.0,
                'subtotal' => 400.0,
            ]],
        ]);
        $pr2Res->assertStatus(201);

        // Deleting PO-GUARD-02 while active PR references it must be blocked (422)
        $this->withToken($this->admin->api_token)->deleteJson("/api/purchases/{$po2Id}")
            ->assertStatus(422);
    }

    public function test_ias2_tax_recoverability_and_no_double_tax_when_line_items_carry_tax(): void
    {
        // PO with line-level tax 500, discount 500, shipping 200, order tax_rate 10%:
        // Tax-exclusive net base = 10000 - 500 discount = 9500 (excludes 500 line tax so NO tax-on-tax!).
        // order tax_amount = 9500 * 10% = 950.0 (not 1000.0).
        // grand_total = 10500 - 500 + 950 + 200 = 11150.0.
        // IAS 2 recoverable inventory cost excludes both line tax (500) & order tax (950): (10000 - 500 + 200) / 10 = 970.0/unit.
        $poRec = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-IAS2-REC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 09:00:00',
            'status' => 'Received',
            'tax_rate' => 10.0,
            'discount' => 500.0,
            'shipping' => 200.0,
            'is_tax_recoverable' => true,
            'paid_amount' => 11150.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'tax' => 500.0,
                'subtotal' => 10500.0,
            ]],
        ]);
        $poRec->assertStatus(201);
        $recPurchase = Purchase::with('items')->findOrFail($poRec->json('data.id'));
        $this->assertEquals(950.0, (float)$recPurchase->tax_amount);
        $this->assertEquals(11150.0, (float)$recPurchase->grand_total);

        $recBatch = InventoryBatch::where('purchase_item_id', $recPurchase->items->first()->id)->firstOrFail();
        $this->assertEquals(970.0, (float)$recBatch->unit_cost);

        // Non-recoverable tax PO (default when omitted) capitalizes all non-recoverable taxes: 11150 / 10 = 1115.0/unit
        $poNonRec = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-IAS2-NONREC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 10:00:00',
            'status' => 'Received',
            'tax_rate' => 10.0,
            'discount' => 500.0,
            'shipping' => 200.0,
            'paid_amount' => 11150.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'tax' => 500.0,
                'subtotal' => 10500.0,
            ]],
        ]);
        $poNonRec->assertStatus(201);
        $nonRecPurchase = Purchase::with('items')->findOrFail($poNonRec->json('data.id'));
        $this->assertFalse((bool)$nonRecPurchase->is_tax_recoverable);
        $nonRecBatch = InventoryBatch::where('purchase_item_id', $nonRecPurchase->items->first()->id)->firstOrFail();
        $this->assertEquals(1115.0, (float)$nonRecBatch->unit_cost);

        // Purchase Return with line-level tax 100, discount 50, shipping 20, tax_rate 10% -> no tax-on-tax!
        // tax-exclusive base = 2000 - 50 = 1950 -> order tax = 195.0; grand_total = 2100 - 50 + 195 + 20 = 2265.0
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-IAS2-TAX',
            'purchase_reference_no' => 'PO-IAS2-REC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 12:00:00',
            'status' => 'Completed',
            'tax_rate' => 10.0,
            'discount' => 50.0,
            'shipping' => 20.0,
            'is_tax_recoverable' => true,
            'subtotal' => 2100.0,
            'grand_total' => 2265.0,
            'paid_amount' => 2265.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'tax' => 100.0,
                'subtotal' => 2100.0,
            ]],
        ]);
        $prRes->assertStatus(201);
        $prModel = PurchaseReturn::findOrFail($prRes->json('data.id'));
        $this->assertEquals(195.0, (float)$prModel->tax_amount);
        $this->assertEquals(2265.0, (float)$prModel->grand_total);
    }

    public function test_pending_and_ordered_purchases_and_pending_returns_excluded_from_ap_stock_and_reports(): void
    {
        $today = Carbon::today()->toDateString();

        // Pending PO (unpaid 10,000 + 500 tax)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-PEND-ONLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => "{$today} 09:00:00",
            'status' => 'Pending',
            'tax_rate' => 5.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);

        // Ordered PO (unpaid 5,000)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-ORD-ONLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => "{$today} 10:00:00",
            'status' => 'Ordered',
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 5.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        // Pending Return (unlinked 4,000 + 200 tax)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-PEND-ONLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => "{$today} 11:00:00",
            'status' => 'Pending',
            'tax_rate' => 5.0,
            'subtotal' => 4000.0,
            'grand_total' => 4200.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 4.0,
                'subtotal' => 4000.0,
            ]],
        ])->assertStatus(201);

        // Zero inventory & AP impact
        $this->assertEquals(0.0, (float)$this->product->fresh()->stock_quantity);
        $this->assertEquals(0, InventoryBatch::count());
        $this->assertEquals(0.0, (float)$this->supplier->fresh()->balance_due);

        // Reports exclude Pending / Ordered
        $dash = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dashboard-summary?date_filter=custom&start_date={$today}&end_date={$today}&force_refresh=true")
            ->assertStatus(200)
            ->json('data');
        $this->assertEquals(0.0, (float)$dash['total_purchases']);
        $this->assertEquals(0.0, (float)$dash['supplier_due']);

        $pnl = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/profit-loss?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200)
            ->json();
        $this->assertEquals(0.0, (float)($pnl['summary']['total_purchases'] ?? $pnl['data']['total_purchases'] ?? 0));

        $tax = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/commercial-tax?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200)
            ->json();
        $this->assertEquals(0.0, (float)($tax['input_tax']['net_input_tax'] ?? 0));
        $this->assertEquals(0.0, (float)($tax['input_tax']['gross_purchases_tax'] ?? 0));

        $apAging = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?force_refresh=1')
            ->assertStatus(200)
            ->json();
        $this->assertEquals(0.0, (float)($apAging['summary']['total_payable'] ?? $apAging['summary']['total_due'] ?? 0));

        $ledger = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200)
            ->json();
        $this->assertCount(0, $ledger['movements'] ?? []);
    }

    public function test_ap_credit_note_offset_marks_po_credit_settled_and_reconciles_supplier_balance(): void
    {
        // Unpaid Received PO = 20,000
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-CRD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 09:00:00',
            'status' => 'Received',
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 20.0,
                'subtotal' => 20000.0,
            ]],
        ]);
        $poRes->assertStatus(201);
        $po = Purchase::findOrFail($poRes->json('data.id'));
        $this->assertEquals(20000.0, (float)$this->supplier->fresh()->balance_due);

        // Full Credit Note PR returning all 20 units (no cash refunded, offset against PO AP)
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-CRD-01',
            'purchase_reference_no' => 'PO-CRD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 11:00:00',
            'status' => 'Completed',
            'subtotal' => 20000.0,
            'grand_total' => 20000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 20.0,
                'subtotal' => 20000.0,
            ]],
        ]);
        $prRes->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        $po->refresh();
        $this->assertEquals(20000.0, (float)$po->returned_credit_amount);
        $this->assertEquals(0.0, (float)$po->due_amount);
        $this->assertSame('Credit Settled', $po->payment_status);
        $this->assertEquals(0.0, (float)$this->supplier->fresh()->balance_due);

        $alloc = PurchaseReturnAllocation::where('purchase_id', $po->id)
            ->where('purchase_return_id', $prId)
            ->first();
        $this->assertNotNull($alloc);
        $this->assertEquals(20000.0, (float)$alloc->amount);
    }

    public function test_purchase_return_negative_guards_enforce_qty_status_sku_supplier_and_warehouse(): void
    {
        // Pending PO cannot be returned against
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-PEND-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 09:00:00',
            'status' => 'Pending',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 5.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-PEND-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(422);

        // Received PO of 5 units of product A
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 09:30:00',
            'status' => 'Received',
            'paid_amount' => 5000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 5.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        // Add stock of secondProduct so stock itself isn't why SKU guard rejects
        app(FifoInventoryService::class)->recordBatch(
            $this->warehouse->id,
            $this->secondProduct->id,
            null,
            5.0,
            200.0,
            'adjustment',
            'ADJ-SEED',
            '2026-10-16 08:00:00'
        );
        $this->secondProduct->update(['stock_quantity' => 5.0]);
        StockBalance::where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->secondProduct->id)
            ->update(['quantity' => 5.0]);

        // Unpurchased SKU blocked (422)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 11:00:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 200.0,
                'quantity' => 1.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(422);

        // Supplier mismatch blocked (422)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 11:05:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(422);

        // Warehouse mismatch blocked (422)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->secondaryWarehouse->id,
            'warehouse_name' => $this->secondaryWarehouse->name,
            'date' => '2026-10-16 11:10:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(422);

        // Cumulative over-return blocked: first return 3 units OK, second return 3 units (3+3=6 > 5) blocked (422)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-PART-01',
            'purchase_reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 11:15:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 3.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-OVER-02',
            'purchase_reference_no' => 'PO-VALID-RET',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 11:20:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 3.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_cash_register_reconciles_cash_purchase_payout_and_cash_supplier_refund_while_ignoring_pending(): void
    {
        $now = Carbon::now();
        $openRes = $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 50000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'Main Till',
            'user_name' => $this->admin->name,
        ]);
        $openRes->assertStatus(200);
        $register = CashRegister::where('status', 'open')->firstOrFail();

        // Received Cash PO paying 10,000 out of till
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-TILL-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->copy()->addMinutes(5)->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'paid_amount' => 10000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);

        // Pending Cash PO (must NOT affect till!)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-TILL-PEND',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->copy()->addMinutes(6)->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'paid_amount' => 4000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 4.0,
                'subtotal' => 4000.0,
            ]],
        ])->assertStatus(201);

        // Completed Cash PR receiving 3,000 refund back into till
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-TILL-01',
            'purchase_reference_no' => 'PO-TILL-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 3.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(201);

        $details = $this->withToken($this->admin->api_token)
            ->getJson("/api/cash-register/details/{$register->id}")
            ->assertStatus(200)
            ->json('register');

        // Expected cash = 50,000 opening - 10,000 cash PO + 3,000 cash PR refund = 43,000
        $this->assertEquals(43000.0, (float)$details['expected_cash']);
    }

    public function test_reports_cash_flow_payment_timing_commercial_tax_and_stock_ledger_purchase_return_void(): void
    {
        // PO created on 2026-10-01 unpaid with line-item input tax 500 + shipping 200
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-RPT-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-01 10:00:00',
            'status' => 'Received',
            'shipping' => 200.0,
            'is_tax_recoverable' => true,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'tax' => 500.0,
                'subtotal' => 10500.0,
            ]],
        ]);
        $poRes->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Later payment made on 2026-10-15 for 6,000
        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$poId}/payments", [
            'amount' => 6000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-10-15 14:00:00',
        ])->assertStatus(200);

        // Cash Flow on 2026-10-15 should capture the 6,000 payment outflow (based on payment date, not PO creation date!)
        $cf = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/cash-flow?start_date=2026-10-15&end_date=2026-10-15&force_refresh=1')
            ->assertStatus(200)
            ->json('summary');
        $this->assertEquals(6000.0, (float)($cf['purchases_cash_paid'] ?? 0));

        // Commercial Tax for Oct 1..20 should capture 500 input tax and exclude 200 shipping from taxable purchases
        $tax = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/commercial-tax?start_date=2026-10-01&end_date=2026-10-20&force_refresh=1')
            ->assertStatus(200)
            ->json('input_tax');
        $this->assertEquals(500.0, (float)($tax['net_input_tax'] ?? 0));
        $this->assertEquals(10000.0, (float)($tax['taxable_purchases_amount'] ?? 0));

        // Create and delete PR on 2026-10-16 so stock_movements has purchase_return_void
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-RPT-VOID',
            'purchase_reference_no' => 'PO-RPT-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 09:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ]);
        $prRes->assertStatus(201);
        $this->withToken($this->admin->api_token)->deleteJson('/api/purchase-returns/' . $prRes->json('data.id'))
            ->assertStatus(200);

        $todayStr = Carbon::today()->toDateString();
        $ledger = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?start_date=2026-10-01&end_date={$todayStr}&force_refresh=1")
            ->assertStatus(200)
            ->json('movements');
        $types = collect($ledger)->pluck('movement_type')->all();
        $this->assertContains('purchase_return', $types);
        $this->assertContains('purchase_return_void', $types);
    }

    public function test_cost_masking_hides_purchase_and_return_costs_in_json_and_csv_without_view_cost_permission(): void
    {
        $role = Role::create([
            'name' => 'Warehouse Receiving Clerk',
            'slug' => 'receiving_clerk',
            'permissions' => [
                'purchases.view',
                'purchases.export',
                'purchase_returns.view',
                'purchase_returns.export',
            ],
            'status' => 'active',
        ]);

        $clerk = User::create([
            'user_id' => 'USR-CLERK-01',
            'name' => 'Receiving Clerk',
            'email' => 'clerk@retailpos.test',
            'role' => 'Warehouse Receiving Clerk',
            'role_id' => $role->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'receiving_clerk_token_123',
        ]);

        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-MSK-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-18 10:00:00',
            'status' => 'Received',
            'paid_amount' => 5000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 10.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-MSK-01',
            'purchase_reference_no' => 'PO-MSK-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-18 11:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        // Purchases JSON masked
        $poIndex = $this->withToken($clerk->api_token)->getJson('/api/purchases')->assertStatus(200)->json();
        $firstPo = $poIndex['data'][0];
        $this->assertTrue((bool)$firstPo['cost_masked']);
        $this->assertNull($firstPo['grand_total']);
        $this->assertNull($firstPo['paid_amount']);
        $this->assertNull($firstPo['due_amount']);
        $this->assertNull($firstPo['items'][0]['unit_cost']);

        // Purchases CSV export masked
        $poCsv = $this->withToken($clerk->api_token)->get('/api/purchases/export')->assertStatus(200)->streamedContent();
        $this->assertStringContainsString('Protected', $poCsv);
        $this->assertStringNotContainsString('5000.00', $poCsv);

        // Purchase Returns JSON masked
        $prIndex = $this->withToken($clerk->api_token)->getJson('/api/purchase-returns')->assertStatus(200)->json();
        $firstPr = $prIndex['data'][0];
        $this->assertTrue((bool)$firstPr['cost_masked']);
        $this->assertNull($firstPr['grand_total']);
        $this->assertNull($firstPr['items'][0]['unit_cost']);

        // Purchase Returns CSV export masked
        $prCsv = $this->withToken($clerk->api_token)->get('/api/purchase-returns/export')->assertStatus(200)->streamedContent();
        $this->assertStringContainsString('Protected', $prCsv);
        $this->assertStringNotContainsString('1000.00', $prCsv);
    }

    public function test_tax_recoverability_defaults_to_false_across_settings_purchase_and_purchase_return(): void
    {
        // Settings defaults must expose tax_is_recoverable = '0'
        $settings = $this->withToken($this->admin->api_token)->getJson('/api/settings')->assertStatus(200)->json('data');
        $this->assertSame('0', $settings['tax_is_recoverable'] ?? null);

        // Creating a PO without specifying is_tax_recoverable defaults to false and excludes from Commercial Tax input credit
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-DEF-NONREC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19 10:00:00',
            'status' => 'Received',
            'tax_rate' => 5.0,
            'paid_amount' => 10500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);

        $po = Purchase::with('items')->findOrFail($poRes->json('data.id'));
        $this->assertFalse((bool)$po->is_tax_recoverable);
        $batch = InventoryBatch::where('purchase_item_id', $po->items->first()->id)->firstOrFail();
        $this->assertEquals(1050.0, (float)$batch->unit_cost);

        // Creating a PR without specifying is_tax_recoverable also defaults to false
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-DEF-NONREC',
            'purchase_reference_no' => 'PO-DEF-NONREC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19 12:00:00',
            'status' => 'Completed',
            'tax_rate' => 5.0,
            'subtotal' => 2000.0,
            'grand_total' => 2100.0,
            'paid_amount' => 2100.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);

        $pr = PurchaseReturn::findOrFail($prRes->json('data.id'));
        $this->assertFalse((bool)$pr->is_tax_recoverable);

        $ct = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/commercial-tax?start_date=2026-10-19&end_date=2026-10-19&force_refresh=1')
            ->assertStatus(200)
            ->json('input_tax');
        $this->assertEquals(0.0, (float)($ct['net_input_tax'] ?? 0));
    }

    public function test_p0_partial_refund_preserves_credit_allocation_and_credit_note_strips_cash_tenders_and_caps_split(): void
    {
        // PO: 10 units @ 500 = 5,000; 4,000 paid -> PO due = 1,000, Supplier balance_due = 1,000
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P0-CRD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20 09:00:00',
            'status' => 'Received',
            'paid_amount' => 4000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 10.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');
        $this->assertEquals(1000.0, (float)$this->supplier->fresh()->balance_due);

        // PR of 3,000 sent with payment_type = 'Credit Note' AND bogus paid_amount = 3000 & Credit Note tender in payments[]
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P0-CRD-01',
            'purchase_reference_no' => 'PO-P0-CRD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20 10:00:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Credit Note',
            'payments' => [
                ['payment_type' => 'Credit Note', 'amount' => 3000.0],
            ],
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 6.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');
        $pr = PurchaseReturn::findOrFail($prId);

        // [P0-2]: Credit Note forces paid=0, due=3000, Credit Note, empty cash payments, cost_variance = 0
        $this->assertEquals(0.0, (float)$pr->paid_amount);
        $this->assertEquals(3000.0, (float)$pr->due_amount);
        $this->assertSame('Unpaid', $pr->payment_status);
        $this->assertSame('Credit Note', $pr->payment_type);
        $this->assertEmpty($pr->payments);
        $this->assertEquals(0.0, (float)$pr->cost_variance);

        // PO due becomes 0 ('Paid' via 4k cash + 1k credit note allocated), supplier balance drops 1000 - 3000 = -2000
        $po = Purchase::findOrFail($poId);
        $this->assertEquals(0.0, (float)$po->due_amount);
        $this->assertEquals(1000.0, (float)$po->returned_credit_amount);
        $this->assertSame('Paid', $po->payment_status);
        $this->assertEquals(-2000.0, (float)$this->supplier->fresh()->balance_due);

        // [P0-1]: Supplier refunds excess 2,000 cash -> PR due = 1,000, still >= 1,000 allocation! PO MUST remain settled (due=0)
        $this->withToken($this->admin->api_token)->postJson("/api/purchase-returns/{$prId}/payments", [
            'amount' => 2000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-10-20 11:00:00',
        ])->assertStatus(200);

        $this->assertEquals(0.0, (float)$po->fresh()->due_amount);
        $this->assertEquals(1000.0, (float)$po->fresh()->returned_credit_amount);
        $this->assertSame('Paid', $po->fresh()->payment_status);
        $this->assertEquals(0.0, (float)$this->supplier->fresh()->balance_due);

        // Adding another 500 cash refund drops PR due to 500 -> ONLY excess 500 allocation releases! PO due = 500 Partial
        $this->withToken($this->admin->api_token)->postJson("/api/purchase-returns/{$prId}/payments", [
            'amount' => 500.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-10-20 12:00:00',
        ])->assertStatus(200);

        $this->assertEquals(500.0, (float)$po->fresh()->due_amount);
        $this->assertEquals(500.0, (float)$po->fresh()->returned_credit_amount);
        $this->assertSame('Partial', $po->fresh()->payment_status);
        $this->assertEquals(500.0, (float)$this->supplier->fresh()->balance_due);

        // Credit Note tender on addPayment must be rejected (422)
        $this->withToken($this->admin->api_token)->postJson("/api/purchase-returns/{$prId}/payments", [
            'amount' => 100.0,
            'payment_type' => 'Credit Note',
        ])->assertStatus(422);

        // [P0-2] + [P1-5]: Split Return (Cash tendered 1,500 + Credit Note 2,000 on 1,000 return) -> caps cash tender at 1,000 (change 500), strips Credit Note
        $splitRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P0-SPLIT-02',
            'purchase_reference_no' => 'PO-P0-CRD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20 13:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1500.0,
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 1500.0],
                ['payment_type' => 'Credit Note', 'amount' => 2000.0],
            ],
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        $splitPr = PurchaseReturn::findOrFail($splitRes->json('data.id'));
        $this->assertEquals(1000.0, (float)$splitPr->paid_amount);
        $this->assertEquals(0.0, (float)$splitPr->due_amount);
        $this->assertCount(1, $splitPr->payments);
        $this->assertSame('Cash', $splitPr->payments[0]['payment_type']);
        $this->assertEquals(1000.0, (float)$splitPr->payments[0]['amount']);
        $this->assertEquals(1500.0, (float)$splitPr->payments[0]['received_amount']);
        $this->assertEquals(500.0, (float)$splitPr->payments[0]['change_amount']);
    }

    public function test_p1_purchase_return_update_recomputes_cost_variance_from_updated_header(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-VAR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-21 09:00:00',
            'status' => 'Received',
            'paid_amount' => 5000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 10.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        // Return 2 units @ 500 (FIFO cost = 1,000, variance = 0)
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-VAR-01',
            'purchase_reference_no' => 'PO-P1-VAR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-21 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');
        $this->assertEquals(0.0, (float)PurchaseReturn::findOrFail($prId)->cost_variance);

        // [P1-1]: Update discount = 150 (credit = 850 vs FIFO 1,000 -> cost_variance = -150)
        $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'discount' => 150.0,
        ])->assertStatus(200);

        $updatedPr = PurchaseReturn::findOrFail($prId);
        $this->assertEquals(850.0, (float)$updatedPr->grand_total);
        $this->assertEquals(1000.0, (float)$updatedPr->return_cost_total);
        $this->assertEqualsWithDelta(-150.0, (float)$updatedPr->cost_variance, 0.01);
    }

    public function test_p1_profit_loss_includes_purchase_return_cost_variance_and_report_status_guards(): void
    {
        // 1. Received PO (10 units @ 500 = 5,000)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-ALL-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 10.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        // 2. Pending & Ordered POs ([P1-4] addPayment guard + [P1-3] report exclusion under All)
        $pendPo = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-PEND-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:30:00',
            'status' => 'Pending',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $pendPoId = (int)$pendPo->json('data.id');

        $ordPo = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-ORD-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:45:00',
            'status' => 'Ordered',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $ordPoId = (int)$ordPo->json('data.id');

        // [P1-4]: Recording payment on Pending or Ordered PO must be rejected with HTTP 422
        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$pendPoId}/payments", [
            'amount' => 500.0,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$ordPoId}/payments", [
            'amount' => 500.0,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        // 3. Completed Purchase Return with -200 cost variance (2 units FIFO=1,000, credit=800)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-VAR-02',
            'purchase_reference_no' => 'PO-P1-ALL-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 11:00:00',
            'status' => 'Completed',
            'subtotal' => 800.0,
            'grand_total' => 800.0,
            'paid_amount' => 800.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 400.0,
                'quantity' => 2.0,
                'subtotal' => 800.0,
            ]],
        ])->assertStatus(201);

        // Pending PR (excluded under default status=All in purchase-returns report)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-PEND-02',
            'purchase_reference_no' => 'PO-P1-ALL-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 12:00:00',
            'status' => 'Pending',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 1.0,
                'subtotal' => 500.0,
            ]],
        ])->assertStatus(201);

        // 4. Sale in Oct 2026 (Revenue 3,000 - COGS 1,000 = Gross Profit 2,000)
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'reference_no' => 'SL-P1-PL-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_name' => 'Walk-in',
            'date' => '2026-10-10 13:00:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'due_amount' => 0.0,
            'total_cogs' => 1000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'unit_price' => 1500.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'subtotal' => 3000.0,
            'total_cogs' => 1000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // [P1-2]: P&L must include purchase_return_variance (-200) -> Net Profit = 2,000 - 200 = 1,800
        $plRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/profit-loss?start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200)
            ->json('data');
        $this->assertEqualsWithDelta(-200.0, (float)($plRes['purchase_return_variance'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(-200.0, (float)($plRes['summary']['purchase_return_variance'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(1800.0, (float)($plRes['total_net_profit'] ?? 0), 0.01);
        $octPeriod = collect($plRes['periods'] ?? [])->first(fn($p) => abs((float)($p['purchase_return_variance'] ?? 0)) > 0.01);
        $this->assertNotNull($octPeriod);
        $this->assertEqualsWithDelta(-200.0, (float)$octPeriod['purchase_return_variance'], 0.01);

        // [P1-3] & [P1-6]: Reports purchases default (All) excludes Pending/Ordered & includes P1-6 fields
        $purchRepDefault = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchases?start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200)
            ->json('data');
        $this->assertEquals(1, (int)$purchRepDefault['total_purchases_count']);
        $firstPoRep = $purchRepDefault['purchases'][0];
        $this->assertEquals('PO-P1-ALL-01', $firstPoRep['reference_no']);
        $this->assertArrayHasKey('is_tax_recoverable', $firstPoRep);
        $this->assertArrayHasKey('returned_credit_amount', $firstPoRep);
        $this->assertArrayHasKey('cash_register_id', $firstPoRep);
        $this->assertArrayHasKey('payments_payload', $firstPoRep);

        // Explicit status=Pending filter returns only PO-P1-PEND-01
        $purchRepPend = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchases?status=Pending&start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200)
            ->json('data');
        $this->assertEquals(1, (int)$purchRepPend['total_purchases_count']);
        $this->assertEquals('PO-P1-PEND-01', $purchRepPend['purchases'][0]['reference_no']);

        // Reports purchase-returns default (All) excludes Pending PR, explicit status=Pending returns it
        $prRepDefault = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchase-returns?start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200)
            ->json();
        $this->assertEquals(1, (int)($prRepDefault['summary']['total_returns'] ?? 0));
        $this->assertEquals('PR-P1-VAR-02', $prRepDefault['data'][0]['reference_no']);

        $prRepPend = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchase-returns?status=Pending&start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200)
            ->json();
        $this->assertEquals(1, (int)($prRepPend['summary']['total_returns'] ?? 0));
        $this->assertEquals('PR-P1-PEND-02', $prRepPend['data'][0]['reference_no']);

        // [P1-6]: /api/purchases index includes required fields
        $poIndex = $this->withToken($this->admin->api_token)
            ->getJson('/api/purchases?search=PO-P1-ALL-01')
            ->assertStatus(200)
            ->json('data.0');
        $this->assertIsArray($poIndex);
        $this->assertArrayHasKey('is_tax_recoverable', $poIndex);
        $this->assertArrayHasKey('returned_credit_amount', $poIndex);
        $this->assertArrayHasKey('cash_register_id', $poIndex);
        $this->assertArrayHasKey('payments', $poIndex);
    }

    public function test_p2_header_edit_syncs_cost_export_columns_rbac_and_historical_ap_cashflow_stockledger(): void
    {
        // [P2-1]: Header-only update (shipping change without items) syncs batch unit_cost AND Product master cost
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-HDR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 09:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        $this->assertEqualsWithDelta(100.0, (float)$this->product->fresh()->cost_price, 0.01);

        // Update shipping = 200 without sending items
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'shipping' => 200.0,
        ])->assertStatus(200);

        $itemIds = Purchase::findOrFail($poId)->items->pluck('id');
        $batch = InventoryBatch::whereIn('purchase_item_id', $itemIds)->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float)$batch->unit_cost, 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$this->product->fresh()->cost_price, 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$this->product->fresh()->purchase_price, 0.01);

        // [P2-3]: Credit Note creates returned_credit_amount=300; CSV export appends Returned Credit Offset & Tax Recoverable
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-CR-01',
            'purchase_reference_no' => 'PO-P2-HDR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 10:00:00',
            'status' => 'Completed',
            'subtotal' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 150.0,
                'quantity' => 2.0,
                'subtotal' => 300.0,
            ]],
        ])->assertStatus(201);

        $adminCsv = $this->withToken($this->admin->api_token)
            ->get('/api/purchases/export?search=PO-P2-HDR-01')
            ->assertStatus(200)
            ->streamedContent();
        $this->assertStringContainsString('Returned Credit Offset', $adminCsv);
        $this->assertStringContainsString('Tax Recoverable', $adminCsv);
        $this->assertStringContainsString(',300,No', $adminCsv);

        $noCostRole = Role::create([
            'name' => 'No Cost Export Viewer',
            'slug' => 'no_cost_export_viewer',
            'permissions' => ['purchases.view', 'purchases.export'],
            'status' => 'active',
        ]);
        $noCostUser = User::create([
            'user_id' => 'USR-NOCOST-01',
            'name' => 'No Cost Clerk',
            'email' => 'nocost@retailpos.test',
            'role' => 'No Cost Export Viewer',
            'role_id' => $noCostRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'no_cost_export_token_123',
        ]);
        $maskedCsv = $this->withToken($noCostUser->api_token)
            ->get('/api/purchases/export?search=PO-P2-HDR-01')
            ->assertStatus(200)
            ->streamedContent();
        $this->assertStringNotContainsString(',300,No', $maskedCsv);
        $this->assertStringContainsString(',Protected,No', $maskedCsv);

        // [P2-4a]: Historical AP Aging reconstructs open debt before later settlement
        $histPo = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-HIST-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-01 09:00:00',
            'status' => 'Received',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);
        $histPoId = (int)$histPo->json('data.id');

        // Settle PO on 2026-10-20 (currently due_amount = 0.0)
        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$histPoId}/payments", [
            'amount' => 2000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-10-20 12:00:00',
        ])->assertStatus(200);
        $this->assertEquals(0.0, (float)Purchase::findOrFail($histPoId)->due_amount);

        // As of 2026-10-15 (before Oct 20 payment), 2,000.0 must still show in AP Aging for otherSupplier
        $apAsOfOct15 = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-15&force_refresh=1')
            ->assertStatus(200)
            ->json('data');
        $histSupplierRow = collect($apAsOfOct15)->firstWhere('supplier_id', $this->otherSupplier->id);
        $this->assertNotNull($histSupplierRow);
        $this->assertEqualsWithDelta(2000.0, (float)$histSupplierRow['total_due'], 0.01);

        // As of 2026-10-25 (after Oct 20 payment), otherSupplier has 0 open AP
        $apAsOfOct25 = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-25&force_refresh=1')
            ->assertStatus(200)
            ->json('data');
        $settledSupplierRow = collect($apAsOfOct25)->firstWhere('supplier_id', $this->otherSupplier->id);
        $this->assertTrue($settledSupplierRow === null || (float)$settledSupplierRow['total_due'] < 0.01);

        // [P2-4b]: Cash Flow includes Received POs with non-'Opening Stock' payment_type & excludes 'Opening Stock'
        \Illuminate\Support\Facades\DB::table('purchases')->insert([
            'reference_no' => 'PO-P2-BLANKPT-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Received',
            'payment_status' => 'Paid',
            'payment_type' => '',
            'grand_total' => 750.0,
            'paid_amount' => 750.0,
            'due_amount' => 0.0,
            'payments_payload' => json_encode([['date' => '2026-10-12', 'amount' => 750.0, 'payment_type' => 'Cash']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('purchases')->insert([
            'reference_no' => 'PO-P2-OPENING-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:05:00',
            'status' => 'Received',
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'due_amount' => 0.0,
            'payments_payload' => json_encode([['date' => '2026-10-12', 'amount' => 500.0, 'payment_type' => 'Opening Stock']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cf = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/cash-flow?start_date=2026-10-12&end_date=2026-10-12&force_refresh=1')
            ->assertStatus(200)
            ->json('summary');
        $this->assertEqualsWithDelta(750.0, (float)($cf['purchases_cash_paid'] ?? 0), 0.01);

        // [P2-4c]: Stock Ledger aligns purchase_return_void date to purchase_returns.date
        $voidPr = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-VOID-01',
            'purchase_reference_no' => 'PO-P2-HDR-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-08 09:00:00',
            'status' => 'Completed',
            'subtotal' => 150.0,
            'grand_total' => 150.0,
            'paid_amount' => 150.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 150.0,
                'quantity' => 1.0,
                'subtotal' => 150.0,
            ]],
        ])->assertStatus(201);
        $voidPrId = (int)$voidPr->json('data.id');

        // Revert to Pending -> creates purchase_return_void whose date should follow purchase_returns.date (2026-10-08)
        $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$voidPrId}", [
            'status' => 'Pending',
        ])->assertStatus(200);

        $ledgerMovements = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-08&end_date=2026-10-08&force_refresh=1')
            ->assertStatus(200)
            ->json('movements');
        $voidTypes = collect($ledgerMovements)->pluck('movement_type')->all();
        $this->assertContains('purchase_return_void', $voidTypes);
    }

    public function test_p1_1_update_blocks_supplier_warehouse_and_status_reversion_when_linked_return_exists(): void
    {
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-1-LOCK',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-1-LOCK',
            'purchase_reference_no' => 'PO-P1-1-LOCK',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 09:00:00',
            'status' => 'Completed',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);

        // Supplier reassignment must be blocked
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
        ])->assertStatus(422);

        // Warehouse change must be blocked
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'warehouse_id' => $this->secondaryWarehouse->id,
            'warehouse_name' => $this->secondaryWarehouse->name,
        ])->assertStatus(422);

        // Reversion to Pending or Ordered must be blocked
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'status' => 'Pending',
        ])->assertStatus(422);
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'status' => 'Ordered',
        ])->assertStatus(422);

        $freshPo = Purchase::findOrFail($poId);
        $this->assertEquals($this->supplier->id, $freshPo->supplier_id);
        $this->assertEquals($this->warehouse->id, $freshPo->warehouse_id);
        $this->assertEquals('Received', $freshPo->status);
        $this->assertEqualsWithDelta(0.0, (float)$this->otherSupplier->fresh()->balance_due, 0.01);
    }

    public function test_p1_2_pending_ordered_purchases_and_pending_returns_sanitize_payments_and_exclude_from_summary_kpis(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 50000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'Draft Guard Till',
            'user_name' => $this->admin->name,
        ])->assertStatus(200);
        $register = CashRegister::where('status', 'open')->firstOrFail();

        // Ordered PO with attempted payment payload must store 0 paid & null register
        $ordRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-ORD-SANITIZE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 09:00:00',
            'status' => 'Ordered',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'payments' => [['amount' => 3000.0, 'payment_type' => 'Cash']],
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 3.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(201);

        $ordPo = Purchase::findOrFail((int)$ordRes->json('data.id'));
        $this->assertEqualsWithDelta(0.0, (float)$ordPo->paid_amount, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float)$ordPo->due_amount, 0.01);
        $this->assertEquals('Unpaid', $ordPo->payment_status);
        $this->assertEquals('N/A', $ordPo->payment_type);
        $this->assertNull($ordPo->cash_register_id);
        $this->assertEmpty($ordPo->payments);

        // Received PO later reverted to Ordered must wipe prior paid_amount, register link & AP balance
        $recRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-REC-TO-ORD',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 09:30:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 400.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $recId = (int)$recRes->json('data.id');
        $this->assertEqualsWithDelta(600.0, (float)$this->otherSupplier->fresh()->balance_due, 0.01);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$recId}", [
            'status' => 'Ordered',
        ])->assertStatus(200);
        $revertedPo = Purchase::findOrFail($recId);
        $this->assertEqualsWithDelta(0.0, (float)$revertedPo->paid_amount, 0.01);
        $this->assertEquals('Unpaid', $revertedPo->payment_status);
        $this->assertEquals('N/A', $revertedPo->payment_type);
        $this->assertNull($revertedPo->cash_register_id);
        $this->assertEmpty($revertedPo->payments);
        $this->assertEqualsWithDelta(0.0, (float)$this->otherSupplier->fresh()->balance_due, 0.01);

        // Pending Purchase Return with explicit paid_amount/cash tenders must sanitize to 0 / Unpaid
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-PEND-SANITIZE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Pending',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'payments' => [['amount' => 2000.0, 'payment_type' => 'Cash']],
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);
        $pendPr = PurchaseReturn::findOrFail((int)$prRes->json('data.id'));
        $this->assertEqualsWithDelta(0.0, (float)$pendPr->paid_amount, 0.01);
        $this->assertEqualsWithDelta(2000.0, (float)$pendPr->due_amount, 0.01);
        $this->assertEquals('Unpaid', $pendPr->payment_status);
        $this->assertEquals('N/A', $pendPr->payment_type);
        $this->assertNull($pendPr->cash_register_id);

        // Index KPI summaries exclude draft Ordered/Pending monetary amounts while preserving total_records
        $purIndex = $this->withToken($this->admin->api_token)->getJson('/api/purchases')->assertStatus(200);
        $this->assertEquals(2, (int)$purIndex->json('summary.total_records'));
        $this->assertEqualsWithDelta(0.0, (float)$purIndex->json('summary.total_grand_total'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$purIndex->json('summary.total_paid'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$purIndex->json('summary.total_due'), 0.01);

        $prIndex = $this->withToken($this->admin->api_token)->getJson('/api/purchase-returns')->assertStatus(200);
        $this->assertEquals(1, (int)$prIndex->json('summary.total_records'));
        $this->assertEqualsWithDelta(0.0, (float)$prIndex->json('summary.total_grand_total'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$prIndex->json('summary.total_paid_amount'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$prIndex->json('summary.total_due_amount'), 0.01);
    }

    public function test_p1_3_and_p1_4_register_report_and_dashboard_summary_reconcile_purchases_and_unallocated_pr_credits(): void
    {
        $now = Carbon::now();
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 50000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'Reconciliation Till',
            'user_name' => $this->admin->name,
        ])->assertStatus(200);
        $register = CashRegister::where('status', 'open')->firstOrFail();

        // Received Cash PO (-10,000 from till)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-REG-SYNC-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 10000.0,
            'paid_amount' => 10000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 10.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);

        // Cash refund Purchase Return (+2,000 into till)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-REG-SYNC-01',
            'purchase_reference_no' => 'PO-REG-SYNC-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->copy()->addMinutes(5)->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);

        $detailsRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/cash-register/details/{$register->id}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(42000.0, (float)$detailsRes->json('register.expected_cash'), 0.01);

        $regReport = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?force_refresh=1')
            ->assertStatus(200);
        $regRow = collect($regReport->json('data'))->firstWhere('id', $register->id);
        $this->assertNotNull($regRow);
        $this->assertEqualsWithDelta(10000.0, (float)$regRow['cash_purchases_paid'], 0.01);
        $this->assertEqualsWithDelta(2000.0, (float)$regRow['cash_purchase_refunds'], 0.01);
        $this->assertEqualsWithDelta(42000.0, (float)$regRow['expected_cash'], 0.01);

        // P1-4: Open Unpaid PO (8,000) + Unallocated Credit Note PR (3,000) => Dashboard supplier_due == 5,000 == AP Aging
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-DASH-AP-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'grand_total' => 8000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 8.0,
                'subtotal' => 8000.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-DASH-UNALLOC-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $now->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 3.0,
                'subtotal' => 3000.0,
            ]],
        ])->assertStatus(201);

        $dashRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/dashboard-summary?force_refresh=1')
            ->assertStatus(200);
        $apRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?force_refresh=1')
            ->assertStatus(200);

        $this->assertEqualsWithDelta(5000.0, (float)$dashRes->json('data.supplier_due'), 0.01);
        $this->assertEqualsWithDelta(
            (float)$apRes->json('summary.total_payable'),
            (float)$dashRes->json('data.supplier_due'),
            0.01
        );
    }

    public function test_p2_fixes_pl_line_tax_ap_aging_scope_search_return_payment_type_and_freebie_landed_cost(): void
    {
        // P2-4: 0-cost freebie PO with positive shipping (500 / 10 qty = 50.0) syncs master product cost
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-FREEBIE-SHIP',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 09:00:00',
            'status' => 'Received',
            'shipping' => 500.0,
            'subtotal' => 0.0,
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 0.0,
                'quantity' => 10.0,
                'subtotal' => 0.0,
            ]],
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(50.0, (float)$this->product->fresh()->cost_price, 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$this->product->fresh()->purchase_price, 0.01);
        $freebieBatch = InventoryBatch::where('source_reference', 'PO-FREEBIE-SHIP')->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float)$freebieBatch->unit_cost, 0.01);

        // P2-1: Recoverable line-item tax on Purchase Return is subtracted in Profit & Loss
        // Seed stock for secondProduct at cost 1000
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-1-LINE-TAX',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'is_tax_recoverable' => true,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'grand_total' => 5250.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 5.0,
                'tax' => 250.0,
                'subtotal' => 5250.0,
            ]],
        ])->assertStatus(201);

        $prLineTaxRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-1-LINE-TAX',
            'purchase_reference_no' => 'PO-P2-1-LINE-TAX',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 11:00:00',
            'status' => 'Completed',
            'is_tax_recoverable' => true,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'grand_total' => 2100.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 2.0,
                'tax' => 100.0,
                'subtotal' => 2100.0,
            ]],
        ])->assertStatus(201);
        $this->assertEqualsWithDelta(2100.0, (float)$prLineTaxRes->json('data.grand_total'), 0.01);

        $plRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/profit-loss?date_filter=custom&start_date=2026-10-10&end_date=2026-10-10&force_refresh=1')
            ->assertStatus(200);
        // Gross purchases net of line tax = 5000.0, Purchase returns net of line tax = 2000.0 (2100 - 100)
        $this->assertEqualsWithDelta(5000.0, (float)$plRes->json('data.summary.total_gross_purchases'), 0.01);
        $this->assertEqualsWithDelta(2000.0, (float)$plRes->json('data.summary.total_purchase_return'), 0.01);
        $this->assertEqualsWithDelta(3000.0, (float)$plRes->json('data.summary.total_net_purchases'), 0.01);

        // P2-2: AP Aging search summary recalculation + scoped $allocByPr
        // Add second supplier PO on 2026-10-10 (4,000 unpaid)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-BETA-SEARCH-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 12:00:00',
            'status' => 'Received',
            'grand_total' => 4000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 4.0,
                'subtotal' => 4000.0,
            ]],
        ])->assertStatus(201);

        // Create a backdated Purchase Return on 2026-10-10 allocated to a future PO on 2026-10-20 for Alpha Wholesale
        $futurePoRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-ALPHA-FUTURE-20',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20 09:00:00',
            'status' => 'Received',
            'grand_total' => 2000.0,
            'paid_amount' => 0.0,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 4.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-BACKDATED-FUT',
            'purchase_reference_no' => 'PO-ALPHA-FUTURE-20',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 15:00:00',
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 2.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        // Search Beta Distributors -> AP summary must match ONLY Beta (4,000.0), not Alpha + Beta combined
        $apSearchBeta = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-15&search=Beta&force_refresh=1')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$apSearchBeta->json('summary.total_suppliers'));
        $this->assertEqualsWithDelta(4000.0, (float)$apSearchBeta->json('summary.total_payable'), 0.01);

        // Search Alpha as of 2026-10-15:
        // Open PO on Oct 10 = 5,250.0, PR-P2-1-LINE-TAX allocated to Oct 10 PO = -2,100.0 -> 3,150.0 due
        // Backdated PR-BACKDATED-FUT (1,000.0) on Oct 10 allocates to Oct 20 future PO (excluded on Oct 15),
        // so its 1,000.0 open credit as of Oct 15 MUST reduce Alpha AP from 3,150.0 to 2,150.0
        $apSearchAlpha = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-15&search=Alpha&force_refresh=1')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(2150.0, (float)$apSearchAlpha->json('summary.total_payable'), 0.01);

        // P2-3: Deleting all cash payments from a Split Payment return clears stale 'Split Payment' payment_type
        // (a) Linked to PO with credit allocation -> becomes 'Credit Note'
        $splitLinkedRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-3-LINKED',
            'purchase_reference_no' => 'PO-BETA-SEARCH-01',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->otherSupplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 09:00:00',
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'payments' => [
                ['amount' => 200.0, 'payment_type' => 'Cash'],
                ['amount' => 300.0, 'payment_type' => 'Bank Transfer'],
            ],
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $linkedId = (int)$splitLinkedRes->json('data.id');
        $payments1 = $splitLinkedRes->json('data.payments');
        $this->assertEquals('Split Payment', $splitLinkedRes->json('data.payment_type'));
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$linkedId}/payments/{$payments1[0]['id']}")
            ->assertStatus(200);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$linkedId}/payments/{$payments1[1]['id']}")
            ->assertStatus(200);
        $this->assertEquals('Credit Note', PurchaseReturn::findOrFail($linkedId)->payment_type);

        // (b) Standalone Cash Return with NO credit allocation -> becomes 'N/A'
        $standalonePrRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-3-STANDALONE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Completed',
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 500.0,
                'quantity' => 1.0,
                'subtotal' => 500.0,
            ]],
        ])->assertStatus(201);
        $standaloneId = (int)$standalonePrRes->json('data.id');
        $standalonePayId = $standalonePrRes->json('data.payments.0.id');
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$standaloneId}/payments/{$standalonePayId}")
            ->assertStatus(200);
        $this->assertEquals('N/A', PurchaseReturn::findOrFail($standaloneId)->payment_type);
    }

    public function test_p1_1_opening_stock_product_create_records_fifo_batch_and_is_excluded_from_procurement_and_supplier_reports(): void
    {
        $prodRes = $this->withToken($this->admin->api_token)->postJson('/api/products', [
            'name' => 'Opening Stock Audit SKU',
            'sku' => 'OPEN-AUDIT-01',
            'code' => 'OPEN-AUDIT-01',
            'category_name' => 'Electronics',
            'unit_name' => 'Pcs',
            'cost_price' => 1000.0,
            'selling_price' => 1500.0,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'has_variants' => false,
            'opening_stocks' => [[
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10.0,
            ]],
        ])->assertStatus(201);

        $productId = (int)$prodRes->json('data.id');
        $batch = InventoryBatch::where('product_id', $productId)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($batch);
        $this->assertEquals('opening_stock', $batch->source_type);
        $this->assertEqualsWithDelta(10.0, (float)$batch->remaining_quantity, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float)$batch->unit_cost, 0.01);

        $recon = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/inventory-reconciliation?warehouse_id=' . $this->warehouse->id)
            ->assertStatus(200)
            ->json('data');
        $unbatchedForProduct = collect($recon['discrepancies'] ?? [])
            ->where('product_id', $productId)
            ->all();
        $this->assertEmpty($unbatchedForProduct);

        $today = Carbon::today()->toDateString();
        $purRep = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/purchases?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200)
            ->json('data');
        $this->assertEquals(0, (int)$purRep['total_purchases_count']);
        $this->assertEqualsWithDelta(0.0, (float)$purRep['total_cost'], 0.01);

        $dash = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dashboard-summary?date_filter=today&force_refresh=1&force_snapshot=1")
            ->assertStatus(200)
            ->json('data');
        $this->assertEqualsWithDelta(0.0, (float)$dash['total_purchases'], 0.01);

        $pl = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/profit-loss?date_filter=today&force_refresh=1")
            ->assertStatus(200)
            ->json('data');
        $this->assertEqualsWithDelta(0.0, (float)$pl['gross_purchases'], 0.01);

        $suppStmt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers/{$this->supplier->id}/statement")
            ->assertStatus(200)
            ->json('data.summary');
        $this->assertEqualsWithDelta(0.0, (float)$suppStmt['total_purchased'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$suppStmt['total_paid'], 0.01);

        // Stock ledger still shows physical Opening Stock inflow at batch cost
        $ledger = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?start_date={$today}&end_date={$today}&movement_type=purchases&force_refresh=1")
            ->assertStatus(200)
            ->json('movements');
        $openRow = collect($ledger)->firstWhere('product_id', $productId);
        $this->assertNotNull($openRow);
        $this->assertEqualsWithDelta(10.0, (float)$openRow['quantity_in'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float)$openRow['unit_cost'], 0.01);
    }

    public function test_p1_2_dashboard_summary_matches_profit_loss_for_pr_line_tax_and_cost_variance(): void
    {
        $today = Carbon::today()->toDateString();

        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-2-TAX-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $today . ' 09:00:00',
            'status' => 'Received',
            'is_tax_recoverable' => true,
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        // Return 2 units (FIFO cost = 200.0) for supplier credit 250.0 + line tax 20.0 = grand_total 270.0
        // IAS 2 Recoverable Tax = 20.0 -> Net Return = 250.0 -> Cost Variance = +50.0
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-2-TAX-01',
            'purchase_reference_no' => 'PO-P1-2-TAX-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'is_tax_recoverable' => true,
            'subtotal' => 270.0,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'grand_total' => 270.0,
            'paid_amount' => 270.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 125.0,
                'quantity' => 2.0,
                'tax' => 20.0,
                'subtotal' => 270.0,
            ]],
        ])->assertStatus(201);

        $pl = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/profit-loss?date_filter=today&force_refresh=1")
            ->assertStatus(200)
            ->json('data');

        $dash = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dashboard-summary?date_filter=today&force_refresh=1&force_snapshot=1")
            ->assertStatus(200)
            ->json('data');

        $this->assertEqualsWithDelta((float)$pl['total_purchase_return'], (float)$dash['total_purchase_returns'], 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$dash['total_purchase_returns'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$dash['purchase_return_variance'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['net_profit'], (float)$dash['net_profit'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$dash['today_profit'], 0.01);
    }

    public function test_p1_3_z_report_reconciles_cash_purchases_paid_cash_purchase_refunds_floats_and_drops(): void
    {
        $today = Carbon::today()->toDateString();

        $register = CashRegister::create([
            'reference_no' => 'REG-Z1-01',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'register_name' => 'Register Z1',
            'opening_cash' => 500.0,
            'status' => 'open',
            'opened_at' => $today . ' 08:00:00',
        ]);

        \Illuminate\Support\Facades\DB::table('cash_register_transactions')->insert([
            [
                'cash_register_id' => $register->id,
                'type' => 'float_in',
                'amount' => 100.0,
                'reason' => 'Change float',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cash_register_id' => $register->id,
                'type' => 'drop',
                'amount' => 50.0,
                'reason' => 'Midday safe drop',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        \App\Models\Sale::create([
            'reference_no' => 'SL-Z1-01',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $today . ' 09:30:00',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'grand_total' => 300.0,
            'paid_amount' => 300.0,
            'due_amount' => 0.0,
            'cash_register_id' => $register->id,
        ]);

        // Cash purchase paid from register drawer (-120.0)
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-Z1-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Received',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 120.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 2.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(201);

        // Cash refund received on purchase return into drawer (+40.0)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-Z1-01',
            'purchase_reference_no' => 'PO-Z1-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $today . ' 11:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'paid_amount' => 40.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 1.0,
                'subtotal' => 100.0,
            ]],
        ])->assertStatus(201);

        $zRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/z-report?warehouse={$this->warehouse->id}&date={$today}&force_refresh=1")
            ->assertStatus(200)
            ->json('data.0');

        // Expected Cash = Opening(500) + CashSales(300) + FloatIn(100) + PRRefund(40) - Drop(50) - POPaid(120) = 770.0
        $this->assertEqualsWithDelta(770.0, (float)$zRes['expected_cash'], 0.01);
        $this->assertEqualsWithDelta(770.0, (float)$zRes['closing_cash'], 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$zRes['cash_purchases_paid'], 0.01);
        $this->assertEqualsWithDelta(40.0, (float)$zRes['cash_purchase_refunds'], 0.01);
    }

    public function test_p2_1_stock_product_details_and_stock_ledger_use_base_qty_fifo_landed_and_historical_return_cost(): void
    {
        $boxUnit = \App\Models\ProductUnit::create([
            'name' => 'Box-P2-1',
            'short_name' => 'Box',
            'status' => 'Active',
        ]);
        \App\Models\ProductUnitLink::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'unit_name' => 'Box-P2-1',
            'unit_type' => 'purchase',
            'conversion_rate' => 12.0,
            'cost_price' => 1200.0,
            'is_default' => true,
        ]);

        // Purchase 2 Boxes (= 24 base units) @ 1200 + 120 shipping = 2520 landed total -> 105.00/base pc
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-1-UOM',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 09:00:00',
            'status' => 'Received',
            'subtotal' => 2400.0,
            'shipping' => 120.0,
            'grand_total' => 2520.0,
            'paid_amount' => 2520.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box-P2-1',
                'conversion_rate' => 12.0,
                'unit_cost' => 1200.0,
                'quantity' => 2.0,
                'subtotal' => 2400.0,
            ]],
        ])->assertStatus(201);

        // Return 1 Box (= 12 base units)
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-1-UOM',
            'purchase_reference_no' => 'PO-P2-1-UOM',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 11:00:00',
            'status' => 'Completed',
            'subtotal' => 1200.0,
            'grand_total' => 1200.0,
            'paid_amount' => 1200.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box-P2-1',
                'conversion_rate' => 12.0,
                'unit_cost' => 1200.0,
                'quantity' => 1.0,
                'subtotal' => 1200.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        // Mutate product master cost_price to 999 to verify historical FIFO costs are used instead of master cost
        $this->product->update(['cost_price' => 999.0]);

        $details = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock/{$this->product->id}/details")
            ->assertStatus(200)
            ->json('data.movements');
        $poDetail = collect($details['purchases'] ?? [])->firstWhere('reference', 'PO-P2-1-UOM');
        $prDetail = collect($details['purchase_returns'] ?? [])->firstWhere('reference', 'PR-P2-1-UOM');
        $this->assertEqualsWithDelta(24.0, (float)$poDetail['quantity'], 0.001);
        $this->assertEqualsWithDelta(12.0, (float)$prDetail['quantity'], 0.001);

        $ledger = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-14&end_date=2026-10-14&force_refresh=1')
            ->assertStatus(200)
            ->json('movements');
        $poLedger = collect($ledger)->firstWhere('reference_no', 'PO-P2-1-UOM');
        $prLedger = collect($ledger)->firstWhere('reference_no', 'PR-P2-1-UOM');
        $this->assertEqualsWithDelta(105.0, (float)$poLedger['unit_cost'], 0.01);
        $this->assertEqualsWithDelta(105.0, (float)$prLedger['unit_cost'], 0.01);

        // Revert PR to Pending -> purchase_return_void retains historical FIFO unit_cost = 105.0 (not 999.0)
        $this->withToken($this->admin->api_token)
            ->putJson("/api/purchase-returns/{$prId}", ['status' => 'Pending'])
            ->assertStatus(200);
        $ledgerAfterVoid = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-14&end_date=2026-10-14&force_refresh=1')
            ->assertStatus(200)
            ->json('movements');
        $voidRow = collect($ledgerAfterVoid)->firstWhere('movement_type', 'purchase_return_void');
        $this->assertNotNull($voidRow);
        $this->assertEqualsWithDelta(105.0, (float)$voidRow['unit_cost'], 0.01);
    }

    public function test_p2_4_supplier_controller_guards_raw_balance_edit_and_negative_credit_delete(): void
    {
        // Supplier with advance credit (negative balance_due = -250.0) and 0 PO/PR cannot be deleted
        $creditSupplier = Supplier::create([
            'name' => 'Advance Credit Supplier',
            'phone' => '09988776655',
            'balance_due' => -250.0,
            'status' => 'Active',
        ]);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/suppliers/{$creditSupplier->id}")
            ->assertStatus(422);

        // Supplier with active PO cannot have balance_due overwritten via update()
        Purchase::create([
            'reference_no' => 'PO-P2-4-GUARD',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 10:00:00',
            'status' => 'Received',
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'due_amount' => 500.0,
            'payment_status' => 'Unpaid',
        ]);
        $this->supplier->update(['balance_due' => 500.0]);

        $this->withToken($this->admin->api_token)
            ->putJson("/api/suppliers/{$this->supplier->id}", [
                'name' => $this->supplier->name,
                'phone' => '09123456789',
                'balance_due' => 0.0,
            ])
            ->assertStatus(422);

        // Echoing unchanged balance_due still allows normal profile update
        $this->withToken($this->admin->api_token)
            ->putJson("/api/suppliers/{$this->supplier->id}", [
                'name' => 'Updated Name Same Balance',
                'phone' => '09123456789',
                'balance_due' => 500.0,
            ])
            ->assertStatus(200);
        $this->assertEqualsWithDelta(500.0, (float)$this->supplier->fresh()->balance_due, 0.01);
    }

    public function test_p1_1_audit_opening_stock_excluded_from_purchase_list_and_supplier_count_and_destroy_nullifies_fk(): void
    {
        $tempSup = Supplier::create([
            'name' => 'Opening Stock Temp Supplier',
            'phone' => '0955566677',
            'balance_due' => 0.0,
            'status' => 'Active',
        ]);

        $openingPo = Purchase::create([
            'reference_no' => 'PO-OPEN-P1-1',
            'supplier_id' => $tempSup->id,
            'supplier_name' => $tempSup->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-01 08:00:00',
            'status' => 'Received',
            'subtotal' => 2500.0,
            'grand_total' => 2500.0,
            'paid_amount' => 2500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
        ]);

        $indexRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/purchases')
            ->assertStatus(200);
        $rows = $indexRes->json('data.data') ?? $indexRes->json('data') ?? [];
        $this->assertFalse(collect($rows)->contains('reference_no', 'PO-OPEN-P1-1'));
        $this->assertSame(0, (int)($indexRes->json('data.unfiltered_total_purchases_count') ?? $indexRes->json('unfiltered_total_purchases_count') ?? 0));

        $supIndex = $this->withToken($this->admin->api_token)
            ->getJson('/api/suppliers')
            ->assertStatus(200);
        $supRows = $supIndex->json('data.data') ?? $supIndex->json('data') ?? [];
        $foundSup = collect($supRows)->firstWhere('id', $tempSup->id);
        $this->assertNotNull($foundSup);
        $this->assertSame(0, (int)($foundSup['purchases_count'] ?? -1));

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/suppliers/{$tempSup->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('suppliers', ['id' => $tempSup->id]);
        $freshOpen = Purchase::find($openingPo->id);
        $this->assertNotNull($freshOpen);
        $this->assertNull($freshOpen->supplier_id);
        $this->assertSame('Opening Stock', $freshOpen->payment_type);
    }

    public function test_p1_2_audit_cost_masking_and_cache_isolation_across_purchase_return_and_stock_reports_and_export(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-MSK-RPT-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 10:00:00',
            'status' => 'Received',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 5.0,
                'unit_cost' => 100.0,
                'subtotal' => 500.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-MSK-RPT-1',
            'purchase_reference_no' => 'PO-MSK-RPT-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 12:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'paid_amount' => 100.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 1.0,
                'unit_cost' => 100.0,
                'subtotal' => 100.0,
            ]],
        ])->assertStatus(201);

        $noCostRole = Role::create([
            'name' => 'Report No Cost Viewer',
            'slug' => 'report_no_cost_viewer',
            'permissions' => [
                'reports.view',
                'reports.export',
                'report_purchases.view',
                'report_purchase_return.view',
                'report_stock.view',
                'report_stock.export',
            ],
            'status' => 'active',
        ]);
        $noCostUser = User::create([
            'user_id' => 'USR-RPT-NOCOST',
            'name' => 'No Cost Reporter',
            'email' => 'nocost-rpt@retailpos.test',
            'role' => 'Report No Cost Viewer',
            'role_id' => $noCostRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'no_cost_reporter_token_001',
        ]);

        $withCostRole = Role::create([
            'name' => 'Report With Cost Viewer',
            'slug' => 'report_with_cost_viewer',
            'permissions' => [
                'reports.view',
                'reports.export',
                'products.view_cost',
                'report_purchases.view',
                'report_purchase_return.view',
                'report_stock.view',
                'report_stock.export',
            ],
            'status' => 'active',
        ]);
        $withCostUser = User::create([
            'user_id' => 'USR-RPT-WITHCOST',
            'name' => 'With Cost Reporter',
            'email' => 'withcost-rpt@retailpos.test',
            'role' => 'Report With Cost Viewer',
            'role_id' => $withCostRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'with_cost_reporter_token_001',
        ]);

        // 1. Warm report caches as non-cost viewer (NO force_refresh)
        $noCostPo = $this->withToken($noCostUser->api_token)
            ->getJson('/api/reports/purchases')
            ->assertStatus(200);
        $this->assertTrue((bool)$noCostPo->json('data.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$noCostPo->json('data.total_cost'), 0.01);

        $noCostPr = $this->withToken($noCostUser->api_token)
            ->getJson('/api/reports/purchase-returns')
            ->assertStatus(200);
        $this->assertTrue((bool)$noCostPr->json('cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$noCostPr->json('summary.total_returned_amount'), 0.01);

        $noCostStock = $this->withToken($noCostUser->api_token)
            ->getJson('/api/reports/stock')
            ->assertStatus(200);
        $this->assertTrue((bool)$noCostStock->json('data.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$noCostStock->json('data.summary.total_cost_value'), 0.01);
        $noCostRiceRow = collect($noCostStock->json('data.items'))->firstWhere('id', $this->product->id);
        $this->assertNotNull($noCostRiceRow);
        $this->assertNull($noCostRiceRow['cost_price']);
        $this->assertTrue((bool)$noCostRiceRow['cost_masked']);

        $noCostCsv = $this->withToken($noCostUser->api_token)
            ->get('/api/reports/stock/export')
            ->assertStatus(200)
            ->streamedContent();
        $this->assertStringContainsString('Protected', $noCostCsv);

        // 2. Verify cost viewer gets UNMASKED real values even WITHOUT force_refresh (no cache poison)
        $withCostPo = $this->withToken($withCostUser->api_token)
            ->getJson('/api/reports/purchases')
            ->assertStatus(200);
        $this->assertFalse((bool)$withCostPo->json('data.cost_masked'));
        $this->assertEqualsWithDelta(500.0, (float)$withCostPo->json('data.total_cost'), 0.01);

        $withCostPr = $this->withToken($withCostUser->api_token)
            ->getJson('/api/reports/purchase-returns')
            ->assertStatus(200);
        $this->assertFalse((bool)$withCostPr->json('cost_masked'));
        $this->assertEqualsWithDelta(100.0, (float)$withCostPr->json('summary.total_returned_amount'), 0.01);

        $withCostStock = $this->withToken($withCostUser->api_token)
            ->getJson('/api/reports/stock')
            ->assertStatus(200);
        $this->assertFalse((bool)$withCostStock->json('data.cost_masked'));
        $this->assertEqualsWithDelta(400.0, (float)$withCostStock->json('data.summary.total_cost_value'), 0.01);
        $withCostRiceRow = collect($withCostStock->json('data.items'))->firstWhere('id', $this->product->id);
        $this->assertNotNull($withCostRiceRow);
        $this->assertEqualsWithDelta(100.0, (float)$withCostRiceRow['cost_price'], 0.01);
        $this->assertFalse((bool)$withCostRiceRow['cost_masked']);

        $withCostCsv = $this->withToken($withCostUser->api_token)
            ->get('/api/reports/stock/export')
            ->assertStatus(200)
            ->streamedContent();
        $this->assertStringNotContainsString('Protected', $withCostCsv);
    }

    public function test_p1_3_audit_csv_exports_exclude_drafts_from_financial_and_unit_totals(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-EXP-POSTED-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 09:00:00',
            'status' => 'Received',
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'paid_amount' => 2000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 5.0,
                'unit_cost' => 1000.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-EXP-DRAFT-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 10:00:00',
            'status' => 'Pending',
            'subtotal' => 9000.0,
            'grand_total' => 9000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'N/A',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 10.0,
                'unit_cost' => 900.0,
                'subtotal' => 9000.0,
            ]],
        ])->assertStatus(201);

        Purchase::create([
            'reference_no' => 'PO-EXP-OPEN-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-01 08:00:00',
            'status' => 'Received',
            'subtotal' => 20000.0,
            'grand_total' => 20000.0,
            'paid_amount' => 20000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
        ]);

        $csv = $this->withToken($this->admin->api_token)
            ->get('/api/purchases/export')
            ->assertStatus(200)
            ->streamedContent();

        $this->assertStringNotContainsString('PO-EXP-OPEN-1', $csv);
        $this->assertStringContainsString('PO-EXP-POSTED-1', $csv);
        $this->assertStringContainsString('PO-EXP-DRAFT-1', $csv);
        $this->assertStringContainsString('TOTAL,"2 Purchases",,,,,,5,0,0,0,5000,5000,2000,3000', $csv);
        $this->assertStringNotContainsString(',15,', $csv);
    }

    public function test_p1_4_audit_closed_register_blocks_cash_mutation_on_purchase_and_return_while_allowing_notes_and_open_shift(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 50000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'Shift 1 Register',
            'user_name' => $this->admin->name,
        ])->assertStatus(200);
        $r1 = CashRegister::where('status', 'open')->latest('id')->firstOrFail();

        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-SHIFT1-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $r1->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 10.0,
                'unit_cost' => 200.0,
                'subtotal' => 2000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');
        $poPayId = $poRes->json('data.payments.0.id');

        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-SHIFT1-01',
            'purchase_reference_no' => 'PO-SHIFT1-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 400.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $r1->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 2.0,
                'unit_cost' => 200.0,
                'subtotal' => 400.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');
        $prPayId = $prRes->json('data.payments.0.id');

        // Close Shift 1
        $this->withToken($this->admin->api_token)
            ->postJson("/api/cash-register/close/{$r1->id}", ['closing_cash' => 49400.0])
            ->assertStatus(200);
        $this->assertSame('closed', $r1->fresh()->status);

        // Mutations affecting Shift 1 cash must return 422
        $this->withToken($this->admin->api_token)
            ->putJson("/api/purchases/{$poId}", ['paid_amount' => 1500.0, 'payment_type' => 'Cash'])
            ->assertStatus(422);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchases/{$poId}/payments/{$poPayId}")
            ->assertStatus(422);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchases/{$poId}")
            ->assertStatus(422);

        $this->withToken($this->admin->api_token)
            ->putJson("/api/purchase-returns/{$prId}", ['paid_amount' => 200.0, 'payment_type' => 'Cash'])
            ->assertStatus(422);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$prId}/payments/{$prPayId}")
            ->assertStatus(422);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$prId}")
            ->assertStatus(422);

        // Editing notes without mutating Shift 1 cash is still permitted
        $this->withToken($this->admin->api_token)
            ->putJson("/api/purchases/{$poId}", ['notes' => 'Shift closed metadata edit OK'])
            ->assertStatus(200);

        // Open Shift 2 and append a new Cash leg keeping Shift 1 leg untouched -> binds new leg to Shift 2
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 10000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'Shift 2 Register',
            'user_name' => $this->admin->name,
        ])->assertStatus(200);
        $r2 = CashRegister::where('status', 'open')->latest('id')->firstOrFail();

        $existingPo = Purchase::findOrFail($poId);
        $existingPayments = is_array($existingPo->payments) ? $existingPo->payments : [];
        $updRes = $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'payment_type' => 'Split Payment',
            'paid_amount' => 1500.0,
            'payments' => array_merge($existingPayments, [[
                'payment_type' => 'Cash',
                'amount' => 500.0,
                'payment_date' => Carbon::now()->format('Y-m-d H:i:s'),
            ]]),
        ])->assertStatus(200);
        $newLeg = collect($updRes->json('data.payments'))->first(fn($leg) => abs((float)($leg['amount'] ?? 0) - 500.0) < 0.01);
        $this->assertNotNull($newLeg);
        $this->assertSame($r2->id, (int)($newLeg['cash_register_id'] ?? 0));
    }

    public function test_p1_5_audit_commercial_tax_preserves_signed_negative_net_input_tax(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-CTAX-EARLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'tax_rate' => 10.0,
            'tax_amount' => 500.0,
            'subtotal' => 5000.0,
            'grand_total' => 5500.0,
            'paid_amount' => 0.0,
            'payment_type' => 'N/A',
            'is_tax_recoverable' => true,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 10.0,
                'unit_cost' => 500.0,
                'tax_rate' => 10.0,
                'tax_amount' => 500.0,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(201);

        Sale::create([
            'reference_no' => 'SL-CTAX-19',
            'customer_name' => 'Walk-in Tax',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19 10:00:00',
            'status' => 'Completed',
            'tax_rate' => 10.0,
            'tax_amount' => 100.0,
            'subtotal' => 1000.0,
            'grand_total' => 1100.0,
            'paid_amount' => 1100.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);

        // Purchase return on 2026-10-19 claws back 150 input tax when 0 POs occurred in [2026-10-19, 2026-10-19]
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-CTAX-19',
            'purchase_reference_no' => 'PO-CTAX-EARLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-19 15:00:00',
            'status' => 'Completed',
            'tax_rate' => 10.0,
            'tax_amount' => 150.0,
            'subtotal' => 1500.0,
            'grand_total' => 1650.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'is_tax_recoverable' => true,
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 3.0,
                'unit_cost' => 500.0,
                'tax_rate' => 10.0,
                'tax_amount' => 150.0,
                'subtotal' => 1500.0,
            ]],
        ])->assertStatus(201);

        $ctRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/commercial-tax?start_date=2026-10-19&end_date=2026-10-19&force_refresh=1')
            ->assertStatus(200);

        $this->assertEqualsWithDelta(-150.0, (float)$ctRes->json('input_tax.net_input_tax'), 0.01);
        $this->assertEqualsWithDelta(-150.0, (float)$ctRes->json('tax_reconciliation.net_input_tax'), 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$ctRes->json('tax_reconciliation.net_tax_payable'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$ctRes->json('tax_reconciliation.tax_credit_forward'), 0.01);
    }

    public function test_p2_6_audit_supplier_report_statement_historical_as_of_date_and_legacy_null_fk(): void
    {
        $poEarlyRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-SUP-EARLY',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'N/A',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 10.0,
                'unit_cost' => 100.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $poEarlyId = (int)$poEarlyRes->json('data.id');

        // Future payment on 2026-10-20 (after As-Of 2026-10-12)
        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$poEarlyId}/payments", [
            'amount' => 1000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-10-20 10:00:00',
        ])->assertStatus(200);

        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-SUP-MID',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'N/A',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 10.0,
                'unit_cost' => 100.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        // PR Credit Note on 2026-10-18 allocating 400 to PO-SUP-MID (after As-Of 2026-10-12)
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-SUP-FUTURE',
            'purchase_reference_no' => 'PO-SUP-MID',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-18 11:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 4.0,
                'unit_cost' => 100.0,
                'subtotal' => 400.0,
            ]],
        ])->assertStatus(201);

        // Legacy NULL supplier_id PO matching name
        Purchase::create([
            'reference_no' => 'PO-SUP-LEGACY',
            'supplier_id' => null,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 08:00:00',
            'status' => 'Received',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 0.0,
            'due_amount' => 200.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'N/A',
        ]);

        // Different supplier_id carrying same string name must NOT bleed into $this->supplier
        Purchase::create([
            'reference_no' => 'PO-OTHER-SUP-ISOLATE',
            'supplier_id' => $this->otherSupplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-07 08:00:00',
            'status' => 'Received',
            'subtotal' => 9999.0,
            'grand_total' => 9999.0,
            'paid_amount' => 0.0,
            'due_amount' => 9999.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'N/A',
        ]);

        $repAsOf = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/suppliers?start_date=2026-10-05&end_date=2026-10-12&force_refresh=1')
            ->assertStatus(200);
        $supRow = collect($repAsOf->json('data.suppliers'))->firstWhere('id', $this->supplier->id);
        $this->assertNotNull($supRow);
        $this->assertEqualsWithDelta(2200.0, (float)$supRow['total_purchased_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$supRow['total_paid_amount'], 0.01);
        $this->assertEqualsWithDelta(2200.0, (float)$supRow['total_due'], 0.01);

        // Filtered empty period on statement must return 0 instead of falling back to supplier.total_purchased
        $this->supplier->update(['total_purchased' => 50000.0]);
        $emptyRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers/{$this->supplier->id}/statement?start_date=2026-10-25&end_date=2026-10-26")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, (float)$emptyRes->json('data.summary.total_purchased'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$emptyRes->json('data.supplier.total_purchased_amount'), 0.01);
    }

    public function test_p2_7_audit_stock_report_cache_invalidated_when_purchase_is_received(): void
    {
        $initial = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock')
            ->assertStatus(200);
        $initRow = collect($initial->json('data.items'))->firstWhere('id', $this->product->id);
        $this->assertEqualsWithDelta(0.0, (float)($initRow['stock_quantity'] ?? 0), 0.01);

        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-STK-CACHE-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
            'status' => 'Received',
            'subtotal' => 1200.0,
            'grand_total' => 1200.0,
            'paid_amount' => 1200.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 12.0,
                'unit_cost' => 100.0,
                'subtotal' => 1200.0,
            ]],
        ])->assertStatus(201);

        // Without force_refresh, bumped cache version serves updated stock immediately
        $after = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock')
            ->assertStatus(200);
        $afterRow = collect($after->json('data.items'))->firstWhere('id', $this->product->id);
        $this->assertEqualsWithDelta(12.0, (float)($afterRow['stock_quantity'] ?? 0), 0.01);
    }

    public function test_p2_8_audit_cash_flow_includes_normal_purchases_whose_notes_mention_opening_stock(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-CF-NOTES-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-21 10:00:00',
            'status' => 'Received',
            'subtotal' => 750.0,
            'grand_total' => 750.0,
            'paid_amount' => 750.0,
            'payment_type' => 'Cash',
            'notes' => 'Restock after Opening Stock audit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 5.0,
                'unit_cost' => 150.0,
                'subtotal' => 750.0,
            ]],
        ])->assertStatus(201);

        Purchase::create([
            'reference_no' => 'PO-CF-ACTUAL-OPEN',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-21 08:00:00',
            'status' => 'Received',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
        ]);

        $cf = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/cash-flow?start_date=2026-10-21&end_date=2026-10-21&force_refresh=1')
            ->assertStatus(200)
            ->json('summary');
        $this->assertEqualsWithDelta(750.0, (float)($cf['purchases_cash_paid'] ?? 0), 0.01);
    }

    public function test_p2_9_audit_by_product_100_percent_duplicate_sku_batch_guard_and_pr_allocated_credit_amount(): void
    {
        // Duplicate SKU lines in a single PO -> 2 InventoryBatch rows for same product
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-DUP-SKU-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-22 09:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'N/A',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 5.0,
                    'unit_cost' => 100.0,
                    'subtotal' => 500.0,
                ],
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 5.0,
                    'unit_cost' => 100.0,
                    'subtotal' => 500.0,
                ],
            ],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Consume 7 units via PR (depletes 5 from line 1 + 2 from line 2) with discount 100 (subtotal 700 -> grand_total 600)
        // Partial Cash refund 200 -> 400 allocated AP Credit Offset onto unpaid PO-DUP-SKU-1
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-DUP-SKU-1',
            'purchase_reference_no' => 'PO-DUP-SKU-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-22 11:00:00',
            'status' => 'Completed',
            'subtotal' => 700.0,
            'discount' => 100.0,
            'grand_total' => 600.0,
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'quantity' => 7.0,
                'unit_cost' => 100.0,
                'subtotal' => 700.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        // Duplicate SKU batch consumption check via FifoInventoryService directly
        $batches = InventoryBatch::where('source_reference', 'PO-DUP-SKU-1')->orderBy('id')->get();
        $this->assertCount(2, $batches);
        // Simulate first batch untouched and only second batch partially consumed to prove loop aggregates per SKU key
        $batches[0]->update(['remaining_quantity' => 5.0]);
        $batches[1]->update(['remaining_quantity' => 3.0]);
        $guardMsg = app(FifoInventoryService::class)->assertPurchaseBatchesCanModifyOrDelete(
            Purchase::findOrFail($poId),
            null,
            true
        );
        $this->assertNotNull($guardMsg);

        // Check allocated_credit_amount in PR index & show, and 100% by_product share even with PR discount
        $prIndex = $this->withToken($this->admin->api_token)->getJson('/api/purchase-returns')->assertStatus(200);
        $prIndexRow = collect($prIndex->json('data.data') ?? $prIndex->json('data') ?? [])->firstWhere('id', $prId);
        $this->assertNotNull($prIndexRow);
        $this->assertEqualsWithDelta(400.0, (float)($prIndexRow['allocated_credit_amount'] ?? 0), 0.01);

        $prShow = $this->withToken($this->admin->api_token)->getJson("/api/purchase-returns/{$prId}")->assertStatus(200);
        $this->assertEqualsWithDelta(400.0, (float)($prShow->json('data.allocated_credit_amount') ?? 0), 0.01);

        $prRpt = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchase-returns?force_refresh=1')
            ->assertStatus(200);
        $byProd = $prRpt->json('by_product') ?? [];
        $this->assertNotEmpty($byProd);
        $this->assertEqualsWithDelta(100.0, (float)$byProd[0]['percentage'], 0.01);
    }

    public function test_p1_1_completed_purchase_return_requires_non_empty_items_on_store_and_finalization(): void
    {
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-1-BASE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Completed store with empty items array must be rejected 422
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-EMPTY-01',
            'purchase_reference_no' => 'PO-P1-1-BASE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 500.0,
            'items' => [],
        ])->assertStatus(422);

        // Completed store with omitted items key must also be rejected 422
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-EMPTY-02',
            'purchase_reference_no' => 'PO-P1-1-BASE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 500.0,
        ])->assertStatus(422);

        $this->assertEquals(0, PurchaseReturn::count());
        $this->assertEqualsWithDelta(1000.0, (float)$this->supplier->fresh()->balance_due, 0.01);
        $this->assertEqualsWithDelta(0.0, (float)Purchase::findOrFail($poId)->returned_credit_amount, 0.01);

        // Empty Pending draft cannot be finalized to Completed without sending items
        $draftRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-DRAFT-EMPTY-01',
            'purchase_reference_no' => 'PO-P1-1-BASE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Pending',
            'grand_total' => 500.0,
        ])->assertStatus(201);
        $draftId = (int)$draftRes->json('data.id');

        $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$draftId}", [
            'status' => 'Completed',
        ])->assertStatus(422);
        $this->assertEquals('Pending', PurchaseReturn::findOrFail($draftId)->status);
        $this->assertEqualsWithDelta(1000.0, (float)$this->supplier->fresh()->balance_due, 0.01);
    }

    public function test_p1_2_over_return_code_fallback_variant_ownership_and_authoritative_unit_rate(): void
    {
        $varProduct = Product::create([
            'name' => 'Variant Tee',
            'code' => 'TEE-PARENT-01',
            'unit_name' => 'Piece',
            'has_variants' => true,
            'stock_quantity' => 0.0,
            'purchase_price' => 50.0,
            'cost_price' => 50.0,
            'retail_price' => 100.0,
        ]);
        $varA = \App\Models\ProductVariant::create([
            'product_id' => $varProduct->id,
            'name' => 'Size M',
            'sku' => 'TEE-M-01',
            'cost_price' => 50.0,
            'selling_price' => 100.0,
            'is_active' => true,
        ]);
        $foreignVar = \App\Models\ProductVariant::create([
            'product_id' => $this->secondProduct->id,
            'name' => 'Foreign Litre',
            'sku' => 'OIL-FOREIGN-01',
            'cost_price' => 200.0,
            'selling_price' => 300.0,
            'is_active' => true,
        ]);
        $boxUnit = \App\Models\ProductUnit::create([
            'name' => 'Box-P1-2',
            'short_name' => 'Box',
            'status' => 'Active',
        ]);
        \App\Models\ProductUnitLink::create([
            'product_id' => $varProduct->id,
            'product_variant_id' => $varA->id,
            'unit_id' => $boxUnit->id,
            'unit_name' => 'Box-P1-2',
            'unit_type' => 'purchase',
            'conversion_rate' => 12.0,
            'is_default' => false,
            'is_active' => true,
        ]);

        // Purchase 2 Boxes (= 24 base pieces) of Variant A
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-2-VAR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 1200.0,
            'grand_total' => 1200.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $varProduct->id,
                'product_variant_id' => $varA->id,
                'variant_name' => 'Size M',
                'product_code' => 'TEE-PARENT-01',
                'product_name' => 'Variant Tee',
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box-P1-2',
                'conversion_rate' => 12.0,
                'unit_cost' => 600.0,
                'quantity' => 2.0,
                'subtotal' => 1200.0,
            ]],
        ])->assertStatus(201);

        // 1. Omitting variant on variable product -> 422
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-P1-2-VAR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 600.0,
            'items' => [[
                'product_id' => $varProduct->id,
                'product_code' => 'TEE-PARENT-01',
                'product_name' => 'Variant Tee',
                'unit_cost' => 600.0,
                'quantity' => 1.0,
                'subtotal' => 600.0,
            ]],
        ])->assertStatus(422);

        // 2. Foreign variant owned by another product -> 422
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-P1-2-VAR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 600.0,
            'items' => [[
                'product_id' => $varProduct->id,
                'product_variant_id' => $foreignVar->id,
                'product_code' => 'TEE-PARENT-01',
                'product_name' => 'Variant Tee',
                'unit_cost' => 600.0,
                'quantity' => 1.0,
                'subtotal' => 600.0,
            ]],
        ])->assertStatus(422);

        // 3. Return 1 Box omitting product_id & passing spoofed conversion_rate=1.0 -> server overrides conversion_rate=12.0 & base_quantity=12.0
        $firstRet = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-2-BOX-1',
            'purchase_reference_no' => 'PO-P1-2-VAR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 600.0,
            'items' => [[
                'product_variant_id' => $varA->id,
                'product_code' => 'TEE-PARENT-01',
                'product_name' => 'Variant Tee',
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box-P1-2',
                'conversion_rate' => 1.0,
                'unit_cost' => 600.0,
                'quantity' => 1.0,
                'subtotal' => 600.0,
            ]],
        ])->assertStatus(201);

        $savedItem = $firstRet->json('data.items.0');
        $this->assertEqualsWithDelta(12.0, (float)$savedItem['conversion_rate'], 0.001);
        $this->assertEqualsWithDelta(12.0, (float)$savedItem['base_quantity'], 0.001);
        $this->assertEquals($varProduct->id, (int)$savedItem['product_id']);

        // 4. Attempting to return 2 more Boxes (cumulative 36 > 24 purchased) omitting product_id MUST block 422!
        $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P1-2-BOX-OVER',
            'purchase_reference_no' => 'PO-P1-2-VAR',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 11:00:00',
            'status' => 'Completed',
            'grand_total' => 1200.0,
            'items' => [[
                'product_variant_id' => $varA->id,
                'product_code' => 'TEE-PARENT-01',
                'product_name' => 'Variant Tee',
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box-P1-2',
                'conversion_rate' => 12.0,
                'unit_cost' => 600.0,
                'quantity' => 2.0,
                'subtotal' => 1200.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_p1_3_consumed_purchase_blocks_header_landed_cost_and_date_edits_while_allowing_notes_and_unconsumed_edits(): void
    {
        // PO-1: 10 units received on 2026-10-10, 2 units consumed -> remaining 8 < 10
        $po1Res = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-3-CONS',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'shipping' => 0.0,
            'discount' => 0.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $po1Id = (int)$po1Res->json('data.id');

        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'subtotal' => 320.0,
            'grand_total' => 320.0,
            'paid_amount' => 320.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_price' => 160.0,
                'quantity' => 2.0,
                'subtotal' => 320.0,
            ]],
        ])->assertStatus(201);

        // Editing shipping, discount, tax_rate, is_tax_recoverable, or date on consumed PO must fail 422
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po1Id}", [
            'shipping' => 200.0,
        ])->assertStatus(422);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po1Id}", [
            'discount' => 50.0,
        ])->assertStatus(422);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po1Id}", [
            'tax_rate' => 10.0,
        ])->assertStatus(422);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po1Id}", [
            'date' => '2026-10-12 09:00:00',
        ])->assertStatus(422);

        // Non-cost notes update (even with same calendar date-only) still succeeds 200
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po1Id}", [
            'date' => '2026-10-10',
            'notes' => 'Invoice audited ok',
        ])->assertStatus(200);
        $this->assertEquals('Invoice audited ok', Purchase::findOrFail($po1Id)->notes);

        // PO-2: Unconsumed PO allows landed-cost & date updates and syncs InventoryBatch + StockMovement date
        $po2Res = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P1-3-UNCONS',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $po2Id = (int)$po2Res->json('data.id');

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po2Id}", [
            'shipping' => 100.0,
            'date' => '2026-10-15 11:30:00',
        ])->assertStatus(200);

        $batch2 = InventoryBatch::where('source_reference', 'PO-P1-3-UNCONS')->firstOrFail();
        $this->assertEqualsWithDelta(110.0, (float)$batch2->unit_cost, 0.01);
        $this->assertStringStartsWith('2026-10-15', (string)$batch2->received_date);

        $mv2 = \App\Models\StockMovement::where('reference_type', 'purchase')
            ->where('reference_id', (string)$po2Id)
            ->where('movement_type', 'purchase')
            ->firstOrFail();
        $this->assertStringStartsWith('2026-10-15', $mv2->created_at->format('Y-m-d'));
    }

    public function test_p2_1_deleted_backdated_purchase_return_keeps_stock_movements_at_document_date(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-1-DATE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);

        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-1-BACKDATE',
            'purchase_reference_no' => 'PO-P2-1-DATE',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 14:00:00',
            'status' => 'Completed',
            'grand_total' => 400.0,
            'paid_amount' => 400.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 4.0,
                'subtotal' => 400.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$prId}")
            ->assertStatus(200);

        $movements = \App\Models\StockMovement::where('reference_type', 'purchase_return')
            ->where('reference_id', (string)$prId)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $movements);
        $this->assertSame('purchase_return', $movements[0]->movement_type);
        $this->assertSame('purchase_return_void', $movements[1]->movement_type);
        $this->assertStringStartsWith('2026-10-16', $movements[0]->created_at->format('Y-m-d'));
        $this->assertStringStartsWith('2026-10-16', $movements[1]->created_at->format('Y-m-d'));

        $ledgerRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-01&end_date=2026-10-16&force_refresh=1')
            ->assertStatus(200);
        $ledgerMovements = collect($ledgerRes->json('movements') ?? []);
        $types = $ledgerMovements->pluck('movement_type')->all();
        $this->assertContains('purchase_return', $types);
        $this->assertContains('purchase_return_void', $types);
        $netStockOnCutoff = $ledgerMovements->sum(fn($m) => (float)($m['quantity_in'] ?? 0) - (float)($m['quantity_out'] ?? 0));
        $this->assertEqualsWithDelta(10.0, $netStockOnCutoff, 0.01);
    }

    public function test_p2_3_cost_blind_user_preserves_existing_costs_and_blocks_unpriced_new_items_on_update(): void
    {
        $blindRole = Role::create([
            'name' => 'Cost Blind Buyer',
            'slug' => 'cost_blind_buyer_p2_3',
            'permissions' => [
                'purchases.view',
                'purchases.update',
                'purchase_returns.view',
                'purchase_returns.update',
            ],
            'status' => 'active',
        ]);
        $blindUser = User::create([
            'user_id' => 'USR-BLIND-P2-3',
            'name' => 'Cost Blind Editor',
            'email' => 'blind-p2-3@retailpos.test',
            'role' => 'Cost Blind Buyer',
            'role_id' => $blindRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'cost_blind_p2_3_token',
        ]);

        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-P2-3-RBAC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 5.0,
                'subtotal' => 500.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // 1. Editing quantity of existing item with masked unit_cost=0 preserves historical 100.0
        $this->withToken($blindUser->api_token)->putJson("/api/purchases/{$poId}", [
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 0.0,
                'quantity' => 6.0,
                'subtotal' => 0.0,
            ]],
        ])->assertStatus(200);
        $this->assertEqualsWithDelta(600.0, (float)Purchase::findOrFail($poId)->grand_total, 0.01);

        // 2. Adding a brand-new unpriced (0-cost) item without view_cost permission must fail 422
        $this->withToken($blindUser->api_token)->putJson("/api/purchases/{$poId}", [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 0.0,
                    'quantity' => 6.0,
                    'subtotal' => 0.0,
                ],
                [
                    'product_id' => $this->secondProduct->id,
                    'product_code' => $this->secondProduct->code,
                    'product_name' => $this->secondProduct->name,
                    'unit_cost' => 0.0,
                    'quantity' => 2.0,
                    'subtotal' => 0.0,
                ],
            ],
        ])->assertStatus(422);

        // 3. Same protection on PurchaseReturn update()
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-P2-3-RBAC',
            'purchase_reference_no' => 'PO-P2-3-RBAC',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 09:00:00',
            'status' => 'Completed',
            'grand_total' => 200.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 2.0,
                'subtotal' => 200.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        $this->withToken($blindUser->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 0.0,
                'quantity' => 3.0,
                'subtotal' => 0.0,
            ]],
        ])->assertStatus(200);
        $this->assertEqualsWithDelta(300.0, (float)PurchaseReturn::findOrFail($prId)->grand_total, 0.01);

        $this->withToken($blindUser->api_token)->putJson("/api/purchase-returns/{$prId}", [
            'items' => [[
                'product_id' => $this->secondProduct->id,
                'product_code' => $this->secondProduct->code,
                'product_name' => $this->secondProduct->name,
                'unit_cost' => 0.0,
                'quantity' => 1.0,
                'subtotal' => 0.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_p2_purchase_destroy_stamps_purchase_deletion_movement_with_document_date(): void
    {
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-DEL-DATE-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-08 09:00:00',
            'status' => 'Received',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 5.0,
                'subtotal' => 500.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchases/{$poId}")
            ->assertStatus(200);

        $delMovement = \App\Models\StockMovement::where('reference_type', 'purchase')
            ->where('reference_id', (string)$poId)
            ->where('movement_type', 'purchase_deletion')
            ->firstOrFail();
        $this->assertStringStartsWith('2026-10-08', $delMovement->created_at->format('Y-m-d'));
        $this->assertEqualsWithDelta(100.0, (float)$delMovement->unit_cost, 0.01);
    }

    public function test_p1_p2_non_destructive_partial_po_edit_preserves_consumed_batches_and_syncs_master_cost(): void
    {
        // 1. Newer PO sets master cost = 150.00
        $poNewRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-MCOST-NEW',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 10:00:00',
            'status' => 'Received',
            'subtotal' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 150.0,
                'quantity' => 2.0,
                'subtotal' => 300.0,
            ]],
        ])->assertStatus(201);
        $poNewId = (int)$poNewRes->json('data.id');
        $this->assertEqualsWithDelta(150.0, (float)$this->product->fresh()->cost_price, 0.0001);

        // 2. Backdated PO-OLD (80.00) must NOT clobber newer PO master cost 150.00
        $poOldRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-MCOST-OLD',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 10:00:00',
            'status' => 'Received',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 80.0,
                'quantity' => 5.0,
                'subtotal' => 400.0,
            ]],
        ])->assertStatus(201);
        $poOldId = (int)$poOldRes->json('data.id');
        $this->assertEqualsWithDelta(150.0, (float)$this->product->fresh()->cost_price, 0.0001);

        $oldBatch = InventoryBatch::where('source_reference', 'PO-MCOST-OLD')->firstOrFail();
        $oldBatchId = $oldBatch->id;

        // 3. Consume 3 units from PO-OLD via sale FIFO depletion (also records SaleItemCostLayer)
        $fifo = app(FifoInventoryService::class);
        $sale = Sale::create([
            'reference_no' => 'SL-PO-PART-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 10:00:00',
            'status' => 'Completed',
            'grand_total' => 450.0,
            'paid_amount' => 450.0,
        ]);
        $saleItem = \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'unit_price' => 150.0,
            'quantity' => 3.0,
            'base_quantity' => 3.0,
            'subtotal' => 450.0,
        ]);
        $depletion = $fifo->depleteFifo($this->warehouse->id, $this->product->id, null, 3.0, $sale->id, $saleItem->id, '2026-10-06 10:00:00', 80.0);
        $this->assertEqualsWithDelta(240.0, (float)$depletion['total_cogs'], 0.01);

        // 4. Shrinking consumed item below 3 OR altering its unit_cost returns 422
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poOldId}", [
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 80.0,
                'quantity' => 2.0,
                'subtotal' => 160.0,
            ]],
        ])->assertStatus(422);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poOldId}", [
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 95.0,
                'quantity' => 5.0,
                'subtotal' => 475.0,
            ]],
        ])->assertStatus(422);

        // 5. Increasing qty 5 -> 8 at same cost AND adding second unconsumed line succeeds in-place (preserving InventoryBatch.id!)
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poOldId}", [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'unit_cost' => 80.0,
                    'quantity' => 8.0,
                    'subtotal' => 640.0,
                ],
                [
                    'product_id' => $this->secondProduct->id,
                    'product_code' => $this->secondProduct->code,
                    'product_name' => $this->secondProduct->name,
                    'unit_cost' => 210.0,
                    'quantity' => 4.0,
                    'subtotal' => 840.0,
                ],
            ],
        ])->assertStatus(200);

        $freshBatch = InventoryBatch::findOrFail($oldBatchId);
        $this->assertEqualsWithDelta(8.0, (float)$freshBatch->initial_quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float)$freshBatch->remaining_quantity, 0.0001);
        $this->assertEquals(1, \App\Models\SaleItemCostLayer::where('inventory_batch_id', $oldBatchId)->count());

        // 6. Deleting newer PO-MCOST-NEW resyncs master cost back to remaining latest batch (80.0)
        $this->withToken($this->admin->api_token)->deleteJson("/api/purchases/{$poNewId}")->assertStatus(200);
        $this->assertEqualsWithDelta(80.0, (float)$this->product->fresh()->cost_price, 0.0001);
    }

    public function test_p1_opening_balance_cross_period_payments_and_released_at_history_in_ap_reports(): void
    {
        $supWithOpen = Supplier::create([
            'name' => 'Opening Balance Vendor',
            'phone' => '0977788899',
            'balance_due' => 500.0,
        ]);
        $supWithOpen->created_at = Carbon::parse('2026-09-01 09:00:00');
        $supWithOpen->save();

        // PO on 2026-09-25 for 1000.0 (Unpaid initially) -> total AP = 1500.0
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-OPEN-PER-01',
            'supplier_id' => $supWithOpen->id,
            'supplier_name' => $supWithOpen->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-09-25 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 10.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Payment of 600.0 made on 2026-10-10 against prior-period PO-OPEN-PER-01
        $this->withToken($this->admin->api_token)->postJson("/api/purchases/{$poId}/payments", [
            'amount' => 600.0,
            'payment_type' => 'Bank Transfer',
            'payment_date' => '2026-10-10 14:00:00',
        ])->assertStatus(200);

        // Credit Note return 400.0 on 2026-10-05 allocating remaining 400.0 of PO-OPEN-PER-01
        $prRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'reference_no' => 'PR-REL-HIST-01',
            'purchase_reference_no' => 'PO-OPEN-PER-01',
            'supplier_id' => $supWithOpen->id,
            'supplier_name' => $supWithOpen->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 11:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit Note',
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 4.0,
                'subtotal' => 400.0,
            ]],
        ])->assertStatus(201);
        $prId = (int)$prRes->json('data.id');

        // Later on 2026-10-20, convert PR-REL-HIST-01 into Cash Refund -> allocation gets released_at, NOT erased!
        $this->withToken($this->admin->api_token)->postJson("/api/purchase-returns/{$prId}/payments", [
            'amount' => 400.0,
            'payment_type' => 'Bank Transfer',
            'payment_date' => '2026-10-20 15:00:00',
        ])->assertStatus(200);

        // As of 2026-10-12: allocation was still active (PO due=0), leaving ONLY Opening Balance 500.0!
        $aging = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-12&force_refresh=1')
            ->assertStatus(200);
        $agingRow = collect($aging->json('data'))->firstWhere('supplier_id', $supWithOpen->id);
        $this->assertNotNull($agingRow);
        $this->assertEqualsWithDelta(500.0, (float)$agingRow['total_due'], 0.01);
        $openInv = collect($agingRow['invoices'])->firstWhere('reference_no', 'OPENING-BALANCE');
        $this->assertNotNull($openInv);

        // Supplier Report for [2026-10-05..2026-10-12] shows period payment 600.0 on prior PO & closing balance 500.0
        $supRep = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/suppliers?start_date=2026-10-05&end_date=2026-10-12&force_refresh=1')
            ->assertStatus(200);
        $sRow = collect($supRep->json('data.suppliers'))->firstWhere('id', $supWithOpen->id);
        $this->assertEqualsWithDelta(600.0, (float)$sRow['total_paid_amount'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$sRow['opening_balance_due'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$sRow['total_due'], 0.01);

        // Statement for [2026-10-05..2026-10-12] reconciles identically
        $stmt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers/{$supWithOpen->id}/statement?start_date=2026-10-05&end_date=2026-10-12")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(600.0, (float)$stmt->json('data.summary.total_paid'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$stmt->json('data.summary.total_due'), 0.01);
    }

    public function test_p2_landed_cost_penny_plug_8dp_and_lifo_payment_trim(): void
    {
        $accounting = app(\App\Services\PurchaseAccountingService::class);
        $calc = $accounting->calculateDocumentTotals(
            items: [
                ['quantity' => 1, 'conversion_rate' => 3, 'unit_cost' => 10.0, 'subtotal' => 10.0],
                ['quantity' => 1, 'conversion_rate' => 1, 'unit_cost' => 10.0, 'subtotal' => 10.0],
                ['quantity' => 1, 'conversion_rate' => 1, 'unit_cost' => 10.0, 'subtotal' => 10.0],
            ],
            orderDiscount: 0.0,
            taxRate: 0.0,
            flatTaxAmount: null,
            shipping: 1.00,
            isTaxRecoverable: false
        );
        $this->assertEquals(3.33333333, (float)$calc['items'][0]['base_unit_cost']);
        $sumLanded = round(array_sum(array_column($calc['items'], 'landed_total')), 2);
        $this->assertEqualsWithDelta((float)$calc['inventory_cost_total'], $sumLanded, 0.01);

        // Split payment LIFO trim preserves multi-tender history
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 5000.0,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'register_name' => 'LIFO Till',
            'user_name' => $this->admin->name,
        ])->assertStatus(200);

        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-LIFO-01',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Received',
            'subtotal' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 300.0,
            'payment_type' => 'Split Payment',
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 100.0],
                ['payment_type' => 'Bank Transfer', 'amount' => 200.0],
            ],
            'items' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->code,
                'product_name' => $this->product->name,
                'unit_cost' => 100.0,
                'quantity' => 3.0,
                'subtotal' => 300.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        // Verify Purchase StockMovement captured unit_cost
        $mv = \App\Models\StockMovement::where('reference_type', 'purchase')
            ->where('reference_id', (string)$poId)
            ->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float)$mv->unit_cost, 0.01);

        // Reduce scalar paid_amount 300 -> 150 -> trims Bank Transfer 200 -> 50 while keeping Cash 100
        $upd = $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'paid_amount' => 150.0,
        ])->assertStatus(200);
        $payments = $upd->json('data.payments');
        $this->assertCount(2, $payments);
        $this->assertEqualsWithDelta(100.0, (float)$payments[0]['amount'], 0.01);
        $this->assertSame('Cash', $payments[0]['payment_type']);
        $this->assertEqualsWithDelta(50.0, (float)$payments[1]['amount'], 0.01);
        $this->assertSame('Bank Transfer', $payments[1]['payment_type']);
    }
}
