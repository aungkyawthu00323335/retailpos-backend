<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferPaymentExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_payment_synchronizes_with_linked_expense(): void
    {
        // 1. Create a stock transfer with 50,000 MMK shipping charge
        $transfer = StockTransfer::create([
            'reference_no'    => 'TR_9998881',
            'from_warehouse'  => 'Main Branch Outlet',
            'to_warehouse'    => 'Downtown Store Branch',
            'date'            => '2026-09-29',
            'status'          => 'Completed',
            'shipping_charge' => 50000.0,
            'items_count'     => 1,
            'total_quantity'  => 10,
            'user_name'       => 'Senior Auditor',
            'user_role'       => 'Accountant',
        ]);

        // 2. Create the linked expense record matching TransferController conventions
        $expense = Expense::create([
            'reference_no'          => 'EXP_TRF-TR_9998881',
            'date'                  => '2026-09-29',
            'expense_category_name' => 'Transfer Shipping',
            'title'                 => 'Shipping for Transfer #TR_9998881',
            'warehouse_name'        => 'Main Branch Outlet',
            'amount'                => 50000.0,
            'paid_amount'           => 30000.0,
            'due_amount'            => 20000.0,
            'payment_status'        => 'Partial',
            'payment_method'        => 'Cash',
            'notes'                 => 'Stock Transfer Shipping Freight',
        ]);

        // 3. Create initial expense payment
        ExpensePayment::create([
            'expense_id'     => $expense->id,
            'reference_no'   => 'PAY-TR_9998881-1',
            'amount'         => 30000.0,
            'payment_type'   => 'Cash',
            'payment_date'   => '2026-09-29',
            'user_name'      => 'Senior Auditor',
            'user_role'      => 'Accountant',
        ]);

        // 4. Test GET /api/transfers/{id}/payments
        $getPayments = $this->getJson("/api/transfers/{$transfer->id}/payments");
        $getPayments->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $paymentsList = $getPayments->json('data.payments');
        $this->assertCount(1, $paymentsList);
        $this->assertEquals(30000.0, $paymentsList[0]['amount']);
        $this->assertEquals('PAY-TR_9998881-1', $paymentsList[0]['reference_no']);

        // 5. Test POST /api/transfers/{id}/payments: Add remaining 20,000 MMK via KBZPay
        $addPayment = $this->postJson("/api/transfers/{$transfer->id}/payments", [
            'amount'       => 20000.0,
            'payment_type' => 'KBZPay',
            'notes'        => 'Remaining shipping settlement',
        ]);
        $addPayment->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference_no', 'TR_9998881');
        $this->assertCount(2, $addPayment->json('data.payments'));

        // Verify Expense record is updated to Paid in full
        $freshExpense = $expense->fresh();
        $this->assertEquals(50000.0, $freshExpense->paid_amount);
        $this->assertEquals(0.0, $freshExpense->due_amount);
        $this->assertEquals('Paid', $freshExpense->payment_status);

        // Verify Expense now has 2 payment rows
        $this->assertEquals(2, ExpensePayment::where('expense_id', $expense->id)->count());

        // Verify Expense API endpoint returns 2 payments
        $getExpense = $this->getJson("/api/expenses/{$expense->id}");
        $getExpense->assertStatus(200)
            ->assertJsonPath('data.paid_amount', 50000)
            ->assertJsonPath('data.payment_status', 'Paid');
        $this->assertCount(2, $getExpense->json('data.payments'));

        // 6. Test DELETE /api/transfers/{id}/payments/{paymentId}: Delete the initial Cash payment
        $delPayment = $this->deleteJson("/api/transfers/{$transfer->id}/payments/PAY-TR_9998881-1");
        $delPayment->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference_no', 'TR_9998881');
        $this->assertCount(1, $delPayment->json('data.payments'));

        // Verify Expense balance is recalculated correctly after deletion
        $afterDelExpense = $expense->fresh();
        $this->assertEquals(20000.0, $afterDelExpense->paid_amount);
        $this->assertEquals(30000.0, $afterDelExpense->due_amount);
        $this->assertEquals('Partial', $afterDelExpense->payment_status);

        // Verify only 1 payment remains in Expense
        $this->assertEquals(1, ExpensePayment::where('expense_id', $expense->id)->count());

        // Verify Expense API endpoint now reflects 1 payment remaining
        $getExpenseAfter = $this->getJson("/api/expenses/{$expense->id}");
        $getExpenseAfter->assertStatus(200)
            ->assertJsonPath('data.paid_amount', 20000)
            ->assertJsonPath('data.payment_status', 'Partial');
        $this->assertCount(1, $getExpenseAfter->json('data.payments'));
    }

    public function test_transfer_payment_rejects_overpayment_and_supports_reference_no_lookup(): void
    {
        // 1. Create a stock transfer with reference_no TR_2026-00908
        $transfer = StockTransfer::create([
            'reference_no'    => 'TR_2026-00908',
            'from_warehouse'  => 'Main Warehouse',
            'to_warehouse'    => 'Branch Outlet 2',
            'date'            => '2026-09-30',
            'status'          => 'Completed',
            'shipping_charge' => 10.50,
            'items_count'     => 1,
            'total_quantity'  => 5,
            'user_name'       => 'Super Admin',
            'user_role'       => 'Super Admin',
        ]);

        // 2. Overpayment attempt: due is 10.50, paying 15.00 must be rejected with 422
        $overpay = $this->postJson("/api/transfers/{$transfer->reference_no}/payments", [
            'amount'       => 15.00,
            'payment_type' => 'Cash',
        ]);
        $overpay->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Payment amount (15.00) cannot exceed the remaining due shipping fee (10.50)');

        // 3. Multi-tender split payment via reference_no URL path: 5.50 Cash + 5.00 WavePay = 10.50 (Exact)
        $splitPay = $this->postJson("/api/transfers/{$transfer->reference_no}/payments", [
            'amount'       => 10.50,
            'payment_type' => 'Split',
            'reference_no' => 'TR_2026-00908',
            'payments'     => [
                ['payment_type' => 'Cash', 'amount' => 5.50],
                ['payment_type' => 'WavePay', 'amount' => 5.00],
            ],
            'user_name'    => 'Super Admin',
            'user_role'    => 'Super Admin',
            'notes'        => 'Split settlement for freight',
        ]);
        $splitPay->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.shipping_paid', 10.5)
            ->assertJsonPath('data.shipping_due', 0)
            ->assertJsonPath('data.shipping_payment_status', 'Paid');

        $this->assertCount(2, $splitPay->json('data.payments'));

        // 4. Subsequent payment attempt when due is 0 must also be rejected with 422
        $attemptAfterPaid = $this->postJson("/api/transfers/{$transfer->reference_no}/payments", [
            'amount'       => 1.00,
            'payment_type' => 'Cash',
        ]);
        $attemptAfterPaid->assertStatus(422);
    }
}
