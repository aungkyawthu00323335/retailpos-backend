<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\SaleReturn;

class DashboardDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_date_filtering_and_senior_accounting_metrics(): void
    {
        Cache::flush();

        // 1. Create Products with Cost Price for Inventory Valuation & COGS
        $productA = Product::create([
            'name' => 'Premium Coffee Beans',
            'code' => 'COF-001',
            'cost_price' => 10000.0,
            'retail_price' => 15000.0,
            'stock_quantity' => 20, // Valuation: 20 * 10,000 = 200,000
            'category_name' => 'Beverages',
            'brand_name' => 'StarRoast',
            'has_variants' => false,
        ]);

        $productB = Product::create([
            'name' => 'Organic Tea Leaf',
            'code' => 'TEA-001',
            'cost_price' => 4000.0,
            'retail_price' => 6000.0,
            'stock_quantity' => 50, // Valuation: 50 * 4,000 = 200,000
            'category_name' => 'Beverages',
            'brand_name' => 'GreenHill',
            'has_variants' => false,
        ]);

        $todayStr = Carbon::today()->toDateString();
        $yesterdayStr = Carbon::yesterday()->toDateString();
        $lastMonthStr = Carbon::today()->subMonth()->toDateString();

        // 2. Insert Sales on Different Dates
        // Today Sale: 2 units of Product A ($30,000), Paid $25,000, Due $5,000
        $todaySale = Sale::create([
            'reference_no' => 'SA_TODAY_01',
            'customer_name' => 'Mg Mg',
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $todayStr . ' 10:30:00',
            'subtotal' => 30000.0,
            'grand_total' => 30000.0,
            'paid_amount' => 25000.0,
            'due_amount' => 5000.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Partial',
            'status' => 'Completed',
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $todaySale->id,
            'product_id' => $productA->id,
            'product_name' => $productA->name,
            'product_code' => $productA->code,
            'unit_price' => 15000.0,
            'quantity' => 2,
            'subtotal' => 30000.0,
            'total_cogs' => 20000.0, // 2 * 10,000
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Yesterday Sale: 1 unit of Product B ($6,000), Paid $6,000
        $yesterdaySale = Sale::create([
            'reference_no' => 'SA_YEST_01',
            'customer_name' => 'Aye Aye',
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $yesterdayStr . ' 15:00:00',
            'subtotal' => 6000.0,
            'grand_total' => 6000.0,
            'paid_amount' => 6000.0,
            'due_amount' => 0.0,
            'payment_type' => 'KBZPay',
            'payment_status' => 'Paid',
            'status' => 'Completed',
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $yesterdaySale->id,
            'product_id' => $productB->id,
            'product_name' => $productB->name,
            'product_code' => $productB->code,
            'unit_price' => 6000.0,
            'quantity' => 1,
            'subtotal' => 6000.0,
            'total_cogs' => 4000.0, // 1 * 4,000
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Last Month Sale: $50,000
        $lastMonthSale = Sale::create([
            'reference_no' => 'SA_LASTMO_01',
            'customer_name' => 'Ko Ko',
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $lastMonthStr . ' 12:00:00',
            'subtotal' => 50000.0,
            'grand_total' => 50000.0,
            'paid_amount' => 40000.0,
            'due_amount' => 10000.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Partial',
            'status' => 'Completed',
        ]);

        // 3. Today Return: $5,000 on Today's date
        SaleReturn::create([
            'reference_no' => 'RET_TODAY_01',
            'sale_reference_no' => 'SA_TODAY_01',
            'customer_name' => 'Mg Mg',
            'warehouse_name' => 'Main Branch Outlet',
            'date' => $todayStr . ' 16:00:00',
            'grand_total' => 5000.0,
            'paid_amount' => 5000.0,
            'status' => 'Completed',
        ]);

        // 4. Test Filter: 'today'
        $respToday = $this->getJson('/api/reports/dashboard-summary?date_filter=today&force_refresh=true');
        $respToday->assertStatus(200);
        $dataToday = $respToday->json('data');

        $this->assertEquals('today', $dataToday['date_filter']);
        $this->assertEquals(30000.0, $dataToday['total_sales']);
        $this->assertEquals(5000.0, $dataToday['total_sale_returns']);
        $this->assertEquals(25000.0, $dataToday['net_sales']); // 30,000 - 5,000
        $this->assertEquals(1, $dataToday['orders_count']);
        $this->assertEquals(25000.0, $dataToday['average_order_value']); // Net Sales / Orders Count
        $this->assertEquals(20000.0, $dataToday['cogs']); // 20,000 COGS for today

        // 5. Test Filter: 'yesterday'
        $respYest = $this->getJson('/api/reports/dashboard-summary?date_filter=yesterday&force_refresh=true');
        $respYest->assertStatus(200);
        $dataYest = $respYest->json('data');

        $this->assertEquals('yesterday', $dataYest['date_filter']);
        $this->assertEquals(6000.0, $dataYest['total_sales']);
        $this->assertEquals(0.0, $dataYest['total_sale_returns']);
        $this->assertEquals(6000.0, $dataYest['net_sales']);
        $this->assertEquals(1, $dataYest['orders_count']);
        $this->assertEquals(6000.0, $dataYest['average_order_value']);
        $this->assertEquals(4000.0, $dataYest['cogs']);

        // 6. Test Inventory Valuation: 200,000 + 200,000 = 400,000
        $this->assertEquals(400000.0, $dataToday['inventory_valuation_cost']);

        // 7. Test Balance Sheet Snapshot Dues (Uncollected across all dates)
        // Today due ($5,000) + Last month due ($10,000) = $15,000
        $this->assertEquals(15000.0, $dataToday['customer_due']);

        // 8. Test Custom Range (Covering Today and Yesterday only)
        $respCustom = $this->getJson("/api/reports/dashboard-summary?date_filter=custom&start_date={$yesterdayStr}&end_date={$todayStr}&force_refresh=true");
        $respCustom->assertStatus(200);
        $dataCustom = $respCustom->json('data');

        $this->assertEquals('custom', $dataCustom['date_filter']);
        // 30,000 (today) + 6,000 (yesterday) = 36,000
        $this->assertEquals(36000.0, $dataCustom['total_sales']);
        $this->assertEquals(5000.0, $dataCustom['total_sale_returns']);
        $this->assertEquals(31000.0, $dataCustom['net_sales']);
        $this->assertEquals(2, $dataCustom['orders_count']);
        $this->assertEquals(15500.0, $dataCustom['average_order_value']); // 31,000 / 2
        $this->assertEquals(24000.0, $dataCustom['cogs']); // 20,000 + 4,000

        // 9. Verify Category Share
        $this->assertNotEmpty($dataCustom['top_categories']);
        $this->assertEquals('Beverages', $dataCustom['top_categories'][0]['category_name']);
    }
}
