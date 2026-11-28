<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListScrollPerformanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithPermissions(array $permissions, string $roleName = 'Auditor'): User
    {
        $role = Role::create([
            'name' => $roleName,
            'slug' => Str::slug($roleName) . '-' . Str::lower(Str::random(4)),
            'permissions' => $permissions,
        ]);

        return User::create([
            'name' => $roleName . ' User',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'role' => $roleName,
            'api_token' => Str::random(40),
            'is_active' => true,
        ]);
    }

    public function test_all_nine_list_endpoints_default_per_page_50_no_cache_and_cursor_pagination(): void
    {
        $outlet = Outlet::create(['name' => 'Main Warehouse', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Walk-in', 'phone' => '09111111']);
        $supplier = Supplier::create(['name' => 'Default Supplier', 'phone' => '09333333']);
        $expCat = ExpenseCategory::create(['name' => 'Utilities']);

        $user = $this->createUserWithPermissions([
            'products.view', 'products.view_cost',
            'adjustments.view',
            'quotations.view',
            'purchases.view',
            'purchase_returns.view',
            'sales.view',
            'sale_returns.view',
            'transfers.view',
            'expenses.view',
        ], 'Super Admin');

        Product::create([
            'code' => 'P-100',
            'name' => 'Coffee Beans',
            'cost_price' => 5000,
            'price' => 8000,
            'selling_price' => 8000,
            'stock_quantity' => 15,
            'product_unit' => 'Bag',
            'barcode_symbol' => 'CODE128',
        ]);

        StockAdjustment::create([
            'reference_no' => 'ADJ-100',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'type' => 'Addition',
            'total_products' => 1,
            'total_quantity' => 5,
            'net_quantity' => 5,
            'gross_quantity' => 5,
            'total_value' => 25000,
        ]);

        Quotation::create([
            'reference_no' => 'QUO-100',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Pending',
            'grand_total' => 8000,
        ]);

        Purchase::create([
            'reference_no' => 'PUR-100',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Received',
            'grand_total' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        PurchaseReturn::create([
            'reference_no' => 'PR-100',
            'purchase_reference_no' => 'PUR-100',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'grand_total' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);

        Sale::create([
            'reference_no' => 'SA-100',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'grand_total' => 8000,
            'paid_amount' => 8000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        SaleReturn::create([
            'reference_no' => 'SR-100',
            'sale_reference_no' => 'SA-100',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'grand_total' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        StockTransfer::create([
            'reference_no' => 'TRF-100',
            'from_warehouse_id' => $outlet->id,
            'from_warehouse' => $outlet->name,
            'to_warehouse_id' => $outlet->id,
            'to_warehouse' => $outlet->name,
            'date' => '2026-11-18 10:00:00',
            'status' => 'Completed',
            'total_quantity' => 2,
            'items_count' => 1,
        ]);

        Expense::create([
            'reference_no' => 'EXP-100',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Power Bill',
            'amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2026-11-18 10:00:00',
        ]);

        $defaultEndpoints = [
            '/api/products?compact_list=1',
            '/api/adjustments?no_items=1',
            '/api/quotations',
            '/api/purchases?no_items=1',
            '/api/purchase-returns?no_items=1',
            '/api/sales?no_items=1',
            '/api/sale-returns?no_items=1',
            '/api/transfers?no_items=1',
            '/api/expenses?no_items=1',
        ];

        foreach ($defaultEndpoints as $uri) {
            $first = $this->withToken($user->api_token)->getJson($uri);
            $first->assertStatus(200);
            $first->assertJsonPath('pagination.per_page', 50);
            $this->assertNull($first->headers->get('ETag'), "Unexpected ETag header on live {$uri}");
            $this->assertStringContainsString(
                'no-store',
                (string) $first->headers->get('Cache-Control'),
                "Missing Cache-Control: no-store on {$uri}"
            );

            // Even if client sends stale If-None-Match, backend returns live 200 JSON (never 304)
            $second = $this->withToken($user->api_token)
                ->withHeaders(['If-None-Match' => '"stale-etag"'])
                ->getJson($uri);
            $second->assertStatus(200);
        }

        // Verify compact=1 dropdowns + gzip compression middleware on >= 2KB JSON responses
        $usersRes = $this->withToken($user->api_token)->getJson('/api/users?compact=1');
        $usersRes->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $usersRes->headers->get('Cache-Control'));
        $outletsRes = $this->withToken($user->api_token)->getJson('/api/outlets?compact=1');
        $outletsRes->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $outletsRes->headers->get('Cache-Control'));

        // Seed 18 extra sales so cursor pagination & gzip threshold (>= 2KB) can both be verified cleanly
        for ($k = 1; $k <= 18; $k++) {
            Sale::create([
                'reference_no' => sprintf('SA-CUR-%03d', $k),
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => sprintf('2026-11-18 %02d:00:00', min(23, $k)),
                'status' => 'Completed',
                'grand_total' => 1000 * $k,
                'paid_amount' => 1000 * $k,
                'due_amount' => 0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
            ]);
        }

        $cursorPage1 = $this->withToken($user->api_token)->getJson('/api/sales?no_items=1&per_page=10&sort_by=id&sort_order=desc');
        $cursorPage1->assertStatus(200)
            ->assertJsonPath('pagination.has_more', true);
        $nextCursor = (string) $cursorPage1->json('pagination.next_cursor');
        $this->assertNotEmpty($nextCursor);
        $idsPage1 = collect($cursorPage1->json('data'))->pluck('id')->all();

        $cursorPage2 = $this->withToken($user->api_token)->getJson('/api/sales?no_items=1&per_page=10&sort_by=id&sort_order=desc&skip_summary=1&known_total=19&cursor=' . urlencode($nextCursor));
        $cursorPage2->assertStatus(200);
        $idsPage2 = collect($cursorPage2->json('data'))->pluck('id')->all();
        $this->assertCount(9, $idsPage2);
        $this->assertSame([], array_values(array_intersect($idsPage1, $idsPage2)), 'Cursor pages overlapped');

        $gzRes = $this->withToken($user->api_token)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->getJson('/api/sales?no_items=1&per_page=50');
        $gzRes->assertStatus(200);
        $this->assertSame('gzip', strtolower((string) $gzRes->headers->get('Content-Encoding')));
        $decodedGz = gzdecode((string) $gzRes->getContent());
        $this->assertNotFalse($decodedGz);
        $this->assertSame(19, (int) (json_decode($decodedGz, true)['total'] ?? 0));
    }

    public function test_product_compact_list_eliminates_auth_n_plus_one_and_preserves_variant_values(): void
    {
        $user = $this->createUserWithPermissions(['products.view'], 'Staff');

        for ($i = 1; $i <= 8; $i++) {
            $product = Product::create([
                'code' => 'P-N1-' . $i,
                'name' => 'Variant Item ' . $i,
                'cost_price' => 1000,
                'price' => 2000,
                'selling_price' => 2000,
                'stock_quantity' => 10,
                'has_variants' => true,
                'product_unit' => 'Pc',
                'barcode_symbol' => 'CODE128',
            ]);
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'name' => 'Variant Large ' . $i,
                'sku' => 'SKU-N1-' . $i,
                'cost_price' => 1000,
                'selling_price' => 2000,
                'is_active' => true,
            ]);
            ProductVariantValue::create([
                'product_variant_id' => $variant->id,
                'attribute_name' => 'Size',
                'attribute_value' => 'L',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->withToken($user->api_token)->getJson('/api/products?compact_list=1&per_page=100');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(8, $data);

        // Cost must be masked for staff lacking products.view_cost
        $this->assertNull($data[0]['cost_price']);
        // Compact list must omit redundant heavy pivot arrays
        $this->assertArrayNotHasKey('unit_links', $data[0]);
        $this->assertArrayNotHasKey('warehouse_stocks', $data[0]);
        // Variant attribute chips used by ProductVariantTableRow must still be present
        $this->assertNotEmpty($data[0]['variants']);
        $this->assertSame('L', $data[0]['variants'][0]['values'][0]['attribute_value'] ?? null);

        // Ensure users table wasn't queried N times inside ProductListResource/ProductVariantResource
        $userQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'from "users"') || str_contains(strtolower($q['query']), 'from `users`'));
        $this->assertLessThanOrEqual(2, count($userQueries), 'User/role auth check should be memoized per request, not repeated per product/variant');
    }

    public function test_sale_returns_no_items_with_masked_cost_has_zero_sale_n_plus_one_queries(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet 1', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Cust 1', 'phone' => '09222222']);
        $staff = $this->createUserWithPermissions(['sale_returns.view'], 'Cashier');
        $staff->outlets()->sync([$outlet->id]);

        for ($i = 1; $i <= 6; $i++) {
            $sale = Sale::create([
                'reference_no' => "SA-{$i}",
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => '2026-11-18 10:00:00',
                'status' => 'Completed',
                'grand_total' => 5000,
                'paid_amount' => 5000,
                'due_amount' => 0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
            ]);
            SaleReturn::create([
                'reference_no' => "SR-{$i}",
                'sale_id' => $sale->id,
                'sale_reference_no' => $sale->reference_no,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => '2026-11-18 11:00:00',
                'status' => 'Completed',
                'grand_total' => 1000,
                'paid_amount' => 1000,
                'due_amount' => 0,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->withToken($staff->api_token)->getJson('/api/sale-returns?no_items=1&per_page=100');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $res->assertStatus(200);
        $this->assertCount(6, $res->json('data'));

        $saleQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'from "sales"') || str_contains(strtolower($q['query']), 'from `sales`'));
        $this->assertCount(0, $saleQueries, 'SaleReturn no_items=1 must not fall back to Sale queries while masking costs');
    }

    public function test_zero_cache_mode_does_not_repopulate_legacy_list_caches(): void
    {
        Cache::flush();
        Cache::store('file')->flush();
        $accounting = app(PurchaseAccountingService::class);
        $accounting->invalidateAllCaches();

        $this->assertFalse(Cache::has('expense_list_summary_v1_ver'));
        $this->assertFalse(Cache::has('quo_meta_cache_version'));
        $this->assertFalse(Cache::store('file')->has('products_cache_version'));
        $this->assertSame('1', StockAdjustment::listCacheVersion());
    }

    public function test_list_scroll_performance_indexes_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasIndex('products', 'idx_prod_del_hasvar_id'), 'idx_prod_del_hasvar_id missing');
        $this->assertTrue(Schema::hasIndex('product_variants', 'idx_pvar_prod_del_sort'), 'idx_pvar_prod_del_sort missing');
        $this->assertTrue(Schema::hasIndex('quotations', 'idx_quo_wh_date_id'), 'idx_quo_wh_date_id missing');
        $this->assertTrue(Schema::hasIndex('purchases', 'idx_pur_status_id'), 'idx_pur_status_id missing');
        $this->assertTrue(Schema::hasIndex('purchase_returns', 'idx_pr_status_id'), 'idx_pr_status_id missing');
        $this->assertTrue(Schema::hasIndex('sales', ['customer_id', 'id']), 'sales customer_id,id index missing');
        $this->assertTrue(Schema::hasIndex('sale_payments', 'idx_sp_sale_pay_type'), 'idx_sp_sale_pay_type missing');
        $this->assertTrue(Schema::hasIndex('sales', ['status', 'id']), 'sales status,id index missing');
        $this->assertTrue(Schema::hasIndex('sale_returns', 'idx_sr_status_id'), 'idx_sr_status_id missing');
        $this->assertTrue(Schema::hasIndex('stock_transfers', 'idx_trf_from_wh_date_id'), 'idx_trf_from_wh_date_id missing');
        $this->assertTrue(Schema::hasIndex('expenses', 'idx_exp_cat_date_id'), 'idx_exp_cat_date_id missing');
        $this->assertTrue(Schema::hasIndex('outlets', 'idx_outlets_active_id'), 'idx_outlets_active_id missing');
        $this->assertTrue(Schema::hasIndex('products', 'idx_prod_del_sell_id'), 'idx_prod_del_sell_id missing');
        $this->assertTrue(Schema::hasIndex('stock_adjustments', 'idx_sa_updated_at'), 'idx_sa_updated_at missing');
        $this->assertTrue(Schema::hasIndex('quotations', 'idx_quo_expiry_id'), 'idx_quo_expiry_id missing');
        $this->assertTrue(Schema::hasIndex('quotations', 'idx_quo_grand_id'), 'idx_quo_grand_id missing');
        $this->assertTrue(Schema::hasIndex('purchases', 'idx_pur_grand_id'), 'idx_pur_grand_id missing');
        $this->assertTrue(Schema::hasIndex('purchase_returns', 'idx_pr_grand_id'), 'idx_pr_grand_id missing');
        $this->assertTrue(Schema::hasIndex('sales', 'idx_sales_grand_id'), 'idx_sales_grand_id missing');
        $this->assertTrue(Schema::hasIndex('sale_returns', 'idx_sr_grand_id'), 'idx_sr_grand_id missing');
        $this->assertTrue(Schema::hasIndex('stock_transfers', 'idx_trf_ship_id'), 'idx_trf_ship_id missing');
        $this->assertTrue(Schema::hasIndex('expenses', 'idx_exp_amt_id'), 'idx_exp_amt_id missing');
    }
}
