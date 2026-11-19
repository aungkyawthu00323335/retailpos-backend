<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FifoInventoryService;
use App\Services\InventoryReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class Stage2AccountingHardeningTest extends TestCase
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
            'name' => 'Stage2 Main Warehouse',
            'code' => 'WH-S2-01',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->wh2 = Outlet::create([
            'name' => 'Stage2 Branch Store',
            'code' => 'BR-S2-02',
            'is_warehouse' => false,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-S2-ADM',
            'name' => 'Stage2 Super Admin',
            'email' => 's2admin@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 's2_admin_token_001',
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
            'user_id' => 'USR-' . strtoupper(substr(md5($slug), 0, 6)),
            'name' => 'User ' . $slug,
            'email' => $slug . '@retailpos.test',
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

    public function test_sale_return_cumulative_cap_ar_credit_offset_lifo_restore_rbac_and_batch_delete_lock(): void
    {
        $product = Product::create([
            'name' => 'Stage2 Return Coffee',
            'code' => 'S2-RET-01',
            'barcode' => '8852001',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 40,
            'cost_price' => 40,
            'selling_price' => 100,
            'quantity' => 5,
            'alert_quantity' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $saleDate = Carbon::now()->subDay()->toDateTimeString();
        $retDate1 = Carbon::now()->subHours(4)->toDateTimeString();
        $retDate2 = Carbon::now()->subHours(3)->toDateTimeString();
        $retDate3 = Carbon::now()->subHours(2)->toDateTimeString();
        $resellDate = Carbon::now()->subHour()->toDateTimeString();

        // Two distinct cost tranches consumed by the sale: Batch A (2 @ 40), Batch B (3 @ 60)
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 2,
            'remaining_quantity' => 2,
            'unit_cost' => 40.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-S2-A',
            'received_date' => Carbon::now()->subDays(5)->toDateTimeString(),
            'status' => 'active',
        ]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 3,
            'remaining_quantity' => 3,
            'unit_cost' => 60.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-S2-B',
            'received_date' => Carbon::now()->subDays(4)->toDateTimeString(),
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'name' => 'Daw Mya',
            'phone' => '0979100200',
        ]);

        // Create Completed partial-credit sale of 5 units @ 100 = 500 (paid 350, due 150) via API
        $saleRes = $this->withToken($this->admin->api_token)
            ->postJson('/api/sales', [
                'reference_no' => 'SL-S2-RET-1',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $saleDate,
                'status' => 'Completed',
                'subtotal' => 500.0,
                'grand_total' => 500.0,
                'paid_amount' => 350.0,
                'payment_status' => 'Partial',
                'payment_type' => 'Cash',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 5,
                        'unit_price' => 100.0,
                        'subtotal' => 500.0,
                    ],
                ],
                'payments' => [
                    ['amount' => 350.0, 'payment_type' => 'Cash', 'payment_date' => $saleDate],
                ],
            ]);
        $saleRes->assertStatus(201);
        $saleId = (int)$saleRes->json('data.id');

        // Cash-refund RBAC: user with sale_returns.create ONLY (without sale_returns.pay / sales.refund)
        // gets 403 when issuing cash refund on a separate fully Paid sale (no AR credit left to absorb refund)
        $rbacProduct = Product::create([
            'name' => 'Stage2 RBAC Product',
            'code' => 'S2-RBAC-01',
            'barcode' => '8852009',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 40,
            'cost_price' => 40,
            'selling_price' => 100,
            'quantity' => 1,
            'alert_quantity' => 0,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $rbacProduct->id,
            'quantity' => 1,
        ]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $rbacProduct->id,
            'initial_quantity' => 1,
            'remaining_quantity' => 1,
            'unit_cost' => 40.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-S2-RBAC',
            'received_date' => Carbon::now()->subDays(5)->toDateTimeString(),
            'status' => 'active',
        ]);

        $paidSaleRes = $this->withToken($this->admin->api_token)
            ->postJson('/api/sales', [
                'reference_no' => 'SL-S2-PAID-RBAC',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $saleDate,
                'status' => 'Completed',
                'subtotal' => 100.0,
                'grand_total' => 100.0,
                'paid_amount' => 100.0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'items' => [
                    [
                        'product_id' => $rbacProduct->id,
                        'product_code' => $rbacProduct->code,
                        'product_name' => $rbacProduct->name,
                        'quantity' => 1,
                        'unit_price' => 100.0,
                        'subtotal' => 100.0,
                    ],
                ],
                'payments' => [
                    ['amount' => 100.0, 'payment_type' => 'Cash', 'payment_date' => $saleDate],
                ],
            ]);
        $paidSaleRes->assertStatus(201);
        $paidSaleId = (int)$paidSaleRes->json('data.id');

        $clerk = $this->makeScopedUser('sr_clerk', ['sale_returns.create', 'sale_returns.view', 'sales.view'], [$this->wh1->id]);
        $this->withToken($clerk->api_token)
            ->postJson('/api/sale-returns', [
                'sale_id' => $paidSaleId,
                'customer_id' => $customer->id,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $retDate1,
                'status' => 'Completed',
                'paid_amount' => 50.0,
                'payment_type' => 'Cash',
                'refund_tender' => 'Cash',
                'items' => [
                    [
                        'product_id' => $rbacProduct->id,
                        'product_code' => $rbacProduct->code,
                        'product_name' => $rbacProduct->name,
                        'quantity' => 1,
                        'unit_price' => 100.0,
                        'subtotal' => 100.0,
                    ],
                ],
            ])
            ->assertStatus(403);

        // Return #1: 3 units (value 300). Linked sale has due_amount = 150 -> first 150 offsets AR credit, up to 150 paid cash!
        $ret1Res = $this->withToken($this->admin->api_token)
            ->postJson('/api/sale-returns', [
                'reference_no' => 'SR-S2-001',
                'sale_id' => $saleId,
                'customer_id' => $customer->id,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $retDate1,
                'status' => 'Completed',
                'paid_amount' => 100.0,
                'payment_type' => 'Cash',
                'refund_tender' => 'Cash',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 3,
                        'unit_price' => 100.0,
                        'subtotal' => 300.0,
                    ],
                ],
                'payments' => [
                    ['amount' => 100.0, 'payment_type' => 'Cash', 'payment_date' => $retDate1],
                ],
            ]);
        $ret1Res->assertStatus(201);
        $ret1Id = (int)$ret1Res->json('data.id');
        $this->assertEqualsWithDelta(150.0, (float)$ret1Res->json('data.applied_credit_amount'), 0.01);
        // Remaining cash refundable = 300 - 150 (credit offset) - 100 (cash paid) = 50 due_amount
        $this->assertEqualsWithDelta(50.0, (float)$ret1Res->json('data.due_amount'), 0.01);
        // Sale's unpaid due_amount should now be cleared (0.0) by credit offset
        $this->assertEqualsWithDelta(0.0, (float)Sale::find($saleId)->due_amount, 0.01);

        // LIFO restore check: 3 units restored from Batch B (@ 60) -> cost = 180, unit_cost synced onto StockMovement
        $this->assertEqualsWithDelta(180.0, (float)$ret1Res->json('data.return_cost_total'), 0.01);
        $retMove = StockMovement::where('reference_type', 'sale_return')
            ->where('reference_id', (string)$ret1Id)
            ->first();
        $this->assertNotNull($retMove);
        $this->assertEqualsWithDelta(60.0, (float)$retMove->unit_cost, 0.01);

        // Return #2 exceeding cumulative sold qty (3 + 3 > 5) must fail with 422
        $this->withToken($this->admin->api_token)
            ->postJson('/api/sale-returns', [
                'reference_no' => 'SR-S2-OVER',
                'sale_id' => $saleId,
                'customer_id' => $customer->id,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $retDate2,
                'status' => 'Completed',
                'paid_amount' => 0.0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 3,
                        'unit_price' => 100.0,
                        'subtotal' => 300.0,
                    ],
                ],
            ])
            ->assertStatus(422);

        // Return #2 exact remaining 2 units -> LIFO restores from Batch A (@ 40) -> cost = 80
        $ret2Res = $this->withToken($this->admin->api_token)
            ->postJson('/api/sale-returns', [
                'reference_no' => 'SR-S2-002',
                'sale_id' => $saleId,
                'customer_id' => $customer->id,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $retDate3,
                'status' => 'Completed',
                'paid_amount' => 0.0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 2,
                        'unit_price' => 100.0,
                        'subtotal' => 200.0,
                    ],
                ],
            ]);
        $ret2Res->assertStatus(201);
        $this->assertEqualsWithDelta(80.0, (float)$ret2Res->json('data.return_cost_total'), 0.01);

        // Resell restored stock (consumes restored batch down so remaining_quantity < initial_quantity)
        // FIFO consumes oldest active batches first; sell 4 units -> consumes Batch A (2 @ 40) + 2 from Batch B (@ 60)
        $this->withToken($this->admin->api_token)
            ->postJson('/api/sales', [
                'reference_no' => 'SL-S2-RESELL',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'date' => $resellDate,
                'status' => 'Completed',
                'subtotal' => 400.0,
                'grand_total' => 400.0,
                'paid_amount' => 400.0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 4,
                        'unit_price' => 100.0,
                        'subtotal' => 400.0,
                    ],
                ],
            ])
            ->assertStatus(201);

        // Deleting/voiding Return #1 whose restored FIFO batch was already downstream-consumed must return 422
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/sale-returns/{$ret1Id}")
            ->assertStatus(422);
    }

    public function test_transfer_in_transit_valuation_unit_cost_sync_closed_register_lock_and_expense_payment_idor(): void
    {
        $product = Product::create([
            'name' => 'Stage2 Transfer Item',
            'code' => 'S2-TRF-01',
            'barcode' => '8852002',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 2500,
            'cost_price' => 2500,
            'selling_price' => 3500,
            'quantity' => 10,
            'alert_quantity' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'unit_cost' => 2500.0,
            'source_type' => 'purchase',
            'source_reference' => 'PO-S2-TRF',
            'received_date' => '2026-10-01 08:00:00',
            'status' => 'active',
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-S2-TRF',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 5000.0,
            'status' => 'open',
            'opened_at' => '2026-10-06 08:00:00',
        ]);

        $trfRes = $this->withToken($this->admin->api_token)
            ->postJson('/api/transfers', [
                'reference_no' => 'TR-S2-001',
                'from_warehouse_id' => $this->wh1->id,
                'from_warehouse' => $this->wh1->name,
                'to_warehouse_id' => $this->wh2->id,
                'to_warehouse' => $this->wh2->name,
                'date' => '2026-10-06 10:00:00',
                'status' => 'Sent',
                'shipping_charge' => 500.0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'quantity' => 4,
                        'unit_cost' => 2500.0,
                        'subtotal' => 10000.0,
                    ],
                ],
                'payments' => [
                    [
                        'amount' => 500.0,
                        'payment_type' => 'Cash',
                        'payment_date' => '2026-10-06 10:00:00',
                        'cash_register_id' => $register->id,
                    ],
                ],
            ]);
        $trfRes->assertStatus(201);
        $trfId = (int)$trfRes->json('data.id');

        // StockMovement stock_transfer_out carries FIFO unit_cost = 2500.0
        $outMove = StockMovement::where('reference_type', 'stock_transfer')
            ->where('movement_type', 'stock_transfer_out')
            ->where('reference_id', (string)$trfId)
            ->first();
        $this->assertNotNull($outMove);
        $this->assertEqualsWithDelta(2500.0, (float)$outMove->unit_cost, 0.01);

        // InventoryReconciliationService::audit tracks Sent in-transit stock: 4 qty @ 2500 = 10,000
        $audit = app(InventoryReconciliationService::class)->audit();
        $this->assertEqualsWithDelta(4.0, (float)$audit['valuation_summary']['in_transit_qty'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float)$audit['valuation_summary']['in_transit_valuation'], 0.01);

        // Linked shipping expense cannot be deleted once its cash register shift is closed
        $linkedExp = Expense::where('transfer_id', $trfId)->first();
        $this->assertNotNull($linkedExp);

        $register->update([
            'status' => 'closed',
            'closed_at' => '2026-10-06 20:00:00',
        ]);

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/expenses/{$linkedExp->id}")
            ->assertStatus(422);

        // Cross-expense payment IDOR block (deleting E2's payment via E1's route returns 404)
        $expOther = Expense::create([
            'reference_no' => 'EXP-S2-OTHER',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_name' => 'Utilities',
            'title' => 'Power Bill',
            'amount' => 300.0,
            'paid_amount' => 300.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2026-10-06 11:00:00',
        ]);
        $otherPayment = ExpensePayment::create([
            'expense_id' => $expOther->id,
            'reference_no' => 'PAY-EXP-OTHER-1',
            'payment_date' => '2026-10-06 11:00:00',
            'payment_type' => 'Cash',
            'amount' => 300.0,
        ]);

        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/expenses/{$linkedExp->id}/payments/{$otherPayment->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('expense_payments', ['id' => $otherPayment->id]);
    }

    public function test_unpaid_expense_and_ar_exclusions_prefix_immutability_and_linked_category_lock(): void
    {
        $today = Carbon::today()->toDateString();

        $cat = ExpenseCategory::create([
            'name' => 'Logistics & Courier',
            'code' => 'CAT-LOG',
            'status' => 'Active',
        ]);

        $reg = CashRegister::create([
            'reference_no' => 'REG-S2-UNPAID',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 1000.0,
            'status' => 'open',
            'opened_at' => $today . ' 08:00:00',
        ]);

        // Unpaid Credit Sale (400 due, 0 paid) must NOT appear as Cash sales in Z Report / Register Report / Cash Flow
        $sale = Sale::create([
            'reference_no' => 'SL-S2-CR-1',
            'customer_name' => 'Credit Buyer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $reg->id,
            'date' => $today . ' 09:00:00',
            'status' => 'Completed',
            'grand_total' => 400.0,
            'paid_amount' => 0.0,
            'due_amount' => 400.0,
            'payment_type' => 'Credit',
            'payment_status' => 'Unpaid',
        ]);

        // Paid Cash Expense (100) linked to category
        Expense::create([
            'reference_no' => 'EXP-S2-PAID',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_id' => $cat->id,
            'expense_category_name' => $cat->name,
            'title' => 'Courier Parcel Fee',
            'amount' => 100.0,
            'paid_amount' => 100.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'cash_register_id' => $reg->id,
            'date' => $today . ' 10:00:00',
        ]);

        // Unpaid Cash-labeled Expense (999) must NOT reduce Cash Flow, Z Report, or Register drawer!
        Expense::create([
            'reference_no' => 'EXP-S2-UNPAID',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'expense_category_id' => $cat->id,
            'expense_category_name' => $cat->name,
            'title' => 'Pending Contractor Bill',
            'amount' => 999.0,
            'paid_amount' => 0.0,
            'due_amount' => 999.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'cash_register_id' => $reg->id,
            'date' => $today . ' 11:00:00',
        ]);

        $cfRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/cash-flow?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, (float)$cfRes->json('summary.sales_cash_received'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$cfRes->json('summary.operating_expenses_paid'), 0.01);

        $zRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/z-report?date={$today}&force_refresh=1")
            ->assertStatus(200);
        $zRow = $zRes->json('data.0');
        $this->assertNotNull($zRow);
        $this->assertEqualsWithDelta(0.0, (float)$zRow['cash_sales'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$zRow['cash_payouts'], 0.01);
        $this->assertEqualsWithDelta(900.0, (float)$zRow['expected_cash'], 0.01);

        $regRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/register?start_date={$today}&end_date={$today}&force_refresh=1")
            ->assertStatus(200);
        $regRow = $regRes->json('data.0');
        $this->assertNotNull($regRow);
        $this->assertEqualsWithDelta(0.0, (float)$regRow['cash_sales'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$regRow['cash_expenses'], 0.01);
        $this->assertEqualsWithDelta(900.0, (float)$regRow['expected_cash'], 0.01);

        // Updating sale_prefix in settings must NOT rewrite historical posted Sale.reference_no
        $this->withToken($this->admin->api_token)
            ->postJson('/api/settings', ['sale_prefix' => 'INV-'])
            ->assertStatus(200);
        $this->assertEquals('SL-S2-CR-1', $sale->fresh()->reference_no);
        $this->assertEquals('INV-', Setting::get('sale_prefix'));

        // Deleting linked ExpenseCategory must return 422
        $this->withToken($this->admin->api_token)
            ->deleteJson("/api/expense-categories/{$cat->id}")
            ->assertStatus(422);
    }

    public function test_reports_csv_injection_branch_scoping_margin_masking_points_discount_and_customer_id_isolation(): void
    {
        $product = Product::create([
            'name' => '=CMD|"/C calc"!A0',
            'code' => '+FORMULA-01',
            'barcode' => '8852099',
            'category_id' => 1,
            'unit_id' => 1,
            'purchase_price' => 40,
            'cost_price' => 40,
            'selling_price' => 100,
            'quantity' => 15,
            'alert_quantity' => 2,
        ]);

        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 5]);
        StockBalance::create(['warehouse_id' => $this->wh2->id, 'product_id' => $product->id, 'quantity' => 10]);

        $scopedUser = $this->makeScopedUser(
            'wh1_viewer',
            ['report_stock.view', 'report_stock.export', 'report_product_margins.view', 'report_customer.view', 'reports.view'],
            [$this->wh1->id]
        );

        // Seed Completed Sale on wh1 with legacy points_discount = 20
        $c1 = Customer::create(['name' => 'Ma Hnin', 'phone' => '0911111111']);
        $c2 = Customer::create(['name' => 'Ma Hnin', 'phone' => '0922222222']);

        $sale = Sale::create([
            'reference_no' => 'SL-S2-PTS-1',
            'customer_id' => $c2->id,
            'customer_name' => 'Ma Hnin',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => '2026-10-08 12:00:00',
            'status' => 'Completed',
            'subtotal' => 200.0,
            'discount' => 0.0,
            'points_discount' => 20.0,
            'redeemed_points' => 20,
            'grand_total' => 180.0,
            'paid_amount' => 180.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 100.0,
            'subtotal' => 200.0,
            'cogs_amount' => 80.0,
        ]);

        // 1. exportStock neutralizes formulas and enforces branch scoping (only wh1 qty=5, never wh2 qty=10 or total 15)
        $csvRes = $this->withToken($scopedUser->api_token)
            ->get('/api/reports/stock/export');
        $csvRes->assertStatus(200);
        $csvBody = $csvRes->streamedContent();
        $this->assertStringContainsString("'=CMD", $csvBody);
        $this->assertStringContainsString("'+FORMULA-01", $csvBody);
        $this->assertStringContainsString(',5,', $csvBody);
        $this->assertStringNotContainsString(',10,', $csvBody);
        $this->assertStringNotContainsString(',15,', $csvBody);

        // Explicitly filtering by an unauthorized warehouse never leaks wh2's 10 units
        $unauthWhCsv = $this->withToken($scopedUser->api_token)
            ->get('/api/reports/stock/export?warehouse=' . urlencode($this->wh2->name));
        $unauthWhCsv->assertStatus(200);
        $this->assertStringNotContainsString(',10,', $unauthWhCsv->streamedContent());
        $this->assertStringNotContainsString(',15,', $unauthWhCsv->streamedContent());

        // 2. productMarginsReport masks contribution_pct & cost metrics without products.view_cost
        $maskedMarginRes = $this->withToken($scopedUser->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200);
        $row = $maskedMarginRes->json('data.0');
        $this->assertNotNull($row);
        $this->assertNull($row['avg_cost_price']);
        $this->assertEquals(0.0, (float)$row['cogs']);
        $this->assertEquals(0.0, (float)$row['gross_profit']);
        $this->assertEquals(0.0, (float)$row['contribution_pct']);
        // Legacy points_discount (20) subtracted from 200 -> 180 net revenue
        $this->assertEqualsWithDelta(180.0, (float)$row['gross_revenue'], 0.01);

        // 3. Super Admin sees true unmasked profit = 180 - 80 = 100
        $adminMarginRes = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/product-margins?start_date=2026-10-01&end_date=2026-10-31&force_refresh=1')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(100.0, (float)$adminMarginRes->json('data.0.gross_profit'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$adminMarginRes->json('data.0.contribution_pct'), 0.01);

        // 4. customerStatement isolates strictly by customer_id (no name-collision leak between two 'Ma Hnin' records)
        $stmtC1 = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/customers/{$c1->id}/statement")
            ->assertStatus(200);
        $this->assertCount(0, $stmtC1->json('data.invoices'));
        $this->assertCount(0, $stmtC1->json('data.returns'));

        $stmtC2 = $this->withToken($scopedUser->api_token)
            ->getJson("/api/reports/customers/{$c2->id}/statement")
            ->assertStatus(200);
        $this->assertCount(1, $stmtC2->json('data.invoices'));
        $this->assertEquals('SL-S2-PTS-1', $stmtC2->json('data.invoices.0.reference_no'));
    }
}
