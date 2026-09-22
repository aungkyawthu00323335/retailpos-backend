<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProfitLossDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_profit_loss_date_filter_presets_and_fifo_cogs(): void
    {
        $outletA = Outlet::create(['name' => 'Downtown Branch', 'code' => 'DT-01']);
        $outletB = Outlet::create(['name' => 'Uptown Branch', 'code' => 'UT-02']);

        $product = Product::create([
            'name' => 'High Grade Rice',
            'code' => 'RICE-01',
            'cost_price' => 50.0,
            'retail_price' => 100.0,
            'stock_quantity' => 100,
        ]);

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        // 1. Sale Today on Downtown Branch (Gross = 1,000, FIFO COGS = 500)
        $saleTodayId = DB::table('sales')->insertGetId([
            'reference_no' => 'SL-1001',
            'warehouse_name' => $outletA->name,
            'customer_name' => 'Customer A',
            'date' => $today . ' 10:30:00',
            'status' => 'Completed',
            'subtotal' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 1000.0,
            'due_amount' => 0.0,
            'total_cogs' => 500.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $saleTodayId,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 100.0,
            'quantity' => 10,
            'base_quantity' => 10,
            'subtotal' => 1000.0,
            'total_cogs' => 500.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Sale Yesterday on Uptown Branch (Gross = 2,000, FIFO COGS = 1,000)
        $saleYestId = DB::table('sales')->insertGetId([
            'reference_no' => 'SL-1002',
            'warehouse_name' => $outletB->name,
            'customer_name' => 'Customer B',
            'date' => $yesterday . ' 14:00:00',
            'status' => 'Completed',
            'subtotal' => 2000.0,
            'grand_total' => 2000.0,
            'paid_amount' => 2000.0,
            'due_amount' => 0.0,
            'total_cogs' => 1000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $saleYestId,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 100.0,
            'quantity' => 20,
            'base_quantity' => 20,
            'subtotal' => 2000.0,
            'total_cogs' => 1000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Operating Expense Today ($150 on Downtown)
        DB::table('expenses')->insert([
            'reference_no' => 'EXP-1001',
            'title' => 'Electricity Bill',
            'warehouse_name' => $outletA->name,
            'expense_category_name' => 'Utilities',
            'date' => $today,
            'amount' => 150.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Test 'today' preset on Downtown
        $resToday = $this->getJson('/api/reports/profit-loss?date_filter=today&warehouse_name=' . urlencode($outletA->name) . '&force_refresh=true');
        $resToday->assertStatus(200);
        $dataToday = $resToday->json('data');

        $this->assertEquals(1000.0, $dataToday['total_net_sales']);
        $this->assertEquals(500.0, $dataToday['total_cogs']);
        $this->assertEquals(500.0, $dataToday['total_gross_profit']);
        $this->assertEquals(150.0, $dataToday['total_expenses']);
        $this->assertEquals(350.0, $dataToday['total_net_profit']); // 500 - 150 = 350
        $this->assertEquals(35.0, $dataToday['net_margin_percentage']); // 350 / 1000 = 35%
        $this->assertEquals(1, $dataToday['total_orders']);
        $this->assertEquals(1000.0, $dataToday['average_order_value']);

        // 5. Test 'yesterday' preset on Uptown
        $resYest = $this->getJson('/api/reports/profit-loss?date_filter=yesterday&warehouse_name=' . urlencode($outletB->name) . '&force_refresh=true');
        $resYest->assertStatus(200);
        $dataYest = $resYest->json('data');

        $this->assertEquals(2000.0, $dataYest['total_net_sales']);
        $this->assertEquals(1000.0, $dataYest['total_cogs']);
        $this->assertEquals(1000.0, $dataYest['total_gross_profit']);
        $this->assertEquals(1000.0, $dataYest['total_net_profit']);

        // 6. Test 'this_month' across All warehouses
        $resMonth = $this->getJson('/api/reports/profit-loss?date_filter=this_month&warehouse=All&force_refresh=true');
        $resMonth->assertStatus(200);
        $dataMonth = $resMonth->json('data');

        $this->assertEquals(3000.0, $dataMonth['total_net_sales']);
        $this->assertEquals(1500.0, $dataMonth['total_cogs']);
        $this->assertEquals(1500.0, $dataMonth['total_gross_profit']);
        $this->assertEquals(1350.0, $dataMonth['total_net_profit']); // 1500 - 150 = 1350
        $this->assertEquals(2, $dataMonth['total_orders']);
        $this->assertEquals(1500.0, $dataMonth['average_order_value']);
    }
}
