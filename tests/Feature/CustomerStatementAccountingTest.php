<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerStatementAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthorizedAdmin(): array
    {
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin-' . uniqid(),
            'display_name' => 'Super Admin',
            'permissions' => ['reports.view', 'report_customer.view'],
            'is_system' => false,
        ]);

        $token = 'test-token-' . uniqid('', true);
        $user = User::create([
            'name' => 'Senior Accountant',
            'email' => 'accountant-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'Super Admin',
            'role_id' => $role->id,
            'status' => 'Active',
            'api_token' => $token,
            'api_token_created_at' => now(),
        ]);

        return [$user, $token];
    }

    public function test_customer_statement_reconciles_double_entry_ar_credit_balance_and_pending_returns(): void
    {
        [, $token] = $this->createAuthorizedAdmin();

        $outletA = Outlet::create([
            'name' => 'Yangon Main',
            'code' => 'YGN',
            'status' => 'Active',
        ]);
        $outletB = Outlet::create([
            'name' => 'Mandalay Branch',
            'code' => 'MDY',
            'status' => 'Active',
        ]);

        $customer = Customer::create([
            'customer_id' => 'CUST-9001',
            'name' => 'Daw Mya Audit',
            'phone' => '09-111222333',
            'email' => 'mya@example.com',
            'tier' => 'Gold VIP',
            'status' => 'Active',
        ]);

        // 1. Completed Sale #1: 100,000 Ks, Paid 100,000 Ks (with 120,000 tendered -> test effective paid cap!)
        $sale1 = Sale::create([
            'reference_no' => 'SA-9001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-10 10:00:00',
            'status' => 'Completed',
            'grand_total' => 100000,
            'paid_amount' => 120000, // Over-tendered cash (should cap effective paid at 100,000!)
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'product_id' => 1,
            'product_name' => 'Item A',
            'product_code' => 'P-1',
            'quantity' => 2,
            'unit_price' => 50000,
            'subtotal' => 100000,
        ]);

        // 2. Completed Sale #2: 60,000 Ks, Partial Paid 40,000 Ks, Due 20,000 Ks
        Sale::create([
            'reference_no' => 'SA-9002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => '2026-10-12 11:00:00',
            'status' => 'Completed',
            'grand_total' => 60000,
            'paid_amount' => 40000,
            'due_amount' => 20000,
            'payment_status' => 'Partial',
            'payment_type' => 'KBZPay',
        ]);

        // 3. Voided Sale #3: MUST BE EXCLUDED from AR & Gross Sales!
        Sale::create([
            'reference_no' => 'SA-VOID',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-13 12:00:00',
            'status' => 'Voided',
            'grand_total' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);

        // 4. Completed Return #1: 50,000 Ks, Cash Refunded 15,000 Ks, Unrefunded Credit Due 35,000 Ks
        SaleReturn::create([
            'reference_no' => 'SR-9001',
            'sale_reference_no' => 'SA-9001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => '2026-10-14 09:00:00',
            'status' => 'Completed',
            'grand_total' => 50000,
            'paid_amount' => 15000,
            'due_amount' => 35000,
            'payment_status' => 'Partial',
            'refund_tender' => 'Cash',
            'items_payload' => json_encode([['product_id' => 1, 'qty' => 1]]),
        ]);

        // 5. Pending Return #2: 25,000 Ks -> MUST NOT reduce posted AR, but surfaced in pending_returns_*!
        SaleReturn::create([
            'reference_no' => 'SR-PENDING',
            'sale_reference_no' => 'SA-9002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => '2026-10-15 09:00:00',
            'status' => 'Pending',
            'grand_total' => 25000,
            'paid_amount' => 0,
            'due_amount' => 25000,
            'payment_status' => 'Unpaid',
            'refund_tender' => 'Cash',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/reports/customers/{$customer->id}/statement?per_page=1");

        $response->assertOk();
        $summary = $response->json('data.summary');
        $pagination = $response->json('data.pagination');

        // Exact Double-Entry Accounting Assertions:
        // Gross Sales = 100,000 + 60,000 = 160,000 (Voided 500,000 excluded)
        $this->assertEquals(2, $summary['invoices_count']);
        $this->assertEquals(160000.0, $summary['total_invoiced']);
        // Effective Paid = min(120,000, 100,000) + 40,000 = 140,000
        $this->assertEquals(140000.0, $summary['total_paid']);
        $this->assertEquals(20000.0, $summary['total_due']);

        // Posted Returns = 50,000 (Pending 25,000 excluded from posted AR)
        $this->assertEquals(1, $summary['returns_count']);
        $this->assertEquals(50000.0, $summary['total_returned']);
        $this->assertEquals(15000.0, $summary['total_refunded']);
        $this->assertEquals(35000.0, $summary['total_return_due']);

        // Pending returns surfaced cleanly for audit transparency
        $this->assertEquals(1, $summary['pending_returns_count']);
        $this->assertEquals(25000.0, $summary['pending_returns_amount']);

        // Net Reconciliation Identity:
        // Net Sales = 160,000 - 50,000 = 110,000
        // Net Collected = 140,000 - 15,000 = 125,000
        // Signed Balance = Net Sales - Net Collected = 110,000 - 125,000 = -15,000
        // Also equals Invoice Due (20,000) - Unrefunded Return Credit (35,000) = -15,000!
        $this->assertEquals(110000.0, $summary['net_invoiced']);
        $this->assertEquals(125000.0, $summary['net_paid']);
        $this->assertEquals(-15000.0, $summary['signed_net_balance']);
        $this->assertEquals(0.0, $summary['net_due']);
        $this->assertEquals(15000.0, $summary['credit_balance']);
        $this->assertEquals(
            round($summary['net_invoiced'] - $summary['net_paid'], 2),
            $summary['signed_net_balance']
        );

        // Even with per_page=1, pagination reports TRUE totals & has_more!
        $this->assertCount(1, $response->json('data.invoices'));
        $this->assertTrue($pagination['invoices_has_more']);
        $this->assertEquals(2, $response->json('data.invoices_pagination.total'));

        // Server-side warehouse filter reconciles ONLY Mandalay Branch (Sale #2: 60,000)
        $mdyResp = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/reports/customers/{$customer->id}/statement?warehouse_name=Mandalay%20Branch");
        $mdyResp->assertOk();
        $mdySum = $mdyResp->json('data.summary');
        $this->assertEquals(1, $mdySum['invoices_count']);
        $this->assertEquals(60000.0, $mdySum['total_invoiced']);
        $this->assertEquals(20000.0, $mdySum['net_due']);
        $this->assertEquals(0.0, $mdySum['credit_balance']);
    }
}
