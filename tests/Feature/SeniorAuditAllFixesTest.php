<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FifoInventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeniorAuditAllFixesTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $warehouse;
    private Supplier $supplier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->warehouse = Outlet::create([
            'name' => 'Central Audit Warehouse',
            'code' => 'WH-AUD-01',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Audit Global Suppliers',
            'phone' => '0912345678',
            'balance_due' => 0.0,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-AUDIT-ADM',
            'name' => 'Audit Admin',
            'email' => 'audit-admin@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'audit_admin_token_999',
        ]);
    }

    private function makeUserWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'permissions' => $permissions,
        ]);

        $user = User::create([
            'user_id' => 'USR-' . strtoupper(substr(md5($slug), 0, 6)),
            'name' => 'User ' . $slug,
            'email' => $slug . '@retailpos.test',
            'role' => $role->name,
            'role_id' => $role->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'tok_' . $slug . '_' . uniqid(),
        ]);
        $user->outlets()->sync([$this->warehouse->id]);
        return $user;
    }

    public function test_mixed_adjustment_update_does_not_double_deplete_and_preserves_affects_pnl(): void
    {
        $prodAdd = Product::create([
            'code' => 'MIX-ADD-01',
            'name' => 'Mixed Add Product',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'retail_price' => 150.0,
            'stock_quantity' => 5.0,
        ]);
        $prodSub = Product::create([
            'code' => 'MIX-SUB-02',
            'name' => 'Mixed Sub Product',
            'cost_price' => 80.0,
            'purchase_price' => 80.0,
            'retail_price' => 130.0,
            'stock_quantity' => 10.0,
        ]);

        StockBalance::create([
            'product_id' => $prodAdd->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5.0,
        ]);
        StockBalance::create([
            'product_id' => $prodSub->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10.0,
        ]);

        $fifo = app(FifoInventoryService::class);
        $fifo->recordBatch($this->warehouse->id, $prodAdd->id, null, 5.0, 100.0, 'purchase', 'PO-MIX-A', '2026-10-01 09:00:00');
        $subBatch = $fifo->recordBatch($this->warehouse->id, $prodSub->id, null, 10.0, 80.0, 'purchase', 'PO-MIX-B', '2026-10-01 09:00:00');

        // Store Mixed adjustment: +4 Add, -7 Sub (leaving only 3 in prodSub batch)
        $storeRes = $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 12:00:00',
            'type' => 'Mixed',
            'reason_code' => 'Audit Correction',
            'affects_pnl' => false, // explicit custom override
            'notes' => 'Initial mixed voucher',
            'items' => [
                [
                    'product_id' => $prodAdd->id,
                    'product_name' => $prodAdd->name,
                    'product_code' => $prodAdd->code,
                    'quantity' => 4,
                    'type' => 'Addition',
                    'unit_cost' => 100.0,
                ],
                [
                    'product_id' => $prodSub->id,
                    'product_name' => $prodSub->name,
                    'product_code' => $prodSub->code,
                    'quantity' => 7,
                    'type' => 'Subtraction',
                    'unit_cost' => 80.0,
                ],
            ],
        ])->assertStatus(201);

        $adjId = $storeRes->json('data.id');
        $this->assertEquals(3.0, (float)$subBatch->fresh()->remaining_quantity);

        // Update Mixed voucher keeping Subtraction at 7: previously false 422 / double-deplete!
        $updateRes = $this->withToken($this->admin->api_token)->putJson("/api/adjustments/{$adjId}", [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-05 12:00:00',
            'type' => 'Mixed',
            'reason_code' => 'Audit Correction',
            'notes' => 'Updated mixed voucher notes',
            'items' => [
                [
                    'product_id' => $prodAdd->id,
                    'product_name' => $prodAdd->name,
                    'product_code' => $prodAdd->code,
                    'quantity' => 4,
                    'type' => 'Addition',
                    'unit_cost' => 100.0,
                ],
                [
                    'product_id' => $prodSub->id,
                    'product_name' => $prodSub->name,
                    'product_code' => $prodSub->code,
                    'quantity' => 7,
                    'type' => 'Subtraction',
                    'unit_cost' => 80.0,
                ],
            ],
        ])->assertStatus(200);

        $this->assertEquals(3.0, (float)$subBatch->fresh()->remaining_quantity);
        $this->assertEquals(9.0, (float)$prodAdd->fresh()->stock_quantity);
        $this->assertEquals(3.0, (float)$prodSub->fresh()->stock_quantity);
        $this->assertFalse((bool)StockAdjustment::find($adjId)->affects_pnl);
    }

    public function test_unseeded_variable_product_subtraction_and_export_currency_setting(): void
    {
        Setting::create(['key' => 'default_currency', 'value' => 'USD']);

        // Unseeded single product (no stock_balances rows, only products.stock_quantity = 10)
        $legacySingle = Product::create([
            'code' => 'LEGACY-SINGLE',
            'name' => 'Legacy Unseeded Tea',
            'has_variants' => false,
            'cost_price' => 50.0,
            'retail_price' => 90.0,
            'stock_quantity' => 10.0,
        ]);

        $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 10:00:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [
                [
                    'product_id' => $legacySingle->id,
                    'product_name' => $legacySingle->name,
                    'product_code' => $legacySingle->code,
                    'quantity' => 3,
                    'type' => 'Subtraction',
                ],
            ],
        ])->assertStatus(201);
        $this->assertEquals(7.0, (float)$legacySingle->fresh()->stock_quantity);

        // Variable product with StockBalance rows across 2 variants: adjusting varA keeps varB intact
        $parent = Product::create([
            'code' => 'VAR-LEGACY',
            'name' => 'Legacy Var Shirt',
            'has_variants' => true,
            'cost_price' => 50.0,
            'retail_price' => 90.0,
            'stock_quantity' => 15.0,
        ]);
        $varA = ProductVariant::create([
            'product_id' => $parent->id,
            'name' => 'Size S',
            'sku' => 'VAR-LEGACY-S',
            'cost_price' => 50.0,
            'selling_price' => 90.0,
        ]);
        $varB = ProductVariant::create([
            'product_id' => $parent->id,
            'name' => 'Size M',
            'sku' => 'VAR-LEGACY-M',
            'cost_price' => 50.0,
            'selling_price' => 90.0,
        ]);
        StockBalance::create([
            'product_id' => $parent->id,
            'product_variant_id' => $varA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10.0,
        ]);
        StockBalance::create([
            'product_id' => $parent->id,
            'product_variant_id' => $varB->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5.0,
        ]);

        // Subtract 3 from Var S
        $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-06 10:05:00',
            'type' => 'Subtraction',
            'reason_code' => 'Damaged',
            'items' => [
                [
                    'product_id' => $parent->id,
                    'product_variant_id' => $varA->id,
                    'product_name' => $parent->name,
                    'product_code' => $varA->sku,
                    'quantity' => 3,
                    'type' => 'Subtraction',
                ],
            ],
        ])->assertStatus(201);

        $this->assertEquals(
            7.0,
            (float)StockBalance::where('product_variant_id', $varA->id)->value('quantity')
        );
        $this->assertEquals(
            5.0,
            (float)StockBalance::where('product_variant_id', $varB->id)->value('quantity')
        );
        $this->assertEquals(12.0, (float)$parent->fresh()->stock_quantity);

        $csvRes = $this->withToken($this->admin->api_token)->get('/api/adjustments/export');
        $csvRes->assertStatus(200);
        $csvContent = $csvRes->streamedContent();
        $this->assertStringContainsString('Total Estimated Value (USD)', $csvContent);

        // Reconciliation masking for cost-blind user
        $costBlind = $this->makeUserWithPermissions('reconcile-blind', ['report_stock.view']);
        $recRes = $this->withToken($costBlind->api_token)
            ->getJson('/api/reports/inventory-reconciliation')
            ->assertStatus(200);
        $this->assertTrue((bool)$recRes->json('cost_masked'));
        foreach ($recRes->json('data.sku_discrepancies') ?? [] as $disc) {
            $this->assertNull($disc['master_unit_cost'] ?? null);
            $this->assertNull($disc['master_valuation'] ?? null);
            $this->assertNull($disc['fifo_valuation'] ?? null);
            $this->assertNull($disc['effective_valuation'] ?? null);
            $this->assertNull($disc['valuation_variance'] ?? null);
        }
    }

    public function test_cash_register_change_not_double_subtracted_close_guard_and_z_register_masking(): void
    {
        $today = Carbon::today()->toDateString();
        $register = CashRegister::create([
            'reference_no' => 'REG-AUD-01',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'opening_cash' => 500.0,
            'status' => 'open',
            'opened_at' => $today . ' 08:00:00',
        ]);

        $product = Product::create([
            'code' => 'CASH-CHG-01',
            'name' => 'Coffee Beans',
            'cost_price' => 40.0,
            'retail_price' => 100.0,
            'stock_quantity' => 10.0,
        ]);
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10.0,
        ]);

        // Customer buys $100 item, tenders $150 cash, change = $50 -> net applied cash = $100
        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $register->id,
            'customer_name' => 'Walk-in Customer',
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'paid_amount' => 100.0,
            'received_amount' => 150.0,
            'change_amount' => 50.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 100.0,
                    'received_amount' => 150.0,
                    'change_amount' => 50.0,
                ],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 1,
                    'unit_price' => 100.0,
                    'subtotal' => 100.0,
                ],
            ],
        ])->assertStatus(201);

        // CashRegister details & Register Report must show 100.0 cash sales & 600.0 expected (not 50 & 550)
        $detRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/cash-register/details/{$register->id}")
            ->assertStatus(200);
        $this->assertEquals(100.0, (float)$detRes->json('register.cash_sales'));
        $this->assertEquals(600.0, (float)$detRes->json('register.expected_cash'));

        $regRep = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?force_refresh=1')
            ->assertStatus(200);
        $repRow = collect($regRep->json('data'))->firstWhere('id', $register->id);
        $this->assertEquals(100.0, (float)$repRow['cash_sales']);
        $this->assertEquals(600.0, (float)$repRow['expected_cash']);

        // Close register -> 200; close same register again -> 422
        $this->withToken($this->admin->api_token)
            ->postJson("/api/cash-register/close/{$register->id}", ['closing_cash' => 600.0])
            ->assertStatus(200);
        $this->withToken($this->admin->api_token)
            ->postJson("/api/cash-register/close/{$register->id}", ['closing_cash' => 600.0])
            ->assertStatus(422);

        // Closed register cannot be attached to a new sale
        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'cash_register_id' => $register->id,
            'customer_name' => 'Walk-in Customer',
            'date' => $today . ' 11:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'payment_type' => 'Cash',
            'paid_amount' => 100.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 1,
                    'unit_price' => 100.0,
                    'subtotal' => 100.0,
                ],
            ],
        ])->assertStatus(422);

        // Cost-blind viewer must see masked z-report & register report
        $blindReportUser = $this->makeUserWithPermissions('blind-z-reg', [
            'report_z_report.view',
            'report_register.view',
        ]);
        $zMasked = $this->withToken($blindReportUser->api_token)
            ->getJson("/api/reports/z-report?warehouse={$this->warehouse->id}&date={$today}&force_refresh=1")
            ->assertStatus(200);
        $this->assertTrue((bool)$zMasked->json('cost_masked'));
        $this->assertEquals(0.0, (float)$zMasked->json('data.0.cogs'));
        $this->assertEquals(0.0, (float)$zMasked->json('data.0.net_profit'));
        $this->assertEquals(0.0, (float)$zMasked->json('data.0.expected_cash'));

        $rMasked = $this->withToken($blindReportUser->api_token)
            ->getJson('/api/reports/register?force_refresh=1')
            ->assertStatus(200);
        $this->assertTrue((bool)$rMasked->json('cost_masked'));
        $this->assertEquals(0.0, (float)$rMasked->json('summary.total_expected_cash'));
        $this->assertEquals(0.0, (float)$rMasked->json('data.0.expected_cash'));
    }

    public function test_quotation_expired_filter_split_allocation_and_sale_rbac_credit_guard(): void
    {
        $product = Product::create([
            'code' => 'QUO-AUD-01',
            'name' => 'Espresso Machine',
            'cost_price' => 50.0,
            'retail_price' => 100.0,
            'stock_quantity' => 20.0,
        ]);
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20.0,
        ]);

        $customer = Customer::create([
            'name' => 'VIP Cafe',
            'phone' => '0955566677',
            'balance_due' => 0.0,
            'credit_limit' => 500.0,
        ]);

        Quotation::create([
            'reference_no' => 'QT-EXP-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => Carbon::yesterday()->subDays(2)->toDateTimeString(),
            'expiry_date' => Carbon::yesterday()->toDateString(),
            'status' => 'Sent',
            'grand_total' => 100.0,
        ]);

        // Expired status filter includes past-expiry Sent quote; Sent filter excludes it
        $expRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/quotations?status=Expired')
            ->assertStatus(200);
        $this->assertContains('QT-EXP-001', array_column($expRes->json('data'), 'reference_no'));

        $sentRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/quotations?status=Sent')
            ->assertStatus(200);
        $this->assertNotContains('QT-EXP-001', array_column($sentRes->json('data'), 'reference_no'));

        // Active quote converted with split payment (Cash=200 + KBZPay=100 for 150 total -> KBZPay=100, Cash=50)
        $activeQuo = Quotation::create([
            'reference_no' => 'QT-SPL-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => Carbon::today()->toDateTimeString(),
            'expiry_date' => Carbon::tomorrow()->toDateString(),
            'status' => 'Sent',
            'subtotal' => 150.0,
            'grand_total' => 150.0,
        ]);
        $activeQuo->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'quantity' => 1.5,
            'base_quantity' => 1.5,
            'unit_price' => 100.0,
            'subtotal' => 150.0,
        ]);

        // Sales-only user WITHOUT quotations.convert_sale blocked via POST /sales with quotation_id
        $salesOnlyUser = $this->makeUserWithPermissions('sales-no-convert', ['sales.create', 'sales.view']);
        $this->withToken($salesOnlyUser->api_token)->postJson('/api/sales', [
            'quotation_id' => $activeQuo->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'date' => Carbon::today()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 150.0,
            'grand_total' => 150.0,
            'payment_type' => 'Cash',
            'paid_amount' => 150.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 1.5,
                    'unit_price' => 100.0,
                    'subtotal' => 150.0,
                ],
            ],
        ])->assertStatus(403);

        // Walk-in credit guard on POST /sales (unpaid with empty customer_id -> 422)
        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_name' => 'Walk-in Customer',
            'date' => Carbon::today()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'payment_type' => 'Credit',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 1,
                    'unit_price' => 100.0,
                    'subtotal' => 100.0,
                ],
            ],
        ])->assertStatus(422);

        // Convert quote via /convert-to-sale with split Cash(200) + KBZPay(100)
        $convRes = $this->withToken($this->admin->api_token)
            ->postJson("/api/quotations/{$activeQuo->id}/convert-to-sale", [
                'payments' => [
                    ['payment_type' => 'Cash', 'amount' => 200.0],
                    ['payment_type' => 'KBZPay', 'amount' => 100.0],
                ],
            ])->assertStatus(201);

        $paymentsByType = collect($convRes->json('data.sale.payments'))->keyBy('payment_type');
        $this->assertEquals(100.0, (float)$paymentsByType['KBZPay']['amount']);
        $this->assertEquals(50.0, (float)$paymentsByType['Cash']['amount']);
        $this->assertEquals(200.0, (float)$paymentsByType['Cash']['received_amount']);
        $this->assertEquals(150.0, (float)$paymentsByType['Cash']['change_amount']);
    }

    public function test_purchase_opening_stock_reject_completed_destroy_supplier_masking_and_z_report_zero_sale_day(): void
    {
        $product = Product::create([
            'code' => 'PO-AUD-01',
            'name' => 'PO Product',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'retail_price' => 160.0,
            'stock_quantity' => 0.0,
        ]);

        // Manual 'Opening Stock' rejected
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'payment_type' => 'Opening Stock',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 2,
                    'unit_cost' => 100.0,
                    'subtotal' => 200.0,
                ],
            ],
        ])->assertStatus(422);

        // Legacy 'Completed' PO increments AP and deleting it decrements supplier.balance_due
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Received',
            'payment_type' => 'Credit',
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 5,
                    'unit_cost' => 100.0,
                    'subtotal' => 500.0,
                ],
            ],
        ])->assertStatus(201);
        $poId = $poRes->json('data.id');
        Purchase::where('id', $poId)->update(['status' => 'Completed']);

        $this->assertEquals(500.0, (float)$this->supplier->fresh()->balance_due);

        // Cost-blind user calling /api/suppliers must see masked balance_due
        $blindSup = $this->makeUserWithPermissions('blind-sup', ['purchases.view', 'suppliers.view']);
        $supIdx = $this->withToken($blindSup->api_token)->getJson('/api/suppliers')->assertStatus(200);
        $supRow = collect($supIdx->json('data'))->firstWhere('id', $this->supplier->id);
        $this->assertNull($supRow['balance_due']);
        $this->assertTrue((bool)$supRow['cost_masked']);

        // Delete Completed PO -> restores AP balance_due to 0
        $this->withToken($this->admin->api_token)->deleteJson("/api/purchases/{$poId}")->assertStatus(200);
        $this->assertEquals(0.0, (float)$this->supplier->fresh()->balance_due);

        // Zero-sale day with Cash PO appears in Z-Report
        $zeroSaleDate = '2026-10-12';
        $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => $zeroSaleDate . ' 11:00:00',
            'status' => 'Received',
            'payment_type' => 'Cash',
            'paid_amount' => 300.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 3,
                    'unit_cost' => 100.0,
                    'subtotal' => 300.0,
                ],
            ],
        ])->assertStatus(201);

        $zZero = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/z-report?warehouse={$this->warehouse->id}&date={$zeroSaleDate}&force_refresh=1")
            ->assertStatus(200);
        $this->assertNotEmpty($zZero->json('data'));
        $this->assertEquals(300.0, (float)$zZero->json('data.0.cash_purchases_paid'));
    }

    public function test_purchase_return_credit_note_cost_blind_proration_payment_release_and_stock_ledger_isolation(): void
    {
        $product = Product::create([
            'code' => 'PR-AUD-01',
            'name' => 'Audit Grinder',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'retail_price' => 180.0,
            'stock_quantity' => 0.0,
        ]);

        // Purchase 5 @ 100 = 500 subtotal, PO discount 50, recoverable tax 10% (45) -> grand_total = 495 (unpaid)
        $poRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'reference_no' => 'PO-PR-AUD',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-14 09:00:00',
            'status' => 'Received',
            'payment_type' => 'Credit',
            'paid_amount' => 0.0,
            'discount' => 50.0,
            'tax_rate' => 10.0,
            'is_tax_recoverable' => true,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 5,
                    'unit_cost' => 100.0,
                    'subtotal' => 500.0,
                ],
            ],
        ])->assertStatus(201);
        $poId = $poRes->json('data.id');

        // Cost-blind clerk returns 2 units without sending cost/tax -> inherits PO prorated tax/discount & defaults payment_type = Credit Note
        $blindPrUser = $this->makeUserWithPermissions('blind-pr', [
            'purchase_returns.create',
            'purchase_returns.view',
            'purchases.view',
            'report_purchase_return.view',
        ]);
        $prRes = $this->withToken($blindPrUser->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-PR-AUD',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-15 10:00:00',
            'status' => 'Completed',
            'grand_total' => 0.0,
            'paid_amount' => 0.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 2,
                ],
            ],
        ])->assertStatus(201);

        $prId = $prRes->json('data.id');
        $prRow = PurchaseReturn::findOrFail($prId);
        $this->assertSame('Credit Note', $prRow->payment_type);
        $this->assertTrue((bool)$prRow->is_tax_recoverable);
        // 2/5 of 495 = 198.0
        $this->assertEqualsWithDelta(198.0, (float)$prRow->grand_total, 0.02);
        $this->assertEqualsWithDelta(297.0, (float)Purchase::find($poId)->due_amount, 0.02);

        // Report endpoint masks percentage for cost-blind user, shows Credit Note % + new fields for Admin
        $blindRpt = $this->withToken($blindPrUser->api_token)
            ->getJson('/api/reports/purchase-returns?force_refresh=1')
            ->assertStatus(200);
        $this->assertTrue((bool)$blindRpt->json('cost_masked'));
        foreach ($blindRpt->json('by_refund_tender') as $t) {
            $this->assertEquals(0.0, (float)$t['percentage']);
        }
        foreach ($blindRpt->json('by_product') as $p) {
            $this->assertEquals(0.0, (float)$p['percentage']);
        }

        $admRpt = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchase-returns?force_refresh=1')
            ->assertStatus(200);
        $this->assertFalse((bool)$admRpt->json('cost_masked'));
        $this->assertEqualsWithDelta(100.0, (float)$admRpt->json('by_refund_tender.0.percentage'), 0.01);
        $this->assertTrue((bool)$admRpt->json('data.0.is_tax_recoverable'));
        $this->assertEqualsWithDelta(198.0, (float)$admRpt->json('data.0.allocated_credit_amount'), 0.02);

        // Cash refund via addPayment releases Credit Note allocation (PO due back to 495), deletePayment un-releases without doubling
        $payRes = $this->withToken($this->admin->api_token)
            ->postJson("/api/purchase-returns/{$prId}/payments", [
                'amount' => 198.0,
                'payment_type' => 'Cash',
                'date' => '2026-10-16 11:00:00',
            ])->assertStatus(200);
        $this->assertEqualsWithDelta(495.0, (float)Purchase::find($poId)->due_amount, 0.02);
        $payId = $payRes->json('data.payments.0.id');

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/purchase-returns/{$prId}/payments/{$payId}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(297.0, (float)Purchase::find($poId)->due_amount, 0.02);
        $this->assertEquals(1, PurchaseReturnAllocation::where('purchase_return_id', $prId)->whereNull('released_at')->count());

        // Check AP Aging as_of_date = 2026-10-16 returns exact 297.0 (not double-counted)
        $apRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/ap-aging?as_of_date=2026-10-16&force_refresh=1')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(297.0, (float)$apRes->json('summary.total_payable'), 0.02);

        // Excess/unallocated second return destroy MUST NOT revert first return's allocation on linked PO
        $extraPrRes = $this->withToken($this->admin->api_token)->postJson('/api/purchase-returns', [
            'purchase_reference_no' => 'PO-PR-AUD',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-16 12:00:00',
            'status' => 'Completed',
            'grand_total' => 297.0,
            'paid_amount' => 297.0, // cash refund: 0 allocation created
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 3,
                    'unit_cost' => 100.0,
                    'subtotal' => 300.0,
                ],
            ],
        ])->assertStatus(201);
        $extraPrId = $extraPrRes->json('data.id');
        // Deleting cash-settled return must NOT wipe out PR#1's 198.0 allocation (PO due stays 297.0)
        $this->withToken($this->admin->api_token)->deleteJson("/api/purchase-returns/{$extraPrId}")->assertStatus(200);
        $this->assertEqualsWithDelta(297.0, (float)Purchase::find($poId)->due_amount, 0.02);
        $this->assertEqualsWithDelta(198.0, (float)Purchase::find($poId)->returned_credit_amount, 0.02);

        // Stock Ledger historical cutoff ignores equal post-period in/out batches (3 pcs left at 2026-10-16)
        $postBatchRes = $this->withToken($this->admin->api_token)->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'date' => '2026-10-20 09:00:00',
            'status' => 'Received',
            'payment_type' => 'Cash',
            'paid_amount' => 5000.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 5,
                    'unit_cost' => 1000.0,
                    'subtotal' => 5000.0,
                ],
            ],
        ])->assertStatus(201);
        $this->assertNotNull($postBatchRes->json('data.id'));

        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'warehouse_name' => $this->warehouse->name,
            'customer_name' => 'Walk-in Customer',
            'date' => '2026-10-21 10:00:00',
            'status' => 'Completed',
            'subtotal' => 6000.0,
            'grand_total' => 6000.0,
            'payment_type' => 'Cash',
            'paid_amount' => 6000.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => 5,
                    'unit_price' => 1200.0,
                    'subtotal' => 6000.0,
                ],
            ],
        ])->assertStatus(201);

        $ledgerRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/stock-ledger?start_date=2026-10-14&end_date=2026-10-16&force_refresh=1')
            ->assertStatus(200);
        $ledgerProd = collect($ledgerRes->json('data'))->firstWhere('product_id', $product->id);
        $this->assertEquals(3.0, (float)$ledgerProd['closing_stock']);
        // 3 pcs at master cost 100 = 300 (NOT post-period 1000/pc FIFO batch!)
        $this->assertLessThan(500.0, (float)$ledgerProd['closing_cost_value']);
    }
}
