<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Sale;
use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SaleExportStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_export_streams_csv_with_utf8_bom_and_accurate_accounting_totals(): void
    {
        Sale::create([
            'reference_no'   => 'SA-2026-0001',
            'customer_name'  => 'Walk-in Customer',
            'warehouse_name' => 'Main Outlet',
            'date'           => '2026-10-21 12:00:00',
            'status'         => 'Completed',
            'subtotal'       => 100.0,
            'grand_total'    => 105.0,
            'paid_amount'    => 105.0,
            'due_amount'     => 0.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
            'created_by'     => 'Super Admin',
            'user_role'      => 'Super Admin',
        ]);

        Sale::create([
            'reference_no'   => 'SA-2026-0002',
            'customer_name'  => 'Daw Hla',
            'warehouse_name' => 'Main Outlet',
            'date'           => '2026-10-21 13:00:00',
            'status'         => 'Completed',
            'subtotal'       => 200.0,
            'grand_total'    => 210.0,
            'paid_amount'    => 100.0,
            'due_amount'     => 110.0,
            'payment_status' => 'Partial',
            'payment_type'   => 'KBZPay',
            'created_by'     => 'Super Admin',
            'user_role'      => 'Super Admin',
        ]);

        $response = $this->get('/api/sales/export');

        $response->assertStatus(200);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('content-type'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // 1. Verify UTF-8 BOM
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));

        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        $this->assertGreaterThan(5, count($lines));

        // 2. Verify Headers
        $this->assertStringContainsString('Sales Orders Export', $lines[0]);
        $this->assertStringContainsString('#,Reference,Customer,Warehouse,Status,"Grand Total","Paid Amount","Due Amount","Payment Status","Payment Type","Created By","User Role","Date & Time",Notes', $content);

        // 3. Verify Totals Summary Row at the end
        $lastLine = end($lines);
        $this->assertStringStartsWith('TOTAL,', $lastLine);
        $this->assertStringContainsString('2 Orders', $lastLine);
        $this->assertStringContainsString('315', $lastLine); // grand total sum: 105 + 210 = 315
        $this->assertStringContainsString('205', $lastLine); // paid amount sum: 105 + 100 = 205
        $this->assertStringContainsString('110', $lastLine); // due amount sum: 0 + 110 = 110
    }

    public function test_sale_export_honors_warehouse_filter(): void
    {
        Sale::create([
            'reference_no'   => 'SA-2026-0001',
            'customer_name'  => 'Walk-in Customer',
            'warehouse_name' => 'Hledan Store',
            'date'           => '2026-10-21 12:00:00',
            'status'         => 'Completed',
            'subtotal'       => 100.0,
            'grand_total'    => 100.0,
            'paid_amount'    => 100.0,
            'due_amount'     => 0.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
            'created_by'     => 'Super Admin',
        ]);

        Sale::create([
            'reference_no'   => 'SA-2026-0002',
            'customer_name'  => 'Daw Hla',
            'warehouse_name' => 'Downtown Outlet',
            'date'           => '2026-10-21 13:00:00',
            'status'         => 'Completed',
            'subtotal'       => 200.0,
            'grand_total'    => 200.0,
            'paid_amount'    => 200.0,
            'due_amount'     => 0.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
            'created_by'     => 'Super Admin',
        ]);

        $response = $this->get('/api/sales/export?warehouse=' . urlencode('Hledan Store'));
        $response->assertStatus(200);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('"Warehouse Filter","Hledan Store"', $content);
        $this->assertStringContainsString('SA-2026-0001', $content);
        $this->assertStringNotContainsString('SA-2026-0002', $content);
        $this->assertStringContainsString('1 Orders', $content);
    }

    public function test_sale_export_honors_payment_status_filter(): void
    {
        Sale::create([
            'reference_no'   => 'SA-2026-0001',
            'customer_name'  => 'Walk-in Customer',
            'warehouse_name' => 'Main Outlet',
            'date'           => '2026-10-21 12:00:00',
            'status'         => 'Completed',
            'subtotal'       => 100.0,
            'grand_total'    => 100.0,
            'paid_amount'    => 100.0,
            'due_amount'     => 0.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
            'created_by'     => 'Super Admin',
        ]);

        Sale::create([
            'reference_no'   => 'SA-2026-0002',
            'customer_name'  => 'Daw Hla',
            'warehouse_name' => 'Main Outlet',
            'date'           => '2026-10-21 13:00:00',
            'status'         => 'Completed',
            'subtotal'       => 200.0,
            'grand_total'    => 200.0,
            'paid_amount'    => 0.0,
            'due_amount'     => 200.0,
            'payment_status' => 'Unpaid',
            'payment_type'   => 'Cash',
            'created_by'     => 'Super Admin',
        ]);

        $response = $this->get('/api/sales/export?payment_status=Unpaid');
        $response->assertStatus(200);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('"Payment Status Filter",Unpaid', $content);
        $this->assertStringContainsString('SA-2026-0002', $content);
        $this->assertStringNotContainsString('SA-2026-0001', $content);
        $this->assertStringContainsString('1 Orders', $content);
    }
}
