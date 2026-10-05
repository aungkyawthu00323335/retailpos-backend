<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseEnterpriseAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_expense_with_split_payments(): void
    {
        $payload = [
            'reference_no'          => 'EXP_SPLIT_001',
            'warehouse_name'        => 'Main Branch Outlet',
            'expense_category_name' => 'Store Utilities',
            'title'                 => 'Electricity & Generator Fuel',
            'amount'                => 100000.0,
            'date'                  => '2026-09-30 10:00:00',
            'details'               => 'Monthly utilities payment',
            'payments'              => [
                [
                    'amount'       => 60000.0,
                    'payment_type' => 'Cash',
                    'notes'        => 'Cash from register',
                ],
                [
                    'amount'       => 40000.0,
                    'payment_type' => 'KBZPay',
                    'notes'        => 'Digital wallet settlement',
                ],
            ],
        ];

        $response = $this->postJson('/api/expenses', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference_no', 'EXP_SPLIT_001')
            ->assertJsonPath('data.amount', 100000)
            ->assertJsonPath('data.paid_amount', 100000)
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.payment_status', 'Paid')
            ->assertJsonPath('data.payment_method', 'Split Payment');

        $this->assertCount(2, ExpensePayment::where('expense_id', $response->json('data.id'))->get());
    }

    public function test_create_partial_expense_and_add_split_payment(): void
    {
        // 1. Create partial expense (100,000 total, 40,000 paid)
        $payload = [
            'reference_no'          => 'EXP_PARTIAL_002',
            'warehouse_name'        => 'Main Branch Outlet',
            'expense_category_name' => 'Store Supplies',
            'title'                 => 'Packaging Boxes',
            'amount'                => 100000.0,
            'date'                  => '2026-09-30 11:00:00',
            'payments'              => [
                [
                    'amount'       => 40000.0,
                    'payment_type' => 'Cash',
                ],
            ],
        ];

        $res = $this->postJson('/api/expenses', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('data.paid_amount', 40000)
            ->assertJsonPath('data.due_amount', 60000)
            ->assertJsonPath('data.payment_status', 'Partial');

        $expenseId = $res->json('data.id');

        // 2. Add payment with split tenders where amount is 0
        $addRes = $this->postJson("/api/expenses/{$expenseId}/payments", [
            'amount'       => 0,
            'payment_type' => 'Split Payment',
            'payments'     => [
                [
                    'amount'       => 30000.0,
                    'payment_type' => 'KBZPay',
                ],
                [
                    'amount'       => 30000.0,
                    'payment_type' => 'WavePay',
                ],
            ],
        ]);

        $addRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.paid_amount', 100000)
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.payment_status', 'Paid');

        $this->assertEquals(3, ExpensePayment::where('expense_id', $expenseId)->count());
    }

    public function test_expense_update_dynamically_recalculates_due_and_status(): void
    {
        $expense = Expense::create([
            'reference_no'          => 'EXP_DYN_003',
            'warehouse_name'        => 'Main Branch Outlet',
            'expense_category_name' => 'Office Maintenance',
            'title'                 => 'Air Con Repair',
            'amount'                => 50000.0,
            'paid_amount'           => 50000.0,
            'due_amount'            => 0.0,
            'payment_status'        => 'Paid',
            'payment_method'        => 'Cash',
            'date'                  => '2026-09-30 12:00:00',
        ]);

        ExpensePayment::create([
            'expense_id'   => $expense->id,
            'reference_no' => 'PAY-EXP_DYN_003-1',
            'amount'       => 50000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-09-30 12:00:00',
        ]);

        // Increase expense amount from 50,000 to 80,000
        $updateRes = $this->putJson("/api/expenses/{$expense->id}", [
            'amount'                => 80000.0,
            'warehouse_name'        => 'Main Branch Outlet',
            'expense_category_name' => 'Office Maintenance',
            'title'                 => 'Air Con Repair and Parts Replacement',
            'date'                  => '2026-09-30 12:00:00',
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.amount', 80000)
            ->assertJsonPath('data.paid_amount', 50000)
            ->assertJsonPath('data.due_amount', 30000)
            ->assertJsonPath('data.payment_status', 'Partial');
    }

    public function test_cash_vs_digital_breakdown_aggregates_from_payments(): void
    {
        // Expense 1: 50,000 Cash
        $exp1 = Expense::create([
            'reference_no'          => 'EXP_AGG_001',
            'warehouse_name'        => 'Main Branch',
            'expense_category_name' => 'Fuel',
            'title'                 => 'Van Fuel',
            'amount'                => 50000.0,
            'paid_amount'           => 50000.0,
            'due_amount'            => 0.0,
            'payment_status'        => 'Paid',
            'payment_method'        => 'Cash',
            'date'                  => '2026-09-30',
        ]);
        ExpensePayment::create([
            'expense_id'   => $exp1->id,
            'reference_no' => 'PAY-001',
            'amount'       => 50000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-09-30',
        ]);

        // Expense 2: Split 20,000 Cash + 80,000 KBZPay
        $exp2 = Expense::create([
            'reference_no'          => 'EXP_AGG_002',
            'warehouse_name'        => 'Main Branch',
            'expense_category_name' => 'Hardware',
            'title'                 => 'Server Upgrades',
            'amount'                => 100000.0,
            'paid_amount'           => 100000.0,
            'due_amount'            => 0.0,
            'payment_status'        => 'Paid',
            'payment_method'        => 'Split Payment',
            'date'                  => '2026-09-30',
        ]);
        ExpensePayment::create([
            'expense_id'   => $exp2->id,
            'reference_no' => 'PAY-002',
            'amount'       => 20000.0,
            'payment_type' => 'Cash',
            'payment_date' => '2026-09-30',
        ]);
        ExpensePayment::create([
            'expense_id'   => $exp2->id,
            'reference_no' => 'PAY-003',
            'amount'       => 80000.0,
            'payment_type' => 'KBZPay',
            'payment_date' => '2026-09-30',
        ]);

        $res = $this->getJson('/api/expenses');
        $res->assertStatus(200);

        // Cash total should be 50,000 + 20,000 = 70,000
        $this->assertEquals(70000.0, $res->json('summary.cash_total'));
        // Digital total should be 80,000
        $this->assertEquals(80000.0, $res->json('summary.digital_total'));
    }
}
