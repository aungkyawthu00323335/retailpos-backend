<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\CashRegister;
use App\Models\Outlet;
use Carbon\Carbon;

class CashRegisterDenominationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_close_cash_register_with_denomination_breakdown(): void
    {
        $outlet = Outlet::create([
            'name' => 'Main Outlet',
            'code' => 'OUT-01',
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-DENOM-001',
            'register_name' => 'Register 01',
            'user_id' => 1,
            'user_name' => 'Cashier Aye',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Outlet',
            'opening_cash' => 100000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(8),
        ]);

        $denominations = [
            '10000' => 25, // 250,000
            '5000' => 10,  // 50,000
            '1000' => 20,  // 20,000
            '500' => 10,   // 5,000
        ];
        $totalCash = (25 * 10000) + (10 * 5000) + (20 * 1000) + (10 * 500); // 325,000

        $response = $this->postJson("/api/cash-register/close/{$register->id}", [
            'closing_cash' => $totalCash,
            'closing_notes' => 'Shift closed with accurate cash denomination tally.',
            'denominations' => $denominations,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'register' => [
                'id' => $register->id,
                'status' => 'Closed',
                'closing_cash' => $totalCash,
                'denominations' => $denominations,
            ],
        ]);

        $this->assertDatabaseHas('cash_registers', [
            'id' => $register->id,
            'status' => 'closed',
            'closing_cash' => $totalCash,
        ]);

        $fresh = $register->fresh();
        $this->assertEquals($denominations, $fresh->denominations);

        // Also verify details API returns the denominations
        $detailsResponse = $this->getJson("/api/cash-register/details/{$register->id}");
        $detailsResponse->assertStatus(200);
        $detailsResponse->assertJsonPath('register.denominations', $denominations);
    }
}
