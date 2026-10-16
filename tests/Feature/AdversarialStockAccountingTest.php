<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleItemCostLayer;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashRegister;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\TelegramBotService;
use App\Services\ProductUnitConversionService;
use App\Services\FifoInventoryService;

/**
 * Adversarial Verification Test Suite for Stock Reservation & Double-Entry Accounting
 *
 * Covers:
 * - Challenge 1: Out-of-stock rejection & race condition simulation (never negative stock)
 * - Challenge 2: Double-deduction prevention & FIFO cost layer stamping on approval
 * - Challenge 3: Rejection rollback (immediate restoration, idempotency, cannot reject approved)
 * - Challenge 4: Expiration cleanup command (releases expired, leaves active/approved alone, dynamic timeout)
 * - Challenge 5: Double-entry Output Tax/VAT balance & Grand Total equation integrity
 * - Challenge 6: Cashier active shift ledger linkage & preliminary payment voiding
 * - Challenge 7: Fallback when reservation disabled & multi-item integrity
 */
class AdversarialStockAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramBotService $botService;
    protected ProductUnitConversionService $unitService;
    protected FifoInventoryService $fifoService;
    protected Outlet $outlet;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->botService = app(TelegramBotService::class);
        $this->unitService = app(ProductUnitConversionService::class);
        $this->fifoService = app(FifoInventoryService::class);

        $this->outlet = Outlet::create([
            'name' => 'Adversarial Test Outlet',
            'address' => 'Central Yangon',
            'phone' => '0999990001',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
        Setting::set('telegram_bot_token', '999999:TEST_BOT_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_enable_stock_reservation', '1');
        Setting::set('telegram_bot_stock_reservation_timeout', '60');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '0');
        Setting::set('telegram_bot_enable_tax', '0');

        $this->product = Product::create([
            'name' => 'Adversarial Test Widget',
            'code' => 'WIDGET-ADV-001',
            'sku' => 'SKU-ADV-001',
            'retail_price' => 5000,
            'selling_price' => 5000,
            'cost_price' => 3000,
            'purchase_price' => 3000,
            'unit_name' => 'Piece',
            'stock_quantity' => 10,
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 10.0000,
            'reserved_quantity' => 0.0000,
        ]);
    }

    /**
     * Challenge 1: Concurrent checkout race condition simulation
     * Available stock = 5.
     * Order 1 requests 4 units -> succeeds (available = 1, reserved = 4).
     * Order 2 requests 3 units -> rejected due to insufficient stock (only 1 remaining).
     * Assert stock NEVER goes negative.
     * Order 3 requests 1 unit -> succeeds (available = 0, reserved = 5).
     * Order 4 requests 1 unit -> rejected (available = 0).
     */
    public function test_challenge_1_concurrent_checkout_race_condition_and_negative_stock_prevention(): void
    {
        // 1. Set initial stock to exactly 5 units
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 5.0000, 'reserved_quantity' => 0.0000]);

        $this->product->update(['stock_quantity' => 5.0000]);

        // Order 1: Requests 4 units
        $chatId1 = 1001;
        $session1 = [
            'is_cart' => true,
            'phone' => '09111110001',
            'address' => 'Customer 1 Address',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 4,
                    'price' => 5000.0,
                    'subtotal' => 20000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId1, $session1, 'Customer 1', 'cust_1');

        $sale1 = Sale::where('customer_phone', '09111110001')->first();
        $this->assertNotNull($sale1, 'Order 1 must be created');
        $this->assertTrue((bool)$sale1->stock_reserved);

        $balance1 = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(1.0000, (float)$balance1->quantity, 'Available stock must be 1.0 after reserving 4');
        $this->assertEquals(4.0000, (float)$balance1->reserved_quantity, 'Reserved stock must be 4.0');

        // Order 2: Requests 3 units (Race condition: only 1 unit is available)
        $chatId2 = 1002;
        $session2 = [
            'is_cart' => true,
            'phone' => '09111110002',
            'address' => 'Customer 2 Address',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 3, // Requires 3, only 1 available!
                    'price' => 5000.0,
                    'subtotal' => 15000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId2, $session2, 'Customer 2', 'cust_2');

        $sale2 = Sale::where('customer_phone', '09111110002')->first();
        $this->assertNull($sale2, 'Order 2 must be REJECTED and no sale record created');

        $balance2 = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(1.0000, (float)$balance2->quantity, 'Available stock must remain exactly 1.0 (never negative)');
        $this->assertEquals(4.0000, (float)$balance2->reserved_quantity, 'Reserved stock must remain 4.0');

        // Order 3: Requests exactly remaining 1 unit -> must succeed
        $chatId3 = 1003;
        $session3 = [
            'is_cart' => true,
            'phone' => '09111110003',
            'address' => 'Customer 3 Address',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 1,
                    'price' => 5000.0,
                    'subtotal' => 5000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId3, $session3, 'Customer 3', 'cust_3');

        $sale3 = Sale::where('customer_phone', '09111110003')->first();
        $this->assertNotNull($sale3, 'Order 3 must succeed for exact remaining 1 unit');

        $balance3 = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(0.0000, (float)$balance3->quantity, 'Available stock must drop to exactly 0.0');
        $this->assertEquals(5.0000, (float)$balance3->reserved_quantity, 'Total reserved must be 5.0 (4+1)');

        // Order 4: Requests 1 unit when available is 0 -> must be rejected
        $chatId4 = 1004;
        $session4 = [
            'is_cart' => true,
            'phone' => '09111110004',
            'address' => 'Customer 4 Address',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 1,
                    'price' => 5000.0,
                    'subtotal' => 5000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId4, $session4, 'Customer 4', 'cust_4');
        $this->assertNull(Sale::where('customer_phone', '09111110004')->first());

        $balanceFinal = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertGreaterThanOrEqual(0.0, (float)$balanceFinal->quantity, 'Available stock must NEVER be negative');
        $this->assertEquals(0.0000, (float)$balanceFinal->quantity);
        $this->assertEquals(5.0000, (float)$balanceFinal->reserved_quantity);
    }

    /**
     * Challenge 2: Double-deduction prevention & FIFO cost layer stamping
     * Initial stock = 10.
     * Order reserves 3 units (available becomes 7, reserved becomes 3).
     * Staff approves order.
     * Assert:
     * - Available remains 7 (NOT 4! NO double-deduction!).
     * - Reserved becomes 0.
     * - FIFO cost layer is stamped (COGS calculated, cost layers recorded).
     * - StockMovement records 'bot_reservation_fulfill'.
     */
    public function test_challenge_2_double_deduction_prevention_and_fifo_costing_on_approval(): void
    {
        $staffUser = User::factory()->create(['name' => 'Approving Manager', 'role' => 'Super Admin']);

        // 1. Establish initial stock with explicit FIFO inventory batch (10 units @ 3000 MMK)
        $batch = $this->fifoService->recordBatch(
            warehouseId: $this->outlet->id,
            productId: $this->product->id,
            variantId: null,
            baseQuantity: 10.0,
            baseUnitCost: 3000.0,
            sourceType: 'purchase',
            sourceReference: 'ADV-PO-001',
            receivedDate: now()->subDays(5)->format('Y-m-d H:i:s'),
            notes: 'Initial FIFO batch for double deduction test'
        );
        $this->assertNotNull($batch);

        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 10.0000, 'reserved_quantity' => 0.0000]);
        $this->product->update(['stock_quantity' => 10.0000]);

        // 2. Customer places bot order for 3 units
        $chatId = 2001;
        $session = [
            'is_cart' => true,
            'phone' => '09222220001',
            'address' => 'Customer Double-Deduction Test',
            'payment_type' => 'KBZPay',
            'payment_status' => 'Paid',
            'shipping_fee' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 3,
                    'price' => 5000.0,
                    'subtotal' => 15000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Double Deduct Test Customer', 'dd_cust');

        $sale = Sale::with('items')->where('customer_phone', '09222220001')->first();
        $this->assertNotNull($sale);
        $this->assertTrue((bool)$sale->stock_reserved);

        // Verification after checkout: available is 7, reserved is 3
        $balancePre = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(7.0000, (float)$balancePre->quantity);
        $this->assertEquals(3.0000, (float)$balancePre->reserved_quantity);

        // 3. Staff approves order
        $response = $this->actingAs($staffUser)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => true,
            'payment_type' => 'KBZPay',
        ]);
        $response->assertStatus(200);

        // 4. Assert Available stock MUST remain 7 (NOT 4!), reserved becomes 0
        $balancePost = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(7.0000, (float)$balancePost->quantity, 'CRITICAL: Available stock must remain 7.0 (NO double deduction)');
        $this->assertEquals(0.0000, (float)$balancePost->reserved_quantity, 'Reserved quantity must be cleared to 0.0');

        $sale->refresh();
        $this->assertEquals('approved', $sale->bot_approval_status);
        $this->assertEquals('Completed', $sale->status);
        $this->assertFalse((bool)$sale->stock_reserved);

        // 5. Assert FIFO cost layer is stamped
        $saleItem = $sale->items->first();
        $this->assertNotNull($saleItem);
        $this->assertEquals(3000.0, (float)$saleItem->base_unit_cost, 'FIFO unit cost must be stamped');
        $this->assertEquals(9000.0, (float)$saleItem->total_cogs, 'Total COGS must be 3 units * 3000 = 9000');
        $this->assertEquals(9000.0, (float)$sale->total_cogs, 'Sale level COGS must be 9000');

        $costLayer = SaleItemCostLayer::where('sale_item_id', $saleItem->id)->first();
        $this->assertNotNull($costLayer, 'SaleItemCostLayer record must exist');
        $this->assertEquals(3.0, (float)$costLayer->base_quantity);
        $this->assertEquals(3000.0, (float)$costLayer->unit_cost);
        $this->assertEquals(9000.0, (float)$costLayer->total_cost);

        // 6. Assert StockMovement audit trail has 'bot_reservation_fulfill'
        $fulfillMovement = StockMovement::where('movement_type', 'bot_reservation_fulfill')
            ->where('reference_id', (string)$sale->id)
            ->first();
        $this->assertNotNull($fulfillMovement, 'Fulfill stock movement must be recorded');
        $this->assertEquals(0.0, (float)$fulfillMovement->quantity_in);
        $this->assertEquals(0.0, (float)$fulfillMovement->quantity_out, 'Fulfill movement must not deduct quantity again');
        $this->assertEquals(7.0, (float)$fulfillMovement->balance_after);
    }

    /**
     * Challenge 3: Rejection rollback
     * Initial stock = 10.
     * Order reserves 4 units (available becomes 6).
     * Staff rejects order.
     * Assert available stock is restored to 10 immediately, reserved becomes 0.
     */
    public function test_challenge_3_rejection_rollback_restores_stock_immediately(): void
    {
        $staffUser = User::factory()->create(['name' => 'Reject Auditor', 'role' => 'Super Admin']);

        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 10.0000, 'reserved_quantity' => 0.0000]);

        // Place bot order reserving 4 units
        $chatId = 3001;
        $session = [
            'is_cart' => true,
            'phone' => '09333330001',
            'address' => 'Customer Rejection Test',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 4,
                    'price' => 5000.0,
                    'subtotal' => 20000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Rejection Customer', 'rej_cust');

        $sale = Sale::where('customer_phone', '09333330001')->first();
        $this->assertNotNull($sale);
        $this->assertTrue((bool)$sale->stock_reserved);

        // Pre-rejection balance: 6 available, 4 reserved
        $balPre = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(6.0000, (float)$balPre->quantity);
        $this->assertEquals(4.0000, (float)$balPre->reserved_quantity);

        // Staff rejects order
        $res = $this->actingAs($staffUser)->postJson("/api/bot/orders/{$sale->id}/reject", [
            'reason' => 'Delivery location outside service area',
        ]);
        $res->assertStatus(200);

        // Post-rejection balance: restored to 10.0 available, 0.0 reserved
        $balPost = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(10.0000, (float)$balPost->quantity, 'Available stock must be restored to 10.0 immediately');
        $this->assertEquals(0.0000, (float)$balPost->reserved_quantity, 'Reserved quantity must drop to 0.0');

        $sale->refresh();
        $this->assertEquals('rejected', $sale->bot_approval_status);
        $this->assertEquals('Cancelled', $sale->status);
        $this->assertFalse((bool)$sale->stock_reserved);
        $this->assertNotNull($sale->stock_released_at);
        $this->assertEquals(0.0, (float)$sale->due_amount);
        $this->assertEquals('Delivery location outside service area', $sale->bot_rejection_reason);

        // Stock movement check
        $movement = StockMovement::where('movement_type', 'bot_reservation_rollback')
            ->where('reference_id', (string)$sale->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(4.0, (float)$movement->quantity_in);
        $this->assertEquals(10.0, (float)$movement->balance_after);
    }

    /**
     * Challenge 3B: Rejection edge cases:
     * - Cannot reject an order that is already approved (must return 422).
     * - Multiple calls to rollback are idempotent (no double restoration).
     */
    public function test_challenge_3b_cannot_reject_approved_order_and_idempotent_rollback(): void
    {
        $staffUser = User::factory()->create(['name' => 'Staff Tester', 'role' => 'Super Admin']);

        // 1. Create already-approved sale
        $saleApproved = Sale::create([
            'reference_no' => 'ADV-APPROVED-001',
            'customer_name' => 'Approved Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Completed',
            'bot_approval_status' => 'approved',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'due_amount' => 0,
            'paid_amount' => 10000,
            'payment_status' => 'Paid',
            'stock_reserved' => false,
        ]);

        $res = $this->actingAs($staffUser)->postJson("/api/bot/orders/{$saleApproved->id}/reject", [
            'reason' => 'Attempting to reject approved order',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('Cannot reject', $res->json('message'));

        // 2. Idempotent rollback check
        $saleCancelled = Sale::create([
            'reference_no' => 'ADV-CANCELLED-001',
            'customer_name' => 'Already Cancelled',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Cancelled',
            'bot_approval_status' => 'rejected',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'due_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => false, // Already released
        ]);

        SaleItem::create([
            'sale_id' => $saleCancelled->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
        ]);

        $balBefore = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first()->quantity;

        // Directly call rollbackStockReservation
        $result = $this->botService->rollbackStockReservation($saleCancelled, 'Double rollback attempt');
        $this->assertFalse($result, 'Rollback on non-reserved sale must return false');

        $balAfter = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first()->quantity;
        $this->assertEquals((float)$balBefore, (float)$balAfter, 'Stock must NOT change on redundant rollback');
    }

    /**
     * Challenge 4: Expiration cleanup command (bot:release-expired-reservations)
     * - Releases expired orders (stock_reserved = true and stock_reserved_at <= threshold)
     * - Leaves active orders (stock_reserved_at within threshold) untouched
     * - Ignores approved or already cancelled orders
     */
    public function test_challenge_4_expiration_cleanup_command_audits_and_restores_expired_reservations(): void
    {
        // Setup initial balance: 10 available, 0 reserved
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 5.0000, 'reserved_quantity' => 5.0000]);

        // Order A: Expired (reserved 3 units, 90 mins ago, timeout is 60 mins)
        $expiredSale = Sale::create([
            'reference_no' => 'ADV-EXP-001',
            'customer_name' => 'Expired Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now()->subMinutes(90),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 15000,
            'grand_total' => 15000,
            'due_amount' => 15000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now()->subMinutes(90),
        ]);
        SaleItem::create([
            'sale_id' => $expiredSale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 3,
            'base_quantity' => 3,
            'unit_price' => 5000,
            'subtotal' => 15000,
        ]);

        // Order B: Active (reserved 2 units, 10 mins ago, timeout is 60 mins)
        $activeSale = Sale::create([
            'reference_no' => 'ADV-ACT-002',
            'customer_name' => 'Active Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now()->subMinutes(10),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'due_amount' => 10000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now()->subMinutes(10),
        ]);
        SaleItem::create([
            'sale_id' => $activeSale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
        ]);

        // Order C: Approved order 90 mins ago (must NOT be touched)
        $approvedOldSale = Sale::create([
            'reference_no' => 'ADV-APP-003',
            'customer_name' => 'Old Approved Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now()->subMinutes(90),
            'status' => 'Completed',
            'bot_approval_status' => 'approved',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'due_amount' => 0,
            'paid_amount' => 10000,
            'payment_status' => 'Paid',
            'stock_reserved' => false,
        ]);

        Setting::set('telegram_bot_stock_reservation_timeout', '60');
        Setting::set('telegram_bot_enable_stock_reservation', '1');

        $exitCode = Artisan::call('bot:release-expired-reservations');
        $this->assertEquals(0, $exitCode);

        // Verify Expired Order A was released and marked Cancelled/rejected
        $expiredSale->refresh();
        $this->assertEquals('rejected', $expiredSale->bot_approval_status);
        $this->assertEquals('Cancelled', $expiredSale->status);
        $this->assertFalse((bool)$expiredSale->stock_reserved);
        $this->assertNotNull($expiredSale->stock_released_at);
        $this->assertEquals(0.0, (float)$expiredSale->due_amount);

        // Verify Active Order B was NOT touched
        $activeSale->refresh();
        $this->assertEquals('pending', $activeSale->bot_approval_status);
        $this->assertEquals('Pending', $activeSale->status);
        $this->assertTrue((bool)$activeSale->stock_reserved);
        $this->assertNull($activeSale->stock_released_at);

        // Verify Approved Order C was NOT touched
        $approvedOldSale->refresh();
        $this->assertEquals('approved', $approvedOldSale->bot_approval_status);
        $this->assertEquals('Completed', $approvedOldSale->status);

        // Verify stock balance: 5 available + 3 released = 8 available; 5 reserved - 3 released = 2 reserved (Order B)
        $bal = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(8.0000, (float)$bal->quantity);
        $this->assertEquals(2.0000, (float)$bal->reserved_quantity);
    }

    /**
     * Challenge 4B: Dynamic expiration timeout and disabled setting
     */
    public function test_challenge_4b_expiration_dynamic_timeout_and_disabled_setting(): void
    {
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 8.0000, 'reserved_quantity' => 2.0000]);

        $shortSale = Sale::create([
            'reference_no' => 'ADV-SHORT-001',
            'customer_name' => 'Short Timeout Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now()->subMinutes(20),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'due_amount' => 10000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now()->subMinutes(20),
        ]);
        SaleItem::create([
            'sale_id' => $shortSale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
        ]);

        // When reservation is disabled, command should skip without releasing
        Setting::set('telegram_bot_enable_stock_reservation', '0');
        Artisan::call('bot:release-expired-reservations');
        $shortSale->refresh();
        $this->assertTrue((bool)$shortSale->stock_reserved, 'Should skip when reservation disabled');

        // Now enable and set dynamic timeout to 15 mins (order age 20 > 15)
        Setting::set('telegram_bot_enable_stock_reservation', '1');
        Setting::set('telegram_bot_stock_reservation_timeout', '15');
        Artisan::call('bot:release-expired-reservations');

        $shortSale->refresh();
        $this->assertFalse((bool)$shortSale->stock_reserved, 'Should release with 15 min dynamic timeout');
        $this->assertEquals('rejected', $shortSale->bot_approval_status);

        $bal = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(10.0000, (float)$bal->quantity);
        $this->assertEquals(0.0000, (float)$bal->reserved_quantity);
    }

    /**
     * Challenge 5: Double-Entry Output Tax/VAT Balance & Grand Total Equation
     * - Subtotal: 20,000 MMK (4 units @ 5,000)
     * - Tax Rate: 5% -> Tax Amount = 1,000 MMK
     * - Shipping: 2,500 MMK
     * - Discount: 1,500 MMK
     * Equation: grand_total == subtotal + tax_amount + shipping - discount = 22,000 MMK
     * Item tax: sum(items.tax) == sale.tax_amount
     * Accounts Receivable isolation: pending bot order not in AR; once approved unpaid, recognized in AR.
     */
    public function test_challenge_5_double_entry_output_tax_grand_total_equation(): void
    {
        Setting::set('telegram_bot_enable_tax', '1');
        Setting::set('telegram_bot_tax_rate', '5.0');

        $customer = Customer::create([
            'customer_id' => 'CUST-TAX-001',
            'name' => 'Tax Verification Customer',
            'phone' => '09555550001',
            'status' => 'Active',
        ]);

        $chatId = 5001;
        $session = [
            'is_cart' => true,
            'phone' => '09555550001',
            'address' => 'Double Entry Accounting Street',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 2500.0,
            'discount' => 1500.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 4,
                    'price' => 5000.0,
                    'subtotal' => 20000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Tax Verification Customer', 'tax_cust');

        $sale = Sale::with('items')->where('customer_phone', '09555550001')->first();
        $this->assertNotNull($sale);

        // 1. Double-entry equation check
        $this->assertEquals(20000.0, (float)$sale->subtotal);
        $this->assertEquals(5.0, (float)$sale->tax_rate);
        $this->assertEquals(1000.0, (float)$sale->tax_amount);
        $this->assertEquals(2500.0, (float)$sale->shipping);
        $this->assertEquals(1500.0, (float)$sale->discount);

        $expectedGrandTotal = round(20000.0 + 1000.0 + 2500.0 - 1500.0, 2); // 22000.00
        $this->assertEquals($expectedGrandTotal, (float)$sale->grand_total, 'Grand total must satisfy subtotal + tax + shipping - discount');
        $this->assertEquals(22000.0, (float)$sale->grand_total);
        $this->assertEquals(22000.0, (float)$sale->due_amount);
        $this->assertEquals(0.0, (float)$sale->paid_amount);

        // 2. Item-level tax integrity
        $itemTaxSum = (float)$sale->items->sum('tax');
        $this->assertEquals((float)$sale->tax_amount, $itemTaxSum, 'Sum of item taxes must equal sale Output Tax/VAT');

        // 3. Accounts Receivable Isolation: Pending bot order must NOT leak into Customer AR
        $arBefore = Customer::applyReceivableScope($customer->sales())->sum('due_amount');
        $this->assertEquals(0.0, (float)$arBefore, 'Unapproved pending order must NOT leak into Customer AR');

        // 4. Staff approves order unpaid -> Genuine AR is recognized
        $staffUser = User::factory()->create(['name' => 'Accounting Approver', 'role' => 'Super Admin']);
        $response = $this->actingAs($staffUser)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => false,
        ]);
        $response->assertStatus(200);

        $arAfter = Customer::applyReceivableScope($customer->sales())->sum('due_amount');
        $this->assertEquals(22000.0, (float)$arAfter, 'Approved unpaid order must accurately reflect in genuine AR');
    }

    /**
     * Challenge 6: Cashier Active Shift Ledger & Payment Tender Recording
     * - Cashier opens CashRegister shift.
     * - Order is approved with payment.
     * - Assert sale is linked to active cash_register_id.
     * - Assert SalePayment is recorded with correct cashier user_id, amount, and payment_type.
     * - Assert shift ledger accurately includes the payment.
     * - Assert rejection voids any unverified preliminary payments.
     */
    public function test_challenge_6_cashier_active_shift_ledger_and_payment_tender_recording(): void
    {
        $cashier = User::factory()->create(['name' => 'Daw Mya POS Cashier', 'role' => 'Cashier']);

        // Cashier opens register shift
        $register = CashRegister::create([
            'reference_no' => 'REG-ADV-20261016-01',
            'user_id' => $cashier->id,
            'user_name' => $cashier->name,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'opening_cash' => 50000.0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'reference_no' => 'ADV-SHIFT-001',
            'customer_name' => 'Shift Test Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 15000,
            'tax_amount' => 750,
            'shipping' => 1000,
            'grand_total' => 16750,
            'due_amount' => 16750,
            'paid_amount' => 0,
            'payment_type' => 'KBZPay',
            'payment_status' => 'Unpaid',
            'stock_reserved' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 3,
            'base_quantity' => 3,
            'unit_price' => 5000,
            'subtotal' => 15000,
        ]);

        // Cashier approves order as paid
        $res = $this->actingAs($cashier)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => true,
            'payment_type' => 'KBZPay',
            'received_amount' => 16750.0,
        ]);
        $res->assertStatus(200);

        $sale->refresh();
        $this->assertEquals($register->id, $sale->cash_register_id, 'Sale must be linked to cashier active open shift');
        $this->assertEquals('Paid', $sale->payment_status);
        $this->assertEquals(16750.0, (float)$sale->paid_amount);
        $this->assertEquals(0.0, (float)$sale->due_amount);

        // Verify SalePayment record
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment, 'SalePayment record must be created');
        $this->assertEquals(16750.0, (float)$payment->amount);
        $this->assertEquals('KBZPay', $payment->payment_type);
        $this->assertEquals($cashier->id, $payment->user_id);

        // Verify CashRegister relationship includes sale
        $shiftSalesTotal = (float)$register->sales()->sum('paid_amount');
        $this->assertEquals(16750.0, $shiftSalesTotal, 'Shift sales total must include the approved bot sale');

        // Test rejection voiding preliminary payment:
        // Create another pending sale with preliminary payment
        $saleWithPayment = Sale::create([
            'reference_no' => 'ADV-PREPAY-002',
            'customer_name' => 'Premature Pay Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 5000,
            'grand_total' => 5000,
            'due_amount' => 0,
            'paid_amount' => 5000,
            'payment_status' => 'Paid',
            'stock_reserved' => false,
        ]);
        SalePayment::create([
            'sale_id' => $saleWithPayment->id,
            'reference_no' => 'PAY-PREMATURE',
            'payment_date' => now(),
            'payment_type' => 'KBZPay',
            'amount' => 5000.0,
            'received_amount' => 5000.0,
            'change_amount' => 0.0,
            'user_id' => $cashier->id,
            'user_name' => $cashier->name,
        ]);

        $this->assertEquals(1, SalePayment::where('sale_id', $saleWithPayment->id)->count());

        // Reject the order
        $rejectRes = $this->actingAs($cashier)->postJson("/api/bot/orders/{$saleWithPayment->id}/reject", [
            'reason' => 'Invalid or forged payment slip',
        ]);
        $rejectRes->assertStatus(200);

        // Preliminary payment MUST be voided/deleted to protect shift ledger
        $this->assertEquals(0, SalePayment::where('sale_id', $saleWithPayment->id)->count(), 'Preliminary payments must be deleted on rejection');
        $saleWithPayment->refresh();
        $this->assertEquals(0.0, (float)$saleWithPayment->paid_amount);
        $this->assertEquals(0.0, (float)$saleWithPayment->due_amount);
        $this->assertEquals('Unpaid', $saleWithPayment->payment_status);
    }

    /**
     * Challenge 7: Fallback when reservation disabled & multi-item order fulfillment
     */
    public function test_challenge_7_fallback_when_reservation_disabled_and_multi_item_order(): void
    {
        $staffUser = User::factory()->create(['name' => 'Manager 7', 'role' => 'Super Admin']);

        // Create second product
        $product2 = Product::create([
            'name' => 'Second Adversarial Product',
            'code' => 'WIDGET-ADV-002',
            'sku' => 'SKU-ADV-002',
            'retail_price' => 8000,
            'selling_price' => 8000,
            'cost_price' => 5000,
            'unit_name' => 'Piece',
            'stock_quantity' => 15,
            'is_active' => true,
            'is_bot_available' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $product2->id,
            'quantity' => 15.0000,
            'reserved_quantity' => 0.0000,
        ]);

        // Disable stock reservation
        Setting::set('telegram_bot_enable_stock_reservation', '0');

        $chatId = 7001;
        $session = [
            'is_cart' => true,
            'phone' => '09777770001',
            'address' => 'Multi Item Street',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 2,
                    'price' => 5000.0,
                    'subtotal' => 10000.0,
                ],
                [
                    'product_id' => $product2->id,
                    'variant_id' => null,
                    'quantity' => 3,
                    'price' => 8000.0,
                    'subtotal' => 24000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Multi Item Customer', 'multi_cust');

        $sale = Sale::with('items')->where('customer_phone', '09777770001')->first();
        $this->assertNotNull($sale);
        $this->assertFalse((bool)$sale->stock_reserved, 'stock_reserved must be false when reservation disabled');

        // Available stocks should still be 10 and 15 (no reservation)
        $bal1 = StockBalance::where('warehouse_id', $this->outlet->id)->where('product_id', $this->product->id)->first();
        $bal2 = StockBalance::where('warehouse_id', $this->outlet->id)->where('product_id', $product2->id)->first();
        $this->assertEquals(10.0000, (float)$bal1->quantity);
        $this->assertEquals(15.0000, (float)$bal2->quantity);

        // Staff approves order: since stock_reserved is false, it uses applyStock('out') fallback
        $res = $this->actingAs($staffUser)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => true,
        ]);
        $res->assertStatus(200);

        // Post approval: 10 - 2 = 8; 15 - 3 = 12
        $bal1Post = StockBalance::where('warehouse_id', $this->outlet->id)->where('product_id', $this->product->id)->first();
        $bal2Post = StockBalance::where('warehouse_id', $this->outlet->id)->where('product_id', $product2->id)->first();
        $this->assertEquals(8.0000, (float)$bal1Post->quantity);
        $this->assertEquals(12.0000, (float)$bal2Post->quantity);
        $this->assertEquals(0.0000, (float)$bal1Post->reserved_quantity);
        $this->assertEquals(0.0000, (float)$bal2Post->reserved_quantity);
    }
}
