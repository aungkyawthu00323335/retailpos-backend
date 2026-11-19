<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Processing;
use App\Models\ProcessingItemCostLayer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ComprehensiveAccountingAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $wh1;
    private Outlet $wh2;
    private Outlet $wh3;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->wh1 = Outlet::create([
            'name' => 'Regression WH1',
            'code' => 'REG-WH1',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->wh2 = Outlet::create([
            'name' => 'Regression WH2',
            'code' => 'REG-WH2',
            'is_warehouse' => false,
            'is_active' => true,
        ]);

        $this->wh3 = Outlet::create([
            'name' => 'Regression WH3',
            'code' => 'REG-WH3',
            'is_warehouse' => false,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-REG-ADM',
            'name' => 'Regression Super Admin',
            'email' => 'reg-admin@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'reg_admin_token_' . uniqid(),
        ]);
    }

    private function makeScopedUser(string $slug, array $permissions, array $outletIds = []): User
    {
        $role = Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'permissions' => $permissions,
            'status' => 'active',
        ]);

        $user = User::create([
            'user_id' => 'USR-' . strtoupper(substr(md5($slug . uniqid()), 0, 6)),
            'name' => 'User ' . $slug,
            'email' => $slug . uniqid() . '@retailpos.test',
            'role' => $role->name,
            'role_id' => $role->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'tok_' . $slug . '_' . uniqid(),
        ]);

        if (!empty($outletIds)) {
            $user->outlets()->sync($outletIds);
        }

        return $user;
    }

    private function createProduct(string $code, string $name, float $cost = 1000.0, float $price = 1500.0): Product
    {
        return Product::create([
            'name' => $name,
            'code' => $code,
            'barcode' => 'BC-' . $code,
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => $cost,
            'cost_price' => $cost,
            'product_cost' => $cost,
            'selling_price' => $price,
            'price' => $price,
            'quantity' => 0,
            'stock_quantity' => 0,
            'alert_quantity' => 5,
        ]);
    }

    public function test_processing_fifo_cost_depletion_rollback_guard_outlet_isolation_and_csv_export(): void
    {
        $rawProd = $this->createProduct('PROC-RAW-1', 'Bulk Sugar Bag', 1000.0, 1500.0);
        $rawProd->update(['stock_quantity' => 10, 'quantity' => 10]);
        $outProd = $this->createProduct('PROC-OUT-1', 'Retail Sugar Pack', 0.0, 1200.0);

        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $rawProd->id,
            'quantity' => 10.0,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $outProd->id,
            'quantity' => 0.0,
        ]);

        // Two chronological FIFO batches at distinct costs: 4 @ 1,000 + 6 @ 2,000
        $batch1 = InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $rawProd->id,
            'initial_quantity' => 4.0,
            'remaining_quantity' => 4.0,
            'unit_cost' => 1000.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-PROC-01',
            'received_date' => Carbon::now()->subDays(5)->toDateTimeString(),
            'status' => 'active',
        ]);
        $batch2 = InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $rawProd->id,
            'initial_quantity' => 6.0,
            'remaining_quantity' => 6.0,
            'unit_cost' => 2000.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-PROC-02',
            'received_date' => Carbon::now()->subDays(3)->toDateTimeString(),
            'status' => 'active',
        ]);

        // Spoofed client unit_cost (99999) must be overridden by exact FIFO layers:
        // (4 * 1000 + 2 * 2000) = 8000 input + 1000 additional = 9000 total / 10 output = 900 unit cost
        $createRes = $this->withToken($this->admin->api_token)->postJson('/api/processings', [
            'reference_no' => 'PROC-FIFO-001',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'output_product_id' => $outProd->id,
            'output_product_code' => $outProd->code,
            'output_product_name' => $outProd->name,
            'output_unit_name' => 'Pack',
            'output_conversion_rate' => 1.0,
            'output_quantity' => 10.0,
            'output_unit_cost' => 99999.0,
            'additional_cost' => 1000.0,
            'status' => 'completed',
            'notes' => '=HYPERLINK("http://evil")',
            'items' => [
                [
                    'product_id' => $rawProd->id,
                    'product_code' => $rawProd->code,
                    'product_name' => $rawProd->name,
                    'unit_name' => 'Kg',
                    'conversion_rate' => 1.0,
                    'quantity' => 6.0,
                    'unit_cost' => 99999.0,
                    'subtotal_cost' => 599994.0,
                ],
            ],
        ]);

        $createRes->assertStatus(201);
        $procId = (int)$createRes->json('data.id');
        $this->assertEquals(8000.0, (float)$createRes->json('data.total_input_cost'));
        $this->assertEquals(9000.0, (float)$createRes->json('data.total_cost'));
        $this->assertEquals(900.0, (float)$createRes->json('data.output_unit_cost'));

        // Batch 1 exhausted (0), Batch 2 has 4 remaining
        $this->assertEquals(0.0, (float)$batch1->fresh()->remaining_quantity);
        $this->assertEquals(4.0, (float)$batch2->fresh()->remaining_quantity);
        $this->assertEquals(2, ProcessingItemCostLayer::where('processing_id', $procId)->count());

        $outBatch = InventoryBatch::where('source_type', 'processing')
            ->where('source_reference', 'PROC-FIFO-001')
            ->first();
        $this->assertNotNull($outBatch);
        $this->assertEquals(900.0, (float)$outBatch->unit_cost);
        $this->assertEquals(10.0, (float)$outBatch->remaining_quantity);

        // /api/processings/export returns UTF-8 BOM + neutralized formula note
        $exportRes = $this->withToken($this->admin->api_token)->get('/api/processings/export');
        $exportRes->assertStatus(200);
        $csvContent = $exportRes->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);
        $this->assertStringContainsString('PROC-FIFO-001', $csvContent);
        $this->assertStringContainsString("'=HYPERLINK", $csvContent);

        // Outlet isolation: WH2-only user gets 403 on WH1 processing & sees 0 WH1 records
        $wh2User = $this->makeScopedUser('proc_wh2', [
            'processings.view',
            'processings.create',
            'processings.delete',
            'processings.export',
        ], [$this->wh2->id]);

        $this->withToken($wh2User->api_token)->getJson("/api/processings/{$procId}")->assertStatus(403);
        $this->withToken($wh2User->api_token)->deleteJson("/api/processings/{$procId}")->assertStatus(403);
        $this->assertCount(0, $this->withToken($wh2User->api_token)->getJson('/api/processings')->json('data'));

        // Consuming 1 unit of finished output batch must block deletion (HTTP 422)
        $outBatch->update(['remaining_quantity' => 9.0]);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/processings/{$procId}")
            ->assertStatus(422);

        // Restoring finished batch remaining_quantity allows atomic FIFO rollback
        $outBatch->update(['remaining_quantity' => 10.0]);
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/processings/{$procId}")
            ->assertStatus(200);

        $this->assertEquals(4.0, (float)$batch1->fresh()->remaining_quantity);
        $this->assertEquals(6.0, (float)$batch2->fresh()->remaining_quantity);
        $this->assertEquals(0, ProcessingItemCostLayer::where('processing_id', $procId)->count());
    }

    public function test_sale_non_posted_statuses_block_cash_and_kpis_plus_void_guard_and_idempotency_rbac(): void
    {
        $prod = $this->createProduct('SL-REG-01', 'Sale Audit Item', 5000.0, 8000.0);
        $prod->update(['stock_quantity' => 20, 'quantity' => 20]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $prod->id, 'quantity' => 20.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $prod->id,
            'initial_quantity' => 20.0,
            'remaining_quantity' => 20.0,
            'unit_cost' => 5000.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-SL-REG',
            'received_date' => Carbon::now()->subDays(2)->toDateTimeString(),
            'status' => 'active',
        ]);

        $customer = Customer::create(['name' => 'Walk-in Audit', 'phone' => '0910001000', 'status' => 'Active']);

        $staff = $this->makeScopedUser('sale_staff_wh1', [
            'sales.view',
            'sales.create',
            'sales.pay',
            'sales.void',
            'sales.export',
        ], [$this->wh1->id]); // No cost permission (products.view_cost / settings.cost absent)

        $register = CashRegister::create([
            'reference_no' => 'REG-SL-01',
            'register_name' => 'Main Drawer',
            'user_id' => $staff->id,
            'cashier_id' => $staff->id,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 10000.0,
            'expected_cash' => 10000.0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. Draft and Pending sales must remain strictly unposted even if paid_amount / payments are sent
        foreach (['Draft', 'Pending'] as $idx => $status) {
            $res = $this->withToken($staff->api_token)->postJson('/api/sales', [
                'reference_no' => 'SL-NONPOST-' . $idx,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => Carbon::now()->toDateTimeString(),
                'status' => $status,
                'subtotal' => 8000.0,
                'grand_total' => 8000.0,
                'paid_amount' => 8000.0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'items' => [[
                    'product_id' => $prod->id,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'quantity' => 1,
                    'unit_price' => 8000.0,
                    'subtotal' => 8000.0,
                ]],
                'payments' => [[
                    'amount' => 8000.0,
                    'payment_type' => 'Cash',
                    'payment_date' => Carbon::now()->toDateTimeString(),
                ]],
            ]);
            $res->assertStatus(201);
            $this->assertEquals(0.0, (float)$res->json('data.paid_amount'));
            $this->assertEquals(8000.0, (float)$res->json('data.due_amount'));
            $this->assertEquals('Unpaid', $res->json('data.payment_status'));
            $this->assertEquals(0, SalePayment::where('sale_id', $res->json('data.id'))->count());
        }
        $this->assertEquals(10000.0, (float)$register->fresh()->expected_cash);

        // 2. Completed sale with idempotency_key: initial + replay must BOTH mask cost fields for non-cost user
        $idemPayload = [
            'reference_no' => 'SL-COMP-IDEM-1',
            'idempotency_key' => 'idem-key-sale-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 8000.0,
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1,
                'unit_price' => 8000.0,
                'subtotal' => 8000.0,
            ]],
            'payments' => [[
                'amount' => 8000.0,
                'payment_type' => 'Cash',
                'payment_date' => Carbon::now()->toDateTimeString(),
            ]],
        ];

        $firstComp = $this->withToken($staff->api_token)->postJson('/api/sales', $idemPayload);
        $firstComp->assertStatus(201);
        $saleId = (int)$firstComp->json('data.id');
        $this->assertArrayNotHasKey('total_cogs', $firstComp->json('data'));
        $this->assertArrayNotHasKey('base_unit_cost', $firstComp->json('data.items.0'));

        $replayComp = $this->withToken($staff->api_token)->postJson('/api/sales', $idemPayload);
        $replayComp->assertStatus(200);
        $this->assertEquals($saleId, (int)$replayComp->json('data.id'));
        $this->assertArrayNotHasKey('total_cogs', $replayComp->json('data'));
        $this->assertArrayNotHasKey('base_unit_cost', $replayComp->json('data.items.0'));

        $this->assertEquals(18000.0, (float)$register->fresh()->expected_cash);

        // Summary KPI on GET /api/sales must count ONLY the 1 Completed sale (8000), excluding Draft & Pending
        $listRes = $this->withToken($staff->api_token)->getJson('/api/sales');
        $listRes->assertStatus(200);
        $this->assertEquals(8000.0, (float)$listRes->json('summary.total_grand_total'));
        $this->assertEquals(8000.0, (float)$listRes->json('summary.total_paid'));
        $this->assertEquals(0.0, (float)$listRes->json('summary.total_due'));

        // Cross-outlet isolation: WH2 user cannot access WH1 sale
        $wh2User = $this->makeScopedUser('sale_staff_wh2', ['sales.view', 'sales.void'], [$this->wh2->id]);
        $this->withToken($wh2User->api_token)->getJson("/api/sales/{$saleId}")->assertStatus(403);
        $this->withToken($wh2User->api_token)->postJson("/api/sales/{$saleId}/void", ['reason' => 'Audit cross-outlet check'])->assertStatus(403);

        // Closing original CashRegister blocks voiding the cash sale (HTTP 422), reopening/open register allows voiding
        $register->update(['status' => 'closed', 'closed_at' => now()]);
        $this->withToken($staff->api_token)->postJson("/api/sales/{$saleId}/void", ['reason' => 'Closed shift check'])->assertStatus(422);

        $register->update(['status' => 'open', 'closed_at' => null]);
        $this->withToken($staff->api_token)->postJson("/api/sales/{$saleId}/void", ['reason' => 'Customer cancelled order'])->assertStatus(200);
        $this->assertEquals(10000.0, (float)$register->fresh()->expected_cash);
        $this->assertEquals(0, SalePayment::where('sale_id', $saleId)->count());
    }

    public function test_sale_return_price_cap_shift_isolation_pending_unposted_and_split_export(): void
    {
        $prod = $this->createProduct('SR-REG-01', 'Return Audit Goods', 4000.0, 8500.0);
        $prod->update(['stock_quantity' => 10, 'quantity' => 10]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $prod->id, 'quantity' => 10.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $prod->id,
            'initial_quantity' => 12.0,
            'remaining_quantity' => 10.0,
            'unit_cost' => 4000.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-SR-01',
            'received_date' => Carbon::now()->subDays(2)->toDateTimeString(),
            'status' => 'active',
        ]);

        $customer = Customer::create(['name' => 'Daw Mya Audit', 'phone' => '0920002000', 'status' => 'Active']);
        $sale = Sale::create([
            'reference_no' => 'SL-SR-BASE-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->subHour()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 42500.0,
            'grand_total' => 42500.0,
            'paid_amount' => 42500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $prod->id,
            'product_code' => $prod->code,
            'product_name' => $prod->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 5.0,
            'base_quantity' => 5.0,
            'unit_price' => 8500.0,
            'base_unit_price' => 8500.0,
            'base_unit_cost' => 4000.0,
            'total_cogs' => 20000.0,
            'subtotal' => 42500.0,
        ]);

        $cashierA = $this->makeScopedUser('sr_cashier_a', ['sale_returns.view', 'sale_returns.create', 'sale_returns.pay', 'sale_returns.export'], [$this->wh1->id]);
        $cashierB = $this->makeScopedUser('sr_cashier_b', ['sale_returns.view', 'sale_returns.create', 'sale_returns.pay'], [$this->wh1->id]);

        $regA = CashRegister::create([
            'reference_no' => 'REG-SR-A',
            'register_name' => 'Counter A',
            'user_id' => $cashierA->id,
            'cashier_id' => $cashierA->id,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 50000.0,
            'expected_cash' => 50000.0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1a. Cashier B explicitly targeting coworker register -> rejected 422, Cashier A untouched
        $this->withToken($cashierB->api_token)->postJson('/api/sale-returns', [
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $regA->id,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'payment_type' => 'Cash',
            'paid_amount' => 8500.0,
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'unit_price' => 8500.0,
                'subtotal' => 8500.0,
            ]],
        ])->assertStatus(422);
        $this->assertEquals(50000.0, (float)$regA->fresh()->expected_cash);

        // 1b. Cashier B omitting cash_register_id -> must NOT hijack Cashier A's open register (cash_register_id=null, regA stays 50000)
        $noRegRes = $this->withToken($cashierB->api_token)->postJson('/api/sale-returns', [
            'reference_no' => 'SR-B-NOREG',
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'payment_type' => 'Cash',
            'paid_amount' => 8500.0,
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'unit_price' => 8500.0,
                'subtotal' => 8500.0,
            ]],
        ]);
        $noRegRes->assertStatus(201);
        $this->assertNull($noRegRes->json('data.cash_register_id'));
        $this->assertEquals(50000.0, (float)$regA->fresh()->expected_cash);

        // 2. Pending sale return must stay unposted (paid_amount = 0, register untouched)
        $pendRes = $this->withToken($cashierA->api_token)->postJson('/api/sale-returns', [
            'reference_no' => 'SR-PEND-1',
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Pending',
            'payment_type' => 'Cash',
            'paid_amount' => 8500.0,
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'unit_price' => 8500.0,
                'subtotal' => 8500.0,
            ]],
        ]);
        $pendRes->assertStatus(201);
        $this->assertEquals(0.0, (float)$pendRes->json('data.paid_amount'));
        $this->assertEquals('Unpaid', $pendRes->json('data.payment_status'));
        $this->assertEquals(50000.0, (float)$regA->fresh()->expected_cash);

        // 3. Inflated unit_price (99000 > original 8500) must be blocked or capped cleanly (server never refunds 99000)
        $capRes = $this->withToken($cashierA->api_token)->postJson('/api/sale-returns', [
            'reference_no' => 'SR-CAP-1',
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'payment_type' => 'Split Payment',
            'payments' => [
                ['amount' => 4500.0, 'payment_type' => 'Cash'],
                ['amount' => 4000.0, 'payment_type' => 'KBZPay'],
            ],
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'unit_price' => 99000.0,
                'subtotal' => 99000.0,
            ]],
        ]);
        if ($capRes->status() === 201) {
            $this->assertLessThanOrEqual(8500.0, (float)$capRes->json('data.grand_total'));
        } else {
            $capRes->assertStatus(422);
        }

        // Valid split-payment return @ 8500 -> included in payment_type=Cash filter & /sale-returns/export
        $splitRes = $this->withToken($cashierA->api_token)->postJson('/api/sale-returns', [
            'reference_no' => 'SR-SPLIT-1',
            'sale_reference_no' => $sale->reference_no,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'payment_type' => 'Split Payment',
            'payments' => [
                ['amount' => 4500.0, 'payment_type' => 'Cash'],
                ['amount' => 4000.0, 'payment_type' => 'KBZPay'],
            ],
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 1.0,
                'unit_price' => 8500.0,
                'subtotal' => 8500.0,
            ]],
        ]);
        $splitRes->assertStatus(201);
        $this->assertEquals(45500.0, (float)$regA->fresh()->expected_cash);

        $filteredSr = $this->withToken($cashierA->api_token)->getJson('/api/sale-returns?payment_type=Cash');
        $filteredSr->assertStatus(200);
        $refs = collect($filteredSr->json('data'))->pluck('reference_no')->all();
        $this->assertContains('SR-SPLIT-1', $refs);

        $exportSr = $this->withToken($cashierA->api_token)->get('/api/sale-returns/export?payment_type=Cash');
        $exportSr->assertStatus(200);
        $this->assertStringContainsString('SR-SPLIT-1', $exportSr->streamedContent());
    }

    public function test_transfer_receive_populates_unit_cost_and_enforces_outlet_isolation(): void
    {
        $prod = $this->createProduct('TRF-PROD-1', 'Transfer Oil', 2500.0, 3500.0);
        $prod->update(['stock_quantity' => 10, 'quantity' => 10]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $prod->id, 'quantity' => 10.0]);
        StockBalance::create(['warehouse_id' => $this->wh2->id, 'product_id' => $prod->id, 'quantity' => 0.0]);

        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $prod->id,
            'initial_quantity' => 10.0,
            'remaining_quantity' => 10.0,
            'unit_cost' => 2500.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-TRF-1',
            'received_date' => Carbon::now()->subDays(2)->toDateTimeString(),
            'status' => 'active',
        ]);

        $sentRes = $this->withToken($this->admin->api_token)->postJson('/api/transfers', [
            'reference_no' => 'TR-REG-001',
            'from_warehouse_id' => $this->wh1->id,
            'from_warehouse' => $this->wh1->name,
            'to_warehouse_id' => $this->wh2->id,
            'to_warehouse' => $this->wh2->name,
            'date' => Carbon::now()->toDateString(),
            'status' => 'Sent',
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 4.0,
                'base_quantity' => 4.0,
                'unit_cost' => 2500.0,
                'subtotal' => 10000.0,
            ]],
        ]);
        $sentRes->assertStatus(201);
        $trfId = (int)$sentRes->json('data.id');

        $recvRes = $this->withToken($this->admin->api_token)->postJson("/api/transfers/{$trfId}/receive");
        $recvRes->assertStatus(200);

        $inMovement = StockMovement::where('reference_type', 'stock_transfer')
            ->where('reference_id', $trfId)
            ->where('movement_type', 'stock_transfer_in')
            ->first();
        $this->assertNotNull($inMovement);
        $this->assertEquals(2500.0, (float)$inMovement->unit_cost);

        // WH3-only user cannot view/receive/delete WH1->WH2 transfer
        $wh3User = $this->makeScopedUser('trf_wh3', ['transfers.view', 'transfers.receive', 'transfers.delete', 'transfers.export'], [$this->wh3->id]);
        $this->withToken($wh3User->api_token)->getJson("/api/transfers/{$trfId}")->assertStatus(403);
        $this->withToken($wh3User->api_token)->deleteJson("/api/transfers/{$trfId}")->assertStatus(403);
        $this->assertCount(0, $this->withToken($wh3User->api_token)->getJson('/api/transfers')->json('data'));
    }

    public function test_adjustment_quotation_purchase_return_and_expense_hardening(): void
    {
        $prod = $this->createProduct('MISC-PROD-1', 'Misc Audit Tea', 1000.0, 2000.0);
        $prod->update(['stock_quantity' => 20, 'quantity' => 20]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $prod->id, 'quantity' => 20.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $prod->id,
            'initial_quantity' => 20.0,
            'remaining_quantity' => 20.0,
            'unit_cost' => 1000.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-MISC-01',
            'received_date' => Carbon::now()->subDays(4)->toDateTimeString(),
            'status' => 'active',
        ]);

        // 1. Adjustment export & destroy outlet guard
        $adjRes = $this->withToken($this->admin->api_token)->postJson('/api/adjustments', [
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateTimeString(),
            'type' => 'Addition',
            'reason_code' => '',
            'notes' => 'UniqueAdjNoteShouldNotBeReasonCode',
            'items' => [[
                'product_id' => $prod->id,
                'product_code' => $prod->code,
                'product_name' => $prod->name,
                'quantity' => 2.0,
                'base_quantity' => 2.0,
                'type' => 'Addition',
                'unit_cost' => 1000.0,
                'subtotal' => 2000.0,
            ]],
        ]);
        $adjRes->assertStatus(201);
        $adjId = (int)$adjRes->json('data.id');

        $adjCsv = $this->withToken($this->admin->api_token)->get('/api/adjustments/export')->streamedContent();
        $this->assertStringContainsString('Accounting Category', $adjCsv);
        $this->assertStringContainsString('Affects P&L', $adjCsv);

        $wh2User = $this->makeScopedUser('misc_wh2', [
            'adjustments.view', 'adjustments.delete',
            'quotations.view', 'quotations.delete',
            'purchase_returns.view', 'purchase_returns.create', 'purchase_returns.delete',
            'expenses.view', 'expenses.delete',
        ], [$this->wh2->id]);
        $this->withToken($wh2User->api_token)->deleteJson("/api/adjustments/{$adjId}")->assertStatus(403);

        // 2. Quotation convert-to-sale with cash populates cash_register_id & updates expected cash
        $cust = Customer::create(['name' => 'Quo Buyer', 'phone' => '0955566677', 'status' => 'Active']);
        $quo = Quotation::create([
            'reference_no' => 'QUO-REG-001',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateString(),
            'grand_total' => 4000.0,
            'status' => 'Sent',
        ]);
        QuotationItem::create([
            'quotation_id' => $quo->id,
            'product_id' => $prod->id,
            'product_code' => $prod->code,
            'product_name' => $prod->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'unit_price' => 2000.0,
            'subtotal' => 4000.0,
        ]);

        $reg = CashRegister::create([
            'reference_no' => 'REG-QUO-1',
            'register_name' => 'Quo Counter',
            'user_id' => $this->admin->id,
            'cashier_id' => $this->admin->id,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 10000.0,
            'expected_cash' => 10000.0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $convRes = $this->withToken($this->admin->api_token)->postJson("/api/quotations/{$quo->id}/convert-to-sale", [
            'paid_amount' => 4000.0,
            'payment_type' => 'Cash',
        ]);
        $convRes->assertStatus(201);
        $convSaleId = (int)($convRes->json('data.sale.id') ?? $convRes->json('data.id'));
        $salePayment = SalePayment::where('sale_id', $convSaleId)->first();
        $this->assertNotNull($salePayment);
        $this->assertEquals($reg->id, (int)$salePayment->cash_register_id);
        $this->assertEquals(14000.0, (float)$reg->fresh()->expected_cash);

        // Unauthorized WH2 user cannot delete a WH1 quotation
        $quoWh1 = Quotation::create([
            'reference_no' => 'QUO-WH1-DEL',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateString(),
            'grand_total' => 2000.0,
            'status' => 'Sent',
        ]);
        $this->withToken($wh2User->api_token)->deleteJson("/api/quotations/{$quoWh1->id}")->assertStatus(403);

        // 3. Purchase Return outlet check + Expense split cash summary & filter
        $supp = Supplier::create(['name' => 'Audit Supplier', 'phone' => '0940005000', 'balance_due' => 0.0]);
        $po = Purchase::create([
            'reference_no' => 'PO-PR-CHK',
            'supplier_id' => $supp->id,
            'supplier_name' => $supp->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->subDay()->toDateString(),
            'status' => 'Received',
            'subtotal' => 10000.0,
            'shipping' => 1000.0,
            'grand_total' => 11000.0,
            'paid_amount' => 11000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $prod->id,
            'product_code' => $prod->code,
            'product_name' => $prod->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 10.0,
            'base_quantity' => 10.0,
            'unit_cost' => 1000.0,
            'base_unit_cost' => 1000.0,
            'subtotal' => 10000.0,
        ]);
        $pr = PurchaseReturn::create([
            'reference_no' => 'PR-WH1-GUARD',
            'purchase_reference_no' => $po->reference_no,
            'supplier_id' => $supp->id,
            'supplier_name' => $supp->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::now()->toDateString(),
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        $this->withToken($wh2User->api_token)->deleteJson("/api/purchase-returns/{$pr->id}")->assertStatus(403);

        // 4. Expense split payment summary + filter + outlet guard
        $expRes = $this->withToken($this->admin->api_token)->postJson('/api/expenses', [
            'reference_no' => 'EXP-REG-SPLIT',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Utilities',
            'title' => 'Split Utility',
            'amount' => 5000.0,
            'date' => Carbon::now()->toDateTimeString(),
            'payments' => [
                ['amount' => 3000.0, 'payment_type' => 'Cash'],
                ['amount' => 2000.0, 'payment_type' => 'KBZPay'],
            ],
        ]);
        $expRes->assertStatus(201);
        $expId = (int)$expRes->json('data.id');

        $this->withToken($wh2User->api_token)->deleteJson("/api/expenses/{$expId}")->assertStatus(403);

        $expFilterRes = $this->withToken($this->admin->api_token)->getJson('/api/expenses?payment_type=Cash');
        $expFilterRes->assertStatus(200);
        $this->assertContains('EXP-REG-SPLIT', collect($expFilterRes->json('data'))->pluck('reference_no')->all());
        $this->assertEquals(3000.0, (float)$expFilterRes->json('summary.cash_total'));
        $this->assertEquals(2000.0, (float)$expFilterRes->json('summary.digital_total'));
    }

    public function test_report_controller_outlet_isolation_and_accounting_accuracy(): void
    {
        $prod1 = $this->createProduct('RPT-P1', 'WH1 Goods', 1000.0, 2000.0);
        $prod2 = $this->createProduct('RPT-P2', 'Secret WH2 Goods', 1000.0, 9000.0);

        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $prod1->id, 'quantity' => 5.0]);
        StockBalance::create(['warehouse_id' => $this->wh2->id, 'product_id' => $prod2->id, 'quantity' => 5.0]);

        $cust1 = Customer::create(['name' => 'WH1 Customer Alpha', 'phone' => '0970001111', 'status' => 'Active']);
        $cust2 = Customer::create(['name' => 'Secret WH2 Customer', 'phone' => '0970002222', 'status' => 'Active']);

        $saleWh1 = Sale::create([
            'reference_no' => 'SL-RPT-WH1',
            'customer_id' => $cust1->id,
            'customer_name' => $cust1->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => Carbon::today()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 4000.0,
            'grand_total' => 4000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 3000.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $saleWh1->id,
            'product_id' => $prod1->id,
            'product_code' => $prod1->code,
            'product_name' => $prod1->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'unit_price' => 2000.0,
            'base_unit_price' => 2000.0,
            'base_unit_cost' => 1000.0,
            'total_cogs' => 2000.0,
            'subtotal' => 4000.0,
        ]);

        // Secret WH2 sale that restricted WH1 user must NEVER see
        $saleWh2 = Sale::create([
            'reference_no' => 'SL-SECRET-WH2',
            'customer_id' => $cust2->id,
            'customer_name' => $cust2->name,
            'warehouse_id' => $this->wh2->id,
            'warehouse_name' => $this->wh2->name,
            'date' => Carbon::today()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 9000.0,
            'tax_amount' => 450.0,
            'grand_total' => 9450.0,
            'paid_amount' => 9450.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $saleWh2->id,
            'product_id' => $prod2->id,
            'product_code' => $prod2->code,
            'product_name' => $prod2->name,
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'unit_price' => 9000.0,
            'base_unit_price' => 9000.0,
            'base_unit_cost' => 1000.0,
            'total_cogs' => 1000.0,
            'subtotal' => 9000.0,
        ]);

        $wh1ReportUser = $this->makeScopedUser('rpt_wh1_only', [
            'reports.view',
            'report_sales.view',
            'report_purchases.view',
            'report_stock.view',
            'report_stock_alert.view',
            'report_stock_ledger.view',
            'report_profit_loss.view',
            'report_cashier_audit.view',
            'report_expense.view',
            'report_top_selling.view',
            'report_dead_stock.view',
            'report_cash_flow.view',
            'report_register.view',
            'report_z_report.view',
            'report_ar_aging.view',
            'report_ap_aging.view',
            'report_product_margins.view',
            'report_sales_return.view',
            'report_purchase_return.view',
            'report_commercial_tax.view',
            'report_customer.view',
            'report_supplier.view',
        ], [$this->wh1->id]);

        // Restricted user explicitly requests WH2 OR queries default unscoped reports -> must never leak SL-SECRET-WH2
        $explicitWh2Urls = [
            "/api/reports/sales?warehouse={$this->wh2->name}",
            "/api/reports/commercial-tax?warehouse={$this->wh2->name}",
            "/api/reports/profit-loss?warehouse={$this->wh2->name}",
            "/api/reports/cash-flow?warehouse={$this->wh2->name}",
            "/api/reports/top-selling?warehouse={$this->wh2->name}",
            "/api/reports/dead-stock?warehouse={$this->wh2->name}",
            "/api/reports/product-margins?warehouse={$this->wh2->name}",
            "/api/reports/ar-aging?warehouse={$this->wh2->name}",
            "/api/reports/ap-aging?warehouse={$this->wh2->name}",
            "/api/reports/customers?warehouse={$this->wh2->name}",
            "/api/reports/suppliers?warehouse={$this->wh2->name}",
            "/api/reports/stock-alert?warehouse_id={$this->wh2->id}",
            "/api/reports/z-report?warehouse={$this->wh2->name}",
            "/api/reports/cashier-audit?warehouse={$this->wh2->name}",
        ];

        foreach ($explicitWh2Urls as $url) {
            $resp = $this->withToken($wh1ReportUser->api_token)->getJson($url);
            $this->assertTrue(
                in_array($resp->status(), [200, 403], true),
                "Expected 200 or 403 at {$url}, got {$resp->status()}"
            );
            if ($resp->status() === 200) {
                $body = $resp->getContent();
                $this->assertStringNotContainsString('SL-SECRET-WH2', $body, "Leak detected at {$url}");
                $this->assertStringNotContainsString('9450', $body, "WH2 amount leak detected at {$url}");
                if (!str_starts_with($url, '/api/reports/customers') && !str_starts_with($url, '/api/reports/suppliers')) {
                    $this->assertStringNotContainsString('Secret WH2 Customer', $body, "Customer leak detected at {$url}");
                } else {
                    $wh2CustRow = collect($resp->json('data.customers') ?? [])->firstWhere('name', 'Secret WH2 Customer');
                    if ($wh2CustRow !== null) {
                        $this->assertEquals(0.0, (float)($wh2CustRow['total_spent'] ?? 0));
                        $this->assertEquals(0, (int)($wh2CustRow['total_orders'] ?? 0));
                    }
                }
            }
        }

        $unscopedUrls = [
            '/api/reports/sales',
            '/api/reports/commercial-tax',
            '/api/reports/profit-loss',
            '/api/reports/cash-flow',
            '/api/reports/top-selling',
            '/api/reports/dead-stock',
            '/api/reports/product-margins',
            '/api/reports/ar-aging',
            '/api/reports/ap-aging',
            '/api/reports/customers',
            '/api/reports/suppliers',
            '/api/reports/stock-alert',
            '/api/reports/z-report',
            '/api/reports/cashier-audit',
        ];

        foreach ($unscopedUrls as $url) {
            $resp = $this->withToken($wh1ReportUser->api_token)->getJson($url);
            $resp->assertStatus(200);
            $body = $resp->getContent();
            $this->assertStringNotContainsString('SL-SECRET-WH2', $body, "Unscoped leak detected at {$url}");
            $this->assertStringNotContainsString('9450', $body, "Unscoped WH2 amount leak detected at {$url}");
            if (!str_starts_with($url, '/api/reports/customers') && !str_starts_with($url, '/api/reports/suppliers')) {
                $this->assertStringNotContainsString('Secret WH2 Customer', $body, "Unscoped customer leak detected at {$url}");
            }
        }

        // AR Aging summary must reflect search filter
        $arSearch = $this->withToken($wh1ReportUser->api_token)->getJson('/api/reports/ar-aging?search=NonExistentPartyXYZ');
        $arSearch->assertStatus(200);
        $this->assertEquals(0.0, (float)$arSearch->json('data.summary.total_receivables'));
    }
}
