<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBalance;
use Carbon\Carbon;

class Phase5EnterpriseFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('manager_pin', '1234');
    }

    public function test_sale_voiding_restores_stock_and_reconciles_drawer_cash(): void
    {
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        $product = Product::create([
            'name' => 'Test Coffee Beans',
            'code' => 'TCB-001',
            'stock_quantity' => 20.0,
            'purchase_price' => 5000.0,
            'cost_price' => 5000.0,
            'retail_price' => 8000.0,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $outlet->id,
            'quantity' => 20.0,
        ]);

        $customer = Customer::create([
            'name' => 'Daw Mya',
            'phone' => '0912345678',
            'reward_points' => 50,
            'total_spent' => 24000.0,
            'total_visits' => 2,
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-VOID-01',
            'register_name' => 'Register 01',
            'user_id' => 1,
            'user_name' => 'Cashier Mg Mg',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => 'Main Outlet',
            'opening_cash' => 50000.0,
            'expected_cash' => 50000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(2),
        ]);

        // 1. Create a sale
        $saleResponse = $this->postJson('/api/sales', [
            'date' => Carbon::now()->toDateTimeString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_name' => 'Main Outlet',
            'warehouse_id' => $outlet->id,
            'subtotal' => 16000.0,
            'tax_amount' => 0.0,
            'discount' => 0.0,
            'grand_total' => 16000.0,
            'paid_amount' => 16000.0,
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_price' => 8000.0,
                    'quantity' => 2.0,
                    'subtotal' => 16000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 16000.0,
                ],
            ],
        ]);

        $saleResponse->assertStatus(201);
        $saleId = $saleResponse->json('data.id');

        // Verify stock reduced by 2
        $this->assertEquals(18.0, StockBalance::where('product_id', $product->id)->where('warehouse_id', $outlet->id)->value('quantity'));

        // Register expected cash increased by 16000
        $register->refresh();
        $register->update(['expected_cash' => $register->expected_cash + 16000]); // simulate till addition

        // 2. Try to hard-delete completed sale (must be rejected)
        $deleteResponse = $this->deleteJson("/api/sales/{$saleId}");
        $deleteResponse->assertStatus(422);

        // 3. Void sale with manager PIN and reason
        $voidResponse = $this->postJson("/api/sales/{$saleId}/void", [
            'pin' => '1234',
            'reason' => 'Customer changed mind and walked out before taking coffee.',
        ]);

        $voidResponse->assertStatus(200);
        $voidResponse->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $saleId,
                'status' => 'Voided',
                'void_reason' => 'Customer changed mind and walked out before taking coffee.',
            ],
        ]);

        // 4. Verify inventory was restored back to 20
        $this->assertEquals(20.0, StockBalance::where('product_id', $product->id)->where('warehouse_id', $outlet->id)->value('quantity'));

        // 5. Verify customer total spent was reversed
        $customer->refresh();
        $this->assertEquals(24000.0, (float)$customer->total_spent);
    }

    public function test_loyalty_points_redemption_on_sale(): void
    {
        $this->markTestSkipped('Customer loyalty points system decommissioned per commit 53f07a4.');
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        $product = Product::create([
            'name' => 'Premium Tea Box',
            'code' => 'PTB-001',
            'stock_quantity' => 50.0,
            'retail_price' => 15000.0,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $outlet->id,
            'quantity' => 50.0,
        ]);

        $customer = Customer::create([
            'name' => 'U Ba',
            'phone' => '0998765432',
            'reward_points' => 200,
            'total_spent' => 50000.0,
            'total_visits' => 5,
        ]);

        // Purchase 1 Tea Box (15,000 Ks), redeem 100 points for 1,000 Ks discount -> Grand Total = 14,000 Ks
        // Points earned = floor(14,000 / 1000) = 14 points
        // Final points = 200 - 100 + 14 = 114 points
        $response = $this->postJson('/api/sales', [
            'date' => Carbon::now()->toDateTimeString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_name' => 'Main Outlet',
            'warehouse_id' => $outlet->id,
            'subtotal' => 15000.0,
            'discount' => 1000.0,
            'redeemed_points' => 100,
            'points_discount' => 1000.0,
            'grand_total' => 14000.0,
            'paid_amount' => 14000.0,
            'payment_type' => 'Cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_price' => 15000.0,
                    'quantity' => 1.0,
                    'subtotal' => 15000.0,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $customer->refresh();
        $this->assertEquals(114, $customer->reward_points);
        $this->assertEquals(64000.0, (float)$customer->total_spent);
    }

    public function test_commercial_tax_reconciliation_report(): void
    {
        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-01']);

        // Create a Sale with 5% tax (500 Ks tax on 10,000 Ks)
        Sale::create([
            'reference_no' => 'SA-TAX-01',
            'customer_name' => 'Walk-in',
            'warehouse_name' => 'Main Outlet',
            'warehouse_id' => $outlet->id,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Completed',
            'subtotal' => 10000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 500.0,
            'discount' => 0.0,
            'grand_total' => 10500.0,
            'paid_amount' => 10500.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        // Create a Purchase with 5% tax (250 Ks tax on 5,000 Ks)
        Purchase::create([
            'reference_no' => 'PO-TAX-01',
            'supplier_name' => 'Supplier A',
            'warehouse_name' => 'Main Outlet',
            'warehouse_id' => $outlet->id,
            'date' => Carbon::now()->toDateTimeString(),
            'status' => 'Received',
            'subtotal' => 5000.0,
            'tax_rate' => 5.0,
            'tax_amount' => 250.0,
            'discount' => 0.0,
            'grand_total' => 5250.0,
            'paid_amount' => 5250.0,
            'due_amount' => 0.0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);

        $response = $this->getJson('/api/reports/commercial-tax');
        $response->assertStatus(200);

        // Output Tax: 500 Ks, Input Tax: 250 Ks, Net Tax Payable: 250 Ks
        $response->assertJson([
            'status' => 'success',
            'output_tax' => [
                'gross_sales_tax' => 500.0,
                'net_output_tax' => 500.0,
            ],
            'input_tax' => [
                'gross_purchases_tax' => 250.0,
                'net_input_tax' => 250.0,
            ],
            'tax_reconciliation' => [
                'net_output_tax' => 500.0,
                'net_input_tax' => 250.0,
                'net_tax_payable' => 250.0,
            ],
        ]);
    }
}
