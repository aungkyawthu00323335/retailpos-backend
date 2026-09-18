<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockTransfer;

class Phase6SupplyChainAndCashDropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('manager_pin', '1234');
    }

    public function test_cash_register_safe_drop_with_valid_pin_reduces_expected_cash(): void
    {
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        $openResponse = $this->postJson('/api/cash-register/open', [
            'opening_cash' => 100000.0,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'register_name' => 'Register 01',
            'user_name' => 'Cashier Mg Mg',
        ]);
        $openResponse->assertStatus(200);

        $register = CashRegister::where('status', 'open')->first();
        $this->assertNotNull($register);

        // Perform Safe Drop of 40,000 Ks
        $dropResponse = $this->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $register->id,
            'type' => 'drop',
            'amount' => 40000.0,
            'reason' => 'Mid-day drop to store safe',
            'manager_pin' => '1234',
        ]);
        $dropResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertEquals(60000.0, (float)$dropResponse->json('register.expected_cash'));
        $this->assertEquals(40000.0, (float)$dropResponse->json('register.cash_drops'));

        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->id,
            'type' => 'drop',
            'amount' => 40000.0,
            'manager_pin_verified' => true,
        ]);

        // Close register with 60,000 Ks -> variance should be exactly 0
        $closeResponse = $this->postJson("/api/cash-register/close/{$register->id}", [
            'closing_cash' => 60000.0,
        ]);
        $closeResponse->assertStatus(200);
        $this->assertEquals(0.0, (float)$closeResponse->json('register.difference'));
    }

    public function test_cash_register_float_in_with_valid_pin_increases_expected_cash(): void
    {
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        $this->postJson('/api/cash-register/open', [
            'opening_cash' => 50000.0,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'register_name' => 'Register 01',
            'user_name' => 'Cashier Ko Ko',
        ])->assertStatus(200);

        $register = CashRegister::where('status', 'open')->first();

        // Perform Float In of 25,000 Ks
        $floatResponse = $this->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $register->id,
            'type' => 'float_in',
            'amount' => 25000.0,
            'reason' => 'Banknote change replenishment',
            'manager_pin' => '1234',
        ]);
        $floatResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertEquals(75000.0, (float)$floatResponse->json('register.expected_cash'));
        $this->assertEquals(25000.0, (float)$floatResponse->json('register.floats_in'));
    }

    public function test_cash_register_safe_drop_fails_with_invalid_pin_or_excess_amount(): void
    {
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        $this->postJson('/api/cash-register/open', [
            'opening_cash' => 30000.0,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'register_name' => 'Register 01',
            'user_name' => 'Cashier Su Su',
        ])->assertStatus(200);

        $register = CashRegister::where('status', 'open')->first();

        // 1. Wrong PIN -> 403 Forbidden
        $this->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $register->id,
            'type' => 'drop',
            'amount' => 10000.0,
            'reason' => 'Test invalid pin',
            'manager_pin' => '0000',
        ])->assertStatus(403);

        // 2. Drop amount exceeding current till cash -> 422 Unprocessable
        $this->postJson('/api/cash-register/transaction', [
            'cash_register_id' => $register->id,
            'type' => 'drop',
            'amount' => 50000.0,
            'reason' => 'Test excess drop',
            'manager_pin' => '1234',
        ])->assertStatus(422);
    }

    public function test_two_step_inter_branch_transfer_dispatch_and_in_transit_receipt(): void
    {
        $yangon = Outlet::create(['name' => 'Yangon Central Warehouse', 'code' => 'WH-YGN']);
        $mandalay = Outlet::create(['name' => 'Mandalay Branch Outlet', 'code' => 'OUT-MDY']);

        $product = Product::create([
            'name' => 'Enterprise Barcode Scanner',
            'code' => 'SCN-990',
            'stock_quantity' => 50.0,
            'purchase_price' => 45000.0,
            'cost_price' => 45000.0,
            'retail_price' => 65000.0,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $yangon->id,
            'quantity' => 50.0,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $mandalay->id,
            'quantity' => 0.0,
        ]);

        // Step 1: Dispatch transfer as 'Sent' (In-Transit)
        $dispatchResponse = $this->postJson('/api/transfers', [
            'reference_no' => 'TRF-YGN-MDY-001',
            'from_warehouse_id' => $yangon->id,
            'from_warehouse' => $yangon->name,
            'to_warehouse_id' => $mandalay->id,
            'to_warehouse' => $mandalay->name,
            'date' => now()->toDateString(),
            'status' => 'Sent',
            'shipping_charge' => 5000.0,
            'notes' => 'Highway transport truck #7',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 20.0,
                    'unit_cost' => 45000.0,
                ],
            ],
        ]);
        $dispatchResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'Sent');

        $transferId = $dispatchResponse->json('data.id');

        // Verify Source stock (Yangon) is DEDUCTED immediately (50 - 20 = 30)
        $yangonStock = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $yangon->id)
            ->value('quantity');
        $this->assertEquals(30.0, (float)$yangonStock);

        // Verify Destination stock (Mandalay) is STILL 0 (goods are on transport truck)
        $mandalayStockBefore = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $mandalay->id)
            ->value('quantity');
        $this->assertEquals(0.0, (float)$mandalayStockBefore);

        // Step 2: Goods arrive at Mandalay branch -> Branch Manager receives transfer
        $receiveResponse = $this->postJson("/api/transfers/{$transferId}/receive", [
            'received_by' => 'U Hla (Mandalay Manager)',
            'receiving_notes' => 'All 20 scanner boxes inspected intact',
        ]);
        $receiveResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'Completed')
            ->assertJsonPath('data.received_by', 'U Hla (Mandalay Manager)');

        // Verify Destination stock (Mandalay) is NOW replenished (0 + 20 = 20)
        $mandalayStockAfter = StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $mandalay->id)
            ->value('quantity');
        $this->assertEquals(20.0, (float)$mandalayStockAfter);

        // Verify destination FIFO batch created
        $this->assertDatabaseHas('inventory_batches', [
            'warehouse_id' => $mandalay->id,
            'product_id' => $product->id,
            'remaining_quantity' => 20.0,
        ]);
    }
}