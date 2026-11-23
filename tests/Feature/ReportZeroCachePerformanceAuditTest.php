<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportZeroCachePerformanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Report Auditor',
            'slug' => 'report-auditor-' . Str::lower(Str::random(5)),
            'permissions' => $permissions,
        ]);

        return User::create([
            'name' => 'Report Auditor User',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'role' => 'Report Auditor',
            'api_token' => Str::random(40),
            'is_active' => true,
        ]);
    }

    public function test_all_reports_registers_and_compact_lookups_emit_no_store_and_default_pagination(): void
    {
        $outlet = Outlet::create(['name' => 'Main Warehouse', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Walk-in', 'phone' => '09111111']);
        $supplier = Supplier::create(['name' => 'Default Supplier', 'phone' => '09333333']);
        ExpenseCategory::create(['name' => 'Utilities']);

        Product::create([
            'code' => 'P-RPT-1',
            'name' => 'Latte Beans',
            'cost_price' => 3000,
            'price' => 6000,
            'selling_price' => 6000,
            'stock_quantity' => 2,
            'alert_quantity' => 5,
            'product_unit' => 'Pc',
            'barcode_symbol' => 'CODE128',
        ]);

        Sale::create([
            'reference_no' => 'SA-RPT-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'grand_total' => 6000,
            'paid_amount' => 3000,
            'due_amount' => 3000,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);

        $user = $this->createAdminUser([
            'dashboard.view',
            'reports.view',
            'report_profit_loss.view',
            'report_commercial_tax.view',
            'report_stock.view',
            'report_stock_alert.view',
            'report_sales.view',
            'report_purchases.view',
            'report_sales_return.view',
            'report_purchase_return.view',
            'report_top_selling.view',
            'report_customer.view',
            'report_supplier.view',
            'report_expense.view',
            'report_register.view',
            'report_z_report.view',
            'report_ar_aging.view',
            'report_ap_aging.view',
            'report_cash_flow.view',
            'report_stock_ledger.view',
            'report_product_margins.view',
            'report_dead_stock.view',
            'report_cashier_audit.view',
            'cash_register.view',
            'customers.view',
            'suppliers.view',
            'expenses.view',
            'products.view',
            'brands.view',
            'product_categories.view',
        ]);

        $noStoreEndpoints = [
            '/api/reports/dashboard-summary',
            '/api/reports/profit-loss',
            '/api/reports/commercial-tax',
            '/api/reports/stock',
            '/api/reports/stock-alert',
            '/api/reports/sales',
            '/api/reports/purchases',
            '/api/reports/sale-returns',
            '/api/reports/purchase-returns',
            '/api/reports/top-selling',
            '/api/reports/customers',
            "/api/reports/customers/{$customer->id}/statement",
            '/api/reports/suppliers',
            "/api/reports/suppliers/{$supplier->id}/statement",
            '/api/reports/expenses',
            '/api/reports/register',
            '/api/reports/z-report',
            '/api/reports/ar-aging',
            '/api/reports/ap-aging',
            '/api/reports/cash-flow',
            '/api/reports/stock-ledger',
            '/api/reports/inventory-reconciliation',
            '/api/reports/product-margins',
            '/api/reports/dead-stock',
            '/api/reports/cashier-audit',
            '/api/cash-register/status',
            '/api/customers?compact=1',
            '/api/suppliers?compact=1',
            '/api/expense-categories?compact=1',
            '/api/product-categories?compact=1',
            '/api/brands?compact=1',
        ];

        foreach ($noStoreEndpoints as $uri) {
            $res = $this->withToken($user->api_token)->getJson($uri);
            $res->assertStatus(200, "Failed HTTP 200 on {$uri}");
            $this->assertStringContainsString(
                'no-store',
                (string) $res->headers->get('Cache-Control'),
                "Missing Cache-Control: no-store on {$uri}"
            );
        }

        $stockAlert = $this->withToken($user->api_token)->getJson('/api/reports/stock-alert');
        $stockAlert->assertStatus(200)
            ->assertJsonPath('data.pagination.per_page', 50);
        $this->assertIsBool($stockAlert->json('data.pagination.has_more'));

        // Verify per_page=50 & has_more contract on newly paginated report endpoints
        foreach ([
            '/api/reports/stock',
            '/api/reports/ar-aging',
            '/api/reports/ap-aging',
            '/api/reports/sale-returns',
            '/api/reports/purchase-returns',
            '/api/reports/register',
            '/api/reports/z-report',
            '/api/reports/cash-flow',
            '/api/reports/product-margins',
            '/api/reports/dead-stock',
            '/api/reports/cashier-audit',
        ] as $paginatedUri) {
            $res = $this->withToken($user->api_token)->getJson($paginatedUri);
            $res->assertStatus(200)
                ->assertJsonPath('pagination.per_page', 50);
            $this->assertIsBool($res->json('pagination.has_more'), "Missing boolean pagination.has_more on {$paginatedUri}");
        }

        $ledger = $this->withToken($user->api_token)->getJson('/api/reports/stock-ledger');
        $ledger->assertStatus(200)
            ->assertJsonPath('pagination.per_page', 50)
            ->assertJsonPath('bin_pagination.per_page', 50);
        $this->assertIsBool($ledger->json('bin_pagination.has_more'));

        $tax = $this->withToken($user->api_token)->getJson('/api/reports/commercial-tax');
        $tax->assertStatus(200);
        $this->assertIsBool($tax->json('sales_pagination.has_more'));
        $this->assertIsBool($tax->json('purchases_pagination.has_more'));

        // Verify CheckPermission avoids duplicate users table queries on a single request
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($user->api_token)->getJson('/api/reports/sales')->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $userQueries = array_filter(
            $queries,
            fn ($q) => str_contains(strtolower($q['query']), 'from "users"') || str_contains(strtolower($q['query']), 'from `users`')
        );
        $this->assertLessThanOrEqual(1, count($userQueries), 'CheckPermission triggered duplicate users queries');
    }

    public function test_z_report_date_isolation_excludes_out_of_range_returns_and_expenses(): void
    {
        $outlet = Outlet::create(['name' => 'Branch A', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Walk-in', 'phone' => '09111111']);
        $expCat = ExpenseCategory::create(['name' => 'Utilities']);
        $user = $this->createAdminUser(['report_z_report.view', 'reports.view']);

        $saleOld = Sale::create([
            'reference_no' => 'SA-OLD',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-10 10:00:00',
            'status' => 'Completed',
            'grand_total' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-OLD',
            'sale_id' => $saleOld->id,
            'sale_reference_no' => 'SA-OLD',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-10 12:00:00',
            'status' => 'Completed',
            'grand_total' => 4000,
            'paid_amount' => 4000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        Expense::create([
            'reference_no' => 'EXP-OLD',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Old Bill',
            'amount' => 9000,
            'paid_amount' => 9000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2026-11-10 15:00:00',
        ]);

        Sale::create([
            'reference_no' => 'SA-TODAY',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'grand_total' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        Expense::create([
            'reference_no' => 'EXP-TODAY',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Today Bill',
            'amount' => 1200,
            'paid_amount' => 1200,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2026-11-18 11:00:00',
        ]);

        $res = $this->withToken($user->api_token)
            ->getJson('/api/reports/z-report?start_date=2026-11-18&end_date=2026-11-18');
        $res->assertStatus(200);

        $rows = $res->json('data');
        $this->assertNotEmpty($rows);
        $this->assertEqualsWithDelta(10000.0, (float) $rows[0]['net_sales'], 0.01);
        $this->assertEqualsWithDelta(1200.0, (float) $rows[0]['total_expenses'], 0.01);
    }

    public function test_register_report_handles_over_1000_legacy_sales_safely_on_sqlite(): void
    {
        $outlet = Outlet::create(['name' => 'Volume Outlet', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Walk-in', 'phone' => '09111111']);
        $user = $this->createAdminUser(['report_register.view', 'reports.view']);

        $now = now()->toDateTimeString();
        $batch = [];
        for ($i = 1; $i <= 1050; $i++) {
            $batch[] = [
                'reference_no' => sprintf('SL-BULK-%04d', $i),
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => '2026-11-18 10:00:00',
                'status' => 'Completed',
                'grand_total' => 100,
                'paid_amount' => 100,
                'due_amount' => 0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === 250) {
                DB::table('sales')->insert($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            DB::table('sales')->insert($batch);
        }

        $res = $this->withToken($user->api_token)
            ->getJson('/api/reports/register?start_date=2026-11-18&end_date=2026-11-18');
        $res->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_report_performance_indexes_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasIndex('outlets', 'idx_outlets_name'));
        $this->assertTrue(Schema::hasIndex('sales', 'idx_sales_wh_id_status_date'));
        $this->assertTrue(Schema::hasIndex('sale_items', 'idx_si_rpt_cover'));
        $this->assertTrue(Schema::hasIndex('sale_returns', 'idx_sr_wh_id_status_date'));
        $this->assertTrue(Schema::hasIndex('purchases', 'idx_pur_wh_id_status_date'));
        $this->assertTrue(Schema::hasIndex('purchase_returns', 'idx_pr_wh_id_status_date'));
        $this->assertTrue(Schema::hasIndex('expenses', 'idx_exp_wh_id_date_id'));
        $this->assertTrue(Schema::hasIndex('cash_registers', 'idx_cr_opened_id'));
        $this->assertTrue(Schema::hasIndex('stock_movements', 'idx_sm_type_wh_created'));
        $this->assertTrue(Schema::hasIndex('stock_balances', 'idx_sb_wh_prod_qty'));
    }

    public function test_cashier_audit_reconciles_tenders_and_includes_shift_or_return_only_staff(): void
    {
        $outlet = Outlet::create(['name' => 'Audit Outlet', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Walk-in', 'phone' => '09111111']);
        $user = $this->createAdminUser(['report_cashier_audit.view', 'reports.view']);
        $today = \Carbon\Carbon::today()->toDateString();

        // Staff A: 1 Cash Sale of 10,000 + 1 KBZPay Sale Return of 15,000 -> Cash=+10,000, NonCash=-15,000, NetCollected=-5,000
        Sale::create([
            'reference_no' => 'SA-AUD-C1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'grand_total' => 10000.0,
            'paid_amount' => 10000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_name' => 'Cashier Alpha',
            'created_by' => 'Cashier Alpha',
            'user_role' => 'Cashier',
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-AUD-C1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 11:00:00',
            'status' => 'Completed',
            'grand_total' => 15000.0,
            'paid_amount' => 15000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'KBZPay',
            'refund_tender' => 'KBZPay',
            'user_name' => 'Cashier Alpha',
            'created_by' => 'Cashier Alpha',
        ]);

        // Staff B: Zero sales, only an open register shift with 50,000 float -> MUST still appear in Fraud Audit!
        DB::table('cash_registers')->insert([
            'reference_no' => 'REG-AUD-BETA',
            'user_id' => $user->id,
            'user_name' => 'Shift Only Staff',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'register_name' => 'Register 02',
            'status' => 'open',
            'opening_cash' => 50000.0,
            'expected_cash' => 50000.0,
            'opened_at' => $today . ' 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withToken($user->api_token)
            ->getJson("/api/reports/cashier-audit?start_date={$today}&end_date={$today}")
            ->assertStatus(200);

        $rows = collect($res->json('data'));
        $alpha = $rows->firstWhere('cashier_name', 'Cashier Alpha');
        $this->assertNotNull($alpha);
        $this->assertEquals(10000.0, (float)$alpha['cash_collected']);
        $this->assertEquals(-15000.0, (float)$alpha['non_cash_collected']);
        $this->assertEquals(-5000.0, (float)$alpha['total_collected']);
        $this->assertEquals(round((float)$alpha['cash_collected'] + (float)$alpha['non_cash_collected'], 2), (float)$alpha['total_collected']);

        $beta = $rows->firstWhere('cashier_name', 'Shift Only Staff');
        $this->assertNotNull($beta);
        $this->assertEquals(50000.0, (float)$beta['expected_cash']);
    }

    public function test_dead_stock_values_inventory_using_active_fifo_batches_and_excludes_deleted_variants(): void
    {
        $outlet = Outlet::create(['name' => 'DeadStock Branch', 'is_active' => true]);
        $user = $this->createAdminUser(['report_dead_stock.view', 'reports.view', 'products.view_cost']);

        $prod = Product::create([
            'code' => 'P-DEAD-FIFO',
            'name' => 'Stale Batch Goods',
            'cost_price' => 1000.0,
            'purchase_price' => 1000.0,
            'selling_price' => 2500.0,
            'stock_quantity' => 5,
            'product_unit' => 'Pc',
            'barcode_symbol' => 'CODE128',
        ]);
        DB::table('products')->where('id', $prod->id)->update(['created_at' => now()->subDays(120)]);
        DB::table('stock_balances')->insert([
            'warehouse_id' => $outlet->id,
            'product_id' => $prod->id,
            'quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_batches')->insert([
            'warehouse_id' => $outlet->id,
            'product_id' => $prod->id,
            'initial_quantity' => 5,
            'remaining_quantity' => 5,
            'unit_cost' => 1400.0, // True landed FIFO cost > master cost_price 1000
            'status' => 'active',
            'received_date' => now()->subDays(120),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withToken($user->api_token)
            ->getJson("/api/reports/dead-stock?warehouse={$outlet->name}&days=60")
            ->assertStatus(200);

        $item = collect($res->json('data'))->firstWhere('product_id', $prod->id);
        $this->assertNotNull($item);
        $this->assertEquals(7000.0, (float)$item['tied_up_capital']); // 5 * 1400 FIFO cost
        $this->assertEquals(7000.0, (float)$res->json('summary.total_tied_up_capital'));
    }
}
