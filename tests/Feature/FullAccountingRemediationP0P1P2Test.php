<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUnitLink;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseUnit;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemCostLayer;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FifoInventoryService;
use App\Services\ProductUnitConversionService;
use App\Services\QuotationAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FullAccountingRemediationP0P1P2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $wh1;
    private Outlet $wh2;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->wh1 = Outlet::create([
            'name' => 'Audit Main WH',
            'code' => 'AUD-WH1',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->wh2 = Outlet::create([
            'name' => 'Audit Branch WH',
            'code' => 'AUD-WH2',
            'is_warehouse' => false,
            'is_active' => true,
        ]);

        $adminRole = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super_admin_' . uniqid(),
            'permissions' => ['*'],
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-AUD-ADMIN-' . uniqid(),
            'name' => 'Audit Super Admin',
            'email' => 'audit-admin-' . uniqid() . '@retailpos.test',
            'role' => 'Super Admin',
            'role_id' => $adminRole->id,
            'status' => 'active',
            'password' => bcrypt('Secret123!'),
            'api_token' => 'audit_admin_token_' . uniqid(),
        ]);
    }

    private function makeScopedUser(string $tag, array $permissions, array $outletIds = []): User
    {
        $role = Role::create([
            'name' => 'Role ' . $tag,
            'slug' => 'role_' . $tag . '_' . uniqid(),
            'permissions' => $permissions,
            'status' => 'active',
        ]);

        $user = User::create([
            'user_id' => 'USR-' . strtoupper($tag) . '-' . uniqid(),
            'name' => 'User ' . $tag,
            'email' => $tag . '-' . uniqid() . '@retailpos.test',
            'role' => $role->name,
            'role_id' => $role->id,
            'outlet_id' => $outletIds[0] ?? null,
            'status' => 'active',
            'password' => bcrypt('Secret123!'),
            'api_token' => 'tok_' . $tag . '_' . uniqid(),
        ]);

        if (!empty($outletIds)) {
            $user->outlets()->sync($outletIds);
        }

        return $user;
    }

    public function test_p0_cross_table_unit_id_collision_does_not_multiply_base_unit_adjustments_or_transfers(): void
    {
        $baseUnit = ProductUnit::create(['name' => 'Piece', 'status' => 'Active']);
        $boxUnit = PurchaseUnit::create([
            'name' => 'Box',
            'base_unit_id' => $baseUnit->id,
            'base_unit_name' => $baseUnit->name,
            'status' => 'Active',
        ]);

        $product = Product::create([
            'code' => 'COLLIDE-SKU-1',
            'name' => 'Collision Test Product',
            'base_unit_id' => $baseUnit->id,
            'unit_name' => 'Piece',
            'cost_price' => 1000.0,
            'price' => 1500.0,
            'stock_quantity' => 50.0,
        ]);

        // Force a ProductUnitLink whose primary key equals $baseUnit->id (cross-table ID collision)
        DB::table('product_unit_links')->insert([
            'id' => $baseUnit->id,
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'unit_type' => 'purchase',
            'unit_name' => 'Box',
            'conversion_rate' => 12.0,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 50.0,
        ]);

        // Direct service check: passing base unit_id with base unit_name must resolve rate=1.0, NEVER Box (12.0)
        $unitService = app(ProductUnitConversionService::class);
        $resolved = $unitService->resolve($product->fresh('unitLinks'), 'purchase', $baseUnit->id, 'Piece');
        $this->assertEqualsWithDelta(1.0, (float)$resolved['conversion_rate'], 0.0001);

        // Adjustment with colliding unit_id + unit_name='Piece' must add exactly 5 pieces (not 60!)
        $adjRes = $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 10:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $baseUnit->id,
                'unit_name' => 'Piece',
                'quantity' => 5.0,
                'type' => 'Addition',
                'unit_cost' => 1000.0,
            ]],
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(5.0, (float)$adjRes->json('data.items.0.base_quantity'), 0.0001);
        $this->assertEqualsWithDelta(55.0, (float)StockBalance::where('warehouse_id', $this->wh1->id)->where('product_id', $product->id)->value('quantity'), 0.0001);

        // Transfer with colliding unit_id + unit_name='Piece' must transfer exactly 3 pieces (not 36!)
        $trfRes = $this->withToken($this->admin->api_token)->postJson('/api/transfers', [
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => '2026-11-11 10:00:00',
            'status' => 'Completed',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $baseUnit->id,
                'unit_name' => 'Piece',
                'quantity' => 3.0,
                'unit_cost' => 1000.0,
            ]],
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(3.0, (float)$trfRes->json('data.items.0.base_quantity'), 0.0001);
        $this->assertEqualsWithDelta(52.0, (float)StockBalance::where('warehouse_id', $this->wh1->id)->where('product_id', $product->id)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(3.0, (float)StockBalance::where('warehouse_id', $this->wh2->id)->where('product_id', $product->id)->value('quantity'), 0.0001);
    }

    public function test_p0_opening_stock_base_unit_costing_edit_preserves_multi_warehouse_stock_and_destroy_blocks_history(): void
    {
        $baseUnit = ProductUnit::create(['name' => 'Bottle', 'status' => 'Active']);
        $caseUnit = PurchaseUnit::create([
            'name' => 'Case',
            'base_unit_id' => $baseUnit->id,
            'base_unit_name' => $baseUnit->name,
            'status' => 'Active',
        ]);

        // Create product with base cost 1000 Ks/Bottle, Purchase unit Case (12), and opening stock 10 bottles
        $createRes = $this->withToken($this->admin->api_token)->postJson('/api/products', [
            'code' => 'WATER-OPEN-01',
            'name' => 'Water Bottle Opening',
            'base_unit_id' => $baseUnit->id,
            'unit_name' => 'Bottle',
            'purchase_unit_id' => $caseUnit->id,
            'unit_links' => [[
                'unit_id' => $caseUnit->id,
                'unit_type' => 'purchase',
                'unit_name' => 'Case',
                'conversion_rate' => 12.0,
                'is_default' => true,
            ]],
            'cost_price' => 1000.0,
            'price' => 1500.0,
            'opening_stocks' => [[
                'warehouse_id' => $this->wh1->id,
                'quantity' => 10.0,
                'unit_type' => 'base',
            ]],
        ])->assertStatus(201);

        $prodId = (int)$createRes->json('data.id');
        $batch = InventoryBatch::where('product_id', $prodId)->firstOrFail();
        // Base unit cost MUST remain 1000.0 (never 1000 / 12 = 83.3333!)
        $this->assertEqualsWithDelta(1000.0, (float)$batch->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float)$batch->initial_quantity, 0.0001);

        // Seed multi-warehouse stock: WH1 = 60, WH2 = 40 (total 100)
        StockBalance::where('warehouse_id', $this->wh1->id)->where('product_id', $prodId)->update(['quantity' => 60.0]);
        StockBalance::create(['warehouse_id' => $this->wh2->id, 'product_id' => $prodId, 'quantity' => 40.0]);
        Product::whereKey($prodId)->update(['stock_quantity' => 100.0]);

        // Editing product name MUST NOT overwrite WH1 stock_balances to 100 (causing 100 + 40 = 140 explosion)
        $this->withToken($this->admin->api_token)->putJson("/api/products/{$prodId}", [
            'code' => 'WATER-OPEN-01',
            'name' => 'Water Bottle Renamed',
            'cost_price' => 1000.0,
            'price' => 1600.0,
            'stock_quantity' => 100.0,
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(60.0, (float)StockBalance::where('warehouse_id', $this->wh1->id)->where('product_id', $prodId)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(40.0, (float)StockBalance::where('warehouse_id', $this->wh2->id)->where('product_id', $prodId)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float)Product::findOrFail($prodId)->stock_quantity, 0.0001);

        // Even if stock reaches 0, product with historical FIFO batch / sale history MUST NOT be deletable (422)
        StockBalance::where('product_id', $prodId)->update(['quantity' => 0.0]);
        Product::whereKey($prodId)->update(['stock_quantity' => 0.0]);
        $this->withToken($this->admin->api_token)->deleteJson("/api/products/{$prodId}")->assertStatus(422);
    }

    public function test_p0_transfer_linked_expense_payments_and_pending_transfer_pnl_deferral(): void
    {
        $product = Product::create([
            'code' => 'TRF-EXP-01',
            'name' => 'Transfer Expense Item',
            'cost_price' => 500.0,
            'price' => 800.0,
            'stock_quantity' => 50.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 50.0]);

        // 1. Pending transfer with shipping MUST defer P&L expense recognition (no Expense created yet)
        $pendingRes = $this->withToken($this->admin->api_token)->postJson('/api/transfers', [
            'reference_no' => 'TRF-PEND-DEF-1',
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => '2026-11-12 09:00:00',
            'status' => 'Pending',
            'shipping_charge' => 25000.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 5.0,
                'unit_cost' => 500.0,
            ]],
        ])->assertStatus(201);
        $pendingId = (int)$pendingRes->json('data.id');
        $this->assertNull(Expense::where('transfer_id', $pendingId)->first());

        // Receiving the Pending transfer recognizes the deferred Transfer Shipping expense
        $this->withToken($this->admin->api_token)
            ->postJson("/api/transfers/{$pendingId}/receive")
            ->assertStatus(200);
        $linkedExp = Expense::where('transfer_id', $pendingId)->first();
        $this->assertNotNull($linkedExp);
        $this->assertEqualsWithDelta(25000.0, (float)$linkedExp->amount, 0.01);

        // 2. Adding & deleting payment on transfer-linked expense must succeed (was HTTP 500 missing StockTransfer import)
        $addPayRes = $this->withToken($this->admin->api_token)
            ->postJson("/api/expenses/{$linkedExp->id}/payments", [
                'amount' => 10000.0,
                'payment_type' => 'KBZPay',
                'payment_date' => '2026-11-12 10:00:00',
            ])
            ->assertStatus(200);
        $this->assertEqualsWithDelta(10000.0, (float)$linkedExp->fresh()->paid_amount, 0.01);
        $payId = (int)$linkedExp->payments()->firstOrFail()->id;

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/expenses/{$linkedExp->id}/payments/{$payId}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, (float)$linkedExp->fresh()->paid_amount, 0.01);
    }

    public function test_p0_cash_register_scoping_blind_count_masking_and_explicit_closing_cash(): void
    {
        $cashierA = $this->makeScopedUser('cashier_a', ['pos.view', 'cash_register.view', 'cash_register.open_close', 'report_cashier_audit.view'], [$this->wh1->id]);
        $cashierB = $this->makeScopedUser('cashier_b', ['pos.view', 'cash_register.view', 'cash_register.open_close'], [$this->wh2->id]);

        $regA = CashRegister::create([
            'reference_no' => 'CR-SCOPE-A',
            'register_name' => 'WH1 Till',
            'user_id' => $cashierA->id,
            'user_name' => $cashierA->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 50000.0,
            'expected_cash' => 75000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $regB = CashRegister::create([
            'reference_no' => 'CR-SCOPE-B',
            'register_name' => 'WH2 Till',
            'user_id' => $cashierB->id,
            'user_name' => $cashierB->name,
            'warehouse_id' => $this->wh2->id,
            'warehouse_name' => $this->wh2->name,
            'opening_cash' => 100000.0,
            'expected_cash' => 120000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        // Cross-branch register inspection & close MUST be rejected with 403
        $this->withToken($cashierA->api_token)->getJson("/api/cash-register/details/{$regB->id}")->assertStatus(403);
        $this->withToken($cashierA->api_token)->postJson("/api/cash-register/close/{$regB->id}", ['closing_cash' => 120000.0])->assertStatus(403);

        // Blind-count cashier inspecting OWN register must NOT see expected_cash OR leaked closing_cash
        $detRes = $this->withToken($cashierA->api_token)->getJson("/api/cash-register/details/{$regA->id}")->assertStatus(200);
        $this->assertTrue((bool)$detRes->json('register.blind_count_masked'));
        $this->assertNull($detRes->json('register.expected_cash'));
        $this->assertNull($detRes->json('register.closing_cash'));

        // Blind-count cashier querying Cashier Audit Report must ALSO receive masked expected_cash
        $auditRes = $this->withToken($cashierA->api_token)
            ->getJson('/api/reports/cashier-audit?start_date=' . now()->format('Y-m-d') . '&end_date=' . now()->format('Y-m-d'))
            ->assertStatus(200);
        $this->assertTrue((bool)($auditRes->json('summary.blind_count_masked') ?? $auditRes->json('data.summary.blind_count_masked')));

        // Closing register without explicit closing_cash MUST fail 422 (never silently auto-match expected_cash)
        $this->withToken($cashierA->api_token)
            ->postJson("/api/cash-register/close/{$regA->id}", [])
            ->assertStatus(422);
    }

    public function test_p0_sale_return_shipping_and_net_goods_cap_blocked(): void
    {
        $product = Product::create([
            'code' => 'SR-CAP-01',
            'name' => 'Cap Test Item',
            'cost_price' => 4000.0,
            'price' => 10000.0,
            'stock_quantity' => 20.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 20.0]);

        $sale = Sale::create([
            'reference_no' => 'SL-CAP-01',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 10:00:00',
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'discount' => 2000.0,
            'shipping' => 0.0,
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 10000.0,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'subtotal' => 10000.0,
            'base_unit_cost' => 4000.0,
            'total_cogs' => 4000.0,
        ]);

        // Attempt 1: Inflate shipping on a zero-shipping sale (would previously authorize 58,000 refund on 8,000 sale) -> 422
        $this->withToken($this->admin->api_token)->postJson('/api/sale-returns', [
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 12:00:00',
            'status' => 'Completed',
            'discount' => 2000.0,
            'shipping' => 50000.0,
            'paid_amount' => 58000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 10000.0,
                'quantity' => 1.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(422);

        // Attempt 2: Drop order discount to over-refund 10,000 on an 8,000 discounted sale -> 422
        $this->withToken($this->admin->api_token)->postJson('/api/sale-returns', [
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 12:00:00',
            'status' => 'Completed',
            'discount' => 0.0,
            'shipping' => 0.0,
            'paid_amount' => 10000.0,
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 10000.0,
                'quantity' => 1.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_p1_pnl_unlayered_return_cogs_line_tax_and_shipping_passthrough_policy(): void
    {
        $product = Product::create([
            'code' => 'PNL-COGS-01',
            'name' => 'PnL Unlayered Return Item',
            'cost_price' => 600.0,
            'price' => 1000.0,
            'stock_quantity' => 10.0,
        ]);

        // Sale: 2 pcs @ 1000 + 100 line tax + 500 shipping (pass-through!) = 2600 grand_total; COGS = 1200
        $sale = Sale::create([
            'reference_no' => 'SL-PNL-PASSTHRU-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-14 10:00:00',
            'status' => 'Completed',
            'subtotal' => 2100.0,
            'tax_amount' => 0.0,
            'shipping' => 500.0,
            'grand_total' => 2600.0,
            'paid_amount' => 2600.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 1200.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 1000.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'tax' => 100.0,
            'subtotal' => 2100.0,
            'base_unit_cost' => 600.0,
            'total_cogs' => 1200.0,
        ]);

        // Legacy unlayered return of 1 pc (return_cost_total = 0, no cost layers, includes 50 line tax)
        SaleReturn::create([
            'reference_no' => 'SR-PNL-UNLAY-1',
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-14 12:00:00',
            'status' => 'Completed',
            'subtotal' => 1050.0,
            'tax_amount' => 0.0,
            'grand_total' => 1050.0,
            'paid_amount' => 1050.0,
            'due_amount' => 0.0,
            'return_cost_total' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'unit_price' => 1000.0,
                'tax' => 50.0,
                'subtotal' => 1050.0,
            ]]),
        ]);

        $pnl = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/profit-loss?start_date=2026-11-14&end_date=2026-11-14')
            ->assertStatus(200);
        $dash = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/dashboard-summary?start_date=2026-11-14&end_date=2026-11-14')
            ->assertStatus(200);
        $margins = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-11-14&end_date=2026-11-14')
            ->assertStatus(200);

        // Policy: sales.shipping (500) MUST remain excluded from P&L & Dashboard revenue (Pass-through!)
        $this->assertEqualsWithDelta(2000.0, (float)$pnl->json('data.summary.total_gross_sales'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$pnl->json('data.summary.total_sales_return'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$pnl->json('data.summary.total_net_sales'), 0.01);
        // Line tax (100) minus returned line tax (50) = 50 net tax collected
        $this->assertEqualsWithDelta(50.0, (float)$pnl->json('data.summary.total_tax_collected'), 0.01);
        // Unlayered return COGS fallback (600) deducted -> net COGS = 600 across ALL 3 reports!
        $this->assertEqualsWithDelta(600.0, (float)$pnl->json('data.summary.total_cogs'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$dash->json('data.cogs'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$margins->json('summary.total_cogs'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$pnl->json('data.summary.total_gross_profit'), 0.01);

        // P&L status=Deficit filter alias must work cleanly (previously returned 0)
        $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/profit-loss?start_date=2026-11-14&end_date=2026-11-14&status=Deficit')
            ->assertStatus(200);
    }

    public function test_p1_ar_ap_reports_no_cross_customer_netting_historical_refund_rollback_and_credit_only_ap(): void
    {
        $custA = Customer::create(['name' => 'AR Debtor A', 'phone' => '0911111111', 'status' => 'Active']);
        $custB = Customer::create(['name' => 'AR Creditor B', 'phone' => '0922222222', 'status' => 'Active']);

        Sale::create([
            'reference_no' => 'SL-AR-A1',
            'customer_id' => $custA->id,
            'customer_name' => $custA->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 10:00:00',
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'due_amount' => 1000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);

        // Customer B has a 600 return on Nov 1 that was later refunded in Cash on Nov 10
        SaleReturn::create([
            'reference_no' => 'SR-AR-B1',
            'sale_reference_no' => 'SL-PREV-B',
            'customer_id' => $custB->id,
            'customer_name' => $custB->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 11:00:00',
            'status' => 'Completed',
            'grand_total' => 600.0,
            'paid_amount' => 600.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'payments_payload' => json_encode([
                ['payment_type' => 'Cash', 'amount' => 600.0, 'payment_date' => '2026-11-10 15:00:00'],
            ]),
        ]);
        // Plus Customer B has an open 300 return credit today
        SaleReturn::create([
            'reference_no' => 'SR-AR-B2',
            'sale_reference_no' => 'SL-PREV-B2',
            'customer_id' => $custB->id,
            'customer_name' => $custB->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-02 11:00:00',
            'status' => 'Completed',
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'due_amount' => 300.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Store Credit',
        ]);

        // IAS 1.32: Customer Report must NOT net Customer B's 300 credit against Customer A's 1000 receivable!
        $custRpt = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/customers')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(1000.0, (float)$custRpt->json('data.summary.net_due'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float)$custRpt->json('data.summary.total_credit_balance'), 0.01);

        // Historical AR Aging as_of 2026-11-05 must roll back Nov 10 cash refund -> open credit notes = 600 + 300 = 900!
        $histAr = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ar-aging?as_of_date=2026-11-05')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(900.0, (float)$histAr->json('summary.total_open_credit_notes'), 0.01);

        // AP Aging: credit-only supplier excluded from payable list while reported in total_credit_balance;
        // pre-cutoff PR credit allocated to post-cutoff PO still reduces historical supplierReport AP!
        $supCreditOnly = Supplier::create(['name' => 'Credit Only Vendor', 'phone' => '0933333333', 'status' => 'Active']);
        PurchaseReturn::create([
            'reference_no' => 'PR-CRED-ONLY-1',
            'purchase_reference_no' => 'PO-OLD-1',
            'supplier_id' => $supCreditOnly->id,
            'supplier_name' => $supCreditOnly->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 09:00:00',
            'status' => 'Completed',
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'due_amount' => 400.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
        ]);

        $apAging = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-11-20')
            ->assertStatus(200);
        $this->assertEquals(0, (int)$apAging->json('summary.total_suppliers'));
        $this->assertEqualsWithDelta(400.0, (float)$apAging->json('summary.total_credit_balance'), 0.01);

        // Historical PR allocation cutoff test
        $supHist = Supplier::create(['name' => 'Hist Alloc Vendor', 'phone' => '0944444444', 'status' => 'Active']);
        Purchase::create([
            'reference_no' => 'PO-HIST-1',
            'supplier_id' => $supHist->id,
            'supplier_name' => $supHist->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 09:00:00',
            'status' => 'Received',
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'due_amount' => 1000.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        $prPre = PurchaseReturn::create([
            'reference_no' => 'PR-HIST-PRE',
            'purchase_reference_no' => 'PO-HIST-1',
            'supplier_id' => $supHist->id,
            'supplier_name' => $supHist->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-02 09:00:00',
            'status' => 'Completed',
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'due_amount' => 300.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
        ]);
        $poPost = Purchase::create([
            'reference_no' => 'PO-HIST-POST',
            'supplier_id' => $supHist->id,
            'supplier_name' => $supHist->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 09:00:00',
            'status' => 'Received',
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'returned_credit_amount' => 300.0,
            'due_amount' => 200.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        PurchaseReturnAllocation::create([
            'purchase_return_id' => $prPre->id,
            'purchase_id' => $poPost->id,
            'allocated_amount' => 300.0,
            'allocated_at' => '2026-11-15 09:00:00',
        ]);

        // As of 2026-11-05 (before PO-HIST-POST), PR-HIST-PRE's 300 credit was NOT yet allocated to PO-HIST-POST -> net due = 700
        $histSup = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/suppliers?start_date=2026-11-01&end_date=2026-11-05')
            ->assertStatus(200);
        $histRow = collect($histSup->json('data.suppliers'))->firstWhere('id', $supHist->id);
        $this->assertNotNull($histRow);
        $this->assertEqualsWithDelta(700.0, (float)$histRow['net_due'], 0.01);
    }

    public function test_p1_return_reports_store_credit_ar_offset_and_top_selling_and_margins_base_units(): void
    {
        $product = Product::create([
            'code' => 'BOX-MARGIN-01',
            'name' => 'Box Margin Item',
            'cost_price' => 100.0,
            'price' => 200.0,
            'stock_quantity' => 50.0,
        ]);

        // Sale: 1 Box (12 pcs base @ 200 = 2400) minus 400 order discount = 2000 net revenue
        $sale = Sale::create([
            'reference_no' => 'SL-BOX-M1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-16 10:00:00',
            'status' => 'Completed',
            'subtotal' => 2400.0,
            'discount' => 400.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 1200.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_name' => 'Box',
            'conversion_rate' => 12.0,
            'quantity' => 1.0,
            'base_quantity' => 12.0,
            'unit_price' => 2400.0,
            'subtotal' => 2400.0,
            'base_unit_cost' => 100.0,
            'total_cogs' => 1200.0,
        ]);

        // Return 6 pcs paid via Store Credit + AR Credit Offset test
        SaleReturn::create([
            'reference_no' => 'SR-BOX-M1',
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-16 12:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 600.0,
            'applied_credit_amount' => 400.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Store Credit',
            'return_reason' => 'Box Torn Corner',
            'items_payload' => json_encode([[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 6.0,
                'conversion_rate' => 1.0,
                'base_quantity' => 6.0,
                'unit_price' => 166.67,
                'subtotal' => 1000.0,
            ]]),
        ]);

        // Product Margins MUST compute 12 base - 6 base = +6 units_sold (never 1 - 6 = -5!)
        $margins = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-11-16&end_date=2026-11-16')
            ->assertStatus(200);
        $mItem = collect($margins->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($mItem);
        $this->assertEqualsWithDelta(6.0, (float)$mItem['units_sold'], 0.001);

        // Top Selling MUST deduct order discount (2000 - 1000 = 1000 net revenue) & base qty = 6
        $top = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/top-selling?start_date=2026-11-16&end_date=2026-11-16&limit=50')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(6.0, (float)($top->json('data.summary.total_quantity') ?? $top->json('summary.total_quantity')), 0.001);
        $this->assertEqualsWithDelta(1000.0, (float)($top->json('data.summary.total_revenue') ?? $top->json('summary.total_revenue')), 0.01);

        // Sale Returns Report MUST classify Store Credit (600) and AR Credit Offset (400) separately and expose return_reason
        $srRpt = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/sale-returns?start_date=2026-11-16&end_date=2026-11-16')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(600.0, (float)$srRpt->json('summary.store_credit_issued'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$srRpt->json('summary.ar_credit_offset'), 0.01);
        $this->assertEquals('Box Torn Corner', $srRpt->json('data.0.return_reason'));
    }

    public function test_p2_fifo_8dp_precision_reverse_cap_and_consumed_po_unit_swap_and_date_blocked(): void
    {
        $baseUnit = ProductUnit::create(['name' => 'Piece', 'status' => 'Active']);
        $boxUnit = PurchaseUnit::create([
            'name' => 'Box12',
            'base_unit_id' => $baseUnit->id,
            'base_unit_name' => $baseUnit->name,
            'status' => 'Active',
        ]);
        $product = Product::create([
            'code' => 'FIFO-P2-01',
            'name' => 'FIFO Precision Product',
            'base_unit_id' => $baseUnit->id,
            'unit_name' => 'Piece',
            'cost_price' => 1000.0,
            'price' => 2000.0,
            'stock_quantity' => 0.0,
        ]);
        ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'unit_type' => 'purchase',
            'unit_name' => 'Box12',
            'conversion_rate' => 12.0,
            'is_default' => true,
        ]);

        // 1. FIFO 8dp precision + reverseSaleFifo() cap at initial_quantity
        $fifo = app(FifoInventoryService::class);
        $batch = $fifo->recordBatch(
            warehouseId: $this->wh1->id,
            productId: $product->id,
            variantId: null,
            baseQuantity: 300.0,
            baseUnitCost: round(100.0 / 300.0, 8),
            sourceType: 'purchase',
            sourceReference: 'PO-8DP-1',
            receivedDate: '2026-11-01 09:00:00'
        );
        // Verify 8dp precision stored (SQLite stores float directly; MySQL DECIMAL(24,8) preserves 8dp)
        $this->assertEqualsWithDelta(0.33333333, (float)$batch->fresh()->unit_cost, 0.00000005);

        // Simulate duplicate layer exceeding remaining capacity; reverseSaleFifo MUST cap at initial_quantity (300)
        $batch->update(['remaining_quantity' => 295.0]);
        $dummySale = Sale::create([
            'reference_no' => 'SL-REV-CAP',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-02 10:00:00',
            'status' => 'Completed',
            'grand_total' => 100.0,
            'paid_amount' => 100.0,
        ]);
        $dummySi = SaleItem::create([
            'sale_id' => $dummySale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 10.0,
            'quantity' => 10.0,
            'base_quantity' => 10.0,
            'subtotal' => 100.0,
        ]);
        SaleItemCostLayer::create([
            'sale_id' => $dummySale->id,
            'sale_item_id' => $dummySi->id,
            'inventory_batch_id' => $batch->id,
            'base_quantity' => 10.0,
            'unit_cost' => 0.33333333,
            'total_cost' => 3.33,
        ]);
        $fifo->reverseSaleFifo($dummySale);
        $this->assertEqualsWithDelta(300.0, (float)$batch->fresh()->remaining_quantity, 0.0001);

        // 2. Consumed Received PO edit guard: changing unit Box12 -> Piece OR changing date with items payload MUST fail 422!
        $sup = Supplier::create(['name' => 'PO Guard Vendor', 'phone' => '0955555555', 'status' => 'Active']);
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-GUARD-SWAP-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 120000.0,
            'grand_total' => 120000.0,
            'paid_amount' => 0.0,
            'payment_type' => 'Credit',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box12',
                'conversion_rate' => 12.0,
                'unit_cost' => 12000.0,
                'quantity' => 10.0,
                'subtotal' => 120000.0,
            ]],
        ])->assertStatus(201);
        $poId = (int)$poRes->json('data.id');

        $poBatch = InventoryBatch::where('source_reference', 'PO-GUARD-SWAP-1')->firstOrFail();
        $poBatch->update(['remaining_quantity' => 100.0]); // 20 pcs consumed

        // Attempt A: Unit swap Box12 -> Piece at same display unit_cost=12000 (inflating base cost 1000 -> 12000) -> 422!
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $baseUnit->id,
                'unit_name' => 'Piece',
                'conversion_rate' => 1.0,
                'unit_cost' => 12000.0,
                'quantity' => 120.0,
                'subtotal' => 1440000.0,
            ]],
        ])->assertStatus(422);

        // Attempt B: Backdating date while sending items payload -> 422!
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$poId}", [
            'date' => '2026-11-01 09:00:00',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box12',
                'conversion_rate' => 12.0,
                'unit_cost' => 12000.0,
                'quantity' => 10.0,
                'subtotal' => 120000.0,
            ]],
        ])->assertStatus(422);

        // Batch received_date and unit_cost remain intact
        $poBatch->refresh();
        $this->assertStringStartsWith('2026-11-10', (string)$poBatch->received_date);
        $this->assertEqualsWithDelta(1000.0, (float)$poBatch->unit_cost, 0.0001);
    }

    public function test_p2_quotation_formula_parity_non_cash_overpay_block_and_split_register_cash(): void
    {
        $product = Product::create([
            'code' => 'QUO-P2-01',
            'name' => 'Quotation Parity Item',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 50.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 50.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'unit_cost' => 50.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-QUO-SEED',
            'received_date' => '2026-11-01 09:00:00',
            'status' => 'active',
        ]);

        // 1. QuotationAccountingService must clamp order discount to taxExclusiveBase (200), NEVER eating item tax (20)
        $quoSvc = app(QuotationAccountingService::class);
        $calc = $quoSvc->calculateDocumentTotals([
            ['quantity' => 2, 'unit_price' => 100.0, 'discount' => 0.0, 'tax' => 20.0],
        ], 500.0, 0.0, null, 0.0);
        $this->assertEqualsWithDelta(200.0, $calc['discount'], 0.01);
        $this->assertEqualsWithDelta(20.0, $calc['grand_total'], 0.01);

        // 2. Create Quotation & convert with non-cash overpayment -> 422
        $quoRes = $this->withToken($this->admin->api_token)->postJson('/api/quotations', [
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
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
        $quoId = (int)$quoRes->json('data.id');

        $this->withToken($this->admin->api_token)
            ->postJson("/api/quotations/{$quoId}/convert-to-sale", [
                'payment_type' => 'KBZPay',
                'paid_amount' => 250.0,
            ])
            ->assertStatus(422);

        // 3. Split-register cash conversion must credit BOTH registers accurately and stamp StockMovement.unit_cost
        $reg1 = CashRegister::create([
            'reference_no' => 'CR-QUO-1',
            'register_name' => 'Till 1',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 100.0,
            'expected_cash' => 100.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);
        $reg2 = CashRegister::create([
            'reference_no' => 'CR-QUO-2',
            'register_name' => 'Till 2',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 200.0,
            'expected_cash' => 200.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $convRes = $this->withToken($this->admin->api_token)
            ->postJson("/api/quotations/{$quoId}/convert-to-sale", [
                'payment_type' => 'Split Payment',
                'cash_register_id' => $reg1->id,
                'payments' => [
                    ['payment_type' => 'Cash', 'amount' => 80.0, 'cash_register_id' => $reg1->id],
                    ['payment_type' => 'Cash', 'amount' => 120.0, 'cash_register_id' => $reg2->id],
                ],
            ])
            ->assertStatus(201);

        $saleId = (int)$convRes->json('data.sale.id');
        $this->assertEqualsWithDelta(180.0, (float)$reg1->fresh()->expected_cash, 0.01);
        $this->assertEqualsWithDelta(320.0, (float)$reg2->fresh()->expected_cash, 0.01);

        $mv = StockMovement::where('reference_type', 'sale')->where('reference_id', (string)$saleId)->first();
        $this->assertNotNull($mv);
        $this->assertEqualsWithDelta(50.0, (float)$mv->unit_cost, 0.01);
    }

    public function test_p2_adjustment_rejects_unresolved_sku_and_consumed_calendar_date_mutation(): void
    {
        // 1. Unresolved SKU must return 422 (no phantom P&L loss without stock deduction)
        $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 09:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [[
                'product_code' => 'NON-EXISTENT-SKU-999',
                'product_name' => 'Ghost Product',
                'quantity' => 10.0,
                'type' => 'Subtraction',
                'unit_cost' => 15000.0,
            ]],
        ])->assertStatus(422);

        // 2. Consumed adjustment addition batch blocks cross-day date mutation (422)
        $product = Product::create([
            'code' => 'ADJ-DATELOCK-01',
            'name' => 'Date Lock Product',
            'cost_price' => 100.0,
            'price' => 200.0,
            'stock_quantity' => 0.0,
        ]);
        $createRes = $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'reference_no' => 'ADJ_DATE_01',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 09:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 10.0,
                'type' => 'Addition',
                'unit_cost' => 100.0,
            ]],
        ])->assertStatus(201);
        $adjId = (int)$createRes->json('data.id');

        $adjBatch = InventoryBatch::where('source_type', 'adjustment_addition')->where('product_id', $product->id)->firstOrFail();
        $adjBatch->update(['remaining_quantity' => 6.0]); // 4 units consumed

        $this->withToken($this->admin->api_token)->putJson("/api/adjustments/{$adjId}", [
            'reference_no' => 'ADJ_DATE_01',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 09:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 10.0,
                'type' => 'Addition',
                'unit_cost' => 100.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_review_blind_count_masking_on_record_transaction_and_close(): void
    {
        \App\Models\Setting::set('manager_pin', '9999');

        $cashierRole = Role::create([
            'name' => 'Blind Cashier Strict',
            'slug' => 'blind_cashier_strict_' . uniqid(),
            'permissions' => ['cash_register.view', 'cash_register.open_close', 'cash_register.safe_drop', 'pos.view'],
            'status' => 'active',
        ]);
        $cashier = User::create([
            'name' => 'Blind Cashier Strict',
            'email' => 'blind-strict@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $cashierRole->id,
            'role' => 'Cashier',
            'outlet_id' => $this->wh1->id,
            'status' => 'active',
            'api_token' => 'blind_strict_tok_' . uniqid(),
        ]);

        $reg = CashRegister::create([
            'reference_no' => 'CR-BLIND-STRICT-1',
            'user_id' => $cashier->id,
            'user_name' => $cashier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 1000.0,
            'expected_cash' => 1000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $sale = Sale::create([
            'reference_no' => 'SL-BLIND-STRICT-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $reg->id,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'subtotal' => 250.0,
            'grand_total' => 250.0,
            'paid_amount' => 250.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_register_id' => $reg->id,
            'reference_no' => 'PAY-BLIND-1',
            'payment_date' => $sale->date,
            'payment_type' => 'Cash',
            'amount' => 250.0,
            'received_amount' => 250.0,
            'change_amount' => 0.0,
        ]);

        // 1. Over-limit drop 422 message MUST NOT leak exact drawer cash (1,250) to blind cashier
        $overDrop = $this->withToken($cashier->api_token)->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $reg->id,
            'type' => 'drop',
            'amount' => 9999.0,
            'reason' => 'Attempt over drop',
            'manager_pin' => '9999',
        ])->assertStatus(422);
        $this->assertStringNotContainsString('1,250', (string)$overDrop->json('message'));

        // 2. Float-in response MUST redact closing_cash, expected_cash, cash_sales, floats_in, payment_breakdown[0].amount
        $floatRes = $this->withToken($cashier->api_token)->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $reg->id,
            'type' => 'float_in',
            'amount' => 100.0,
            'reason' => 'Mid-shift change float',
            'manager_pin' => '9999',
        ])->assertStatus(200);
        $this->assertTrue((bool)$floatRes->json('register.blind_count_masked'));
        $this->assertNull($floatRes->json('register.expected_cash'));
        $this->assertNull($floatRes->json('register.closing_cash'));
        $this->assertNull($floatRes->json('register.difference'));
        $this->assertNull($floatRes->json('register.cash_sales'));
        $this->assertNull($floatRes->json('register.floats_in'));
        $this->assertNull($floatRes->json('register.cash_drops'));
        $this->assertNull($floatRes->json('register.total_collections'));
        $this->assertNull($floatRes->json('register.total_payment'));
        $this->assertNull($floatRes->json('register.payment_breakdown.0.amount'));

        // 3. Close response for blind cashier MUST keep user's submitted closing_cash while masking expected_cash, difference & cash scalars
        $closeRes = $this->withToken($cashier->api_token)->postJson("/api/cash-register/close/{$reg->id}", [
            'closing_cash' => 1300.0,
            'closing_notes' => 'End of shift blind count',
        ])->assertStatus(200);
        $this->assertTrue((bool)$closeRes->json('register.blind_count_masked'));
        $this->assertEqualsWithDelta(1300.0, (float)$closeRes->json('register.closing_cash'), 0.01);
        $this->assertNull($closeRes->json('register.expected_cash'));
        $this->assertNull($closeRes->json('register.difference'));
        $this->assertNull($closeRes->json('register.cash_sales'));
        $this->assertNull($closeRes->json('register.floats_in'));
        $this->assertNull($closeRes->json('register.payment_breakdown.0.amount'));
    }

    public function test_review_closed_shift_edit_echo_across_sale_purchase_and_returns_plus_add_payment_split_reg(): void
    {
        $closedReg = CashRegister::create([
            'reference_no' => 'CR-ECHO-CLOSED',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 5000.0,
            'expected_cash' => 5000.0,
            'status' => 'closed',
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
        ]);
        $openReg = CashRegister::create([
            'reference_no' => 'CR-ECHO-OPEN',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 2000.0,
            'expected_cash' => 2000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);
        $openReg2 = CashRegister::create([
            'reference_no' => 'CR-ECHO-OPEN-2',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 1000.0,
            'expected_cash' => 1000.0,
            'status' => 'open',
            'opened_at' => now()->subMinutes(30),
        ]);

        $supplier = Supplier::create(['name' => 'Echo Shift Vendor', 'phone' => '0966666666', 'status' => 'Active']);
        $customer = Customer::create(['name' => 'Echo Shift Client', 'phone' => '0977777777', 'credit_limit' => 500.0, 'status' => 'Active']);
        $product = Product::create([
            'code' => 'ECHO-ITEM-01',
            'name' => 'Echo Shift Item',
            'cost_price' => 100.0,
            'price' => 200.0,
            'stock_quantity' => 50.0,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 50.0,
        ]);

        // 1. Purchase paid in closedReg: echoing cash_register_id on notes edit MUST succeed (200), while changing cash MUST fail (422)
        $po = Purchase::create([
            'reference_no' => 'PO-ECHO-CLOSED-1',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedReg->id,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 600.0,
            'due_amount' => 400.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'payments_payload' => json_encode([[
                'id' => 1,
                'reference_no' => 'PAY-PO-ECHO-1',
                'payment_date' => '2026-11-18 10:00:00',
                'payment_type' => 'Cash',
                'amount' => 600.0,
                'cash_register_id' => $closedReg->id,
            ]]),
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 10.0,
            'base_quantity' => 10.0,
            'unit_cost' => 100.0,
            'base_unit_cost' => 100.0,
            'subtotal' => 1000.0,
        ]);

        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po->id}", [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Received',
            'grand_total' => 1000.0,
            'paid_amount' => 600.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $closedReg->id, // echoed original closed shift ID
            'notes' => 'Notes edited after shift closed',
            'payments' => [[
                'payment_type' => 'Cash',
                'amount' => 600.0,
                'cash_register_id' => $closedReg->id,
            ]],
        ])->assertStatus(200);
        $this->assertEquals('Notes edited after shift closed', $po->fresh()->notes);

        // Mutating closed-shift cash MUST still return 422
        $this->withToken($this->admin->api_token)->putJson("/api/purchases/{$po->id}", [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Received',
            'grand_total' => 1000.0,
            'paid_amount' => 500.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $closedReg->id,
            'payments' => [[
                'payment_type' => 'Cash',
                'amount' => 500.0,
                'cash_register_id' => $closedReg->id,
            ]],
        ])->assertStatus(422);

        // 2. Sale paid in closedReg: notes edit echoing closedReg MUST succeed (200), AND adding new Cash leg routes to openReg!
        $sale = Sale::create([
            'reference_no' => 'SL-ECHO-CLOSED-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedReg->id,
            'date' => '2026-11-18 11:00:00',
            'status' => 'Completed',
            'subtotal' => 600.0,
            'grand_total' => 600.0,
            'paid_amount' => 300.0,
            'due_amount' => 300.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 3.0,
            'base_quantity' => 3.0,
            'unit_price' => 200.0,
            'subtotal' => 600.0,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_register_id' => $closedReg->id,
            'reference_no' => 'PAY-SL-ECHO-1',
            'payment_date' => '2026-11-18 11:00:00',
            'payment_type' => 'Cash',
            'amount' => 300.0,
            'received_amount' => 300.0,
            'change_amount' => 0.0,
        ]);

        $this->withToken($this->admin->api_token)->putJson("/api/sales/{$sale->id}", [
            'notes' => 'Updated sale note safely',
            'cash_register_id' => $closedReg->id,
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 300.0, 'cash_register_id' => $closedReg->id],
            ],
        ])->assertStatus(200);
        $this->assertEquals('Updated sale note safely', $sale->fresh()->notes);

        // Mutating closed-shift cash on Sale MUST still return 422
        $this->withToken($this->admin->api_token)->putJson("/api/sales/{$sale->id}", [
            'cash_register_id' => $closedReg->id,
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 200.0, 'cash_register_id' => $closedReg->id],
            ],
        ])->assertStatus(422);

        // 3. SaleController::addPayment() MUST preserve per-leg payments.*.cash_register_id (previously stripped by validator!)
        $this->withToken($this->admin->api_token)->postJson("/api/sales/{$sale->id}/payments", [
            'payments' => [
                ['amount' => 100.0, 'payment_type' => 'Cash', 'cash_register_id' => $openReg->id],
                ['amount' => 150.0, 'payment_type' => 'Cash', 'cash_register_id' => $openReg2->id],
            ],
        ])->assertStatus(200);
        $addedLegs = SalePayment::where('sale_id', $sale->id)->orderBy('id')->get();
        $this->assertEquals($openReg->id, (int)$addedLegs[1]->cash_register_id);
        $this->assertEquals($openReg2->id, (int)$addedLegs[2]->cash_register_id);

        // 4. SaleController::deletePayment() MUST subtract open SaleReturn credits from credit_limit exposure
        // Sale grand_total = 600, paid = 550 (due 50). Customer credit_limit = 500.
        // Add an open SaleReturn credit of 200 -> deleting the 150 openReg2 payment makes sale due 200, net exposure 200 - 200 = 0 <= 500 (allowed).
        // Add another open Sale with due 400 so without return credit: 400 + 200 = 600 > 500 (would falsely fail 422), WITH return credit: (400 - 200) + 200 = 400 <= 500 (succeeds 200!).
        Sale::create([
            'reference_no' => 'SL-CR-OTHER-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'due_amount' => 400.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-OPEN-CREDIT-LIM',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:30:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 0.0,
            'due_amount' => 200.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Store Credit',
        ]);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/sales/{$sale->id}/payments/{$addedLegs[2]->id}")
            ->assertStatus(200);

        // 5. PurchaseReturn & SaleReturn paid in closedReg: notes edit echoing closedReg MUST succeed (200), while changing cash MUST fail (422)
        $prClosed = PurchaseReturn::create([
            'reference_no' => 'PR-ECHO-CLOSED-1',
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedReg->id,
            'date' => '2026-11-18 13:00:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 2.0,
                'base_quantity' => 2.0,
                'unit_cost' => 100.0,
                'subtotal' => 200.0,
            ]]),
            'payments_payload' => json_encode([[
                'id' => 1,
                'reference_no' => 'PAY-PR-ECHO-1',
                'payment_date' => '2026-11-18 13:00:00',
                'payment_type' => 'Cash',
                'amount' => 200.0,
                'cash_register_id' => $closedReg->id,
            ]]),
        ]);
        $this->withToken($this->admin->api_token)->putJson("/api/purchase-returns/{$prClosed->id}", [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 13:00:00',
            'status' => 'Completed',
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $closedReg->id,
            'notes' => 'PR note edited safely after shift close',
            'payments' => [[
                'payment_type' => 'Cash',
                'amount' => 200.0,
                'cash_register_id' => $closedReg->id,
            ]],
        ])->assertStatus(200);
        $this->assertEquals('PR note edited safely after shift close', $prClosed->fresh()->notes);

        $paidSaleForSr = Sale::create([
            'reference_no' => 'SL-ECHO-PAID-FOR-SR',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedReg->id,
            'date' => '2026-11-18 11:30:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $paidSaleForSr->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'unit_price' => 200.0,
            'subtotal' => 200.0,
        ]);

        $srClosed = SaleReturn::create([
            'reference_no' => 'SR-ECHO-CLOSED-1',
            'sale_id' => $paidSaleForSr->id,
            'sale_reference_no' => $paidSaleForSr->reference_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedReg->id,
            'date' => '2026-11-18 14:00:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'refund_tender' => 'Cash',
            'items_payload' => json_encode([[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'base_quantity' => 1.0,
                'unit_price' => 200.0,
                'subtotal' => 200.0,
            ]]),
            'payments_payload' => json_encode([[
                'id' => 1,
                'reference_no' => 'PAY-SR-ECHO-1',
                'payment_date' => '2026-11-18 14:00:00',
                'payment_type' => 'Cash',
                'amount' => 200.0,
                'cash_register_id' => $closedReg->id,
            ]]),
        ]);
        $this->withToken($this->admin->api_token)->putJson("/api/sale-returns/{$srClosed->id}", [
            'sale_id' => $paidSaleForSr->id,
            'sale_reference_no' => $paidSaleForSr->reference_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 14:00:00',
            'status' => 'Completed',
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'payment_type' => 'Cash',
            'refund_tender' => 'Cash',
            'cash_register_id' => $closedReg->id,
            'notes' => 'SR note edited safely after shift close',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'unit_price' => 200.0,
                'subtotal' => 200.0,
            ]],
            'payments' => [[
                'payment_type' => 'Cash',
                'amount' => 200.0,
                'cash_register_id' => $closedReg->id,
            ]],
        ])->assertStatus(200);
        $this->assertEquals('SR note edited safely after shift close', $srClosed->fresh()->notes);
    }

    public function test_review_transfer_update_date_normalization_pending_payment_422_and_adjustment_unit_type(): void
    {
        $baseUnit = ProductUnit::create(['name' => 'BaseSingle', 'status' => 'Active']);
        $packUnit = PurchaseUnit::create([
            'name' => 'PackTen',
            'base_unit_id' => $baseUnit->id,
            'base_unit_name' => $baseUnit->name,
            'status' => 'Active',
        ]);
        $product = Product::create([
            'code' => 'TRF-ADJ-REV-1',
            'name' => 'Transfer & Adj Product',
            'base_unit_id' => $baseUnit->id,
            'unit_name' => 'BaseSingle',
            'cost_price' => 50.0,
            'price' => 100.0,
            'stock_quantity' => 100.0,
        ]);
        ProductUnitLink::create([
            'product_id' => $product->id,
            'unit_id' => $baseUnit->id, // deliberate cross-table ID collision with base_unit_id!
            'unit_type' => 'sale',
            'unit_name' => 'PackTen',
            'conversion_rate' => 10.0,
            'is_default' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 100.0,
        ]);

        // 1. TransferController::store() MUST return 422 when status='Pending' with payments (no silent discard)
        $this->withToken($this->admin->api_token)->postJson('/api/transfers', [
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => '2026-11-19',
            'status' => 'Pending',
            'shipping_charge' => 500.0,
            'payments' => [['payment_type' => 'Cash', 'amount' => 500.0]],
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 2.0,
            ]],
        ])->assertStatus(422);

        // 2. TransferController::update() MUST persist normalized 19-char YYYY-MM-DD HH:MM:SS date
        $trfCreate = $this->withToken($this->admin->api_token)->postJson('/api/transfers', [
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => '2026-11-19',
            'status' => 'Pending',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 2.0,
            ]],
        ])->assertStatus(201);
        $trfId = (int)$trfCreate->json('data.id');

        $trfUpdate = $this->withToken($this->admin->api_token)->putJson("/api/transfers/{$trfId}", [
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => '2026-11-20',
            'status' => 'Pending',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 3.0,
            ]],
        ])->assertStatus(200);
        $this->assertMatchesRegularExpression('/^2026-11-20 \d{2}:\d{2}:\d{2}$/', (string)$trfUpdate->json('data.date'));

        // 3. AdjustmentController::store() MUST preserve items.*.unit_type='base' even when unit_id collides with a 10x sale link
        $adjRes = $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-20 09:00:00',
            'type' => 'Addition',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_id' => $baseUnit->id,
                'unit_name' => 'PackTen', // even with colliding link name/id, explicit unit_type='base' forces rate 1.0
                'unit_type' => 'base',
                'quantity' => 4.0,
                'type' => 'Addition',
                'unit_cost' => 50.0,
            ]],
        ])->assertStatus(201);
        $this->assertEqualsWithDelta(1.0, (float)$adjRes->json('data.items.0.conversion_rate'), 0.0001);
        $this->assertEqualsWithDelta(4.0, (float)$adjRes->json('data.items.0.base_quantity'), 0.0001);
    }

    public function test_review_quotation_convert_non_cash_zero_change_and_shift_attribution(): void
    {
        $openReg = CashRegister::create([
            'reference_no' => 'CR-QUOT-NONCASH',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 1000.0,
            'expected_cash' => 1000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);
        $product = Product::create([
            'code' => 'QUOT-NC-01',
            'name' => 'Quotation NonCash Product',
            'cost_price' => 400.0,
            'price' => 1000.0,
            'stock_quantity' => 10.0,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 10.0,
        ]);

        $quotRes = $this->withToken($this->admin->api_token)->postJson('/api/quotations', [
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-20',
            'status' => 'Sent',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 1000.0,
                'quantity' => 1.0,
                'subtotal' => 1000.0,
            ]],
        ])->assertStatus(201);
        $qId = (int)$quotRes->json('data.id');

        // 1. Non-cash overpayment exceeding grand_total MUST be rejected with 422
        $this->withToken($this->admin->api_token)->postJson("/api/quotations/{$qId}/convert-to-sale", [
            'payments' => [[
                'payment_type' => 'KBZPay',
                'amount' => 1000.0,
                'received_amount' => 1500.0,
            ]],
        ])->assertStatus(422);

        // 2. Split KBZPay (received_amount > amount, <= grand_total) + Cash MUST record change_amount=0 on KBZPay & attach open register ID to KBZPay leg
        $convRes = $this->withToken($this->admin->api_token)->postJson("/api/quotations/{$qId}/convert-to-sale", [
            'payments' => [
                [
                    'payment_type' => 'KBZPay',
                    'amount' => 400.0,
                    'received_amount' => 500.0, // bogus received_amount > amount on non-cash leg within grand_total
                ],
                [
                    'payment_type' => 'Cash',
                    'amount' => 700.0,
                    'received_amount' => 700.0,
                ],
            ],
        ])->assertStatus(201);
        $saleId = (int)$convRes->json('data.sale.id');
        $kbzLeg = SalePayment::where('sale_id', $saleId)->where('payment_type', 'KBZPay')->firstOrFail();
        $cashLeg = SalePayment::where('sale_id', $saleId)->where('payment_type', 'Cash')->firstOrFail();

        $this->assertEqualsWithDelta(400.0, (float)$kbzLeg->amount, 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$kbzLeg->received_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$kbzLeg->change_amount, 0.01);
        $this->assertEquals($openReg->id, (int)$kbzLeg->cash_register_id);

        $this->assertEqualsWithDelta(600.0, (float)$cashLeg->amount, 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$cashLeg->change_amount, 0.01);
        $this->assertEquals($openReg->id, (int)$cashLeg->cash_register_id);
    }

    public function test_review_product_margins_return_only_positive_margin_and_variant_unlayered_return_cogs(): void
    {
        // Parent cost = 100, Variant cost = 600 (6x higher!). Unlayered return of variant MUST use 600, NOT 100!
        $prod = Product::create([
            'code' => 'VAR-RET-MARGIN',
            'name' => 'Variant Return Margin Item',
            'cost_price' => 100.0,
            'price' => 1000.0,
            'has_variants' => true,
            'stock_quantity' => 20.0,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'VAR-RET-M-L',
            'name' => 'Large',
            'cost_price' => 600.0,
            'selling_price' => 1000.0,
            'is_active' => true,
        ]);

        // Period 2026-11-22 has ONLY a SaleReturn (no gross sales in this date window) for 1 unit @ 1000 (cost 600 => +40% margin!)
        SaleReturn::create([
            'reference_no' => 'SR-RETONLY-VAR-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-22 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([[
                'product_id' => $prod->id,
                'product_variant_id' => $variant->id,
                'product_code' => $variant->sku,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'base_quantity' => 1.0,
                'unit_price' => 1000.0,
                'subtotal' => 1000.0,
            ]]),
        ]);

        $marginsAll = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-11-22&end_date=2026-11-22')
            ->assertStatus(200);
        $row = collect($marginsAll->json('data'))->firstWhere('product_id', $prod->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(-1000.0, (float)$row['gross_revenue'], 0.01);
        $this->assertEqualsWithDelta(-600.0, (float)$row['cogs'], 0.01);
        $this->assertEqualsWithDelta(-400.0, (float)$row['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(40.0, (float)$row['gross_margin_pct'], 0.01);
        $this->assertEqualsWithDelta(66.67, (float)$row['markup_pct'], 0.01);

        // Must NOT appear under margin_health=negative, MUST appear under margin_health=high!
        $negFilter = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-11-22&end_date=2026-11-22&margin_health=negative')
            ->assertStatus(200);
        $this->assertNull(collect($negFilter->json('data'))->firstWhere('product_id', $prod->id));

        $highFilter = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-11-22&end_date=2026-11-22&margin_health=high')
            ->assertStatus(200);
        $this->assertNotNull(collect($highFilter->json('data'))->firstWhere('product_id', $prod->id));

        // P&L COGS on 2026-11-22 MUST reconcile identically to -600.0
        $pnl = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/profit-loss?start_date=2026-11-22&end_date=2026-11-22')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-600.0, (float)$pnl->json('data.cogs'), 0.01);
    }

    public function test_review_stock_ledger_variant_historical_cost_dashboard_numeric_wh_and_tax_return_discount(): void
    {
        // 1. Stock Ledger historical rollback with >50 products + variant cost_price != parent cost_price
        $varProd = Product::create([
            'code' => 'SL-VAR-HIST-01',
            'name' => 'Variant Ledger Hist',
            'category_name' => 'Electronics',
            'cost_price' => 100.0,
            'price' => 1000.0,
            'has_variants' => true,
            'stock_quantity' => 5.0,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $varProd->id,
            'sku' => 'SL-VAR-HIST-V1',
            'name' => 'Pro',
            'cost_price' => 500.0,
            'selling_price' => 1000.0,
            'is_active' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $varProd->id,
            'product_variant_id' => $variant->id,
            'quantity' => 5.0, // live balance after post-cutoff sale of 3 units (so closing on Nov 10 was 8 units @ 500 = 4000)
        ]);
        // Seed 52 zero-stock filler products so stockLedgerReport triggers the catalog (>50 products) SQL summary path
        for ($i = 1; $i <= 52; $i++) {
            Product::create([
                'code' => "SL-FILL-{$i}",
                'name' => "Filler {$i}",
                'cost_price' => 10.0,
                'price' => 20.0,
                'stock_quantity' => 0.0,
            ]);
        }
        $postSale = Sale::create([
            'reference_no' => 'SL-POST-VAR-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-20 10:00:00',
            'status' => 'Completed',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $postSale->id,
            'product_id' => $varProd->id,
            'product_variant_id' => $variant->id,
            'product_code' => $variant->sku,
            'product_name' => $varProd->name,
            'quantity' => 3.0,
            'base_quantity' => 3.0,
            'unit_price' => 1000.0,
            'subtotal' => 3000.0,
        ]);

        // Historical closing as of Nov 10 MUST be live (5 * 500 = 2500) + post-cutoff sale (3 * 500 = 1500) = 4000!
        $ledger = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_id={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-10")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(4000.0, (float)$ledger->json('summary.total_closing_cost'), 0.01);

        // 2. Dashboard numeric warehouse filter populates top_categories & excludes Cancelled expenses
        Expense::create([
            'reference_no' => 'EXP-CANCELLED-DASH',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Rent',
            'title' => 'Cancelled Rent Voucher',
            'amount' => 777.0,
            'paid_amount' => 777.0,
            'due_amount' => 0.0,
            'payment_status' => 'Cancelled',
            'payment_method' => 'Cash',
            'date' => '2026-11-20 11:00:00',
        ]);
        $dash = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dashboard-summary?warehouse={$this->wh1->id}&date_filter=custom&start_date=2026-11-20&end_date=2026-11-20")
            ->assertStatus(200);
        $this->assertNotEmpty($dash->json('data.top_categories'));
        $this->assertEquals('Electronics', $dash->json('data.top_categories.0.category_name'));
        $this->assertEqualsWithDelta(0.0, (float)$dash->json('data.total_expenses'), 0.01);

        // Cash Flow MUST also exclude Cancelled expense
        $cf = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->id}&start_date=2026-11-20&end_date=2026-11-20")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, (float)$cf->json('summary.operating_expenses_paid'), 0.01);

        // 3. Commercial Tax return with line-level tax + order discount MUST deduct prorated discount from returned taxable base
        SaleReturn::create([
            'reference_no' => 'SR-TAX-DISC-1',
            'customer_name' => 'Tax Client',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-21 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1050.0, // 1000 taxable + 50 line tax
            'discount' => 100.0,
            'tax_amount' => 0.0,
            'grand_total' => 950.0,
            'paid_amount' => 950.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([[
                'product_id' => $varProd->id,
                'quantity' => 1.0,
                'unit_price' => 1000.0,
                'tax' => 50.0,
                'subtotal' => 1050.0,
            ]]),
        ]);
        $taxRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/commercial-tax?start_date=2026-11-21&end_date=2026-11-21')
            ->assertStatus(200);
        // Returned taxable base = 1000 - 100 discount = 900 (NOT 1000!), so net taxable_sales_amount = -900.0
        $this->assertEqualsWithDelta(-900.0, (float)$taxRes->json('output_tax.taxable_sales_amount'), 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$taxRes->json('output_tax.returns_tax_refund'), 0.01);
        $this->assertEqualsWithDelta(-900.0, (float)$taxRes->json('sale_return_tax_records.0.taxable_amount'), 0.01);
    }
}
