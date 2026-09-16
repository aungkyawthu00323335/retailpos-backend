<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\Sale;
use App\Models\Product;
use App\Models\Expense;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_metrics_and_recent_sales(): void
    {
        Cache::flush();

        // Create a product
        $product = Product::create([
            'name' => 'Sample Test Product',
            'code' => 'PRD-SMPL-01',
            'cost_price' => 100.0,
            'retail_price' => 150.0,
            'stock_quantity' => 50,
            'category_name' => 'General',
            'brand_name' => 'General',
        ]);

        $today = date('Y-m-d H:i:s');

        // Create 10 sales
        for ($i = 1; $i <= 10; $i++) {
            $sale = Sale::create([
                'reference_no' => "SA_TEST_$i",
                'customer_name' => "Customer $i",
                'warehouse_name' => 'Main Branch Outlet',
                'date' => $today,
                'subtotal' => 150.0,
                'grand_total' => 150.0,
                'paid_amount' => 150.0,
                'due_amount' => 0.0,
                'payment_type' => 'Cash',
                'payment_status' => 'Paid',
                'status' => 'Completed',
            ]);

            \DB::table('sale_items')->insert([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'unit_price' => 150.0,
                'quantity' => 1,
                'subtotal' => 150.0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create an expense
        Expense::create([
            'title' => 'Monthly Electricity Bill',
            'reference_no' => 'EXP-001',
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $today,
            'amount' => 200.0,
            'expense_category_name' => 'Utilities',
        ]);

        $response = $this->getJson('/api/reports/dashboard-summary?force_refresh=true');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'total_sales',
                    'total_sale_returns',
                    'net_sales',
                    'total_purchases',
                    'net_purchases',
                    'total_expenses',
                    'cogs',
                    'gross_profit',
                    'net_profit',
                    'profit_margin_pct',
                    'today_sales',
                    'today_sales_count',
                    'today_profit',
                    'recent_sales',
                ],
            ]);

        $data = $response->json('data');

        // 10 sales * $150 = $1500
        $this->assertEquals(1500.0, $data['total_sales']);
        $this->assertEquals(1500.0, $data['today_sales']);
        $this->assertEquals(10, $data['today_sales_count']);

        // 10 items * $100 cost = $1000 COGS
        $this->assertEquals(1000.0, $data['cogs']);

        // Gross Profit = 1500 - 1000 = 500
        $this->assertEquals(500.0, $data['gross_profit']);

        // Expenses = 200
        $this->assertEquals(200.0, $data['total_expenses']);

        // Net Profit = 500 - 200 = 300
        $this->assertEquals(300.0, $data['net_profit']);

        // Profit Margin = 300 / 1500 * 100 = 20%
        $this->assertEquals(20.0, $data['profit_margin_pct']);

        // Verify exactly 10 recent sales returned
        $this->assertCount(10, $data['recent_sales']);
        $this->assertEquals('Main Branch Outlet', $data['recent_sales'][0]['warehouse_name']);
    }
}
