<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\LateOfflineReconciliation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeniorPosAndAccountingPhase2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $wh1;
    private Outlet $hledan;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->wh1 = Outlet::create([
            'name' => 'Main Warehouse Branch',
            'code' => 'WH-MAIN-01',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $this->hledan = Outlet::create([
            'name' => 'Hledan High-Street Store',
            'code' => 'BR-HLD-04',
            'is_warehouse' => false,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'user_id' => 'USR-P2-ADM',
            'name' => 'Phase2 Admin',
            'email' => 'p2admin@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'p2_admin_token_001',
        ]);
    }

    public function test_pos_catalog_and_sync_expose_all_outlets_stock_in_stock_first_cursor_pagination_and_touches(): void
    {
        $p1 = Product::create([
            'name' => 'Only Main Stock Item',
            'code' => 'PRD-MAIN-01',
            'sku' => 'SKU-MAIN-01',
            'selling_price' => 2500.0,
            'cost_price' => 1500.0,
            'stock_quantity' => 50.0,
            'is_active' => true,
            'status' => 'Active',
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $p1->id, 'quantity' => 50.0]);

        $p2 = Product::create([
            'name' => 'Hledan Stocked Item',
            'code' => 'PRD-HLD-02',
            'sku' => 'SKU-HLD-02',
            'selling_price' => 3000.0,
            'cost_price' => 1800.0,
            'stock_quantity' => 25.0,
            'is_active' => true,
            'status' => 'Active',
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $p2->id, 'quantity' => 10.0]);
        $sbHld = StockBalance::create(['warehouse_id' => $this->hledan->id, 'product_id' => $p2->id, 'quantity' => 15.0]);

        $p3 = Product::create([
            'name' => 'Zero Hledan Compact Check',
            'code' => 'PRD-COMP-03',
            'sku' => 'SKU-COMP-03',
            'selling_price' => 4000.0,
            'cost_price' => 2000.0,
            'stock_quantity' => 99.0,
            'is_active' => true,
            'status' => 'Active',
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $p3->id, 'quantity' => 99.0]);

        // 1. POS index on Hledan with in_stock_first=1 puts Hledan-stocked p2 first, and STILL returns wh1 & hledan in warehouse_stocks
        $posRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/products?is_pos=1&warehouse_id={$this->hledan->id}&in_stock_first=1&per_page=10");
        $posRes->assertStatus(200);
        $rows = $posRes->json('data');
        $this->assertEquals($p2->id, $rows[0]['id']);
        $this->assertCount(2, $rows[0]['warehouse_stocks']);

        // 2. compact_list=1 with NULL warehouse_stock on Hledan must return 0.0 (not global 99.0!)
        $compactRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/products?compact_list=1&warehouse_id={$this->hledan->id}&search=PRD-COMP-03");
        $compactRes->assertStatus(200);
        $this->assertEquals(0.0, (float)$compactRes->json('data.0.stock_quantity'));

        // 3. Cursor pagination on /api/products/sync iterates all items deterministically
        $syncP1 = $this->withToken($this->admin->api_token)
            ->getJson("/api/products/sync?limit=2&warehouse_id={$this->hledan->id}")
            ->assertStatus(200);
        $this->assertTrue((bool)$syncP1->json('has_more'));
        $this->assertCount(2, $syncP1->json('products'));
        $cursor = $syncP1->json('next_cursor');
        $this->assertNotEmpty($cursor);

        $syncP2 = $this->withToken($this->admin->api_token)
            ->getJson("/api/products/sync?limit=2&warehouse_id={$this->hledan->id}&cursor=" . urlencode($cursor))
            ->assertStatus(200);
        $this->assertFalse((bool)$syncP2->json('has_more'));
        $this->assertCount(1, $syncP2->json('products'));

        // 4. StockBalance::$touches = ['product'] bumps updated_at on stock mutation
        $before = Carbon::parse('2026-01-01 00:00:00');
        DB::table('products')->where('id', $p2->id)->update(['updated_at' => $before]);
        $sbHld->update(['quantity' => 14.0]);
        $this->assertTrue(Carbon::parse($p2->fresh()->updated_at)->gt($before));

        // 5. coalesced stock_balances index prevents duplicate null-variant rows
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_balances')->insert([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $p1->id,
            'product_variant_id' => null,
            'quantity' => 12.0,
        ]);
    }

    public function test_checkout_math_split_settlement_order_and_offline_guard_and_void_immutability(): void
    {
        $product = Product::create([
            'name' => 'Premium Matcha',
            'code' => 'PRD-MAT-01',
            'selling_price' => 5000.0,
            'cost_price' => 2000.0,
            'stock_quantity' => 50.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 50.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'unit_cost' => 2000.0,
            'source_type' => 'opening_stock',
            'received_date' => now()->subDay(),
            'status' => 'active',
        ]);
        $customer = Customer::create(['name' => 'Daw Aye', 'phone' => '09555555']);

        $reg = CashRegister::create([
            'reference_no' => 'REG-P2-01',
            'register_name' => 'Main Counter',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 10000.0,
            'expected_cash' => 10000.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        // 1. Back-office request cannot spoof is_offline_sync
        $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => now()->toDateTimeString(),
            'pos_flag' => false,
            'is_offline_sync' => true,
            'subtotal' => 5000.0,
            'grand_total' => 5000.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 5000.0,
                'quantity' => 1,
                'subtotal' => 5000.0,
            ]],
        ])->assertStatus(422);

        // 2. Cash submitted before KBZPay in split array settles KBZPay first so change belongs ONLY to Cash leg
        // Item: 2 * 5,000 = 10,000, line discount 2,000 => 8,000 subtotal
        // Payments: Cash 5,000 (tendered 10,000) + KBZPay 5,000 => KBZPay settles 5,000, Cash settles remaining 3,000!
        $saleRes = $this->withToken($this->admin->api_token)->postJson('/api/sales', [
            'idempotency_key' => 'idem-split-order-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $reg->id,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 8000.0,
            'grand_total' => 8000.0,
            'paid_amount' => 8000.0,
            'payments' => [
                ['payment_type' => 'Cash', 'amount' => 5000.0, 'received_amount' => 10000.0],
                ['payment_type' => 'KBZPay', 'amount' => 5000.0],
            ],
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit_price' => 5000.0,
                'quantity' => 2,
                'discount' => 2000.0,
                'subtotal' => 8000.0,
            ]],
        ])->assertStatus(201);

        $saleId = (int)$saleRes->json('data.id');
        $payments = SalePayment::where('sale_id', $saleId)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $kbzLeg = $payments->firstWhere('payment_type', 'KBZPay');
        $cashLeg = $payments->firstWhere('payment_type', 'Cash');
        $this->assertEquals(5000.0, (float)$kbzLeg->amount);
        $this->assertEquals(0.0, (float)$kbzLeg->change_amount);
        $this->assertEquals(3000.0, (float)$cashLeg->amount);
        $this->assertEquals(7000.0, (float)$cashLeg->change_amount);
        $this->assertEquals(13000.0, (float)$reg->fresh()->expected_cash);

        // 3. Void sale then verify DELETE on Voided sale is strictly rejected (immutable audit trail)
        $this->withToken($this->admin->api_token)->postJson("/api/sales/{$saleId}/void", [
            'reason' => 'Wrong split entry',
        ])->assertStatus(200);
        $this->assertEquals(10000.0, (float)$reg->fresh()->expected_cash);

        $this->withToken($this->admin->api_token)->deleteJson("/api/sales/{$saleId}")
            ->assertStatus(422);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'Voided']);
    }

    public function test_pending_offline_blocks_register_close_and_late_offline_sync_creates_immutable_audit_log(): void
    {
        $product = Product::create([
            'name' => 'Offline Item',
            'code' => 'PRD-OFF-01',
            'selling_price' => 4000.0,
            'cost_price' => 1500.0,
            'stock_quantity' => 30.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 30.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 30.0,
            'remaining_quantity' => 30.0,
            'unit_cost' => 1500.0,
            'source_type' => 'opening_stock',
            'received_date' => now()->subDays(3),
            'status' => 'active',
        ]);

        // Day 1 shift (now closed at 50,000)
        $day1 = now()->subDay()->toDateString();
        $closedReg = CashRegister::create([
            'reference_no' => 'REG-DAY1-CLOSED',
            'register_name' => 'Counter 1',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 10000.0,
            'closing_cash' => 10000.0,
            'expected_cash' => 10000.0,
            'cash_difference' => 0.0,
            'status' => 'closed',
            'opened_at' => $day1 . ' 08:00:00',
            'closed_at' => $day1 . ' 21:00:00',
        ]);

        // Day 2 shift currently open
        $today = now()->toDateString();
        $openReg = CashRegister::create([
            'reference_no' => 'REG-DAY2-OPEN',
            'register_name' => 'Counter 1',
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 25000.0,
            'expected_cash' => 25000.0,
            'status' => 'open',
            'opened_at' => $today . ' 08:00:00',
        ]);

        // 1. Register close blocked when pending_offline_count > 0
        $this->withToken($this->admin->api_token)->postJson('/api/cash-register/close', [
            'closing_cash' => 25000.0,
            'pending_offline_count' => 2,
        ])->assertStatus(422);

        // 2. Late offline sync from Day 1 arriving on Day 2 via bulk-sync (explicit closed register ID)
        $syncRes = $this->withToken($this->admin->api_token)->postJson('/api/sales/bulk-sync', [
            'sales' => [[
                'reference_no' => 'OFF-DAY1-LATE-01',
                'idempotency_key' => 'offline-uuid-late-001',
                'customer_name' => 'Walk-in Customer',
                'warehouse_id' => $this->wh1->id,
                'warehouse_name' => $this->wh1->name,
                'cash_register_id' => $closedReg->id,
                'date' => $day1 . ' 15:30:00',
                'status' => 'Completed',
                'subtotal' => 4000.0,
                'grand_total' => 4000.0,
                'paid_amount' => 4000.0,
                'payment_type' => 'Cash',
                'is_offline_sync' => true,
                'items' => [[
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_price' => 4000.0,
                    'quantity' => 1,
                    'subtotal' => 4000.0,
                ]],
                'payments' => [['payment_type' => 'Cash', 'amount' => 4000.0]],
            ]],
        ])->assertStatus(200);

        $this->assertEquals(1, $syncRes->json('synced_count'));
        $this->assertEquals('offline-uuid-late-001', $syncRes->json('synced.0.idempotency_key'));

        // Both Closed Day 1 register (10,000) & Open Day 2 register (25,000) MUST remain untouched!
        $this->assertEquals(10000.0, (float)$closedReg->fresh()->expected_cash);
        $this->assertEquals(25000.0, (float)$openReg->fresh()->expected_cash);

        $lateSale = Sale::where('idempotency_key', 'offline-uuid-late-001')->first();
        $this->assertNotNull($lateSale);
        $this->assertTrue((bool)$lateSale->late_offline_reconciliation);
        $this->assertDatabaseHas('late_offline_reconciliations', [
            'sale_id' => $lateSale->id,
            'original_cash_register_id' => $closedReg->id,
            'syncing_open_register_id' => $openReg->id,
            'cash_amount' => 4000.0,
            'status' => 'pending_review',
        ]);
    }

    public function test_tax_exclusive_pnl_zreport_ordered_exclusion_and_sale_return_cumulative_guard(): void
    {
        $product = Product::create([
            'name' => 'Taxed Merch',
            'code' => 'PRD-TAX-01',
            'selling_price' => 10000.0,
            'cost_price' => 4000.0,
            'stock_quantity' => 20.0,
        ]);
        StockBalance::create(['warehouse_id' => $this->wh1->id, 'product_id' => $product->id, 'quantity' => 20.0]);
        InventoryBatch::create([
            'warehouse_id' => $this->wh1->id,
            'product_id' => $product->id,
            'initial_quantity' => 20.0,
            'remaining_quantity' => 20.0,
            'unit_cost' => 4000.0,
            'source_type' => 'opening_stock',
            'received_date' => now()->subDays(2),
            'status' => 'active',
        ]);

        $today = now()->toDateString();

        // Completed sale: 20,000 merchandise + 1,000 Commercial Tax (5%) + 500 Shipping = 21,500 grand_total
        $sale = Sale::create([
            'reference_no' => 'SL-TAX-EXCL-01',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 20000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 1000.0,
            'shipping' => 500.0,
            'grand_total' => 21500.0,
            'paid_amount' => 21500.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 8000.0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 10000.0,
            'base_unit_price' => 10000.0,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'conversion_rate' => 1.0,
            'subtotal' => 20000.0,
            'total_cogs' => 8000.0,
        ]);

        // Unposted 'Ordered' document must NOT appear in P&L or Z-Report!
        Sale::create([
            'reference_no' => 'SL-ORDERED-IGNORE',
            'customer_name' => 'Pending Customer',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => $today . ' 11:00:00',
            'status' => 'Ordered',
            'subtotal' => 50000.0,
            'grand_total' => 50000.0,
            'payment_status' => 'Unpaid',
        ]);

        $plRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/profit-loss?start_date={$today}&end_date={$today}&warehouse_name=" . urlencode($this->wh1->name) . "&force_refresh=1")
            ->assertStatus(200);
        // Tax-Exclusive & ex-Shipping Net Sales = 20,000 (NOT 21,500)
        $this->assertEqualsWithDelta(20000.0, (float)$plRes->json('data.net_sales'), 0.01);
        $this->assertEqualsWithDelta(12000.0, (float)$plRes->json('data.total_gross_profit'), 0.01);

        $zRes = $this->withToken($this->admin->api_token)
            ->getJson("/api/reports/z-report?date={$today}&warehouse={$this->wh1->id}&force_refresh=1")
            ->assertStatus(200);
        $this->assertEqualsWithDelta(20000.0, (float)$zRes->json('data.0.net_sales'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float)$zRes->json('data.0.tax_collected'), 0.01);

        // Partial Return #1 (12,000 <= 21,500) succeeds; Partial Return #2 (12,000, cumulative 24,000 > 21,500) must return 422 via || guard!
        $customer = Customer::create(['name' => 'Ret Buyer', 'phone' => '09333333']);
        $sale->update(['customer_id' => $customer->id, 'customer_name' => $customer->name]);

        $ret1 = $this->withToken($this->admin->api_token)->postJson('/api/sale-returns', [
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => $today . ' 13:00:00',
            'status' => 'Completed',
            'tax_rate' => 5.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'unit_price' => 10000.0,
                'subtotal' => 10000.0,
            ]],
        ])->assertStatus(201);

        // Force an attempted second return with inflated shipping/order fee pushing cumulative goods total past sale total -> must 422
        $this->withToken($this->admin->api_token)->postJson('/api/sale-returns', [
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'date' => $today . ' 14:00:00',
            'status' => 'Completed',
            'tax_rate' => 5.0,
            'shipping' => 0.0,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1.0,
                'unit_price' => 12000.0,
                'subtotal' => 12000.0,
            ]],
        ])->assertStatus(422);
    }

    public function test_products_sync_normalizes_utc_and_offset_updated_since_and_variant_deltas(): void
    {
        $prevTz = config('app.timezone');
        config(['app.timezone' => 'Asia/Yangon']);

        try {
            $p1 = Product::create([
                'name' => 'Yangon Time Product 1',
                'code' => 'PRD-TZ-01',
                'sku' => 'SKU-TZ-01',
                'selling_price' => 1500.0,
                'cost_price' => 900.0,
                'stock_quantity' => 10.0,
                'is_active' => true,
                'status' => 'Active',
            ]);
            $p2 = Product::create([
                'name' => 'Yangon Time Product 2',
                'code' => 'PRD-TZ-02',
                'sku' => 'SKU-TZ-02',
                'selling_price' => 2500.0,
                'cost_price' => 1400.0,
                'stock_quantity' => 20.0,
                'has_variants' => true,
                'is_active' => true,
                'status' => 'Active',
            ]);
            $variant = ProductVariant::create([
                'product_id' => $p2->id,
                'name' => 'Large',
                'sku' => 'SKU-TZ-02-L',
                'selling_price' => 2600.0,
                'cost_price' => 1500.0,
                'is_active' => true,
            ]);

            // Both products & variant stored in DB at 17:32:26 Asia/Yangon
            DB::table('products')->whereIn('id', [$p1->id, $p2->id])->update([
                'updated_at' => '2026-11-25 17:32:26',
            ]);
            DB::table('product_variants')->where('id', $variant->id)->update([
                'updated_at' => '2026-11-25 17:32:26',
            ]);

            // 2026-11-25T15:05:38.000Z == 2026-11-25 21:35:38 Asia/Yangon (AFTER 17:32:26!)
            // Before fix, raw UTC comparison returned ALL products instead of 0.
            $utcRes = $this->withToken($this->admin->api_token)
                ->getJson('/api/products/sync?updated_since=' . urlencode('2026-11-25T15:05:38.000Z'))
                ->assertStatus(200);
            $this->assertSame(0, (int) $utcRes->json('count'));
            $this->assertCount(0, $utcRes->json('products'));
            $this->assertFalse((bool) $utcRes->json('has_more'));

            // Now update only the variant to 21:40:00 Yangon -> delta sync must catch parent $p2 ONLY
            DB::table('product_variants')->where('id', $variant->id)->update([
                'updated_at' => '2026-11-25 21:40:00',
            ]);

            $deltaUtc = $this->withToken($this->admin->api_token)
                ->getJson('/api/products/sync?updated_since=' . urlencode('2026-11-25T15:05:38.000Z'))
                ->assertStatus(200);
            $this->assertSame(1, (int) $deltaUtc->json('count'));
            $this->assertSame($p2->id, $deltaUtc->json('products.0.id'));

            // Explicit +06:30 offset string must behave identically
            $deltaOffset = $this->withToken($this->admin->api_token)
                ->getJson('/api/products/sync?updated_since=' . urlencode('2026-11-25T21:35:38+06:30'))
                ->assertStatus(200);
            $this->assertSame(1, (int) $deltaOffset->json('count'));
            $this->assertSame($p2->id, $deltaOffset->json('products.0.id'));
        } finally {
            config(['app.timezone' => $prevTz]);
        }
    }
}
