<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccountingAndGraphAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reconciles_tax_exclusive_revenue_partial_cogs_and_date_only_rows_with_profit_loss(): void
    {
        $year = Carbon::today()->year;
        $today = Carbon::today()->toDateString();
        $janFirst = "{$year}-01-01";

        $outlet = Outlet::create([
            'name' => 'Downtown Flagship',
            'code' => 'DT-01',
        ]);

        $product = Product::create([
            'name' => 'Tea Mix 30s',
            'code' => 'PRD-TEA-01',
            'cost_price' => 3000.0,
            'retail_price' => 5000.0,
            'stock_quantity' => 100,
        ]);

        // 1. Today sale WITH 5% Commercial Tax (50,000 subtotal + 2,500 tax = 52,500 grand_total) and precomputed COGS 30,000
        // Stored as 10-char YYYY-MM-DD (no 00:00:00) to prove lower-bound matching works!
        Sale::create([
            'reference_no' => 'SL-TAX-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'status' => 'Completed',
            'subtotal' => 50000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 2500.0,
            'grand_total' => 52500.0,
            'paid_amount' => 52500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 30000.0,
        ]);

        // 2. Today sale with MISSING precomputed total_cogs (0) -> must trigger partial fallback COGS (2 * 3,000 = 6,000)!
        $saleMissingCogs = Sale::create([
            'reference_no' => 'SL-MISS-COGS',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 10:15:00',
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'tax_amount' => 0.0,
            'shipping' => 1000.0,
            'grand_total' => 11000.0,
            'paid_amount' => 11000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 0.0,
        ]);

        SaleItem::create([
            'sale_id' => $saleMissingCogs->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price' => 5000.0,
            'subtotal' => 10000.0,
            'total_cogs' => 0.0,
        ]);

        // 3. Today Expense stored as 10-char YYYY-MM-DD (must NOT be dropped by 00:00:00 comparison!)
        Expense::create([
            'reference_no' => 'EX-TODAY-01',
            'title' => 'Store Electricity',
            'expense_category_name' => 'Utilities',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'amount' => 4000.0,
            'payment_status' => 'Paid',
        ]);

        // 4. Jan 1 Sale stored as 10-char YYYY-01-01 (must appear in monthly_trends[0]!)
        Sale::create([
            'reference_no' => 'SL-JAN1-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $janFirst,
            'status' => 'Completed',
            'subtotal' => 20000.0,
            'tax_amount' => 1000.0,
            'grand_total' => 21000.0,
            'paid_amount' => 21000.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 12000.0,
        ]);

        // 5. Today Sale Return with tax (5,000 net + 250 tax = 5,250 grand_total, returned COGS 3,000)
        SaleReturn::create([
            'reference_no' => 'SR-TODAY-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'status' => 'Completed',
            'tax_amount' => 250.0,
            'grand_total' => 5250.0,
            'return_cost_total' => 3000.0,
        ]);

        // Expected Today figures (tax & shipping excluded):
        // Gross Sales ex-tax/ex-shipping = 50,000 + 10,000 = 60,000
        // Sale Returns ex-tax = 5,000
        // Net Sales = 55,000
        // COGS = 30,000 + 6,000 (fallback!) - 3,000 (returned) = 33,000
        // Gross Profit = 22,000
        // Expenses = 4,000
        // Net Profit = 18,000
        $dashRes = $this->getJson("/api/reports/dashboard-summary?date_filter=today&year={$year}&compact=1");
        $dashRes->assertStatus(200)
            ->assertJsonPath('data.total_sales', 60000)
            ->assertJsonPath('data.total_sale_returns', 5000)
            ->assertJsonPath('data.net_sales', 55000)
            ->assertJsonPath('data.cogs', 33000)
            ->assertJsonPath('data.total_expenses', 4000)
            ->assertJsonPath('data.gross_profit', 22000)
            ->assertJsonPath('data.net_profit', 18000)
            ->assertJsonPath('data.today_sales', 55000)
            ->assertJsonPath('data.today_expenses', 4000)
            ->assertJsonPath('data.today_cogs', 33000)
            ->assertJsonPath('data.today_profit', 18000);

        // Must match Profit & Loss Report to the exact kyat!
        $pnlRes = $this->getJson('/api/reports/profit-loss?date_filter=today');
        $pnlRes->assertStatus(200);
        $this->assertEquals(
            (float)$pnlRes->json('data.net_sales'),
            (float)$dashRes->json('data.net_sales'),
            'Dashboard Net Sales must equal Profit & Loss Net Sales'
        );
        $this->assertEquals(
            (float)$pnlRes->json('data.net_profit'),
            (float)$dashRes->json('data.net_profit'),
            'Dashboard Net Profit must equal Profit & Loss Net Profit'
        );

        // Jan 1 date-only sale must appear in monthly_trends[0] (20,000 net sales, 8,000 net profit if today isn't Jan)
        $monthlySales = $dashRes->json('data.monthly_trends.sales');
        $monthlyProfit = $dashRes->json('data.monthly_trends.net_profit');
        $this->assertGreaterThanOrEqual(20000.0, (float)$monthlySales[0]);
        $this->assertEquals(
            round((float)$monthlySales[0] - 12000.0 - (Carbon::today()->month === 1 ? 37000.0 : 0.0), 2),
            round((float)$monthlyProfit[0], 2)
        );
    }
}
