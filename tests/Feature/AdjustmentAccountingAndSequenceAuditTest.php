<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdjustmentAccountingAndSequenceAuditTest extends TestCase
{
    use RefreshDatabase;

    private function createOutlet(string $name = 'Central Warehouse'): Outlet
    {
        return Outlet::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createProduct(string $code, string $name, float $stock, float $cost = 100.0): Product
    {
        return Product::create([
            'code' => $code,
            'name' => $name,
            'cost_price' => $cost,
            'price' => $cost * 1.5,
            'stock_quantity' => $stock,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);
    }

    public function test_summary_aggregates_mixed_multiunit_and_null_item_type_accurately(): void
    {
        $outlet = $this->createOutlet('Central Warehouse');
        $prodA = $this->createProduct('AUD-SUM-A', 'Summary Item A', 20.0, 500.0);
        $prodB = $this->createProduct('AUD-SUM-B', 'Summary Item B', 50.0, 200.0);

        // 1. Mixed voucher via API (Addition 2 boxes @ conv 12 = +24 pcs @ 6000/box = +12000; Subtraction 5 pcs @ 200 = -1000)
        $res = $this->postJson('/api/adjustments', [
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-15 10:00:00',
            'type' => 'Mixed',
            'reason_code' => 'Audit Correction',
            'notes' => 'Mixed multi-unit audit',
            'items' => [
                [
                    'product_id' => $prodA->id,
                    'product_code' => $prodA->code,
                    'product_name' => $prodA->name,
                    'unit_name' => 'Box',
                    'conversion_rate' => 12.0,
                    'quantity' => 2.0,
                    'base_quantity' => 24.0,
                    'type' => 'Addition',
                    'unit_cost' => 6000.0,
                    'base_unit_cost' => 500.0,
                    'subtotal' => 12000.0,
                ],
                [
                    'product_id' => $prodB->id,
                    'product_code' => $prodB->code,
                    'product_name' => $prodB->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'quantity' => 5.0,
                    'base_quantity' => 5.0,
                    'type' => 'Subtraction',
                    'unit_cost' => 200.0,
                    'base_unit_cost' => 200.0,
                    'subtotal' => 1000.0,
                ],
            ],
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.type', 'Mixed');
        $res->assertJsonPath('data.total_value', 11000);
        $this->assertEquals(29.0, (float)$res->json('data.gross_quantity'));
        $this->assertEquals(19.0, (float)$res->json('data.net_quantity'));

        // 2. Legacy Subtraction row with NULL header total_value, empty item type, NULL subtotal, and 0 base_quantity
        $legacy = StockAdjustment::create([
            'reference_no' => 'ADJ-LEG-SUB-01',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-14 09:00:00',
            'total_products' => 1,
            'total_quantity' => -6.0,
            'net_quantity' => -6.0,
            'gross_quantity' => 6.0,
            'total_value' => null,
            'type' => 'Subtraction',
            'notes' => 'Legacy row missing subtotal & item type',
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $legacy->id,
            'product_id' => $prodB->id,
            'product_code' => $prodB->code,
            'product_name' => $prodB->name,
            'conversion_rate' => 2.0,
            'quantity' => 3.0,
            'base_quantity' => 0.0,
            'type' => '',
            'unit_cost' => 100.0,
        ]);
        StockAdjustment::invalidateListCache();

        $indexRes = $this->getJson('/api/adjustments?no_items=1');
        $indexRes->assertStatus(200);
        $indexRes->assertJsonPath('summary.total_additions_value', 12000);
        $indexRes->assertJsonPath('summary.total_subtractions_value', 1300);
        $indexRes->assertJsonPath('summary.total_adjusted_quantity', 35);

        $rows = collect($indexRes->json('data'));
        $legacyRow = $rows->firstWhere('reference_no', 'ADJ-LEG-SUB-01');
        $this->assertNotNull($legacyRow);
        $this->assertEquals(-300.0, (float)$legacyRow['total_value']);
    }

    public function test_date_range_includes_iso_t_timestamps_and_filters_by_user_id(): void
    {
        $outlet = $this->createOutlet('Branch North');
        $userA = User::create([
            'name' => 'Accountant Aung',
            'email' => 'aung@audit.test',
            'password' => bcrypt('Secret123!'),
            'role' => 'Accountant',
            'status' => 'active',
        ]);

        StockAdjustment::create([
            'reference_no' => 'ADJ-DT-DATEONLY',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-10',
            'total_products' => 1,
            'total_quantity' => 1,
            'type' => 'Addition',
            'user_id' => $userA->id,
            'created_by' => $userA->name,
        ]);
        StockAdjustment::create([
            'reference_no' => 'ADJ-DT-ISOT',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-10T23:45:00',
            'total_products' => 1,
            'total_quantity' => 2,
            'type' => 'Addition',
        ]);
        StockAdjustment::create([
            'reference_no' => 'ADJ-DT-NEXTDAY',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-11 08:00:00',
            'total_products' => 1,
            'total_quantity' => 3,
            'type' => 'Addition',
        ]);

        $dateRes = $this->getJson('/api/adjustments?date_from=2026-11-10&date_to=2026-11-10&no_items=1');
        $dateRes->assertStatus(200);
        $refs = collect($dateRes->json('data'))->pluck('reference_no')->all();
        $this->assertContains('ADJ-DT-DATEONLY', $refs);
        $this->assertContains('ADJ-DT-ISOT', $refs);
        $this->assertNotContains('ADJ-DT-NEXTDAY', $refs);

        $userRes = $this->getJson("/api/adjustments?user_id={$userA->id}&no_items=1");
        $userRes->assertStatus(200);
        $this->assertSame(['ADJ-DT-DATEONLY'], collect($userRes->json('data'))->pluck('reference_no')->all());
    }

    public function test_cursor_pagination_supports_total_value_sort(): void
    {
        $outlet = $this->createOutlet();
        foreach ([100.0, 500.0, 300.0] as $idx => $val) {
            StockAdjustment::create([
                'reference_no' => 'ADJ-CUR-' . ($idx + 1),
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => '2026-11-12',
                'total_products' => 1,
                'total_quantity' => 1,
                'net_quantity' => 1,
                'gross_quantity' => 1,
                'total_value' => $val,
                'type' => 'Addition',
            ]);
        }

        $page1 = $this->getJson('/api/adjustments?sort_by=value&sort_order=desc&per_page=2&no_items=1');
        $page1->assertStatus(200);
        $page1Values = collect($page1->json('data'))->pluck('total_value')->all();
        $this->assertEquals([500, 300], $page1Values);
        $this->assertTrue((bool)$page1->json('pagination.has_more'));
        $cursor = $page1->json('pagination.next_cursor');
        $this->assertNotEmpty($cursor);

        $page2 = $this->getJson('/api/adjustments?sort_by=value&sort_order=desc&per_page=2&no_items=1&page=2&skip_summary=1&known_total=3&cursor=' . urlencode($cursor));
        $page2->assertStatus(200);
        $page2Values = collect($page2->json('data'))->pluck('total_value')->all();
        $this->assertEquals([100], $page2Values);
        $this->assertFalse((bool)$page2->json('pagination.has_more'));
    }

    public function test_export_csv_resolves_latest_user_sorts_by_value_and_masks_cost_for_restricted_user(): void
    {
        $outlet = $this->createOutlet();
        $prod = $this->createProduct('AUD-EXP-1', 'Export Product', 100.0, 250.0);

        $role = Role::create([
            'name' => 'Warehouse Clerk',
            'slug' => 'warehouse-clerk',
            'permissions' => ['adjustments.view', 'adjustments.export'],
        ]);
        $clerk = User::create([
            'name' => 'Current Auditor Name',
            'email' => 'clerk@audit.test',
            'password' => bcrypt('Secret123!'),
            'role_id' => $role->id,
            'role' => 'Senior Auditor',
            'status' => 'active',
        ]);

        $adj = StockAdjustment::create([
            'reference_no' => 'ADJ-EXP-001',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-11-13T14:20:00',
            'total_products' => 1,
            'total_quantity' => -4,
            'net_quantity' => -4,
            'gross_quantity' => 4,
            'total_value' => -1000.0,
            'type' => 'Subtraction',
            'user_id' => $clerk->id,
            'created_by' => 'Stale Staff Name',
            'user_role' => 'Old Role',
            'notes' => 'Export verification',
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adj->id,
            'product_id' => $prod->id,
            'product_code' => $prod->code,
            'product_name' => $prod->name,
            'quantity' => 4,
            'base_quantity' => 4,
            'type' => 'Subtraction',
            'unit_cost' => 250.0,
            'base_unit_cost' => 250.0,
            'subtotal' => 1000.0,
        ]);

        // Unauthenticated test harness sees full cost + resolved user name/role + value sorting
        $exportRes = $this->get('/api/adjustments/export?sort_by=value&sort_order=asc');
        $exportRes->assertStatus(200);
        $csv = $exportRes->streamedContent();
        $this->assertStringContainsString('ADJ-EXP-001', $csv);
        $this->assertStringContainsString('Current Auditor Name', $csv);
        $this->assertStringContainsString('Senior Auditor', $csv);
        $this->assertStringContainsString('-1000', $csv);
        $this->assertStringNotContainsString('Stale Staff Name', $csv);

        // Restricted user without products.view_cost sees Masked index/show/export
        $this->actingAs($clerk);

        $maskedIndex = $this->getJson('/api/adjustments?no_items=1');
        $maskedIndex->assertStatus(200);
        $this->assertNull($maskedIndex->json('data.0.total_value'));
        $this->assertEquals(0, $maskedIndex->json('summary.total_additions_value'));
        $this->assertEquals(0, $maskedIndex->json('summary.total_subtractions_value'));

        $maskedShow = $this->getJson("/api/adjustments/{$adj->id}");
        $maskedShow->assertStatus(200);
        $this->assertNull($maskedShow->json('data.total_value'));
        $this->assertNull($maskedShow->json('data.items.0.unit_cost'));
        $this->assertNull($maskedShow->json('data.items.0.subtotal'));

        $maskedExport = $this->get('/api/adjustments/export');
        $maskedExport->assertStatus(200);
        $maskedCsv = $maskedExport->streamedContent();
        $this->assertStringContainsString('Protected', $maskedCsv);
        $this->assertStringNotContainsString('-1000', $maskedCsv);
    }
}
