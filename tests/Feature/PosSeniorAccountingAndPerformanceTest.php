<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PosSeniorAccountingAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $outlet;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->outlet = Outlet::create([
            'name' => 'Taunggyi Flagship Store',
            'code' => 'TGY-01',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-ACC-01',
            'name' => 'Senior Accountant POS',
            'email' => 'accpos@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'senior_acc_token_001',
        ]);

        $this->product = Product::create([
            'name' => 'Coca Cola 330ml',
            'code' => 'PRD-COLA-01',
            'sku' => 'SKU-COLA-01',
            'selling_price' => 5000.0,
            'cost_price' => 3000.0,
            'stock_quantity' => 100.0,
            'is_active' => true,
            'status' => 'Active',
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 100.0,
        ]);

        InventoryBatch::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'unit_cost' => 3000.0,
            'received_date' => Carbon::now()->subDay(),
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->admin->api_token];
    }

    private function baseSalePayload(array $overrides = []): array
    {
        return array_merge([
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'customer_name' => 'Walk-in Customer',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'subtotal' => 10000.0,
            'grand_total' => 10000.0,
            'paid_amount' => 10000.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_code' => $this->product->code,
                    'product_name' => $this->product->name,
                    'quantity' => 2,
                    'unit_price' => 5000.0,
                    'discount_amount' => 0,
                    'subtotal' => 10000.0,
                ],
            ],
        ], $overrides);
    }

    public function test_split_tender_cash_change_is_authoritatively_persisted_and_non_cash_overpayment_rejected(): void
    {
        $openRes = $this->withHeaders($this->headers())->postJson('/api/cash-register/open', [
            'opening_cash' => 100000.0,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
        ]);
        $openRes->assertSuccessful();
        $registerId = (int)$openRes->json('register.id');

        // 1. Non-cash overpayment must be rejected with 422
        $overpayNonCash = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'payments' => [
                    ['payment_type' => 'KBZPay', 'amount' => 12000.0],
                ],
            ])
        );
        $overpayNonCash->assertStatus(422);

        // 2. Split tender: Grand total = 10,000; KBZPay 5,000 + Cash tendered 6,000 => Net Cash = 5,000, Change = 1,000
        $splitRes = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'payments' => [
                    ['payment_type' => 'KBZPay', 'amount' => 5000.0],
                    ['payment_type' => 'Cash', 'amount' => 6000.0],
                ],
            ])
        );
        $splitRes->assertStatus(201);

        $saleId = (int)$splitRes->json('data.id');
        $sale = Sale::with('payments')->findOrFail($saleId);

        $this->assertEquals(10000.0, round((float)$sale->grand_total, 2));
        $this->assertEquals(10000.0, round((float)$sale->paid_amount, 2));
        $this->assertEquals(1000.0, round((float)$sale->change_amount, 2));
        $this->assertEquals(6000.0, round((float)$sale->total_cogs, 2));

        $cashPayment = $sale->payments->firstWhere('payment_type', 'Cash');
        $kbzPayment = $sale->payments->firstWhere('payment_type', 'KBZPay');
        $this->assertNotNull($cashPayment);
        $this->assertNotNull($kbzPayment);
        $this->assertEquals(5000.0, round((float)$cashPayment->amount, 2));
        $this->assertEquals(1000.0, round((float)$cashPayment->change_amount, 2));
        $this->assertEquals(5000.0, round((float)$kbzPayment->amount, 2));

        // Verify register details expected cash = 100,000 + 5,000 = 105,000 (not 106,000)
        $detailsRes = $this->withHeaders($this->headers())->getJson("/api/cash-register/details/{$registerId}");
        $detailsRes->assertStatus(200);
        $this->assertEquals(5000.0, round((float)$detailsRes->json('register.cash_sales'), 2));
        $this->assertEquals(105000.0, round((float)$detailsRes->json('register.expected_cash'), 2));
    }

    public function test_phantom_customer_id_cannot_bypass_walk_in_credit_guard_and_conversion_rate_cannot_be_tampered(): void
    {
        // 1. Phantom customer_id=999999 with unpaid balance must be blocked as Walk-in
        $phantomCreditRes = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'customer_id' => 999999,
                'customer_name' => 'Ghost Buyer',
                'payment_status' => 'Partial',
                'paid_amount' => 1000.0,
                'payment_type' => 'Cash',
            ])
        );
        $phantomCreditRes->assertStatus(422);

        // 2. Client tries to tamper conversion_rate=0.1 without any linked product_unit_links row
        $tamperRes = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'payment_type' => 'Cash',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'product_code' => $this->product->code,
                        'product_name' => $this->product->name,
                        'quantity' => 2,
                        'conversion_rate' => 0.1,
                        'unit_price' => 5000.0,
                        'subtotal' => 10000.0,
                    ],
                ],
            ])
        );
        $tamperRes->assertStatus(201);

        $sale = Sale::with('items')->findOrFail((int)$tamperRes->json('data.id'));
        $this->assertEquals(1.0, (float)$sale->items->first()->conversion_rate);
        $this->assertEquals(2.0, (float)$sale->items->first()->base_quantity);
        $this->assertEquals(6000.0, round((float)$sale->total_cogs, 2));

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEquals(98.0, round((float)$balance->quantity, 4));
    }

    public function test_pending_and_ordered_sales_are_excluded_from_customer_receivable_exposure(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-AR-01',
            'name' => 'Credit Buyer',
            'phone' => '09111222333',
            'credit_limit' => 20000.0,
            'status' => 'active',
        ]);

        // Unposted Pending & Ordered quotes/orders totaling 50,000 must NOT block a 10,000 real credit sale
        Sale::create([
            'reference_no' => 'SA-PEND-01',
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'subtotal' => 30000.0,
            'grand_total' => 30000.0,
            'paid_amount' => 0.0,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
        ]);
        Sale::create([
            'reference_no' => 'SA-ORD-01',
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'subtotal' => 20000.0,
            'grand_total' => 20000.0,
            'paid_amount' => 0.0,
            'status' => 'Ordered',
            'payment_status' => 'Unpaid',
        ]);

        $receivableBefore = (float)Customer::applyReceivableScope(
            Sale::where('customer_id', $customer->id)
        )->selectRaw('COALESCE(SUM(CASE WHEN grand_total > paid_amount THEN grand_total - paid_amount ELSE 0 END), 0) as due')
            ->value('due');
        $this->assertEquals(0.0, $receivableBefore);

        $creditSaleRes = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'payment_status' => 'Unpaid',
                'paid_amount' => 0.0,
                'payment_type' => 'Credit',
            ])
        );
        $creditSaleRes->assertStatus(201);

        $receivableAfter = (float)Customer::applyReceivableScope(
            Sale::where('customer_id', $customer->id)
        )->selectRaw('COALESCE(SUM(CASE WHEN grand_total > paid_amount THEN grand_total - paid_amount ELSE 0 END), 0) as due')
            ->value('due');
        $this->assertEquals(10000.0, $receivableAfter);
    }

    public function test_register_reconciliation_isolates_cross_register_returns_normalizes_cash_aliases_and_ignores_voided_expenses(): void
    {
        $cashier2 = User::create([
            'user_id' => 'USR-ACC-02',
            'name' => 'Cashier Two',
            'email' => 'cashier2@retailpos.test',
            'role' => 'Cashier',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'cashier2_token_002',
        ]);

        $regA = CashRegister::create([
            'reference_no' => 'REG-ISO-A',
            'register_name' => 'Counter A',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'opening_cash' => 50000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(2),
        ]);

        $regB = CashRegister::create([
            'reference_no' => 'REG-ISO-B',
            'register_name' => 'Counter B',
            'user_id' => $cashier2->id,
            'user_name' => $cashier2->name,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'opening_cash' => 20000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(2),
        ]);

        // Sale in Register A paid with 'Petty Cash' (must normalize to Cash!)
        $saleRes = $this->withHeaders($this->headers())->postJson(
            '/api/sales',
            $this->baseSalePayload([
                'payments' => [
                    ['payment_type' => 'Petty Cash', 'amount' => 10000.0],
                ],
            ])
        );
        $saleRes->assertStatus(201);
        $saleId = (int)$saleRes->json('data.id');

        // Two returns on the same sale: one on Register A (2,000), one on Register B (3,000)
        SaleReturn::create([
            'reference_no' => 'SR-ISO-A',
            'date' => Carbon::today()->toDateString(),
            'sale_id' => $saleId,
            'cash_register_id' => $regA->id,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'customer_name' => 'Walk-in Customer',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'payment_type' => 'Cash',
            'status' => 'Completed',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-ISO-B',
            'date' => Carbon::today()->toDateString(),
            'sale_id' => $saleId,
            'cash_register_id' => $regB->id,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'customer_name' => 'Walk-in Customer',
            'subtotal' => 3000.0,
            'grand_total' => 3000.0,
            'paid_amount' => 3000.0,
            'payment_type' => 'Cash',
            'status' => 'Completed',
        ]);

        // Expenses on Register A: 1 Posted Cash (1,500) + 1 Voided Cash (4,000 must be ignored)
        $postedExp = Expense::create([
            'reference_no' => 'EXP-POSTED-A',
            'date' => Carbon::today()->toDateString(),
            'expense_category_name' => 'Store Supplies',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'cash_register_id' => $regA->id,
            'title' => 'Ice & Bags',
            'amount' => 1500.0,
            'paid_amount' => 1500.0,
            'payment_method' => 'Cash',
            'status' => 'Approved',
            'payment_status' => 'Paid',
        ]);
        ExpensePayment::create([
            'expense_id' => $postedExp->id,
            'reference_no' => 'EP-POSTED-A',
            'cash_register_id' => $regA->id,
            'amount' => 1500.0,
            'payment_type' => 'Cash',
            'payment_date' => Carbon::today()->toDateString(),
        ]);

        $voidedExp = Expense::create([
            'reference_no' => 'EXP-VOID-A',
            'date' => Carbon::today()->toDateString(),
            'expense_category_name' => 'Store Supplies',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'cash_register_id' => $regA->id,
            'title' => 'Cancelled Expense',
            'amount' => 4000.0,
            'paid_amount' => 4000.0,
            'payment_method' => 'Cash',
            'payment_status' => 'Voided',
        ]);
        ExpensePayment::create([
            'expense_id' => $voidedExp->id,
            'reference_no' => 'EP-VOID-A',
            'cash_register_id' => $regA->id,
            'amount' => 4000.0,
            'payment_type' => 'Cash',
            'payment_date' => Carbon::today()->toDateString(),
        ]);

        // Digital (KBZPay) expense on Register A: must NOT reduce physical cash drawer expected_cash!
        $digitalExp = Expense::create([
            'reference_no' => 'EXP-KBZ-A',
            'date' => Carbon::today()->toDateString(),
            'expense_category_name' => 'Utilities',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'cash_register_id' => $regA->id,
            'title' => 'WiFi Bill',
            'amount' => 3500.0,
            'paid_amount' => 3500.0,
            'payment_method' => 'KBZPay',
            'payment_status' => 'Paid',
        ]);
        ExpensePayment::create([
            'expense_id' => $digitalExp->id,
            'reference_no' => 'EP-KBZ-A',
            'cash_register_id' => $regA->id,
            'amount' => 3500.0,
            'payment_type' => 'KBZPay',
            'payment_date' => Carbon::today()->toDateString(),
        ]);

        $resA = $this->withHeaders($this->headers())->getJson("/api/cash-register/details/{$regA->id}");
        $resA->assertStatus(200);
        // Reg A expected: 50,000 open + 10,000 petty cash sale - 2,000 regA refund - 1,500 cash expense = 56,500
        // (Voided 4,000 excluded completely; KBZPay 3,500 counted in total_expenses = 5,000 but NOT deducted from cash drawer!)
        $this->assertEquals(10000.0, round((float)$resA->json('register.cash_sales'), 2));
        $this->assertEquals(2000.0, round((float)$resA->json('register.total_refunds'), 2));
        $this->assertEquals(5000.0, round((float)$resA->json('register.total_expenses'), 2));
        $this->assertEquals(1500.0, round((float)$resA->json('register.cash_expenses'), 2));
        $this->assertEquals(56500.0, round((float)$resA->json('register.expected_cash'), 2));

        $resB = $this->withHeaders($this->headers())
            ->getJson("/api/cash-register/details/{$regB->id}");
        $resB->assertStatus(200);
        // Reg B expected: 20,000 open - 3,000 regB refund = 17,000
        $this->assertEquals(3000.0, round((float)$resB->json('register.total_refunds'), 2));
        $this->assertEquals(17000.0, round((float)$resB->json('register.expected_cash'), 2));
    }
}
