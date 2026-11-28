<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Expense;
use App\Models\SaleReturn;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\CashRegister;
use Carbon\Carbon;

class AccountingReportsIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_z_report_subtracts_cash_expenses_and_tender_filtered_refunds(): void
    {
        $today = Carbon::today()->toDateString();

        $outlet = Outlet::create([
            'name' => 'Main Branch Outlet',
            'code' => 'OUT-01',
        ]);

        // Create open register session
        $reg = CashRegister::create([
            'reference_no' => 'REG-001',
            'user_id' => 1,
            'user_name' => 'Cashier 1',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'opening_cash' => 500.0,
            'status' => 'open',
            'opened_at' => $today . ' 08:00:00',
        ]);

        // Cash Sale of 200
        Sale::create([
            'reference_no' => 'SL-101',
            'customer_name' => 'Customer A',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 09:00:00',
            'status' => 'Completed',
            'grand_total' => 200.0,
            'paid_amount' => 200.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);

        // Cash Return of 30
        SaleReturn::create([
            'reference_no' => 'SR-101',
            'sale_reference_no' => 'SL-101',
            'customer_name' => 'Customer A',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'grand_total' => 30.0,
            'paid_amount' => 30.0,
            'refund_tender' => 'Cash',
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);

        // Cash Expense of 20 (paid from till drawer)
        Expense::create([
            'reference_no' => 'EXP-101',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'expense_category_name' => 'Office Supplies',
            'title' => 'Paper and Pens',
            'amount' => 20.0,
            'payment_method' => 'Cash',
            'date' => $today . ' 11:00:00',
        ]);

        // Digital Expense of 50 (paid via Bank Transfer, does NOT touch drawer)
        Expense::create([
            'reference_no' => 'EXP-102',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'expense_category_name' => 'Utilities',
            'title' => 'Internet Bill',
            'amount' => 50.0,
            'payment_method' => 'Bank Transfer',
            'date' => $today . ' 11:30:00',
        ]);

        $response = $this->getJson("/api/reports/z-report?warehouse={$outlet->id}&date={$today}&force_refresh=1");
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertNotEmpty($json['data']);
        $session = $json['data'][0];

        // Expected Cash Formula: Opening(500) + CashSales(200) - CashRefunds(30) - CashPayouts(20) = 650
        $this->assertEquals(500.0, (float)$session['opening_cash']);
        $this->assertEquals(200.0, (float)$session['cash_sales']);
        $this->assertEquals(30.0, (float)$session['cash_refunds']);
        $this->assertEquals(20.0, (float)$session['cash_payouts']);
        $this->assertEquals(70.0, (float)$session['total_expenses']); // 20 cash + 50 bank
        $this->assertEquals(650.0, (float)$session['closing_cash']);
    }

    public function test_stock_ledger_report_unions_sale_returns_and_purchase_returns(): void
    {
        $today = Carbon::today()->toDateString();

        $outlet = Outlet::create([
            'name' => 'Main Branch Outlet',
            'code' => 'OUT-01',
        ]);

        $product = Product::create([
            'name' => 'Organic Green Tea',
            'code' => 'TEA-001',
            'cost_price' => 15.0,
            'retail_price' => 30.0,
            'stock_quantity' => 100,
            'category_name' => 'Beverages',
        ]);

        // 1. Sale Return Movement (Customer returned 5 units to warehouse)
        $saleReturn = SaleReturn::create([
            'reference_no' => 'SR-201',
            'sale_reference_no' => 'SL-201',
            'customer_name' => 'Customer B',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 09:30:00',
            'status' => 'Completed',
            'grand_total' => 150.0,
            'paid_amount' => 150.0,
            'refund_tender' => 'Cash',
            'payment_type' => 'Cash',
            'created_by' => 'Cashier 1',
        ]);

        StockMovement::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'movement_type' => 'sale_return',
            'reference_type' => 'sale_return',
            'reference_id' => (string)$saleReturn->id,
            'quantity_in' => 5.0,
            'quantity_out' => 0.0,
            'balance_after' => 105.0,
            'created_by' => 1,
            'created_at' => $today . ' 09:30:00',
        ]);

        // 2. Purchase Return Movement (Returned 3 defective units to supplier)
        $purchaseReturn = PurchaseReturn::create([
            'reference_no' => 'PR-201',
            'purchase_reference_no' => 'PO-201',
            'supplier_name' => 'Supplier Alpha',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 10:15:00',
            'status' => 'Completed',
            'grand_total' => 45.0,
            'paid_amount' => 45.0,
            'payment_type' => 'Cash',
            'created_by' => 'Purchaser 1',
        ]);

        StockMovement::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'movement_type' => 'purchase_return',
            'reference_type' => 'purchase_return',
            'reference_id' => (string)$purchaseReturn->id,
            'quantity_in' => 0.0,
            'quantity_out' => 3.0,
            'balance_after' => 102.0,
            'created_by' => 1,
            'created_at' => $today . ' 10:15:00',
        ]);

        // Query Stock Ledger with movement_type=returns
        $response = $this->getJson("/api/reports/stock-ledger?warehouse_name=Main Branch Outlet&start_date={$today}&end_date={$today}&movement_type=returns&force_refresh=1");
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertGreaterThanOrEqual(2, $json['summary']['total_movements']);
        $movements = collect($json['movements']);

        $returnIn = $movements->firstWhere('movement_type', 'sale_return');
        $this->assertNotNull($returnIn);
        $this->assertEquals(5.0, (float)$returnIn['quantity_in']);
        $this->assertEquals('Return In', $returnIn['type_label']);

        $returnOut = $movements->firstWhere('movement_type', 'purchase_return');
        $this->assertNotNull($returnOut);
        $this->assertEquals(3.0, (float)$returnOut['quantity_out']);
        $this->assertEquals('Return Out', $returnOut['type_label']);
    }

    public function test_cash_flow_report_incorporates_supplier_refunds_into_total_inflows(): void
    {
        $today = Carbon::today()->toDateString();

        $outlet = Outlet::create([
            'name' => 'Main Branch Outlet',
            'code' => 'OUT-01',
        ]);

        // Sales Cash Received = 1000
        Sale::create([
            'reference_no' => 'SL-301',
            'customer_name' => 'Customer C',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 08:30:00',
            'status' => 'Completed',
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);

        // Supplier Cash Refund on Purchase Return = 250
        PurchaseReturn::create([
            'reference_no' => 'PR-301',
            'purchase_reference_no' => 'PO-301',
            'supplier_name' => 'Supplier Beta',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today . ' 11:00:00',
            'status' => 'Completed',
            'grand_total' => 250.0,
            'paid_amount' => 250.0,
            'payment_type' => 'Cash',
        ]);

        // Operating Expense = 150
        Expense::create([
            'reference_no' => 'EXP-301',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Branch Outlet',
            'expense_category_name' => 'Cleaning',
            'title' => 'Cleaning Services',
            'amount' => 150.0,
            'payment_method' => 'Cash',
            'date' => $today . ' 12:00:00',
        ]);

        $response = $this->getJson("/api/reports/cash-flow?warehouse_name=Main Branch Outlet&start_date={$today}&end_date={$today}&force_refresh=1");
        $response->assertStatus(200);

        $json = $response->json();
        $summary = $json['summary'];

        // Total Inflows = Sales (1000) + Supplier Refunds (250) = 1250
        $this->assertEquals(1250.0, (float)$summary['total_inflow']);
        $this->assertEquals(1000.0, (float)$summary['sales_cash_received']);
        $this->assertEquals(250.0, (float)$summary['supplier_refunds_received']);

        // Total Outflows = Expenses (150)
        $this->assertEquals(150.0, (float)$summary['total_outflow']);

        // Net Cash Flow = 1250 - 150 = 1100
        $this->assertEquals(1100.0, (float)$summary['net_cash_flow']);
    }

    public function test_p2_cash_flow_iso_dates_and_negative_expected_cash_till_visibility(): void
    {
        $outlet = Outlet::create([
            'name' => 'Till Shortfall Outlet',
            'code' => 'OUT-SHORT-1',
        ]);

        $adminRole = \App\Models\Role::create([
            'name' => 'Cash Admin',
            'slug' => 'cash_admin_p2',
            'permissions' => ['cash_register.view', 'cash_register.view_expected', 'reports.register', 'reports.cash_flow', 'reports.view'],
            'status' => 'active',
        ]);
        $admin = \App\Models\User::create([
            'name' => 'Cash Admin',
            'email' => 'cashadmin-p2@test.local',
            'password' => bcrypt('Secret123!'),
            'role_id' => $adminRole->id,
            'status' => 'active',
            'api_token' => 'cash_admin_p2_tok',
        ]);
        $admin->outlets()->sync([$outlet->id]);

        $register = \App\Models\CashRegister::create([
            'reference_no' => 'CR-SHORT-1',
            'user_id' => $admin->id,
            'user_name' => $admin->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'opening_cash' => 100.0,
            'expected_cash' => 100.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        \App\Models\CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'user_id' => $admin->id,
            'type' => 'drop',
            'amount' => 300.0,
            'reason' => 'Supplier cash payout exceeding opening float',
        ]);

        // Unclamped expected_cash surfaces negative till shortage (-200.0) instead of hiding at 0.0
        $statusRes = $this->withToken($admin->api_token)
            ->getJson("/api/cash-register/status?cash_register_id={$register->id}")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(-200.0, (float)$statusRes->json('register.expected_cash'), 0.01);

        // Cash flow report normalizes ISO UTC filter boundaries cleanly
        $today = Carbon::today()->toDateString();
        Sale::create([
            'reference_no' => 'SL-ISO-CF-1',
            'customer_name' => 'ISO Client',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 12:00:00',
            'status' => 'Completed',
            'grand_total' => 450.0,
            'paid_amount' => 450.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
        ]);
        $cf = $this->withToken($admin->api_token)
            ->getJson('/api/reports/cash-flow?start_date=' . urlencode($today . 'T00:00:00.000Z') . '&end_date=' . urlencode($today . 'T23:59:59.000Z') . '&force_refresh=1')
            ->assertStatus(200);
        $this->assertEqualsWithDelta(450.0, (float)$cf->json('summary.sales_cash_received'), 0.01);
    }
}
