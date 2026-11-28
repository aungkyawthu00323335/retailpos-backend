<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\InventoryLedgerRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryLedgerRepairAndCanonicalNameTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin-' . Str::lower(Str::random(5)),
            'permissions' => ['*'],
        ]);

        return User::create([
            'name' => 'Chief Accountant',
            'email' => 'cfo@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'role' => 'Super Admin',
            'api_token' => Str::random(40),
            'is_active' => true,
        ]);
    }

    public function test_repair_service_replaces_placeholders_preserves_custom_names_and_reconciles_stock(): void
    {
        $wh1 = Outlet::create(['name' => 'Yangon Main', 'is_active' => true]);
        $wh2 = Outlet::create(['name' => 'Mandalay Hub', 'is_active' => true]);

        $unitId = DB::table('product_units')->insertGetId([
            'name' => 'ဘူး',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prod = Product::create([
            'name' => 'Samsung ကိုကာကိုလာ သံဘူး #1',
            'code' => 'PRD-000001',
            'sku' => 'SKU-000001',
            'base_unit_id' => $unitId,
            'unit_name' => 'ဘူး',
            'cost_price' => 1750,
            'purchase_price' => 1750,
            'selling_price' => 2700,
            'retail_price' => 2700,
            'stock_quantity' => 50,
            'status' => 'Active',
            'is_active' => 1,
        ]);

        // Seed opening stock (initial_seed) + stale stock_balance = 50 (never updated by transactions)
        DB::table('stock_balances')->insert([
            'warehouse_id' => $wh1->id,
            'product_id' => $prod->id,
            'quantity' => 50,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
        ]);
        DB::table('stock_movements')->insert([
            'warehouse_id' => $wh1->id,
            'product_id' => $prod->id,
            'movement_type' => 'opening_stock',
            'reference_type' => 'initial_seed',
            'reference_id' => (string)$prod->id,
            'quantity_in' => 50,
            'quantity_out' => 0,
            'balance_after' => 50,
            'created_by' => 1,
            'created_at' => '2026-01-01 08:00:00',
        ]);

        // Seed orphaned pre-seed INIT-* inventory batch (must be purged)
        DB::table('inventory_batches')->insert([
            'warehouse_id' => $wh1->id,
            'product_id' => $prod->id,
            'source_type' => 'initial_stock',
            'source_reference' => 'INIT-ELEC-001',
            'initial_quantity' => 50,
            'remaining_quantity' => 50,
            'unit_cost' => 28000,
            'status' => 'active',
            'received_date' => '2026-01-01 08:00:00',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
        ]);

        // Purchase (+20) with placeholder name
        $poId = DB::table('purchases')->insertGetId([
            'reference_no' => 'PO-0001',
            'supplier_name' => 'DKSH Myanmar',
            'warehouse_id' => $wh1->id,
            'warehouse_name' => $wh1->name,
            'date' => '2026-02-01 10:00:00',
            'status' => 'Received',
            'grand_total' => 35000,
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);
        DB::table('purchase_items')->insert([
            'purchase_id' => $poId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000001',
            'product_name' => "Retail Product #{$prod->id}",
            'unit_id' => 999,
            'unit_name' => 'ခု',
            'conversion_rate' => 1,
            'unit_cost' => 1750,
            'base_unit_cost' => 1750,
            'quantity' => 20,
            'base_quantity' => 20,
            'subtotal' => 35000,
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);

        // Sale (-5) with placeholder name + second custom historical line (-2) that MUST be preserved
        $saleId = DB::table('sales')->insertGetId([
            'reference_no' => 'SA-0001',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $wh1->id,
            'warehouse_name' => $wh1->name,
            'date' => '2026-02-05 11:00:00',
            'status' => 'Completed',
            'grand_total' => 18900,
            'created_at' => '2026-02-05 11:00:00',
            'updated_at' => '2026-02-05 11:00:00',
        ]);
        $siPlaceholderId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000001',
            'product_name' => "Retail Product #{$prod->id}",
            'unit_id' => 999,
            'unit_name' => 'ခု',
            'conversion_rate' => 1,
            'unit_price' => 2700,
            'quantity' => 5,
            'base_quantity' => 5,
            'subtotal' => 13500,
            'total_cogs' => 8750,
            'created_at' => '2026-02-05 11:00:00',
            'updated_at' => '2026-02-05 11:00:00',
        ]);
        $siCustomId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000001',
            'product_name' => 'Custom Promo Edition Coca-Cola',
            'unit_id' => $unitId,
            'unit_name' => 'ဘူး',
            'conversion_rate' => 1,
            'unit_price' => 2700,
            'quantity' => 2,
            'base_quantity' => 2,
            'subtotal' => 5400,
            'total_cogs' => 3500,
            'created_at' => '2026-02-05 11:00:00',
            'updated_at' => '2026-02-05 11:00:00',
        ]);

        // Completed Sale Return (+3) with placeholder JSON payload & missing stock_movement
        DB::table('sale_returns')->insert([
            'reference_no' => 'SR-0001',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $wh1->id,
            'warehouse_name' => $wh1->name,
            'date' => '2026-02-06 12:00:00',
            'status' => 'Completed',
            'grand_total' => 8100,
            'items_payload' => json_encode([[
                'product_id' => $prod->id,
                'product_name' => "Retail Product #{$prod->id}",
                'product_code' => 'PRD-000001',
                'quantity' => 3,
                'unit_price' => 2700,
                'subtotal' => 8100,
            ]]),
            'created_at' => '2026-02-06 12:00:00',
            'updated_at' => '2026-02-06 12:00:00',
        ]);

        // Completed Transfer (10 units wh1 -> wh2) with placeholder name
        $trfId = DB::table('stock_transfers')->insertGetId([
            'reference_no' => 'TRF-0001',
            'from_warehouse_id' => $wh1->id,
            'from_warehouse' => $wh1->name,
            'to_warehouse_id' => $wh2->id,
            'to_warehouse' => $wh2->name,
            'date' => '2026-02-07 14:00:00',
            'status' => 'Completed',
            'created_at' => '2026-02-07 14:00:00',
            'updated_at' => '2026-02-07 14:00:00',
        ]);
        DB::table('stock_transfer_items')->insert([
            'stock_transfer_id' => $trfId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000001',
            'product_name' => "Retail Product #{$prod->id}",
            'quantity' => 10,
            'base_quantity' => 10,
            'unit_cost' => 1750,
            'base_unit_cost' => 1750,
            'subtotal' => 17500,
            'created_at' => '2026-02-07 14:00:00',
            'updated_at' => '2026-02-07 14:00:00',
        ]);

        $service = app(InventoryLedgerRepairService::class);
        $firstRun = $service->repairAll(true);

        // 1. Placeholder names replaced, custom snapshot preserved
        $this->assertSame($prod->name, DB::table('sale_items')->where('id', $siPlaceholderId)->value('product_name'));
        $this->assertSame('ဘူး', DB::table('sale_items')->where('id', $siPlaceholderId)->value('unit_name'));
        $this->assertSame('Custom Promo Edition Coca-Cola', DB::table('sale_items')->where('id', $siCustomId)->value('product_name'));
        $this->assertSame($prod->name, DB::table('purchase_items')->where('purchase_id', $poId)->value('product_name'));
        $this->assertSame($prod->name, DB::table('stock_transfer_items')->where('stock_transfer_id', $trfId)->value('product_name'));

        $srPayload = json_decode((string)DB::table('sale_returns')->where('reference_no', 'SR-0001')->value('items_payload'), true);
        $this->assertSame($prod->name, $srPayload[0]['product_name']);

        // 2. Orphaned INIT-* batch removed
        $this->assertSame(1, $firstRun['orphaned_batches_removed']);
        $this->assertSame(0, DB::table('inventory_batches')->count());

        // 3. Sale return stock movement backfilled
        $this->assertSame(1, $firstRun['sale_return_movements_backfilled']);

        // 4. Exact perpetual stock reconciliation per warehouse:
        //    wh1 = 50 (open) + 20 (PO) - 7 (SA) + 3 (SR) - 10 (TRF out) = 56
        //    wh2 = 0 + 10 (TRF in) = 10
        //    Total product stock = 66
        $this->assertEqualsWithDelta(56.0, (float)DB::table('stock_balances')->where('warehouse_id', $wh1->id)->where('product_id', $prod->id)->value('quantity'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float)DB::table('stock_balances')->where('warehouse_id', $wh2->id)->where('product_id', $prod->id)->value('quantity'), 0.001);
        $this->assertEqualsWithDelta(66.0, (float)DB::table('products')->where('id', $prod->id)->value('stock_quantity'), 0.001);

        // 5. Idempotency check: second run touches 0 records
        $secondRun = $service->repairAll(true);
        $this->assertSame(0, array_sum($secondRun['tables_synced']));
        $this->assertSame(0, array_sum($secondRun['return_payloads_synced']));
        $this->assertSame(0, $secondRun['orphaned_batches_removed']);
        $this->assertSame(0, $secondRun['sale_return_movements_backfilled']);
        $this->assertSame(0, $secondRun['stock_balances_reconciled']);
    }

    public function test_stock_ledger_resolves_canonical_name_supports_burmese_search_and_exposes_item_row_id(): void
    {
        $user = $this->createSuperAdmin();
        $wh1 = Outlet::create(['name' => 'Yangon Main', 'status' => 'Active']);
        $wh2 = Outlet::create(['name' => 'Mandalay Hub', 'status' => 'Active']);

        $prod = Product::create([
            'name' => 'Samsung ကိုကာကိုလာ သံဘူး #88',
            'code' => 'PRD-000088',
            'sku' => 'SKU-000088',
            'cost_price' => 1500,
            'selling_price' => 2500,
            'stock_quantity' => 100,
            'status' => 'Active',
            'is_active' => 1,
        ]);

        // Un-repaired placeholder sale row + completed transfer (Out & In pair)
        $saleId = DB::table('sales')->insertGetId([
            'reference_no' => 'SA-8801',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $wh1->id,
            'warehouse_name' => $wh1->name,
            'date' => '2026-05-10 10:00:00',
            'status' => 'Completed',
            'grand_total' => 5000,
            'created_at' => '2026-05-10 10:00:00',
            'updated_at' => '2026-05-10 10:00:00',
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000088',
            'product_name' => "Retail Product #{$prod->id}",
            'unit_price' => 2500,
            'quantity' => 2,
            'base_quantity' => 2,
            'subtotal' => 5000,
            'total_cogs' => 3000,
            'created_at' => '2026-05-10 10:00:00',
            'updated_at' => '2026-05-10 10:00:00',
        ]);

        $trfId = DB::table('stock_transfers')->insertGetId([
            'reference_no' => 'TRF-8801',
            'from_warehouse_id' => $wh1->id,
            'from_warehouse' => $wh1->name,
            'to_warehouse_id' => $wh2->id,
            'to_warehouse' => $wh2->name,
            'date' => '2026-05-11 10:00:00',
            'status' => 'Completed',
            'created_at' => '2026-05-11 10:00:00',
            'updated_at' => '2026-05-11 10:00:00',
        ]);
        $trfItemId = DB::table('stock_transfer_items')->insertGetId([
            'stock_transfer_id' => $trfId,
            'product_id' => $prod->id,
            'product_code' => 'PRD-000088',
            'product_name' => "Retail Product #{$prod->id}",
            'quantity' => 5,
            'base_quantity' => 5,
            'unit_cost' => 1500,
            'base_unit_cost' => 1500,
            'subtotal' => 7500,
            'created_at' => '2026-05-11 10:00:00',
            'updated_at' => '2026-05-11 10:00:00',
        ]);

        // Search by canonical Burmese product name even before DB repair
        $res = $this->withToken($user->api_token)
            ->getJson('/api/reports/stock-ledger?section=journal&search=' . urlencode('ကိုကာကိုလာ'))
            ->assertOk();

        $movements = collect($res->json('movements'));
        $this->assertCount(3, $movements); // 1 Sale Out + 1 Transfer Out + 1 Transfer In

        foreach ($movements as $mv) {
            $this->assertSame('Samsung ကိုကာကိုလာ သံဘူး #88', $mv['product_name']);
            $this->assertGreaterThan(0, (int)$mv['item_row_id']);
        }

        $saleRow = $movements->firstWhere('reference_no', 'SA-8801');
        $this->assertSame($saleItemId, (int)$saleRow['item_row_id']);

        $trfRows = $movements->where('reference_no', 'TRF-8801')->values();
        $this->assertCount(2, $trfRows);
        $this->assertSame($trfItemId, (int)$trfRows[0]['item_row_id']);
        $this->assertNotSame($trfRows[0]['type_label'], $trfRows[1]['type_label']);
    }
}
