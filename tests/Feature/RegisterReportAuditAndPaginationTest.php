<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegisterReportAuditAndPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Outlet $wh1;
    private Outlet $wh2;
    private Outlet $wh3;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->wh1 = Outlet::create(['name' => 'Alpha Outlet', 'code' => 'ALP-01', 'is_active' => true]);
        $this->wh2 = Outlet::create(['name' => 'Beta Outlet', 'code' => 'BET-02', 'is_active' => true]);
        $this->wh3 = Outlet::create(['name' => 'Gamma Outlet', 'code' => 'GAM-03', 'is_active' => true]);

        $this->admin = User::create([
            'user_id' => 'USR-REG-AUD',
            'name' => 'Register Audit Admin',
            'email' => 'regaudit@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'reg_audit_token_001',
        ]);
    }

    public function test_deterministic_zero_overlap_pagination_across_same_day_boundary_for_cursor_and_offset(): void
    {
        $cust = Customer::create(['name' => 'Pagination Buyer', 'phone' => '0910000001', 'status' => 'Active']);

        // Create 6 legacy shifts across 2 days where 3 shifts share the boundary date (2026-10-07)
        // with distinct opened_at timestamps — previously caused 2 overlapping/dropped rows when per_page=4.
        $fixtures = [
            [$this->wh1, '2026-10-08 10:00:00', 1000.0, 'Cash', 'Admin POS'],
            [$this->wh2, '2026-10-08 08:30:00', 1500.0, 'KBZPay', 'Admin POS'],
            [$this->wh1, '2026-10-07 06:00:00', 2000.0, 'Cash', 'Admin POS'],
            [$this->wh2, '2026-10-07 14:00:00', 2500.0, 'WavePay', 'Admin POS'],
            [$this->wh3, '2026-10-07 09:30:00', 3000.0, 'Cash', 'Manager Mg'],
            [$this->wh1, '2026-10-06 11:00:00', 3500.0, 'CB Bank', 'Cashier A'],
        ];

        foreach ($fixtures as $i => [$wh, $date, $amt, $pt, $cashier]) {
            $sale = Sale::create([
                'reference_no' => 'SL-PAG-' . ($i + 1),
                'customer_id' => $cust->id,
                'customer_name' => $cust->name,
                'warehouse_id' => $wh->id,
                'warehouse_name' => $wh->name,
                'cash_register_id' => null,
                'date' => $date,
                'status' => 'Completed',
                'subtotal' => $amt,
                'grand_total' => $amt,
                'paid_amount' => $amt,
                'due_amount' => 0.0,
                'payment_status' => 'Paid',
                'payment_type' => $pt,
                'created_by' => $cashier,
            ]);
            SalePayment::create([
                'sale_id' => $sale->id,
                'reference_no' => 'SP-PAG-' . ($i + 1),
                'payment_date' => $date,
                'payment_type' => $pt,
                'amount' => $amt,
            ]);
        }

        // Page 1 (per_page=3) splits right inside 2026-10-07!
        $p1 = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?page=1&per_page=3')
            ->assertStatus(200);

        $this->assertEquals(6, (int)$p1->json('pagination.total'));
        $this->assertTrue((bool)$p1->json('pagination.has_more'));
        $cursor1 = $p1->json('pagination.next_cursor');
        $this->assertNotEmpty($cursor1);

        // Verify user_role consistency for Admin POS
        $this->assertEquals('Super Admin', $p1->json('sessions.0.user_role'));

        // Page 2 with cursor
        $p2Cursor = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?page=2&per_page=3&skip_summary=1&known_total=6&cursor=' . urlencode($cursor1))
            ->assertStatus(200);

        // Page 2 without cursor (offset)
        $p2Offset = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register?page=2&per_page=3&skip_summary=1&known_total=6')
            ->assertStatus(200);

        $ids1 = array_column($p1->json('sessions'), 'id');
        $ids2c = array_column($p2Cursor->json('sessions'), 'id');
        $ids2o = array_column($p2Offset->json('sessions'), 'id');

        $this->assertCount(3, $ids1);
        $this->assertCount(3, $ids2c);
        $this->assertEmpty(array_intersect($ids1, $ids2c), 'Page 1 and Page 2 must have zero overlapping IDs');
        $this->assertSame($ids2o, $ids2c, 'Cursor Page 2 and Offset Page 2 must return identical IDs');
        $this->assertSame(
            array_column($p2Offset->json('sessions'), 'reference_no'),
            array_column($p2Cursor->json('sessions'), 'reference_no')
        );

        // Strict descending opened_at across all 6 rows
        $all = array_merge($p1->json('sessions'), $p2Cursor->json('sessions'));
        $this->assertCount(6, array_unique(array_column($all, 'id')));
        for ($i = 1; $i < count($all); $i++) {
            $this->assertLessThanOrEqual($all[$i - 1]['opened_at'], $all[$i]['opened_at']);
        }
    }

    public function test_unpaid_credit_legacy_shift_does_not_inflate_cash_tender_count_and_avoids_real_reg_id_collision(): void
    {
        $cust = Customer::create(['name' => 'Credit Buyer', 'phone' => '0920000002', 'status' => 'Active']);

        // Real register with ID=1..N
        $reg = CashRegister::create([
            'reference_no' => 'REG-REAL-001',
            'register_name' => 'Register 01',
            'user_id' => $this->admin->id,
            'user_name' => 'Real Cashier',
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'opening_cash' => 500.0,
            'expected_cash' => 1000.0,
            'closing_cash' => 1000.0,
            'cash_difference' => 0.0,
            'status' => 'closed',
            'opened_at' => '2026-10-10 08:00:00',
            'closed_at' => '2026-10-10 18:00:00',
        ]);
        $realSale = Sale::create([
            'reference_no' => 'SL-REAL-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh1->id,
            'warehouse_name' => $this->wh1->name,
            'cash_register_id' => $reg->id,
            'date' => '2026-10-10 09:00:00',
            'status' => 'Completed',
            'subtotal' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'created_by' => 'Real Cashier',
        ]);
        SalePayment::create([
            'sale_id' => $realSale->id,
            'cash_register_id' => $reg->id,
            'reference_no' => 'SP-REAL-1',
            'payment_date' => '2026-10-10 09:00:00',
            'payment_type' => 'Cash',
            'amount' => 500.0,
        ]);

        // Unpaid Credit-only legacy shift (0 paid, no sale_payments)
        Sale::create([
            'reference_no' => 'SL-LEGACY-CR-1',
            'customer_id' => $cust->id,
            'customer_name' => $cust->name,
            'warehouse_id' => $this->wh2->id,
            'warehouse_name' => $this->wh2->name,
            'cash_register_id' => null,
            'date' => '2026-10-09 10:00:00',
            'status' => 'Completed',
            'subtotal' => 1200.0,
            'grand_total' => 1200.0,
            'paid_amount' => 0.0,
            'due_amount' => 1200.0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Credit',
            'created_by' => 'Credit Cashier',
        ]);

        $res = $this->withToken($this->admin->api_token)
            ->getJson('/api/reports/register')
            ->assertStatus(200);

        $sessions = $res->json('sessions');
        $this->assertCount(2, $sessions);
        // Zero ID collision between real CashRegister and synthetic legacy shift
        $this->assertNotEquals($sessions[0]['id'], $sessions[1]['id']);

        $legacyRow = collect($sessions)->firstWhere('warehouse_name', $this->wh2->name);
        $this->assertNotNull($legacyRow);
        $this->assertEquals('Credit', $legacyRow['primary_payment_type']);
        $this->assertEqualsWithDelta(0.0, (float)$legacyRow['cash_sales'], 0.01);
        $this->assertEqualsWithDelta(1200.0, (float)$legacyRow['total_sales'], 0.01);

        // Accounting reconciliation: total_shift_sales = 1700 (500 cash + 1200 unpaid credit),
        // Cash tender count MUST be 1 (only realSale), NOT 2!
        $this->assertEqualsWithDelta(1700.0, (float)$res->json('summary.total_shift_sales'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float)$res->json('summary.total_cash_sales'), 0.01);
        $cashTender = collect($res->json('by_payment_tender'))->firstWhere('payment_type', 'Cash');
        $this->assertNotNull($cashTender);
        $this->assertEquals(1, (int)$cashTender['count']);
        $this->assertEqualsWithDelta(500.0, (float)$cashTender['total_amount'], 0.01);
    }
}
