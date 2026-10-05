<?php

namespace Tests\Feature;

use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_delete_initial_payment_from_purchase_return(): void
    {
        $supplier = Supplier::create([
            'name'  => 'Metro General Wholesale',
            'phone' => '0912345678',
        ]);

        $return = PurchaseReturn::create([
            'reference_no'   => 'PR-20260922-1144',
            'supplier_id'    => $supplier->id,
            'supplier_name'  => $supplier->name,
            'warehouse_name' => 'Main Branch Outlet',
            'date'           => '2026-09-22 19:13:00',
            'status'         => 'Completed',
            'grand_total'    => 20000.0,
            'paid_amount'    => 20000.0,
            'due_amount'     => 0.0,
            'payment_status' => 'Paid',
            'payment_type'   => 'Cash',
        ]);

        $this->assertCount(1, $return->payments);
        $this->assertEquals(20000.0, $return->payments[0]['amount']);
        $this->assertEquals('REF-PR-20260922-1144', $return->payments[0]['reference_no']);

        // Delete payment via API
        $response = $this->deleteJson("/api/purchase-returns/{$return->id}/payments/REF-PR-20260922-1144");

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
            ]);

        $fresh = $return->fresh();
        $this->assertEquals(0.0, $fresh->paid_amount);
        $this->assertEquals(20000.0, $fresh->due_amount);
        $this->assertEquals('Unpaid', $fresh->payment_status);
        $this->assertEmpty($fresh->payments);
    }

    public function test_can_add_and_then_delete_payment(): void
    {
        $supplier = Supplier::create([
            'name'  => 'Metro General Wholesale',
            'phone' => '0912345678',
        ]);

        $return = PurchaseReturn::create([
            'reference_no'   => 'PR-20260922-1155',
            'supplier_id'    => $supplier->id,
            'supplier_name'  => $supplier->name,
            'warehouse_name' => 'Main Branch Outlet',
            'date'           => '2026-09-22 19:13:00',
            'status'         => 'Completed',
            'grand_total'    => 30000.0,
            'paid_amount'    => 0.0,
            'due_amount'     => 30000.0,
            'payment_status' => 'Unpaid',
            'payment_type'   => 'Cash',
        ]);

        $this->assertEmpty($return->payments);

        // Add payment 15,000
        $addRes = $this->postJson("/api/purchase-returns/{$return->id}/payments", [
            'amount'       => 15000.0,
            'payment_type' => 'Bank Transfer',
            'payment_date' => '2026-09-22 20:00:00',
        ]);

        $addRes->assertStatus(200);
        $fresh = $return->fresh();
        $this->assertEquals(15000.0, $fresh->paid_amount);
        $this->assertEquals(15000.0, $fresh->due_amount);
        $this->assertEquals('Partial', $fresh->payment_status);
        $this->assertCount(1, $fresh->payments);

        // Delete the payment
        $paymentRef = $fresh->payments[0]['reference_no'];
        $delRes = $this->deleteJson("/api/purchase-returns/{$return->id}/payments/{$paymentRef}");

        $delRes->assertStatus(200);
        $freshAfterDelete = $return->fresh();
        $this->assertEquals(0.0, $freshAfterDelete->paid_amount);
        $this->assertEquals(30000.0, $freshAfterDelete->due_amount);
        $this->assertEquals('Unpaid', $freshAfterDelete->payment_status);
        $this->assertEmpty($freshAfterDelete->payments);
    }
}
