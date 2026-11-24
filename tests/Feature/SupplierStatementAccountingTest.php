<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierStatementAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithPermissions(array $permissions): array
    {
        $role = Role::create([
            'name' => 'AP Auditor ' . uniqid(),
            'slug' => 'ap-auditor-' . uniqid(),
            'display_name' => 'AP Auditor',
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        $token = 'test-token-' . uniqid('', true);
        $user = User::create([
            'name' => 'Senior AP Accountant',
            'email' => 'ap-accountant-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => $role->name,
            'role_id' => $role->id,
            'status' => 'Active',
            'api_token' => $token,
            'api_token_created_at' => now(),
        ]);

        return [$user, $token];
    }

    public function test_supplier_statement_reconciles_double_entry_ap_opening_balance_and_pending_returns(): void
    {
        [, $token] = $this->createUserWithPermissions(['reports.view', 'report_supplier.view', 'products.view_cost']);

        $outletA = Outlet::create([
            'name' => 'Yangon Central WH',
            'code' => 'YGN',
            'status' => 'Active',
        ]);
        $outletB = Outlet::create([
            'name' => 'Mandalay Regional WH',
            'code' => 'MDY',
            'status' => 'Active',
        ]);

        // Supplier has 25,000 Ks prior opening balance + (90,000 open PO due - 60,000 unallocated PR credit) = 55,000 ledger balance_due
        $supplier = Supplier::create([
            'name' => 'Apex Wholesale Co.',
            'contact_person' => 'U Kyaw',
            'phone' => '09-777888999',
            'email' => 'apex@example.com',
            'category' => 'Wholesale',
            'payment_terms' => 'Net 30',
            'status' => 'Active',
            'balance_due' => 55000.0,
        ]);

        // 1. Received PO #1: 150,000 Ks, Paid 100,000 Ks, Due 50,000 Ks (2 line items)
        $po1 = Purchase::create([
            'reference_no' => 'PO-AP-1001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 150000,
            'grand_total' => 150000,
            'paid_amount' => 100000,
            'due_amount' => 50000,
            'payment_status' => 'Partial',
            'payment_type' => 'Bank Transfer',
        ]);
        PurchaseItem::create([
            'purchase_id' => $po1->id,
            'product_id' => 1,
            'product_code' => 'SKU-1',
            'product_name' => 'Product A',
            'unit_cost' => 50000,
            'quantity' => 2,
            'subtotal' => 100000,
        ]);
        PurchaseItem::create([
            'purchase_id' => $po1->id,
            'product_id' => 2,
            'product_code' => 'SKU-2',
            'product_name' => 'Product B',
            'unit_cost' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
        ]);

        // 2. Received PO #2: 100,000 Ks, Paid 60,000 Ks, Due 40,000 Ks
        Purchase::create([
            'reference_no' => 'PO-AP-1002',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => '2026-10-12 10:00:00',
            'status' => 'Received',
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_amount' => 60000,
            'due_amount' => 40000,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
        ]);

        // 3. Opening Stock PO: MUST be excluded from Gross Purchases & AP!
        Purchase::create([
            'reference_no' => 'PO-OPENING-99',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-01 08:00:00',
            'status' => 'Received',
            'subtotal' => 999999,
            'grand_total' => 999999,
            'paid_amount' => 999999,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Opening Stock',
        ]);

        // 4. Completed Purchase Return: 70,000 Ks, Cash Refunded 10,000 Ks, Unrefunded Credit 60,000 Ks (2 items)
        PurchaseReturn::create([
            'reference_no' => 'PR-AP-2001',
            'purchase_reference_no' => 'PO-AP-1001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-14 14:00:00',
            'status' => 'Completed',
            'grand_total' => 70000,
            'paid_amount' => 10000,
            'due_amount' => 60000,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'return_reason' => 'Damaged Carton',
            'items_payload' => json_encode([
                ['product_id' => 1, 'qty' => 1, 'unit_cost' => 50000],
                ['product_id' => 2, 'qty' => 1, 'unit_cost' => 20000],
            ]),
        ]);

        // 5. Pending Purchase Return: 45,000 Ks -> excluded from posted AP, surfaced in pending audit fields!
        PurchaseReturn::create([
            'reference_no' => 'PR-AP-PENDING',
            'purchase_reference_no' => 'PO-AP-1002',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => '2026-10-15 15:00:00',
            'status' => 'Pending',
            'grand_total' => 45000,
            'paid_amount' => 0,
            'due_amount' => 45000,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
            'return_reason' => 'Awaiting Approval',
        ]);

        $res = $this->withToken($token)
            ->getJson("/api/reports/suppliers/{$supplier->id}/statement?page=1&per_page=1")
            ->assertStatus(200);

        $summary = $res->json('data.summary');
        $this->assertEquals(2, $summary['purchases_count']);
        $this->assertEqualsWithDelta(250000.0, (float)$summary['total_purchased'], 0.01);
        $this->assertEqualsWithDelta(160000.0, (float)$summary['total_paid'], 0.01);
        $this->assertEqualsWithDelta(90000.0, (float)$summary['open_po_due'], 0.01);

        $this->assertEquals(1, $summary['returns_count']);
        $this->assertEqualsWithDelta(70000.0, (float)$summary['total_returned'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float)$summary['total_refunded'], 0.01);
        $this->assertEqualsWithDelta(60000.0, (float)$summary['total_return_due'], 0.01);
        $this->assertEqualsWithDelta(60000.0, (float)$summary['unallocated_credit'], 0.01);

        // Pending returns cleanly separated and reported
        $this->assertEquals(1, $summary['pending_returns_count']);
        $this->assertEqualsWithDelta(45000.0, (float)$summary['pending_returns_amount'], 0.01);

        // Double-entry AP reconciliation
        $this->assertEqualsWithDelta(180000.0, (float)$summary['net_purchased'], 0.01);
        $this->assertEqualsWithDelta(150000.0, (float)$summary['net_paid'], 0.01);
        $this->assertEqualsWithDelta(25000.0, (float)$summary['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(55000.0, (float)$summary['total_due'], 0.01);
        $this->assertEqualsWithDelta(55000.0, (float)$summary['net_due'], 0.01);
        $this->assertEqualsWithDelta(55000.0, (float)$summary['signed_net_balance'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$summary['credit_balance'], 0.01);

        // Exact AP settlement rate: 150,000 / (180,000 + 25,000) = 73.17%
        $this->assertEqualsWithDelta(73.17, (float)$summary['payment_rate_pct'], 0.02);

        // Dedicated per-tab pagination blocks present and accurate (per_page=1 with 2 POs, 1 PR)
        $this->assertTrue((bool)$res->json('data.purchases_pagination.has_more'));
        $this->assertEquals(2, (int)$res->json('data.purchases_pagination.total'));
        $this->assertFalse((bool)$res->json('data.returns_pagination.has_more'));
        $this->assertEquals(1, (int)$res->json('data.returns_pagination.total'));

        // Return row computes items_count without sending heavy raw items payload
        $retRow = $res->json('data.returns.0');
        $this->assertEquals(2, (int)$retRow['items_count']);
        $this->assertArrayNotHasKey('items', $retRow);

        // Supplier code formatted consistently
        $this->assertStringStartsWith('SUP-', (string)$res->json('data.supplier.code'));

        // Fetch page 2 to verify PO #1 item count (2 items)
        $page2 = $this->withToken($token)
            ->getJson("/api/reports/suppliers/{$supplier->id}/statement?page=2&per_page=1&skip_summary=1")
            ->assertStatus(200);
        $po1Row = $page2->json('data.purchases.0');
        $this->assertEquals('PO-AP-1001', $po1Row['reference_no']);
        $this->assertEquals(2, (int)$po1Row['items_count']);

        // Warehouse filter narrows posted + pending counts cleanly
        $whFiltered = $this->withToken($token)
            ->getJson("/api/reports/suppliers/{$supplier->id}/statement?warehouse_name=" . urlencode($outletA->name))
            ->assertStatus(200);
        $this->assertEquals(1, (int)$whFiltered->json('data.summary.purchases_count'));
        $this->assertEquals(1, (int)$whFiltered->json('data.summary.returns_count'));
        $this->assertEquals(0, (int)$whFiltered->json('data.summary.pending_returns_count'));
    }

    public function test_supplier_statement_surfaces_credit_balance_and_enforces_cost_masking(): void
    {
        [, $adminToken] = $this->createUserWithPermissions(['reports.view', 'report_supplier.view', 'products.view_cost']);
        [, $staffToken] = $this->createUserWithPermissions(['reports.view', 'report_supplier.view']);

        $outlet = Outlet::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'status' => 'Active',
        ]);

        $supplier = Supplier::create([
            'name' => 'Credit Vendor Ltd',
            'phone' => '09-444555666',
            'status' => 'Active',
            'balance_due' => -30000.0, // Synced AP ledger: 0 opening + 0 open PO due - 30,000 unallocated return credit
        ]);

        // PO: 100,000 Ks paid in full (open PO due = 0)
        Purchase::create([
            'reference_no' => 'PO-CRED-1',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Received',
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Return: 30,000 Ks unrefunded -> Supplier owes US 30,000 Ks credit!
        PurchaseReturn::create([
            'reference_no' => 'PR-CRED-1',
            'purchase_reference_no' => 'PO-CRED-1',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => '2026-10-11 10:00:00',
            'status' => 'Completed',
            'grand_total' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);

        $adminRes = $this->withToken($adminToken)
            ->getJson("/api/reports/suppliers/{$supplier->id}/statement")
            ->assertStatus(200);
        $sum = $adminRes->json('data.summary');
        $this->assertEqualsWithDelta(0.0, (float)$sum['total_due'], 0.01);
        $this->assertEqualsWithDelta(30000.0, (float)$sum['credit_balance'], 0.01);
        $this->assertEqualsWithDelta(-30000.0, (float)$sum['signed_net_balance'], 0.01);
        $this->assertEqualsWithDelta(-30000.0, (float)$sum['net_due'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$sum['payment_rate_pct'], 0.01);

        // Staff without cost.view receives masked costs
        $staffRes = $this->withToken($staffToken)
            ->getJson("/api/reports/suppliers/{$supplier->id}/statement")
            ->assertStatus(200);
        $this->assertTrue((bool)$staffRes->json('cost_masked'));
        $this->assertNull($staffRes->json('data.purchases.0.grand_total'));
        $this->assertNull($staffRes->json('data.returns.0.grand_total'));
        $this->assertEqualsWithDelta(0.0, (float)$staffRes->json('data.summary.credit_balance'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$staffRes->json('data.summary.signed_net_balance'), 0.01);
    }
}
