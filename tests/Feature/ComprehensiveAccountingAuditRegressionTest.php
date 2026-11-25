<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\InventoryBatch;
use App\Models\Outlet;
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

        // Branch-scoped user querying unknown explicit warehouse on commercial-tax must receive 403 (never HTTP 500)
        $this->withToken($wh1ReportUser->api_token)
            ->getJson('/api/reports/commercial-tax?warehouse=UnknownBranch999')
            ->assertStatus(403);
    }

    public function test_eight_reports_senior_accounting_fixes_and_reconciliation(): void
    {
        // 1. Supplier Report must report subledger AP even if cached suppliers.balance_due is stale (0.0)
        $sup = Supplier::create([
            'name' => 'Stale Balance Vendor',
            'phone' => '0998877665',
            'balance_due' => 0.0,
            'status' => 'Active',
        ]);
        Purchase::create([
            'reference_no' => 'PO-STALE-BAL-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 10:00:00',
            'status' => 'Received',
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'paid_amount' => 1500.0,
            'due_amount' => 3500.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);

        foreach ([
            '/api/reports/suppliers',
            '/api/reports/suppliers?start_date=2026-11-01&end_date=2026-11-30',
        ] as $supUrl) {
            $sRes = $this->withToken($this->admin->api_token)->getJson($supUrl)->assertStatus(200);
            $row = collect($sRes->json('data.suppliers'))->firstWhere('id', $sup->id);
            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(3500.0, (float)$row['total_due'], 0.01, "Failed {$supUrl}");
            $this->assertEqualsWithDelta(3500.0, (float)$row['net_due'], 0.01, "Failed {$supUrl}");
        }

        // 2. Stock Alert & Stock Report low_stock honor custom per-product alert_quantity (caught when > 10, ignored when <= stock)
        $highAlertProd = Product::create([
            'code' => 'ALERT-HI-20',
            'name' => 'High Threshold Item',
            'cost_price' => 100.0,
            'price' => 200.0,
            'selling_price' => 200.0,
            'retail_price' => 200.0,
            'stock_quantity' => 15.0,
            'alert_quantity' => 20.0,
            'product_unit' => 'Pc',
            'barcode_symbol' => 'CODE128',
        ]);
        $lowAlertProd = Product::create([
            'code' => 'ALERT-LO-2',
            'name' => 'Low Threshold Item',
            'cost_price' => 100.0,
            'price' => 200.0,
            'selling_price' => 200.0,
            'retail_price' => 200.0,
            'stock_quantity' => 5.0,
            'alert_quantity' => 2.0,
            'product_unit' => 'Pc',
            'barcode_symbol' => 'CODE128',
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $highAlertProd->id, 'quantity' => 15.0]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $lowAlertProd->id, 'quantity' => 5.0]);

        $saRes = $this->withToken($this->admin->api_token)->getJson('/api/reports/stock-alert')->assertStatus(200);
        $saIds = collect($saRes->json('data.items'))->pluck('id')->all();
        $this->assertContains($highAlertProd->id, $saIds);
        $this->assertNotContains($lowAlertProd->id, $saIds);

        $stkLowRes = $this->withToken($this->admin->api_token)->getJson('/api/reports/stock?stock_status=low_stock')->assertStatus(200);
        $stkLowIds = collect($stkLowRes->json('data.items'))->pluck('id')->all();
        $this->assertContains($highAlertProd->id, $stkLowIds);
        $this->assertNotContains($lowAlertProd->id, $stkLowIds);

        // 3. Sale Returns Report unpacks Split Payment refund legs & matches payment_type inside payload
        $srSplit = SaleReturn::create([
            'reference_no' => 'SR-SPLIT-8RPT',
            'sale_reference_no' => 'SL-DUMMY',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 12:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Split Payment',
            'payments_payload' => json_encode([
                ['payment_type' => 'Cash', 'amount' => 400.0],
                ['payment_type' => 'KBZPay', 'amount' => 600.0],
            ]),
            'items_payload' => json_encode([
                ['product_id' => $highAlertProd->id, 'product_code' => $highAlertProd->code, 'product_name' => $highAlertProd->name, 'quantity' => 2, 'subtotal' => 1000.0],
            ]),
        ]);
        $srRpt = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/sale-returns?payment_type=KBZPay&start_date=2026-11-15&end_date=2026-11-15')
            ->assertStatus(200);
        $this->assertCount(1, $srRpt->json('data'));
        $tenders = collect($srRpt->json('by_refund_tender'))->keyBy('payment_type');
        $this->assertEqualsWithDelta(400.0, (float)($tenders['Cash']['total_amount'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)($tenders['KBZPay']['total_amount'] ?? 0), 0.01);

        // 4. Customer Report net due_filter excludes customers whose open due is offset by return credit
        $netCust = Customer::create(['name' => 'NetZero Return Customer', 'phone' => '0966554433', 'status' => 'Active']);
        Sale::create([
            'reference_no' => 'SL-NETZERO-1',
            'customer_id' => $netCust->id,
            'customer_name' => $netCust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Completed',
            'grand_total' => 800.0,
            'paid_amount' => 0.0,
            'due_amount' => 800.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-NETZERO-1',
            'customer_id' => $netCust->id,
            'customer_name' => $netCust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 11:00:00',
            'status' => 'Completed',
            'grand_total' => 800.0,
            'paid_amount' => 0.0,
            'due_amount' => 800.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Store Credit',
        ]);
        $dueCustRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/customers?due_filter=has_due&warehouse={$this->wh1->id}")
            ->assertStatus(200);
        $dueCustIds = collect($dueCustRes->json('data.customers'))->pluck('id')->all();
        $this->assertNotContains($netCust->id, $dueCustIds);
        $this->assertNotEmpty($srSplit->id);
    }

    public function test_sales_and_purchases_reports_split_payment_warehouse_id_and_net_cost(): void
    {
        $sup = Supplier::create(['name' => 'PO Split Vendor', 'phone' => '0955443322', 'balance_due' => 0.0, 'status' => 'Active']);

        $po = Purchase::create([
            'reference_no' => 'PO-SPLIT-RPT-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 09:00:00',
            'status' => 'Received',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 1200.0,
            'returned_credit_amount' => 300.0,
            'due_amount' => 500.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Split Payment',
            'payments_payload' => json_encode([
                ['payment_type' => 'Cash', 'amount' => 500.0],
                ['payment_type' => 'Bank Transfer', 'amount' => 700.0],
            ]),
        ]);

        // Opening Stock excluded when payment_type filter is active for normal tender
        Purchase::create([
            'reference_no' => 'PO-OPEN-SKIP-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 09:30:00',
            'status' => 'Received',
            'subtotal' => 999.0,
            'grand_total' => 999.0,
            'paid_amount' => 999.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
        ]);

        $poRpt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/purchases?warehouse={$this->wh1->id}&supplier={$sup->id}&payment_type=Bank%20Transfer&start_date=2026-11-18&end_date=2026-11-18")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$poRpt->json('data.total_purchases_count'));
        $this->assertEqualsWithDelta(2000.0, (float)$poRpt->json('data.total_cost'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float)$poRpt->json('data.total_returned_credit'), 0.01);
        $this->assertEqualsWithDelta(1700.0, (float)$poRpt->json('data.net_cost'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$poRpt->json('data.total_due'), 0.01);
        $this->assertContains($this->wh1->name, $poRpt->json('data.available_filters.warehouses') ?? []);
        $this->assertContains($sup->name, $poRpt->json('data.available_filters.suppliers') ?? []);

        $dueNormalizedRpt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/purchases?warehouse={$this->wh1->id}&supplier=" . urlencode($sup->name) . "&payment_status=Due&start_date=2026-11-18&end_date=2026-11-18")
            ->assertStatus(200);
        // PO is Partial, so Due/Unpaid should exclude it while verifying supplier name->id resolution & Due/Unpaid filter execute cleanly
        $this->assertEquals(0, (int)$dueNormalizedRpt->json('data.total_purchases_count'));

        $emptySup = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchases?supplier=999999&warehouse=999999&start_date=2026-11-18&end_date=2026-11-18')
            ->assertStatus(200);
        $this->assertEquals(0, (int)$emptySup->json('data.total_purchases_count'));

        $sale = Sale::create([
            'reference_no' => 'SL-SPLIT-RPT-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1500.0,
            'grand_total' => 1500.0,
            'paid_amount' => 1500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Split Payment',
        ]);
        \App\Models\SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'PAY-SL-SPLIT-1',
            'payment_date' => '2026-11-18 10:00:00',
            'payment_type' => 'Cash',
            'amount' => 500.0,
            'received_amount' => 500.0,
            'change_amount' => 0.0,
        ]);
        \App\Models\SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'PAY-SL-SPLIT-2',
            'payment_date' => '2026-11-18 10:00:00',
            'payment_type' => 'KBZPay',
            'amount' => 1000.0,
            'received_amount' => 1000.0,
            'change_amount' => 0.0,
        ]);

        $slRpt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/sales?warehouse={$this->wh1->id}&payment_type=KBZPay&start_date=2026-11-18&end_date=2026-11-18")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$slRpt->json('data.total_sales_count'));
        $this->assertEqualsWithDelta(1500.0, (float)$slRpt->json('data.total_revenue'), 0.01);
        $this->assertEqualsWithDelta(1500.0, (float)$slRpt->json('data.net_revenue'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$slRpt->json('data.total_returned_credit'), 0.01);
        $this->assertContains($this->wh1->name, $slRpt->json('data.available_filters.warehouses') ?? []);
        $emptySl = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/sales?warehouse=999999&start_date=2026-11-18&end_date=2026-11-18')
            ->assertStatus(200);
        $this->assertEquals(0, (int)$emptySl->json('data.total_sales_count'));
        $this->assertNotEmpty($po->id);
        $this->assertNotEmpty($sale->id);

        // Regression guard for HY093 SQL parameter mismatch on Stock Report with warehouse_id + category + status + search
        $stkCombo = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?category=General&warehouse_id={$this->wh1->id}&stock_status=All&search=NonExistent&page=1&per_page=50");
        $stkCombo->assertStatus(200);
        $stkCombo2 = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?category=General&warehouse_id={$this->wh1->id}&stock_status=out_of_stock&page=1&per_page=50");
        $stkCombo2->assertStatus(200);
        $stkCombo3 = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?warehouse_id={$this->wh2->id}&stock_status=low_stock_only&page=1&per_page=50");
        $stkCombo3->assertStatus(200);

        // Accounting accuracy check: empty warehouse with zero stock_balances rows still computes full deficit & restock cost
        Product::create([
            'name' => 'Empty WH Deficit Item',
            'code' => 'DEF-01',
            'sku' => 'DEF-01',
            'cost_price' => 500.0,
            'purchase_price' => 500.0,
            'selling_price' => 800.0,
            'retail_price' => 800.0,
            'stock_quantity' => 50.0,
            'alert_quantity' => 10.0,
            'is_active' => true,
        ]);
        $emptyWh = Outlet::create(['name' => 'Empty Branch WH', 'code' => 'WH-EMPTY', 'is_active' => true]);
        $emptyRpt = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?warehouse_id={$emptyWh->id}&stock_status=low_stock&page=1&per_page=50")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(10.0, (float)$emptyRpt->json('data.summary.total_deficit_quantity'), 0.01);
        $this->assertEqualsWithDelta(5000.0, (float)$emptyRpt->json('data.summary.estimated_restock_cost'), 0.01);
        $this->assertEquals(1, (int)$emptyRpt->json('data.summary.out_of_stock_count'));

        // Top Selling Report: weighted realized selling price, COGS/margin, limit cap, and branch-scoped stock_quantity
        $tsProd = Product::where('code', 'DEF-01')->first();
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $tsProd->id,
            'quantity' => 17.0,
        ]);
        $tsSale = Sale::create([
            'reference_no' => 'SL-TS-ACC-1',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:00:00',
            'status' => 'Completed',
            'subtotal' => 2400.0,
            'grand_total' => 2400.0,
            'paid_amount' => 2400.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $tsSale->id,
            'product_id' => $tsProd->id,
            'product_code' => $tsProd->code,
            'product_name' => $tsProd->name,
            'unit_price' => 800.0,
            'base_unit_price' => 800.0,
            'base_unit_cost' => 500.0,
            'quantity' => 3.0,
            'base_quantity' => 3.0,
            'subtotal' => 2400.0,
            'total_cogs' => 1500.0,
        ]);
        $tsRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/top-selling?warehouse_name={$this->wh1->name}&search=DEF-01&limit=10")
            ->assertStatus(200);
        $tsRow = collect($tsRes->json('data'))->firstWhere('code', 'DEF-01');
        $this->assertNotNull($tsRow);
        $this->assertEqualsWithDelta(17.0, (float)$tsRow['stock_quantity'], 0.01);
        $this->assertEqualsWithDelta(800.0, (float)$tsRow['price'], 0.01);
        $this->assertEqualsWithDelta(1500.0, (float)$tsRow['total_cost'], 0.01);
        $this->assertEqualsWithDelta(900.0, (float)$tsRow['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(37.5, (float)$tsRow['profit_margin'], 0.01);
    }

    public function test_customer_report_code_search_tier_filter_and_net_accounting(): void
    {
        // Customer Report: customer_id code search, flexible Platinum/Platinum VIP tier filter, available_tiers, net_due/net_paid summary
        // Anonymous walk-in sale should NOT pollute Daw Platinum VIP (even if she is customer #1)
        Sale::create([
            'reference_no' => 'SL-ANON-1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 13:00:00',
            'status' => 'Completed',
            'subtotal' => 450.0,
            'grand_total' => 450.0,
            'paid_amount' => 450.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $vipCust = Customer::create([
            'customer_id' => 'CUST-9901',
            'name' => 'Daw Platinum VIP',
            'phone' => '0999001122',
            'tier' => 'Platinum VIP',
            'status' => 'Active',
        ]);
        Sale::create([
            'reference_no' => 'SL-VIP-1',
            'customer_id' => $vipCust->id,
            'customer_name' => $vipCust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 14:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 700.0,
            'due_amount' => 300.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-VIP-1',
            'customer_id' => $vipCust->id,
            'customer_name' => $vipCust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 15:00:00',
            'status' => 'Completed',
            'grand_total' => 200.0,
            'paid_amount' => 150.0,
            'due_amount' => 50.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Store Credit',
        ]);

        $custCodeRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/customers?search=CUST-9901&tier=Platinum')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$custCodeRes->json('data.total_customers'));
        $vipRow = $custCodeRes->json('data.customers.0');
        $this->assertEquals('CUST-9901', $vipRow['code']);
        $this->assertEqualsWithDelta(1000.0, (float)$vipRow['total_spent'], 0.01);
        $this->assertEqualsWithDelta(200.0, (float)$vipRow['total_returned_amount'], 0.01);
        $this->assertEqualsWithDelta(800.0, (float)$vipRow['net_spent'], 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$vipRow['net_due'], 0.01);
        $this->assertEqualsWithDelta(550.0, (float)$custCodeRes->json('data.summary.net_paid'), 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$custCodeRes->json('data.summary.net_due'), 0.01);
        $this->assertContains('Platinum VIP', $custCodeRes->json('data.available_tiers'));
    }

    public function test_supplier_report_code_city_search_categories_and_net_ap(): void
    {
        $sup = Supplier::create([
            'name' => 'Golden Triangle Beverages',
            'phone' => '0988776655',
            'category' => 'Beverages & Coffee',
            'payment_terms' => 'Net 30 Days',
            'city' => 'Mandalay',
            'status' => 'Active',
        ]);
        Purchase::create([
            'reference_no' => 'PO-SUP-ACC-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Received',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 1100.0,
            'due_amount' => 900.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);
        PurchaseReturn::create([
            'reference_no' => 'PR-SUP-ACC-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:00:00',
            'status' => 'Completed',
            'grand_total' => 300.0,
            'paid_amount' => 100.0,
            'due_amount' => 200.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);

        // SUP-id search & category filter
        $resCode = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers?search=SUP-{$sup->id}&category=Beverages%20%26%20Coffee")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$resCode->json('data.total_suppliers'));
        $row = $resCode->json('data.suppliers.0');
        $this->assertEqualsWithDelta(2000.0, (float)$row['total_purchased_amount'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float)$row['total_returned_amount'], 0.01);
        $this->assertEqualsWithDelta(1700.0, (float)$row['net_purchased_amount'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$row['net_paid_amount'], 0.01);
        $this->assertEqualsWithDelta(700.0, (float)$row['total_due'], 0.01);
        $this->assertContains('Beverages & Coffee', $resCode->json('data.available_categories'));
        $this->assertContains('Net 30 Days', $resCode->json('data.available_payment_terms'));

        // Date-range + city search (empty purchase_return_allocations fast path)
        $resDate = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/suppliers?search=Mandalay&start_date=2026-11-18&end_date=2026-11-18')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$resDate->json('data.total_suppliers'));
        $this->assertEqualsWithDelta(700.0, (float)$resDate->json('data.summary.total_due'), 0.01);
    }

    public function test_expense_report_available_filters_due_filter_and_cash_digital_reconciliation(): void
    {
        \App\Models\Expense::create([
            'reference_no' => 'EXP-AUD-1',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Utilities & Power',
            'title' => 'Monthly Power Bill',
            'amount' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'KBZPay',
            'date' => '2026-11-18 10:00:00',
            'created_by' => 'Admin Account',
        ]);
        \App\Models\Expense::create([
            'reference_no' => 'EXP-AUD-2',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Packaging Supplies',
            'title' => 'Boxes & Tape',
            'amount' => 600.0,
            'paid_amount' => 200.0,
            'due_amount' => 400.0,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'date' => '2026-11-18 11:00:00',
            'created_by' => 'Admin Account',
        ]);

        $resAll = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/expenses?category_name=Utilities%20%26%20Power')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$resAll->json('data.total_count'));
        $this->assertContains('Packaging Supplies', $resAll->json('data.available_categories'));
        $this->assertContains('Cash', $resAll->json('data.available_payment_methods'));
        $this->assertEqualsWithDelta(1000.0, (float)$resAll->json('data.summary.digital_paid'), 0.01);

        $resDue = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/expenses?due_filter=due_only')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$resDue->json('data.total_count'));
        $this->assertEqualsWithDelta(400.0, (float)$resDue->json('data.summary.total_due'), 0.01);
        $this->assertEqualsWithDelta(200.0, (float)$resDue->json('data.summary.cash_paid'), 0.01);
    }

    public function test_sale_returns_report_warehouse_name_reason_search_and_available_filters(): void
    {
        $cust = Customer::create(['name' => 'Ma Hla Return Buyer', 'phone' => '0944551122', 'status' => 'Active']);
        SaleReturn::create([
            'reference_no' => 'SR-AUD-101',
            'sale_reference_no' => 'SL-AUD-101',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 14:00:00',
            'status' => 'Completed',
            'grand_total' => 750.0,
            'paid_amount' => 500.0,
            'due_amount' => 250.0,
            'payment_status' => 'Partial',
            'payment_type' => 'KBZPay',
            'refund_tender' => 'KBZPay',
            'return_reason' => 'Damaged Packaging Seal',
            'created_by' => 'Audit Cashier',
            'user_name' => 'Audit Cashier',
        ]);

        // Filter by warehouse name + refund_method alias + search by return_reason
        $res = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/sale-returns?warehouse=' . urlencode($this->wh1->name) . '&refund_method=KBZPay&search=Damaged%20Packaging')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$res->json('summary.total_returns'));
        $this->assertEqualsWithDelta(750.0, (float)$res->json('summary.total_returned_amount'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$res->json('summary.total_paid_amount'), 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$res->json('summary.total_due_amount'), 0.01);
        $this->assertContains('Ma Hla Return Buyer', $res->json('available_customers'));
        $this->assertContains('KBZPay', $res->json('available_payment_types'));
        $this->assertContains('Audit Cashier', $res->json('available_users'));
    }

    public function test_purchase_returns_report_warehouse_name_split_tender_and_available_filters(): void
    {
        $sup = Supplier::create(['name' => 'Golden Delta Suppliers', 'phone' => '0944551199', 'status' => 'Active']);
        PurchaseReturn::create([
            'reference_no' => 'PR-AUD-201',
            'purchase_reference_no' => 'PO-AUD-201',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'outlet_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 15:00:00',
            'status' => 'Completed',
            'grand_total' => 900.0,
            'paid_amount' => 600.0,
            'due_amount' => 300.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Split Payment',
            'payments_payload' => json_encode([
                ['payment_type' => 'Cash', 'amount' => 250.0],
                ['payment_type' => 'KBZPay', 'amount' => 350.0],
            ]),
            'return_reason' => 'Damaged Outer Carton',
            'created_by' => 'Audit Purchaser',
            'user_name' => 'Audit Purchaser',
        ]);
        // Draft pending return must be excluded from financial default scope
        PurchaseReturn::create([
            'reference_no' => 'PR-AUD-DRAFT',
            'purchase_reference_no' => 'PO-AUD-DRAFT',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'outlet_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 16:00:00',
            'status' => 'Pending',
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'due_amount' => 500.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit Note',
            'return_reason' => 'Awaiting Approval',
            'created_by' => 'Draft Clerk',
            'user_name' => 'Draft Clerk',
        ]);

        // Filter by warehouse name + refund_method alias + search by return_reason
        $res = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/purchase-returns?warehouse=' . urlencode($this->wh1->name) . '&refund_method=KBZPay&search=Outer%20Carton')
            ->assertStatus(200);

        $this->assertEquals(1, (int)$res->json('summary.total_returns'));
        $this->assertEqualsWithDelta(900.0, (float)$res->json('summary.total_returned_amount'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$res->json('summary.total_paid_amount'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float)$res->json('summary.total_due_amount'), 0.01);
        $this->assertContains('Golden Delta Suppliers', $res->json('available_suppliers'));
        $this->assertContains('Split Payment', $res->json('available_payment_types'));
        $this->assertContains('Audit Purchaser', $res->json('available_users'));

        // Reconcile posted return total with Supplier Report
        $supRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/suppliers?warehouse=' . urlencode($this->wh1->name))
            ->assertStatus(200);
        $this->assertEqualsWithDelta(900.0, (float)$supRes->json('data.summary.total_returned'), 0.01);
    }

    public function test_register_report_tenders_discrepancy_and_available_filters(): void
    {
        $cust = Customer::create(['name' => 'Walk-in Register Audit', 'phone' => '0911122233', 'status' => 'Active']);
        // 1. Real Cash Register shift with cash variance (-50 shortage)
        $reg = CashRegister::create([
            'reference_no' => 'REG-AUD-901',
            'register_name' => 'Register 09',
            'user_id' => $this->admin->id,
            'user_name' => 'Audit Cashier A',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 1000.0,
            'expected_cash' => 1400.0,
            'closing_cash' => 1350.0,
            'cash_difference' => -50.0,
            'status' => 'closed',
            'opened_at' => '2026-11-18 08:00:00',
            'closed_at' => '2026-11-18 16:00:00',
        ]);
        $saleReg = Sale::create([
            'reference_no' => 'SL-REG-AUD-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $reg->id,
            'date' => '2026-11-18 09:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 400.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'created_by' => 'Audit Cashier A',
        ]);
        SalePayment::create([
            'sale_id' => $saleReg->id,
            'cash_register_id' => $reg->id,
            'reference_no' => 'SP-REG-1',
            'payment_date' => '2026-11-18 09:00:00',
            'payment_type' => 'Cash',
            'amount' => 400.0,
        ]);

        // 2. Legacy unlinked WavePay sale (must preserve 'WavePay', not mislabel as KPay/KBZPay)
        $legacyWaveSale = Sale::create([
            'reference_no' => 'SL-REG-AUD-WAVE',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => null,
            'date' => '2026-11-17 11:00:00',
            'status' => 'Completed',
            'subtotal' => 850.0,
            'grand_total' => 850.0,
            'paid_amount' => 850.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'WavePay',
            'created_by' => 'Wave Cashier B',
        ]);
        SalePayment::create([
            'sale_id' => $legacyWaveSale->id,
            'reference_no' => 'SP-REG-WAVE',
            'payment_date' => '2026-11-17 11:00:00',
            'payment_type' => 'WavePay',
            'amount' => 850.0,
        ]);

        // Verify payment_type=WavePay filter works and tenders are preserved accurately
        $waveRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?warehouse=' . urlencode($this->wh1->name) . '&payment_type=WavePay')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$waveRes->json('summary.total_sessions'));
        $this->assertEqualsWithDelta(850.0, (float)$waveRes->json('summary.total_shift_sales'), 0.01);
        $this->assertEquals('WavePay', $waveRes->json('by_payment_tender.0.payment_type'));
        $this->assertContains('Register 09', $waveRes->json('available_registers'));
        $this->assertContains('Wave Cashier B', $waveRes->json('available_cashiers'));
        $this->assertContains('WavePay', $waveRes->json('available_payment_types'));

        // Verify server-side discrepancy filter returns only REG-AUD-901
        $discRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?discrepancy=' . urlencode('Discrepancy (Over/Short)'))
            ->assertStatus(200);
        $this->assertEquals(1, (int)$discRes->json('summary.total_sessions'));
        $this->assertEquals('REG-AUD-901', $discRes->json('data.0.reference_no'));
        $this->assertEqualsWithDelta(-50.0, (float)$discRes->json('summary.total_variance'), 0.01);
    }

    public function test_z_report_balanced_alias_available_registers_and_accounting_math(): void
    {
        $cust = Customer::create(['name' => 'Z-Report Buyer', 'phone' => '0922233344', 'status' => 'Active']);
        $sale = Sale::create([
            'reference_no' => 'SL-ZR-AUD-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'tax_amount' => 50.0,
            'grand_total' => 1050.0,
            'paid_amount' => 1050.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'created_by' => 'Audit Admin',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => 1,
            'product_code' => 'P-ZR-1',
            'product_name' => 'Z-Report Item',
            'unit_id' => 1,
            'unit_name' => 'Piece',
            'conversion_rate' => 1.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'unit_price' => 500.0,
            'base_unit_price' => 500.0,
            'base_unit_cost' => 300.0,
            'total_cogs' => 600.0,
            'subtotal' => 1000.0,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'reference_no' => 'SP-ZR-AUD-1',
            'payment_date' => '2026-11-18 12:00:00',
            'payment_type' => 'Cash',
            'amount' => 1050.0,
        ]);

        // Mobile sends payment_status=Balanced; backend must treat Balanced symmetrically with Reconciled
        $balRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/z-report?warehouse=' . urlencode($this->wh1->name) . '&payment_status=Balanced')
            ->assertStatus(200);
        $this->assertEquals(1, (int)$balRes->json('summary.total_reports'));
        $this->assertEqualsWithDelta(1000.0, (float)$balRes->json('summary.total_gross_sales'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$balRes->json('summary.total_net_sales'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$balRes->json('data.0.cogs'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$balRes->json('data.0.gross_profit'), 0.01);
        $this->assertEqualsWithDelta(1050.0, (float)$balRes->json('data.0.closing_cash'), 0.01);
        $this->assertNotEmpty($balRes->json('available_registers'));

        // Restricted branch user must get 403 for disallowed or unknown outlet filters on Z-Report
        $wh1OnlyUser = $this->makeScopedUser('wh1_zreport_only', [
            'report_z_report.view',
        ], [$this->wh1->id]);
        $this->withToken($wh1OnlyUser->api_token)
            ->getJson("/api/reports/z-report?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($wh1OnlyUser->api_token)
            ->getJson('/api/reports/z-report?warehouse=' . urlencode($this->wh2->name))
            ->assertStatus(403);
        $this->withToken($wh1OnlyUser->api_token)
            ->getJson('/api/reports/z-report?warehouse=UnknownBranch')
            ->assertStatus(403);
    }

    public function test_cash_flow_statement_filters_opening_closing_math_and_fast_pagination(): void
    {
        // Prior period cash activity (sets opening balance: 1000 - 200 = 800)
        $priorSale = Sale::create([
            'reference_no' => 'SL-CF-PRIOR-1',
            'customer_name' => 'Prior Client',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-01 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'created_by' => 'Audit Admin',
        ]);
        SalePayment::create([
            'sale_id' => $priorSale->id,
            'reference_no' => 'SP-CF-PRIOR-1',
            'payment_date' => '2026-11-01 10:00:00',
            'payment_type' => 'Cash',
            'amount' => 1000.0,
            'created_by' => 'Audit Admin',
        ]);
        Expense::create([
            'reference_no' => 'EXP-CF-PRIOR-1',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Utilities',
            'title' => 'Prior Electric',
            'amount' => 200.0,
            'paid_amount' => 200.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2026-11-02 10:00:00',
            'created_by' => 'Audit Admin',
        ]);

        // In-period inflows & outflows + excluded credit/voided docs
        $sCash = Sale::create([
            'reference_no' => 'SL-CF-CUR-1',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 11:00:00',
            'status' => 'Completed',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'created_by' => 'Cashier One',
        ]);
        SalePayment::create([
            'sale_id' => $sCash->id,
            'reference_no' => 'SP-CF-CUR-1',
            'payment_date' => '2026-11-18 11:00:00',
            'payment_type' => 'Cash',
            'amount' => 500.0,
            'created_by' => 'Cashier One',
        ]);
        $sWave = Sale::create([
            'reference_no' => 'SL-CF-CUR-2',
            'customer_name' => 'Wave VIP',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-19 12:00:00',
            'status' => 'Completed',
            'subtotal' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 300.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'WavePay',
            'created_by' => 'Cashier Two',
        ]);
        SalePayment::create([
            'sale_id' => $sWave->id,
            'reference_no' => 'SP-CF-CUR-2',
            'payment_date' => '2026-11-19 12:00:00',
            'payment_type' => 'WavePay',
            'amount' => 300.0,
            'created_by' => 'Cashier Two',
        ]);
        // Voided sale must be excluded
        Sale::create([
            'reference_no' => 'SL-CF-VOID-9',
            'customer_name' => 'Void Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-19 15:00:00',
            'status' => 'Voided',
            'subtotal' => 999.0,
            'grand_total' => 999.0,
            'paid_amount' => 999.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        Expense::create([
            'reference_no' => 'EXP-CF-CUR-1',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Logistics',
            'title' => 'Delivery Fuel',
            'amount' => 120.0,
            'paid_amount' => 120.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'WavePay',
            'date' => '2026-11-19 16:00:00',
            'created_by' => 'Cashier Two',
        ]);

        // Period query: Inflows = 800 (500 + 300), Outflows = 120, Net = 680, Opening = 800, Closing = 1480
        $res = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->name}&start_date=2026-11-15&end_date=2026-11-20")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(800.0, (float)$res->json('summary.opening_balance'), 0.01);
        $this->assertEqualsWithDelta(800.0, (float)$res->json('summary.total_inflow'), 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$res->json('summary.total_outflow'), 0.01);
        $this->assertEqualsWithDelta(680.0, (float)$res->json('summary.net_cash_flow'), 0.01);
        $this->assertEqualsWithDelta(1480.0, (float)$res->json('summary.closing_balance'), 0.01);
        $this->assertEquals(3, (int)$res->json('pagination.total'));
        $this->assertNotEmpty($res->json('available_filters.warehouses'));
        $this->assertContains('WavePay', $res->json('available_filters.payment_methods'));

        // Activity filter: Operating Outflow returns only EXP-CF-CUR-1
        $outRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->name}&start_date=2026-11-15&end_date=2026-11-20&activity=Operating+Outflow")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$outRes->json('pagination.total'));
        $this->assertEqualsWithDelta(120.0, (float)$outRes->json('summary.total_outflow'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$outRes->json('summary.total_inflow'), 0.01);

        // Payment method filter: WavePay matches SL-CF-CUR-2 (300 in) & EXP-CF-CUR-1 (120 out)
        $waveRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->name}&start_date=2026-11-15&end_date=2026-11-20&payment_method=WavePay")
            ->assertStatus(200);
        $this->assertEquals(2, (int)$waveRes->json('pagination.total'));
        $this->assertEqualsWithDelta(300.0, (float)$waveRes->json('summary.total_inflow'), 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$waveRes->json('summary.total_outflow'), 0.01);

        // User + search filter + skip_summary fast pagination with known_closing_balance
        $p2 = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->name}&start_date=2026-11-15&end_date=2026-11-20&page=2&per_page=1&skip_summary=1&known_total=3&known_closing_balance=1480")
            ->assertStatus(200);
        $this->assertEquals(1, count($p2->json('transactions')));
        $this->assertEqualsWithDelta(1600.0, (float)$p2->json('transactions.0.running_balance'), 0.01);
        $this->assertTrue((bool)$p2->json('pagination.has_more'));

        // Branch authorization guard: scoped user can read allowed outlet, gets 403 on disallowed or unknown warehouse
        $scopedUser = $this->makeScopedUser('cf_wh1_mgr', ['reports.view', 'reports.financial'], [$this->wh1->id]);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh1->name}&start_date=2026-11-15&end_date=2026-11-20")
            ->assertStatus(200);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/cash-flow?warehouse={$this->wh2->name}&start_date=2026-11-15&end_date=2026-11-20")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/cash-flow?warehouse=UnknownBranch&start_date=2026-11-15&end_date=2026-11-20")
            ->assertStatus(403);
    }

    public function test_ar_aging_historical_as_of_date_future_return_credit_and_risk_filters(): void
    {
        $custMod = Customer::create(['name' => 'Moderate Debtor', 'phone' => '0911111111']);
        $custCrit = Customer::create(['name' => 'Critical Debtor', 'phone' => '0922222222']);

        // Sale 45 days before 2026-11-20 -> Moderate (31-60d), with a return applied AFTER 2026-11-10
        $saleMod = Sale::create([
            'reference_no' => 'SL-AR-MOD-1',
            'customer_id' => $custMod->id,
            'customer_name' => $custMod->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-10-06 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'due_amount' => 700.0,
            'returned_credit_amount' => 300.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Credit',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-AR-MOD-1',
            'sale_id' => $saleMod->id,
            'sale_reference_no' => $saleMod->reference_no,
            'customer_id' => $custMod->id,
            'customer_name' => $custMod->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Completed',
            'subtotal' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'due_amount' => 0.0,
            'applied_credit_amount' => 300.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Store Credit',
        ]);

        // Critical debtor (>90 days before 2026-11-20)
        Sale::create([
            'reference_no' => 'SL-AR-CRIT-1',
            'customer_id' => $custCrit->id,
            'customer_name' => $custCrit->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-07-01 10:00:00',
            'status' => 'Completed',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'due_amount' => 500.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);

        // Historical As-Of 2026-11-10 (before SR-AR-MOD-1 on 2026-11-15): Moderate Debtor still owes full 1000!
        $histRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/ar-aging?warehouse={$this->wh1->name}&as_of_date=2026-11-10")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(1500.0, (float)$histRes->json('summary.total_receivable'), 0.01);
        $this->assertNotEmpty($histRes->json('available_filters.warehouses'));

        // Current As-Of 2026-11-20 + risk_filter=moderate: only Moderate Debtor (700.0)
        $modRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/ar-aging?warehouse={$this->wh1->name}&as_of_date=2026-11-20&risk_filter=moderate")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$modRes->json('pagination.total'));
        $this->assertEqualsWithDelta(700.0, (float)$modRes->json('summary.total_receivable'), 0.01);
        $this->assertEqualsWithDelta(700.0, (float)$modRes->json('summary.days_31_60'), 0.01);
        $this->assertEquals('moderate', $modRes->json('data.0.risk_status'));

        // Sub-filter credit note math: customer with gross 500 and unapplied credit note 400 -> net due 100, unapplied 0, net_after_credits 100
        $custCn = Customer::create(['name' => 'Credit Offset Buyer', 'phone' => '0955555555']);
        Sale::create([
            'reference_no' => 'SL-AR-CN-1',
            'customer_id' => $custCn->id,
            'customer_name' => $custCn->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'due_amount' => 500.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-AR-CN-OPEN',
            'customer_id' => $custCn->id,
            'customer_name' => $custCn->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-19 10:00:00',
            'status' => 'Completed',
            'subtotal' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'due_amount' => 400.0,
            'applied_credit_amount' => 0.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Store Credit',
        ]);
        $cnRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/ar-aging?warehouse={$this->wh1->name}&as_of_date=2026-11-20&search=Credit+Offset")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(100.0, (float)$cnRes->json('summary.total_receivable'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$cnRes->json('summary.total_open_credit_notes'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$cnRes->json('summary.unapplied_open_credit_notes'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$cnRes->json('summary.net_receivable_after_all_credits'), 0.01);

        // Branch authorization guard: scoped user can read allowed outlet, gets 403 on disallowed or unknown warehouse
        $scopedUser = $this->makeScopedUser('ar_wh1_mgr', ['report_ar_aging.view'], [$this->wh1->id]);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ar-aging?warehouse={$this->wh1->name}&as_of_date=2026-11-20")
            ->assertStatus(200);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ar-aging?warehouse={$this->wh2->name}&as_of_date=2026-11-20")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ar-aging?warehouse=UnknownBranch&as_of_date=2026-11-20")
            ->assertStatus(403);
    }

    public function test_ap_aging_numeric_warehouse_id_and_risk_filters(): void
    {
        $supUrgent = Supplier::create(['name' => 'Urgent Supplier Co', 'phone' => '0933333333']);
        $supHealthy = Supplier::create(['name' => 'Healthy Supplier Co', 'phone' => '0944444444']);

        Purchase::create([
            'reference_no' => 'PO-AP-URG-1',
            'supplier_id' => $supUrgent->id,
            'supplier_name' => $supUrgent->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-09-10 10:00:00',
            'status' => 'Received',
            'subtotal' => 2500.0,
            'grand_total' => 2500.0,
            'paid_amount' => 0.0,
            'due_amount' => 2500.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);
        Purchase::create([
            'reference_no' => 'PO-AP-HLTH-1',
            'supplier_id' => $supHealthy->id,
            'supplier_name' => $supHealthy->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 10:00:00',
            'status' => 'Received',
            'subtotal' => 800.0,
            'grand_total' => 800.0,
            'paid_amount' => 0.0,
            'due_amount' => 800.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
        ]);

        // Numeric warehouse ID must resolve cleanly & include available_filters
        $allRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/ap-aging?warehouse={$this->wh1->id}&as_of_date=2026-11-20")
            ->assertStatus(200);
        $this->assertEquals(2, (int)$allRes->json('pagination.total'));
        $this->assertEqualsWithDelta(3300.0, (float)$allRes->json('summary.total_payable'), 0.01);
        $this->assertNotEmpty($allRes->json('available_filters.warehouses'));

        // risk_filter=urgent returns strictly Urgent Supplier Co (2500.0)
        $urgRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/ap-aging?warehouse={$this->wh1->id}&as_of_date=2026-11-20&risk_filter=urgent")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$urgRes->json('pagination.total'));
        $this->assertEqualsWithDelta(2500.0, (float)$urgRes->json('summary.total_payable'), 0.01);
        $this->assertEquals('urgent', $urgRes->json('data.0.risk_status'));

        // Branch authorization + cost_masked guard: scoped user without products.view_cost gets cost_masked=true & 403 on disallowed/unknown warehouse
        $scopedUser = $this->makeScopedUser('ap_wh1_mgr', ['report_ap_aging.view'], [$this->wh1->id]);
        $maskedRes = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ap-aging?warehouse={$this->wh1->name}&as_of_date=2026-11-20")
            ->assertStatus(200);
        $this->assertTrue((bool)$maskedRes->json('cost_masked'));
        $this->assertTrue((bool)$maskedRes->json('summary.cost_masked'));
        $this->assertTrue((bool)$maskedRes->json('data.0.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_payable'), 0.01);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ap-aging?warehouse={$this->wh2->name}&as_of_date=2026-11-20")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/ap-aging?warehouse=UnknownBranch&as_of_date=2026-11-20")
            ->assertStatus(403);
    }

    public function test_stock_ledger_warehouse_id_filters_sections_and_bincard_reconciliation(): void
    {
        $prod = Product::create([
            'name' => 'Ledger Audit Widget',
            'code' => 'LAW-100',
            'category_name' => 'Electronics',
            'unit_name' => 'Pcs',
            'cost_price' => 100.0,
            'selling_price' => 150.0,
            'stock_quantity' => 32.0,
        ]);
        StockBalance::create([
            'product_id' => $prod->id,
            'warehouse_id' => $this->wh1->id,
            'quantity' => 12.0,
        ]);
        StockBalance::create([
            'product_id' => $prod->id,
            'warehouse_id' => $this->wh2->id,
            'quantity' => 20.0,
        ]);

        $sup = Supplier::create(['name' => 'Ledger Sup', 'phone' => '0955555555']);
        $cust = Customer::create(['name' => 'Ledger Cust', 'phone' => '0966666666']);

        $po = Purchase::create([
            'reference_no' => 'PO-SL-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-05 10:00:00',
            'status' => 'Received',
            'subtotal' => 1500.0,
            'grand_total' => 1500.0,
            'paid_amount' => 1500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po->id,
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'product_code' => $prod->code,
            'unit_cost' => 100.0,
            'base_unit_cost' => 100.0,
            'quantity' => 15.0,
            'base_quantity' => 15.0,
            'conversion_rate' => 1.0,
            'subtotal' => 1500.0,
        ]);

        $sale = Sale::create([
            'reference_no' => 'SL-SL-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-10 12:00:00',
            'status' => 'Completed',
            'subtotal' => 450.0,
            'grand_total' => 450.0,
            'paid_amount' => 450.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'product_code' => $prod->code,
            'quantity' => 3.0,
            'base_quantity' => 3.0,
            'conversion_rate' => 1.0,
            'unit_price' => 150.0,
            'subtotal' => 450.0,
            'total_cogs' => 300.0,
        ]);

        // Noise in WH2 must be excluded when filtering by numeric warehouse ID wh1->id
        $poWh2 = Purchase::create([
            'reference_no' => 'PO-SL-WH2',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh2->id,
            'warehouse_name' => $this->wh2->name,
            'date' => '2026-11-06 10:00:00',
            'status' => 'Received',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $poWh2->id,
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'product_code' => $prod->code,
            'unit_cost' => 100.0,
            'base_unit_cost' => 100.0,
            'quantity' => 20.0,
            'base_quantity' => 20.0,
            'conversion_rate' => 1.0,
            'subtotal' => 2000.0,
        ]);

        // Numeric warehouse ID resolves BOTH movements AND bin card period quantities
        $res = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEquals(2, (int)$res->json('summary.total_movements'));
        $this->assertEqualsWithDelta(15.0, (float)$res->json('summary.total_inflow_qty'), 0.01);
        $this->assertEqualsWithDelta(3.0, (float)$res->json('summary.total_outflow_qty'), 0.01);
        $this->assertEqualsWithDelta(12.0, (float)$res->json('summary.net_movement_qty'), 0.01);
        $this->assertNotEmpty($res->json('available_filters.categories'));
        $this->assertContains('Electronics', $res->json('available_filters.categories'));

        $bin = collect($res->json('data'))->firstWhere('product_id', $prod->id);
        $this->assertNotNull($bin);
        $this->assertEqualsWithDelta(15.0, (float)$bin['purchases_in'], 0.01);
        $this->assertEqualsWithDelta(3.0, (float)$bin['sales_out'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$bin['opening_stock'], 0.01);
        $this->assertEqualsWithDelta(12.0, (float)$bin['closing_stock'], 0.01);
        $this->assertEqualsWithDelta(1200.0, (float)$bin['closing_cost_value'], 0.01);

        // Case-insensitive movement_type=IN & section=journal skips Bin Card cleanly
        $inRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30&movement_type=IN&section=journal")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$inRes->json('summary.total_movements'));
        $this->assertEqualsWithDelta(15.0, (float)$inRes->json('summary.total_inflow_qty'), 0.01);
        $this->assertCount(0, $inRes->json('data'));

        // Branch authorization + cost_masked guard: scoped user without products.view_cost gets masked costs & 403 on disallowed/unknown warehouse
        $scopedUser = $this->makeScopedUser('sl_wh1_mgr', ['report_stock_ledger.view'], [$this->wh1->id]);
        $maskedRes = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$this->wh1->name}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertTrue((bool)$maskedRes->json('cost_masked'));
        $this->assertTrue((bool)$maskedRes->json('summary.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_movement_value'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_opening_cost'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_closing_cost'), 0.01);
        $maskedBin = collect($maskedRes->json('data'))->firstWhere('product_id', $prod->id);
        $this->assertNotNull($maskedBin);
        $this->assertEqualsWithDelta(0.0, (float)$maskedBin['cost_price'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$maskedBin['closing_cost_value'], 0.01);

        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name={$this->wh2->name}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/stock-ledger?warehouse_name=UnknownBranch&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(403);
    }

    public function test_product_margins_numeric_warehouse_id_health_filters_and_return_cogs(): void
    {
        $pHigh = Product::create([
            'name' => 'High Margin Latte',
            'code' => 'PM-HIGH',
            'category_name' => 'Coffee',
            'unit_name' => 'Cup',
            'cost_price' => 40.0,
            'selling_price' => 100.0,
        ]);
        $pNeg = Product::create([
            'name' => 'Loss Promo Mug',
            'code' => 'PM-NEG',
            'category_name' => 'Merch',
            'unit_name' => 'Pcs',
            'cost_price' => 120.0,
            'selling_price' => 80.0,
        ]);

        $cust = Customer::create(['name' => 'PM Cust', 'phone' => '0977777777']);
        $sale = Sale::create([
            'reference_no' => 'SL-PM-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-12 10:00:00',
            'status' => 'Completed',
            'subtotal' => 280.0,
            'grand_total' => 280.0,
            'paid_amount' => 280.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $pHigh->id,
            'product_code' => $pHigh->code,
            'product_name' => $pHigh->name,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'conversion_rate' => 1.0,
            'unit_price' => 100.0,
            'subtotal' => 200.0,
            'total_cogs' => 80.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $pNeg->id,
            'product_code' => $pNeg->code,
            'product_name' => $pNeg->name,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'conversion_rate' => 1.0,
            'unit_price' => 80.0,
            'subtotal' => 80.0,
            'total_cogs' => 120.0,
        ]);

        // Unlayered sale return of 1 High Margin Latte (rev -100, cogs fallback -40)
        SaleReturn::create([
            'reference_no' => 'SR-PM-1',
            'sale_reference_no' => 'SL-PM-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-13 11:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'paid_amount' => 100.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'items_payload' => json_encode([
                ['product_id' => $pHigh->id, 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0],
            ]),
        ]);

        // Unfiltered on WH1 by numeric ID: High (rev=100, cogs=40, gp=60) + Neg (rev=80, cogs=120, gp=-40) => total rev=180, cogs=160, gp=20
        $allRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/product-margins?warehouse={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEquals(2, (int)$allRes->json('pagination.total'));
        $this->assertEqualsWithDelta(180.0, (float)$allRes->json('summary.total_revenue'), 0.01);
        $this->assertEqualsWithDelta(160.0, (float)$allRes->json('summary.total_cogs'), 0.01);
        $this->assertEqualsWithDelta(20.0, (float)$allRes->json('summary.total_gross_profit'), 0.01);
        $this->assertContains('Coffee', $allRes->json('available_filters.categories'));

        // Server-side margin_health=negative returns only Loss Promo Mug and recomputes summary
        $negRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/product-margins?warehouse={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30&margin_health=loss")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$negRes->json('pagination.total'));
        $this->assertEquals('PM-NEG', $negRes->json('data.0.code'));
        $this->assertEqualsWithDelta(80.0, (float)$negRes->json('summary.total_revenue'), 0.01);
        $this->assertEqualsWithDelta(120.0, (float)$negRes->json('summary.total_cogs'), 0.01);
        $this->assertEqualsWithDelta(-40.0, (float)$negRes->json('summary.total_gross_profit'), 0.01);

        // Branch authorization + cost_masked guard: scoped user without products.view_cost gets masked COGS/profit & 403 on disallowed/unknown warehouse
        $scopedUser = $this->makeScopedUser('pm_wh1_mgr', ['report_product_margins.view'], [$this->wh1->id]);
        $maskedRes = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/product-margins?warehouse={$this->wh1->name}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertTrue((bool)$maskedRes->json('cost_masked'));
        $this->assertTrue((bool)$maskedRes->json('summary.cost_masked'));
        $this->assertTrue((bool)$maskedRes->json('data.0.cost_masked'));
        $this->assertNull($maskedRes->json('data.0.avg_cost_price'));
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_cogs'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$maskedRes->json('summary.total_gross_profit'), 0.01);
        $this->assertEqualsWithDelta(180.0, (float)$maskedRes->json('summary.total_revenue'), 0.01);

        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/product-margins?warehouse={$this->wh2->name}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/product-margins?warehouse=UnknownBranch&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(403);
    }

    public function test_cashier_audit_merges_multi_role_staff_attributes_open_and_closed_shifts_and_returns_filters(): void
    {
        $cust = Customer::create(['name' => 'CA Walk-in', 'phone' => '0999887766']);

        // Closed shift + Open shift for same cashier "Hla Hla" in WH1
        $closedRegId = \Illuminate\Support\Facades\DB::table('cash_registers')->insertGetId([
            'reference_no' => 'REG-CA-CLS-1',
            'user_id' => $this->admin->id,
            'user_name' => 'Hla Hla',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'register_name' => 'Reg 01',
            'status' => 'closed',
            'opening_cash' => 100.0,
            'expected_cash' => 200.0,
            'closing_cash' => 190.0,
            'cash_difference' => -10.0,
            'opened_at' => '2026-11-14 08:00:00',
            'closed_at' => '2026-11-14 16:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $openRegId = \Illuminate\Support\Facades\DB::table('cash_registers')->insertGetId([
            'reference_no' => 'REG-CA-OPN-2',
            'user_id' => $this->admin->id,
            'user_name' => 'Hla Hla',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'register_name' => 'Reg 01',
            'status' => 'open',
            'opening_cash' => 50.0,
            'expected_cash' => 50.0,
            'opened_at' => '2026-11-15 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Two sales for Hla Hla with DIFFERENT user_role values (must merge, never overwrite!)
        Sale::create([
            'reference_no' => 'SL-CA-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $closedRegId,
            'date' => '2026-11-14 10:00:00',
            'status' => 'Completed',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'paid_amount' => 100.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_name' => 'Hla Hla',
            'created_by' => 'Hla Hla',
            'user_role' => 'Cashier',
        ]);

        Sale::create([
            'reference_no' => 'SL-CA-2',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $openRegId,
            'date' => '2026-11-15 11:00:00',
            'status' => 'Completed',
            'subtotal' => 75.0,
            'grand_total' => 75.0,
            'paid_amount' => 75.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_name' => 'Hla Hla',
            'created_by' => 'Hla Hla',
            'user_role' => 'Supervisor',
        ]);

        $res = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cashier-audit?warehouse={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);

        $hla = collect($res->json('data'))->firstWhere('cashier_name', 'Hla Hla');
        $this->assertNotNull($hla);
        // Merged across both roles: 2 bills, 175.0 sales, 175.0 cash collected
        $this->assertEquals(2, (int)$hla['total_bills']);
        $this->assertEqualsWithDelta(175.0, (float)$hla['total_sales'], 0.01);
        $this->assertEqualsWithDelta(175.0, (float)$hla['cash_collected'], 0.01);
        // Open shift cash = 50 opening + 75 open register cash = 125; Closed = 200 -> Expected = 325, Variance = -10
        $this->assertEqualsWithDelta(325.0, (float)$hla['expected_cash'], 0.01);
        $this->assertEqualsWithDelta(-10.0, (float)$hla['cash_difference'], 0.01);
        $this->assertEquals('watch', $hla['audit_risk_status']);
        $this->assertNotEmpty($res->json('available_filters.warehouses'));
    }

    public function test_dead_stock_velocity_critical_filter_available_filters_and_cost_masking(): void
    {
        $cust = Customer::create(['name' => 'DS Walk-in', 'phone' => '0955443322']);

        $pSlow = Product::create([
            'code' => 'DS-SLOW-1',
            'name' => 'Slow Seasonal Tea',
            'category_name' => 'Beverages',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'selling_price' => 180.0,
            'stock_quantity' => 5,
            'product_unit' => 'Box',
            'barcode_symbol' => 'CODE128',
        ]);
        $pCrit = Product::create([
            'code' => 'DS-CRIT-1',
            'name' => 'Ancient Stale Item',
            'category_name' => 'Accessories',
            'cost_price' => 250.0,
            'purchase_price' => 250.0,
            'selling_price' => 400.0,
            'stock_quantity' => 4,
            'product_unit' => 'Pc',
            'barcode_symbol' => 'CODE128',
        ]);
        \Illuminate\Support\Facades\DB::table('products')->where('id', $pSlow->id)->update(['created_at' => now()->subDays(100)]);
        \Illuminate\Support\Facades\DB::table('products')->where('id', $pCrit->id)->update(['created_at' => now()->subDays(200)]);

        foreach ([[$pSlow->id, 5], [$pCrit->id, 4]] as [$pid, $qty]) {
            \Illuminate\Support\Facades\DB::table('stock_balances')->insert([
                'warehouse_id' => $this->wh1->id,
                'product_id' => $pid,
                'quantity' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Slow item sold 70 days ago (slow: 60 <= 70 < 90); Crit item never sold (200d >= 120d -> critical)
        Sale::create([
            'reference_no' => 'SL-DS-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => now()->subDays(70)->toDateTimeString(),
            'status' => 'Completed',
            'grand_total' => 180.0,
            'paid_amount' => 180.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->where('reference_no', 'SL-DS-1')->value('id');
        SaleItem::create([
            'sale_id' => $saleId,
            'product_id' => $pSlow->id,
            'product_code' => $pSlow->code,
            'product_name' => $pSlow->name,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'conversion_rate' => 1.0,
            'unit_price' => 180.0,
            'subtotal' => 180.0,
            'total_cogs' => 100.0,
        ]);

        // 1. All dead & slow -> 2 items, slow_cap = 500, dead_cap = 1000, available_filters populated
        $allRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dead-stock?warehouse={$this->wh1->id}&days=60")
            ->assertStatus(200);
        $this->assertEquals(2, (int)$allRes->json('pagination.total'));
        $this->assertEqualsWithDelta(500.0, (float)$allRes->json('summary.slow_moving_capital'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$allRes->json('summary.dead_stock_capital'), 0.01);
        $this->assertContains('Accessories', $allRes->json('available_filters.categories'));

        // 2. velocity_status=critical returns only Ancient Stale Item (cap=1000, slow_cap=0)
        $critRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/dead-stock?warehouse={$this->wh1->id}&days=60&velocity_status=critical")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$critRes->json('pagination.total'));
        $this->assertEquals('DS-CRIT-1', $critRes->json('data.0.code'));
        $this->assertEqualsWithDelta(1000.0, (float)$critRes->json('summary.total_tied_up_capital'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$critRes->json('summary.slow_moving_capital'), 0.01);

        // 3. Unknown explicit warehouse returns 0 instead of leaking All outlets
        $emptyWh = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/dead-stock?warehouse=NoSuchOutlet999&days=60')
            ->assertStatus(200);
        $this->assertEquals(0, (int)$emptyWh->json('pagination.total'));
    }

    public function test_commercial_tax_numeric_warehouse_recoverable_input_tax_and_available_filters(): void
    {
        $today = Carbon::today()->toDateString();
        $cust = Customer::create(['name' => 'Tax Audit Customer', 'phone' => '09988776655']);
        $supp = Supplier::create(['name' => 'Tax Audit Supplier', 'phone' => '09112233445']);

        // 1. Taxable sale (header tax = 500 on 10,000 taxable base + 200 shipping = 10,700)
        \Illuminate\Support\Facades\DB::table('sales')->insert([
            'reference_no' => 'SL-CT-TAX-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => "{$today} 10:00:00",
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'discount' => 0.0,
            'tax_rate' => 5.0,
            'tax_amount' => 500.0,
            'shipping' => 200.0,
            'grand_total' => 10700.0,
            'paid_amount' => 10700.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // 2. Non-taxable 0% sale (must NOT inflate sales_count or taxable_sales_amount)
        \Illuminate\Support\Facades\DB::table('sales')->insert([
            'reference_no' => 'SL-CT-ZERO-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => "{$today} 10:30:00",
            'status' => 'Completed',
            'subtotal' => 5000.0,
            'tax_amount' => 0.0,
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // 3. Sale return with 100 output tax refund
        \Illuminate\Support\Facades\DB::table('sale_returns')->insert([
            'reference_no' => 'SR-CT-1',
            'sale_reference_no' => 'SL-CT-TAX-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => "{$today} 11:00:00",
            'status' => 'Completed',
            'subtotal' => 2000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 100.0,
            'shipping' => 0.0,
            'grand_total' => 2100.0,
            'paid_amount' => 2100.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // 4. Recoverable purchase (input tax = 300 on 6,000) vs Non-recoverable purchase (tax = 250 ignored)
        \Illuminate\Support\Facades\DB::table('purchases')->insert([
            [
                'reference_no' => 'PO-CT-REC-1',
                'supplier_id' => $supp->id,
                'supplier_name' => $supp->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => "{$today} 12:00:00",
                'status' => 'Received',
                'tax_rate' => 5.0,
                'tax_amount' => 300.0,
                'is_tax_recoverable' => 1,
                'shipping' => 0.0,
                'grand_total' => 6300.0,
                'paid_amount' => 6300.0,
                'due_amount' => 0.0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
            ],
            [
                'reference_no' => 'PO-CT-NONREC-1',
                'supplier_id' => $supp->id,
                'supplier_name' => $supp->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => "{$today} 12:30:00",
                'status' => 'Received',
                'tax_rate' => 5.0,
                'tax_amount' => 250.0,
                'is_tax_recoverable' => 0,
                'shipping' => 0.0,
                'grand_total' => 5250.0,
                'paid_amount' => 5250.0,
                'due_amount' => 0.0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
            ],
        ]);

        // 5. Recoverable purchase return recovering 50 input tax
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'reference_no' => 'PR-CT-REC-1',
            'purchase_reference_no' => 'PO-CT-REC-1',
            'supplier_id' => $supp->id,
            'supplier_name' => $supp->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => "{$today} 13:00:00",
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 50.0,
            'is_tax_recoverable' => 1,
            'shipping' => 0.0,
            'grand_total' => 1050.0,
            'paid_amount' => 1050.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Filter by numeric warehouse id
        $res = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/commercial-tax?warehouse={$this->wh1->id}&start_date={$today}&end_date={$today}")
            ->assertStatus(200);

        $this->assertEquals(1, (int)$res->json('output_tax.sales_count'));
        $this->assertEqualsWithDelta(500.0, (float)$res->json('output_tax.gross_sales_tax'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$res->json('output_tax.returns_tax_refund'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$res->json('output_tax.net_output_tax'), 0.01);
        // Net taxable sales = (10700 - 200 - 500) - 2000 = 8000; gross invoice totals reconcile across all pages
        $this->assertEqualsWithDelta(8000.0, (float)$res->json('output_tax.taxable_sales_amount'), 0.01);
        $this->assertEqualsWithDelta(10000.0, (float)$res->json('output_tax.gross_taxable_sales'), 0.01);
        $this->assertEqualsWithDelta(10700.0, (float)$res->json('output_tax.gross_sales_total'), 0.01);

        $this->assertEquals(1, (int)$res->json('input_tax.purchases_count'));
        $this->assertEqualsWithDelta(300.0, (float)$res->json('input_tax.gross_purchases_tax'), 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$res->json('input_tax.returns_tax_recovered'), 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$res->json('input_tax.net_input_tax'), 0.01);
        // Net taxable purchases = 6000 - 1000 = 5000; gross recoverable purchase totals reconcile
        $this->assertEqualsWithDelta(5000.0, (float)$res->json('input_tax.taxable_purchases_amount'), 0.01);
        $this->assertEqualsWithDelta(6000.0, (float)$res->json('input_tax.gross_taxable_purchases'), 0.01);
        $this->assertEqualsWithDelta(6300.0, (float)$res->json('input_tax.gross_purchases_total'), 0.01);

        // Net Tax Payable = 400 - 250 = 150
        $this->assertEqualsWithDelta(150.0, (float)$res->json('tax_reconciliation.net_tax_payable'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$res->json('tax_reconciliation.tax_credit_forward'), 0.01);
        $this->assertContains($this->wh1->name, $res->json('available_filters.warehouses') ?? []);

        // Guard against string-starting-with-digit casting to wrong outlet ID (e.g. "2nd Branch Nonexistent")
        $nonNumericPrefixRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/commercial-tax?warehouse={$this->wh1->id}ndNonExistentBranch&start_date={$today}&end_date={$today}")
            ->assertStatus(200);
        $this->assertEquals(0, (int)$nonNumericPrefixRes->json('output_tax.sales_count'));
        $this->assertEquals(0, (int)$nonNumericPrefixRes->json('input_tax.purchases_count'));
        $this->assertContains($this->wh1->name, $nonNumericPrefixRes->json('available_filters.warehouses') ?? []);
    }

    public function test_stock_report_branch_scoping_and_low_stock_only_export_accounting(): void
    {
        // Global stock = 100 (at WH1 only), at WH2 stock = 4 (<= alert 10 -> low_stock_only)
        $prodWh1Only = Product::create([
            'name' => 'Branch Scoped InStock WH1 Only',
            'code' => 'STK-WH1-ONLY',
            'sku' => 'STK-WH1-ONLY',
            'cost_price' => 200.0,
            'purchase_price' => 200.0,
            'selling_price' => 350.0,
            'retail_price' => 350.0,
            'stock_quantity' => 50.0,
            'alert_quantity' => 10.0,
            'is_active' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $prodWh1Only->id,
            'quantity' => 50.0,
        ]);

        $prodWh2Low = Product::create([
            'name' => 'Branch Scoped Low WH2',
            'code' => 'STK-WH2-LOW',
            'sku' => 'STK-WH2-LOW',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'selling_price' => 180.0,
            'retail_price' => 180.0,
            'stock_quantity' => 4.0,
            'alert_quantity' => 10.0,
            'is_active' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh2->id,
            'product_id' => $prodWh2Low->id,
            'quantity' => 4.0,
        ]);

        // Manager restricted ONLY to wh2 with warehouse_id=All:
        // in_stock MUST only return WH2-low (NOT WH1-only even though global stock_quantity=50!)
        $wh2Manager = $this->makeScopedUser(
            'stock-wh2-mgr-' . uniqid(),
            ['report_stock.view', 'report_stock.export'],
            [$this->wh2->id]
        );

        $inStockRes = $this->withToken($wh2Manager->api_token)
            ->getJson('/api/reports/stock?warehouse_id=All&stock_status=in_stock')
            ->assertStatus(200);
        $codes = collect($inStockRes->json('data.items'))->pluck('code')->all();
        $this->assertContains('STK-WH2-LOW', $codes);
        $this->assertNotContains('STK-WH1-ONLY', $codes);

        // WH2 low_stock_only check (api + export CSV must agree on alert threshold)
        $lowOnlyRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?warehouse_id={$this->wh2->id}&stock_status=low_stock_only")
            ->assertStatus(200);
        $lowCodes = collect($lowOnlyRes->json('data.items'))->pluck('code')->all();
        $this->assertContains('STK-WH2-LOW', $lowCodes);
        $this->assertNotContains('STK-WH1-ONLY', $lowCodes);

        $exportRes = $this->withToken($this->admin->api_token)
            ->get("/api/reports/stock/export?warehouse_id={$this->wh2->id}&stock_status=low_stock_only")
            ->assertStatus(200);
        $csvContent = $exportRes->streamedContent();
        $this->assertStringContainsString('STK-WH2-LOW', $csvContent);
        $this->assertStringNotContainsString('STK-WH1-ONLY', $csvContent);

        // Mixed-variant low_stock_only vs out_of_stock KPI accuracy:
        // Out-of-stock sibling variant must NOT pollute low_stock_only restock cost / out_of_stock_count
        $mixedVarProd = Product::create([
            'name' => 'Mixed Low & Out Variant Product',
            'code' => 'STK-MIX-VAR',
            'sku' => 'STK-MIX-VAR',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'selling_price' => 200.0,
            'retail_price' => 200.0,
            'stock_quantity' => 3.0,
            'alert_quantity' => 10.0,
            'has_variants' => true,
            'is_active' => true,
        ]);
        $varLow = \App\Models\ProductVariant::create([
            'product_id' => $mixedVarProd->id,
            'name' => 'Mix Low Variant',
            'sku' => 'STK-MIX-VAR-LOW',
            'cost_price' => 100.0,
            'selling_price' => 200.0,
            'is_active' => true,
        ]);
        $varOut = \App\Models\ProductVariant::create([
            'product_id' => $mixedVarProd->id,
            'name' => 'Mix Out Variant',
            'sku' => 'STK-MIX-VAR-OUT',
            'cost_price' => 100.0,
            'selling_price' => 200.0,
            'is_active' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $mixedVarProd->id,
            'product_variant_id' => $varLow->id,
            'quantity' => 3.0,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $mixedVarProd->id,
            'product_variant_id' => $varOut->id,
            'quantity' => 0.0,
        ]);

        $mixLowOnlyRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?warehouse_id={$this->wh1->id}&stock_status=low_stock_only&search=STK-MIX-VAR")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$mixLowOnlyRes->json('data.summary.low_stock_count'));
        $this->assertEquals(0, (int)$mixLowOnlyRes->json('data.summary.out_of_stock_count'));
        // Deficit = (10 - 3) = 7 @ 100 = 700 (excludes out variant deficit 10*100)
        $this->assertEqualsWithDelta(700.0, (float)$mixLowOnlyRes->json('data.summary.estimated_restock_cost'), 0.01);

        $mixOutOnlyRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/stock?warehouse_id={$this->wh1->id}&stock_status=out_of_stock&search=STK-MIX-VAR")
            ->assertStatus(200);
        $this->assertEquals(0, (int)$mixOutOnlyRes->json('data.summary.low_stock_count'));
        $this->assertEquals(1, (int)$mixOutOnlyRes->json('data.summary.out_of_stock_count'));
        // Deficit = (10 - 0) = 10 @ 100 = 1000 (excludes low variant deficit 7*100)
        $this->assertEqualsWithDelta(1000.0, (float)$mixOutOnlyRes->json('data.summary.estimated_restock_cost'), 0.01);
    }

    public function test_top_selling_report_capped_limit_summary_and_branch_guard(): void
    {
        $pTop1 = Product::create([
            'name' => 'Top Cap One',
            'code' => 'TC-01',
            'sku' => 'TC-01',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'selling_price' => 500.0,
            'retail_price' => 500.0,
            'stock_quantity' => 20.0,
            'is_active' => true,
        ]);
        $pTop2 = Product::create([
            'name' => 'Top Cap Two',
            'code' => 'TC-02',
            'sku' => 'TC-02',
            'cost_price' => 100.0,
            'purchase_price' => 100.0,
            'selling_price' => 200.0,
            'retail_price' => 200.0,
            'stock_quantity' => 20.0,
            'is_active' => true,
        ]);
        $s = Sale::create([
            'reference_no' => 'SL-TC-CAP-1',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-22 10:00:00',
            'status' => 'Completed',
            'subtotal' => 700.0,
            'grand_total' => 700.0,
            'paid_amount' => 700.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $s->id,
            'product_id' => $pTop1->id,
            'product_code' => $pTop1->code,
            'product_name' => $pTop1->name,
            'unit_price' => 500.0,
            'base_unit_price' => 500.0,
            'base_unit_cost' => 100.0,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'subtotal' => 500.0,
            'total_cogs' => 100.0,
        ]);
        SaleItem::create([
            'sale_id' => $s->id,
            'product_id' => $pTop2->id,
            'product_code' => $pTop2->code,
            'product_name' => $pTop2->name,
            'unit_price' => 200.0,
            'base_unit_price' => 200.0,
            'base_unit_cost' => 100.0,
            'quantity' => 1.0,
            'base_quantity' => 1.0,
            'subtotal' => 200.0,
            'total_cogs' => 100.0,
        ]);

        // limit=1 (Top 1): summary.total_revenue MUST reflect ONLY the Top 1 (500.0, not 700.0), so average_revenue = 500.0!
        $capRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/top-selling?warehouse_name={$this->wh1->id}&limit=1")
            ->assertStatus(200);
        $this->assertEquals(1, (int)$capRes->json('summary.total_products'));
        $this->assertEqualsWithDelta(500.0, (float)$capRes->json('summary.total_revenue'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$capRes->json('summary.average_revenue'), 0.01);

        // Unauthorized warehouse request for scoped user returns 403
        $wh1Mgr = $this->makeScopedUser('ts-wh1-mgr-' . uniqid(), ['report_top_selling.view'], [$this->wh1->id]);
        $this->withToken($wh1Mgr->api_token)
            ->getJson("/api/reports/top-selling?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
    }

    public function test_supplier_report_and_statement_accounting_reconciliation_and_branch_guard(): void
    {
        $sup = Supplier::create([
            'name' => 'Delta Audit Distributors',
            'phone' => '0944112233',
            'category' => 'Electronics',
            'payment_terms' => 'Net 30 Days',
            'status' => 'Active',
        ]);

        // Prior month purchase in wh1
        Purchase::create([
            'reference_no' => 'PO-AUD-DEL-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Received',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Return in Nov exceeding Nov purchases -> signed net_purchased (-400) & net_paid (-400) must match between supplierReport & supplierStatement
        PurchaseReturn::create([
            'reference_no' => 'PR-AUD-DEL-1',
            'purchase_reference_no' => 'PO-AUD-DEL-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 11:00:00',
            'status' => 'Completed',
            'grand_total' => 400.0,
            'paid_amount' => 400.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $rptRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers?search=Delta%20Audit&warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-400.0, (float)$rptRes->json('data.summary.net_purchased'), 0.01);
        $this->assertEqualsWithDelta(-400.0, (float)$rptRes->json('data.summary.net_paid'), 0.01);

        $stmtRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/suppliers/{$sup->id}/statement?warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-400.0, (float)$stmtRes->json('data.summary.net_purchased'), 0.01);
        $this->assertEqualsWithDelta(-400.0, (float)$stmtRes->json('data.summary.net_paid'), 0.01);

        // Unauthorized warehouse request for scoped user returns 403 on both supplierReport and supplierStatement
        $supWh1Mgr = $this->makeScopedUser('sup-wh1-mgr-' . uniqid(), ['report_supplier.view'], [$this->wh1->id]);
        $this->withToken($supWh1Mgr->api_token)
            ->getJson("/api/reports/suppliers?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($supWh1Mgr->api_token)
            ->getJson("/api/reports/suppliers/{$sup->id}/statement?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
    }

    public function test_customer_report_and_statement_signed_net_reconciliation_and_branch_guard(): void
    {
        $cust = Customer::create([
            'customer_id' => 'CUST-AUD-DEL',
            'name' => 'Epsilon Retail Buyer',
            'phone' => '0955112233',
            'tier' => 'Gold',
            'status' => 'Active',
        ]);

        // Prior month invoice in wh1
        Sale::create([
            'reference_no' => 'SL-CUST-DEL-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Return in Nov exceeding Nov sales -> signed net_revenue (-300) & net_paid (-300) reconcile 1:1
        SaleReturn::create([
            'reference_no' => 'SR-CUST-DEL-1',
            'sale_reference_no' => 'SL-CUST-DEL-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-15 12:00:00',
            'status' => 'Completed',
            'grand_total' => 300.0,
            'paid_amount' => 300.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $rptRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/customers?search=CUST-AUD-DEL&warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-300.0, (float)$rptRes->json('data.summary.net_revenue'), 0.01);
        $this->assertEqualsWithDelta(-300.0, (float)$rptRes->json('data.summary.net_paid'), 0.01);

        $stmtRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/customers/{$cust->id}/statement?warehouse_name={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-300.0, (float)$stmtRes->json('data.summary.net_invoiced'), 0.01);
        $this->assertEqualsWithDelta(-300.0, (float)$stmtRes->json('data.summary.net_paid'), 0.01);

        // Unauthorized warehouse request returns 403 on both customerReport and customerStatement
        $custWh1Mgr = $this->makeScopedUser('cust-wh1-mgr-' . uniqid(), ['report_customer.view'], [$this->wh1->id]);
        $this->withToken($custWh1Mgr->api_token)
            ->getJson("/api/reports/customers?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($custWh1Mgr->api_token)
            ->getJson("/api/reports/customers/{$cust->id}/statement?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
    }

    public function test_expense_report_split_tender_isolation_and_branch_guard(): void
    {
        $splitExp = Expense::create([
            'reference_no' => 'EXP-SPLIT-AUD-1',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Utilities & Power',
            'title' => 'Mixed Cash & KBZPay Utility',
            'amount' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'Split Payment',
            'date' => '2026-11-20 09:00:00',
            'created_by' => 'Admin Account',
        ]);
        ExpensePayment::create([
            'expense_id' => $splitExp->id,
            'reference_no' => 'EP-SPLIT-AUD-1',
            'payment_type' => 'Cash',
            'amount' => 400.0,
            'payment_date' => '2026-11-20 09:00:00',
        ]);
        ExpensePayment::create([
            'expense_id' => $splitExp->id,
            'reference_no' => 'EP-SPLIT-AUD-2',
            'payment_type' => 'KBZPay',
            'amount' => 600.0,
            'payment_date' => '2026-11-20 09:00:00',
        ]);

        // Unfiltered: full split breakdown reconciles
        $allRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/expenses?warehouse_name={$this->wh1->id}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(1000.0, (float)$allRes->json('data.summary.total_paid'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$allRes->json('data.summary.cash_paid'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$allRes->json('data.summary.digital_paid'), 0.01);

        // Single-tender filter (Cash): must NOT leak KBZPay 600 into digital_paid or total_paid
        $cashRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/expenses?warehouse_name={$this->wh1->id}&payment_method=Cash")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(400.0, (float)$cashRes->json('data.summary.total_paid'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$cashRes->json('data.summary.cash_paid'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$cashRes->json('data.summary.digital_paid'), 0.01);
        $methods = $cashRes->json('data.by_payment_method');
        $this->assertCount(1, $methods);
        $this->assertSame('Cash', $methods[0]['payment_method']);
        $this->assertEqualsWithDelta(100.0, (float)$methods[0]['percentage'], 0.01);

        // Unauthorized outlet guard returns 403
        $expWh1Mgr = $this->makeScopedUser('exp-wh1-mgr-' . uniqid(), ['report_expense.view'], [$this->wh1->id]);
        $this->withToken($expWh1Mgr->api_token)
            ->getJson("/api/reports/expenses?warehouse_name={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($expWh1Mgr->api_token)
            ->getJson("/api/reports/expenses?warehouse_id={$this->wh2->id}")
            ->assertStatus(403);
    }

    public function test_sale_returns_report_split_tender_isolation_and_branch_guard(): void
    {
        $cust = Customer::create(['name' => 'Split Return Buyer', 'phone' => '0944551100', 'status' => 'Active']);
        SaleReturn::create([
            'reference_no' => 'SR-SPLIT-AUD-1',
            'sale_reference_no' => 'SL-SPLIT-AUD-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-20 10:00:00',
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Split Payment',
            'payments_payload' => json_encode([
                ['payment_type' => 'Cash', 'amount' => 400.0],
                ['payment_type' => 'KBZPay', 'amount' => 600.0],
            ]),
            'created_by' => 'Audit Cashier',
            'user_name' => 'Audit Cashier',
        ]);

        // Unfiltered: full Cash + KBZPay split reconciliation
        $allRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/sale-returns?warehouse={$this->wh1->id}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(1000.0, (float)$allRes->json('summary.total_paid_amount'), 0.01);
        $this->assertEqualsWithDelta(400.0, (float)$allRes->json('summary.cash_refunded'), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)$allRes->json('summary.digital_refunded'), 0.01);

        // Cash filter matches split return containing Cash leg and reconciles all legs of matched voucher
        $cashRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/sale-returns?warehouse={$this->wh1->id}&payment_type=Cash")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(1000.0, (float)$cashRes->json('summary.total_paid_amount'), 0.01);
        $tenders = collect($cashRes->json('by_refund_tender'))->keyBy('payment_type');
        $this->assertEqualsWithDelta(400.0, (float)($tenders['Cash']['total_amount'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(600.0, (float)($tenders['KBZPay']['total_amount'] ?? 0), 0.01);

        // Unauthorized outlet guard returns 403
        $srWh1Mgr = $this->makeScopedUser('sr-wh1-mgr-' . uniqid(), ['report_sales_return.view'], [$this->wh1->id]);
        $this->withToken($srWh1Mgr->api_token)
            ->getJson("/api/reports/sale-returns?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($srWh1Mgr->api_token)
            ->getJson("/api/reports/sale-returns?warehouse_id={$this->wh2->id}")
            ->assertStatus(403);
    }

    public function test_purchase_returns_report_credit_note_filter_and_branch_guard(): void
    {
        $sup = Supplier::create(['name' => 'Credit Note Vendor', 'phone' => '0944551188', 'status' => 'Active']);
        PurchaseReturn::create([
            'reference_no' => 'PR-CN-AUD-1',
            'purchase_reference_no' => 'PO-CN-AUD-1',
            'supplier_id' => $sup->id,
            'supplier_name' => $sup->name,
            'warehouse_id' => $this->wh1->id,
            'outlet_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-20 11:00:00',
            'status' => 'Completed',
            'grand_total' => 1200.0,
            'paid_amount' => 450.0,
            'due_amount' => 750.0,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'created_by' => 'Audit Purchaser',
            'user_name' => 'Audit Purchaser',
        ]);

        // Credit Note filter matches partial/unpaid return with due > 0 even when primary payment_type is Cash
        $cnRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/purchase-returns?warehouse={$this->wh1->id}&payment_type=" . urlencode('Credit Note'))
            ->assertStatus(200);
        $this->assertEquals(1, (int)$cnRes->json('summary.total_returns'));
        $this->assertEqualsWithDelta(450.0, (float)$cnRes->json('summary.cash_refunded'), 0.01);
        $this->assertEqualsWithDelta(750.0, (float)$cnRes->json('summary.credit_note_issued'), 0.01);

        // Unauthorized outlet guard returns 403 on numeric id, name, or disallowed outlet
        $prWh1Mgr = $this->makeScopedUser('pr-wh1-mgr-' . uniqid(), ['report_purchase_return.view'], [$this->wh1->id]);
        $this->withToken($prWh1Mgr->api_token)
            ->getJson("/api/reports/purchase-returns?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($prWh1Mgr->api_token)
            ->getJson('/api/reports/purchase-returns?warehouse=' . urlencode($this->wh2->name))
            ->assertStatus(403);
    }

    public function test_register_report_no_overlapping_double_count_and_branch_guard(): void
    {
        $cashier1 = $this->makeScopedUser('reg-ovl-1-' . uniqid(), ['report_register.view'], [$this->wh1->id]);
        $cashier2 = $this->makeScopedUser('reg-ovl-2-' . uniqid(), ['report_register.view'], [$this->wh1->id]);

        CashRegister::create([
            'reference_no' => 'REG-OVL-01',
            'register_name' => 'Register 01',
            'user_id' => $cashier1->id,
            'user_name' => 'Cashier One',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 500.0,
            'status' => 'open',
            'opened_at' => '2026-11-21 08:00:00',
        ]);
        CashRegister::create([
            'reference_no' => 'REG-OVL-02',
            'register_name' => 'Register 02',
            'user_id' => $cashier2->id,
            'user_name' => 'Cashier Two',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 500.0,
            'status' => 'open',
            'opened_at' => '2026-11-21 08:00:00',
        ]);

        // Unlinked cash expense (cash_register_id = null) during overlapping shift hours
        \Illuminate\Support\Facades\DB::table('expenses')->insert([
            'reference_no' => 'EXP-OVL-900',
            'title' => 'Overlapping Petty Cash',
            'expense_category_name' => 'Operations',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-21',
            'amount' => 150.0,
            'paid_amount' => 150.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'created_at' => '2026-11-21 10:30:00',
            'updated_at' => '2026-11-21 10:30:00',
        ]);

        // Total expenses across both overlapping shifts must equal 150.00 (never 300.00 double-counted)
        $res = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/register?warehouse={$this->wh1->id}&start_date=2026-11-21&end_date=2026-11-21")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(150.0, (float)$res->json('summary.total_expenses'), 0.01);
        $this->assertEqualsWithDelta(150.0, (float)$res->json('drawer_flow_summary.total_cash_expenses'), 0.01);

        // Unauthorized outlet guard (ID, known name, unknown name)
        $this->withToken($cashier1->api_token)
            ->getJson("/api/reports/register?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($cashier1->api_token)
            ->getJson('/api/reports/register?warehouse=' . urlencode($this->wh2->name))
            ->assertStatus(403);
        $this->withToken($cashier1->api_token)
            ->getJson('/api/reports/register?warehouse=' . urlencode('Secret Branch X'))
            ->assertStatus(403);
    }

    public function test_dead_stock_scoped_user_unmatched_warehouse_returns_403_and_masks_cost(): void
    {
        $scopedUser = $this->makeScopedUser('ds-scoped-' . uniqid(), ['report_dead_stock.view'], [$this->wh1->id]);

        $prod = Product::create([
            'code' => 'DS-SCOPED-1',
            'sku' => 'DS-SCOPED-1',
            'name' => 'Scoped Old Item',
            'category_name' => 'General',
            'unit_name' => 'Pcs',
            'cost_price' => 4500.0,
            'selling_price' => 7000.0,
            'stock_quantity' => 10.0,
            'created_at' => now()->subDays(120)->toDateTimeString(),
            'updated_at' => now()->subDays(120)->toDateTimeString(),
        ]);
        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $prod->id)
            ->update(['created_at' => now()->subDays(120)->toDateTimeString()]);
        StockBalance::create([
            'product_id' => $prod->id,
            'warehouse_id' => $this->wh1->id,
            'quantity' => 10.0,
        ]);

        // Scoped user cannot query another outlet or unknown/arbitrary warehouse string
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/dead-stock?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson('/api/reports/dead-stock?warehouse=' . urlencode($this->wh2->name))
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson('/api/reports/dead-stock?warehouse=' . urlencode('UnknownBranch999'))
            ->assertStatus(403);

        // Authorized outlet for scoped user without product.view_cost returns 200 with cost_masked=true & zeroed costs
        $res = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/dead-stock?warehouse={$this->wh1->id}&days_inactive=60")
            ->assertStatus(200);
        $this->assertTrue((bool)$res->json('summary.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$res->json('summary.total_tied_up_capital'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$res->json('summary.dead_stock_capital'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$res->json('summary.slow_moving_capital'), 0.01);
        $this->assertTrue((bool)$res->json('data.0.cost_masked'));
        $this->assertEqualsWithDelta(0.0, (float)$res->json('data.0.cost_price'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$res->json('data.0.tied_up_capital'), 0.01);
        $this->assertEqualsWithDelta(70000.0, (float)$res->json('data.0.potential_revenue'), 0.01);
    }

    public function test_cashier_audit_scoped_user_403_and_mixed_closed_open_shift_refund_no_double_subtraction(): void
    {
        $blindScopedUser = $this->makeScopedUser('ca-blind-' . uniqid(), ['report_cashier_audit.view'], [$this->wh1->id]);
        $scopedUser = $this->makeScopedUser('ca-scoped-' . uniqid(), ['report_cashier_audit.view', 'cash_register.view_expected'], [$this->wh1->id]);
        $cust = Customer::create(['name' => 'CA Shift Audit Cust', 'phone' => '0988776655']);

        // Closed shift (settled 500 cash refund inside its expected_cash=150) + Open shift (opening=100, open cash sale=80)
        $closedRegId = \Illuminate\Support\Facades\DB::table('cash_registers')->insertGetId([
            'reference_no' => 'REG-MIX-CLS',
            'user_id' => $this->admin->id,
            'user_name' => 'Maung Maung',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'register_name' => 'Counter 1',
            'status' => 'closed',
            'opening_cash' => 100.0,
            'expected_cash' => 150.0,
            'closing_cash' => 150.0,
            'cash_difference' => 0.0,
            'opened_at' => '2026-11-18 08:00:00',
            'closed_at' => '2026-11-18 17:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $openRegId = \Illuminate\Support\Facades\DB::table('cash_registers')->insertGetId([
            'reference_no' => 'REG-MIX-OPN',
            'user_id' => $this->admin->id,
            'user_name' => 'Maung Maung',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'register_name' => 'Counter 1',
            'status' => 'open',
            'opening_cash' => 100.0,
            'expected_cash' => 180.0,
            'opened_at' => '2026-11-19 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sale::create([
            'reference_no' => 'SL-MIX-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $openRegId,
            'date' => '2026-11-19 10:00:00',
            'status' => 'Completed',
            'subtotal' => 80.0,
            'grand_total' => 80.0,
            'paid_amount' => 80.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_name' => 'Maung Maung',
            'created_by' => 'Maung Maung',
        ]);

        // Closed-shift return during closed shift hours (must NOT be subtracted again from openReg expected_cash!)
        \Illuminate\Support\Facades\DB::table('sale_returns')->insert([
            'reference_no' => 'SR-MIX-CLS',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-11-18 12:00:00',
            'status' => 'Completed',
            'grand_total' => 30.0,
            'paid_amount' => 30.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'refund_tender' => 'Cash',
            'user_name' => 'Maung Maung',
            'created_by' => 'Maung Maung',
            'created_at' => '2026-11-18 12:00:00',
            'updated_at' => '2026-11-18 12:00:00',
        ]);

        // Scoped user must get 403 (never 500 undefined variable!) on foreign or unknown outlet
        $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/cashier-audit?warehouse={$this->wh2->id}")
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson('/api/reports/cashier-audit?warehouse=' . urlencode($this->wh2->name))
            ->assertStatus(403);
        $this->withToken($scopedUser->api_token)
            ->getJson('/api/reports/cashier-audit?warehouse=' . urlencode('UnknownBranch999'))
            ->assertStatus(403);

        // Blind-count enforcement: user without cash_register.view_expected gets masked expected_cash/cash_difference
        $blindRes = $this->withToken($blindScopedUser->api_token)
            ->getJson("/api/reports/cashier-audit?warehouse={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $blindMg = collect($blindRes->json('data'))->firstWhere('cashier_name', 'Maung Maung');
        $this->assertNotNull($blindMg);
        $this->assertTrue((bool)$blindMg['blind_count_masked']);
        $this->assertNull($blindMg['expected_cash']);
        $this->assertNull($blindMg['counted_cash']);
        $this->assertNull($blindMg['cash_difference']);

        // Authorized outlet check (with cash_register.view_expected): expected_cash = closed(150) + open(100 opening + 80 sale - 0 open refund) = 330.0 (never 300.0!)
        // net_revenue = 80 sale - 30 return = 50.0, reflected in summary.total_net_revenue
        $res = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/cashier-audit?warehouse={$this->wh1->id}&start_date=2026-11-01&end_date=2026-11-30")
            ->assertStatus(200);
        $mg = collect($res->json('data'))->firstWhere('cashier_name', 'Maung Maung');
        $this->assertNotNull($mg);
        $this->assertFalse((bool)$mg['blind_count_masked']);
        $this->assertEqualsWithDelta(330.0, (float)$mg['expected_cash'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$mg['net_revenue'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float)$res->json('summary.total_net_revenue'), 0.01);
    }
}
