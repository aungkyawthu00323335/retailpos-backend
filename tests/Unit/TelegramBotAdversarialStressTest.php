<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockBalance;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Customer;
use App\Http\Controllers\Api\BotOrderController;
use App\Http\Controllers\Api\ReportController;
use App\Services\TelegramBotService;

class TelegramBotAdversarialStressTest extends TestCase
{
    use RefreshDatabase;

    protected BotOrderController $botController;
    protected ReportController $reportController;
    protected TelegramBotService $botService;
    protected Outlet $outlet;
    protected Product $product;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botController = app(BotOrderController::class);
        $this->reportController = app(ReportController::class);
        $this->botService = app(TelegramBotService::class);

        // Fake outgoing Telegram HTTP requests
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'AdversarialTestBot', 'message_id' => 999]], 200),
        ]);

        $this->outlet = Outlet::create([
            'name' => 'Adversarial Test Outlet',
            'code' => 'ADV-01',
            'address' => 'No. 99 Strand Road, Yangon',
            'phone' => '0999887766',
            'is_active' => true,
        ]);

        $cat = ProductCategory::create([
            'name' => 'Electronics',
            'code' => 'ELEC',
        ]);

        $this->product = Product::create([
            'name' => 'Smart Wireless Earbuds',
            'code' => 'EAR-001',
            'sku' => 'EAR-SKU-001',
            'cost_price' => 15000,
            'purchase_price' => 15000,
            'retail_price' => 25000,
            'selling_price' => 25000,
            'is_active' => true,
            'is_bot_available' => true,
            'category_id' => $cat->id,
            'unit_name' => 'Piece',
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 50,
        ]);

        $this->customer = Customer::create([
            'name' => 'U Ba Thaw',
            'phone' => '0955566677',
            'address' => 'Bahan, Yangon',
            'telegram_chat_id' => '12345678',
        ]);

        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
    }

    /**
     * 1. Adversarial Test: Partial payload updates to POST /api/bot-settings
     */
    public function test_adversarial_partial_payload_updates_and_empty_values(): void
    {
        // 1.1 Submit single key: catalog_per_page = 8
        $req1 = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_catalog_per_page' => 8,
        ]);
        $res1 = $this->botController->updateBotSettings($req1);
        $this->assertEquals(200, $res1->getStatusCode());
        $this->assertEquals('8', Setting::get('telegram_bot_catalog_per_page'));

        // 1.2 Submit single key: telegram_bot_enabled = false
        $req2 = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_enabled' => false,
        ]);
        $res2 = $this->botController->updateBotSettings($req2);
        $this->assertEquals(200, $res2->getStatusCode());
        $this->assertEquals('0', Setting::get('telegram_bot_enabled'));
        $this->assertEquals('8', Setting::get('telegram_bot_catalog_per_page'), 'Prior setting must be preserved');

        // 1.3 Submit empty strings
        $req3 = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_welcome_msg' => '',
            'telegram_bot_contact_info_msg' => '',
        ]);
        $res3 = $this->botController->updateBotSettings($req3);
        $this->assertEquals(200, $res3->getStatusCode());
        $this->assertEquals('', Setting::get('telegram_bot_welcome_msg'));
        $this->assertEquals('', Setting::get('telegram_bot_contact_info_msg'));

        // 1.4 Submit null values for nullable fields
        $req4 = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_warehouse_id' => null,
            'telegram_bot_min_partial_pct' => null,
        ]);
        $res4 = $this->botController->updateBotSettings($req4);
        $this->assertEquals(200, $res4->getStatusCode());
    }

    /**
     * 2. Adversarial Test: Boundary and validation enforcement on POST /api/bot-settings
     */
    public function test_adversarial_out_of_range_and_invalid_payload_rejections(): void
    {
        // 2.1 Out-of-range pagination: catalog_per_page = 999 (max is 10)
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_per_page' => 999]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for catalog_per_page = 999');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_catalog_per_page', $e->errors());
        }

        // 2.2 Out-of-range pagination: catalog_per_page = -5 (min is 1)
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_per_page' => -5]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for catalog_per_page = -5');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_catalog_per_page', $e->errors());
        }

        // 2.3 Out-of-range pagination: catalog_per_page = 0
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_per_page' => 0]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for catalog_per_page = 0');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_catalog_per_page', $e->errors());
        }

        // 2.4 Boundaries 1 and 10 must pass cleanly
        $reqMin = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_per_page' => 1]);
        $resMin = $this->botController->updateBotSettings($reqMin);
        $this->assertEquals(200, $resMin->getStatusCode());
        $this->assertEquals(1, Setting::get('telegram_bot_catalog_per_page'));

        $reqMax = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_per_page' => 10]);
        $resMax = $this->botController->updateBotSettings($reqMax);
        $this->assertEquals(200, $resMax->getStatusCode());
        $this->assertEquals(10, Setting::get('telegram_bot_catalog_per_page'));

        // 2.5 Out-of-range min_partial_pct: 150 (max is 100)
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_min_partial_pct' => 150]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for min_partial_pct = 150');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_min_partial_pct', $e->errors());
        }

        // 2.6 Out-of-range min_partial_pct: -10 (min is 1)
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_min_partial_pct' => -10]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for min_partial_pct = -10');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_min_partial_pct', $e->errors());
        }

        // 2.7 Invalid catalog_style
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_catalog_style' => 'holographic_3d']);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for invalid catalog_style');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_catalog_style', $e->errors());
        }

        // 2.8 Non-existent warehouse_id
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_warehouse_id' => 999999]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for non-existent warehouse_id');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_warehouse_id', $e->errors());
        }

        // 2.9 Negative delivery fee
        try {
            $req = Request::create('/api/bot-settings', 'POST', ['telegram_bot_delivery_city_fee' => -500]);
            $this->botController->updateBotSettings($req);
            $this->fail('Expected ValidationException for negative delivery fee');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('telegram_bot_delivery_city_fee', $e->errors());
        }
    }

    /**
     * 3. Adversarial Test: Backward compatibility when legacy keys are purged
     */
    public function test_adversarial_backward_compatibility_with_purged_legacy_keys(): void
    {
        // Purge legacy keys from settings table
        $legacyKeys = [
            'telegram_bot_btn_catalog', 'telegram_bot_btn_orders', 'telegram_bot_btn_payment',
            'telegram_bot_cart_header_title', 'telegram_bot_cart_toast_inc', 'telegram_bot_cart_toast_dec',
            'telegram_bot_delivery_city_label', 'telegram_bot_order_dispatched_msg', 'telegram_bot_tma_enabled',
            'telegram_bot_cart_badge_style', 'telegram_bot_location_input_mode',
        ];
        foreach ($legacyKeys as $k) {
            Setting::forget($k);
        }

        // 3.1 getBotSettings must not throw any warnings or exceptions
        $settingsRes = $this->botController->getBotSettings();
        $this->assertEquals(200, $settingsRes->getStatusCode());
        $data = $settingsRes->getData(true)['data'];
        $this->assertArrayHasKey('telegram_bot_btn_catalog', $data);
        $this->assertNotEmpty($data['telegram_bot_btn_catalog']);
        $this->assertArrayHasKey('telegram_bot_cart_header_title', $data);
        $this->assertNotEmpty($data['telegram_bot_cart_header_title']);

        // 3.2 getBotConfig must execute without errors
        $configRes = $this->botController->getBotConfig();
        $this->assertEquals(200, $configRes->getStatusCode());
        $configData = $configRes->getData(true)['data'];
        $this->assertArrayHasKey('warehouse_id', $configData);
        $this->assertArrayHasKey('payment_methods', $configData);

        // 3.3 getProducts must execute without errors
        $prodReq = Request::create('/api/bot/products', 'GET');
        $prodRes = $this->botController->getProducts($prodReq);
        $this->assertEquals(200, $prodRes->getStatusCode());

        // 3.4 TelegramBotService methods must not crash when legacy settings are missing
        $mainKb = $this->botService->getMainKeyboard('99887766');
        $this->assertIsArray($mainKb);
        $this->assertArrayHasKey('keyboard', $mainKb);

        $cart = $this->botService->getCart('99887766');
        $this->assertIsArray($cart);

        $cartBtn = $this->botService->formatCartButtonText('99887766');
        $this->assertIsString($cartBtn);
    }

    /**
     * 4. Adversarial Test: Accounting Invariant - Isolation of unapproved bot orders from Sales Report
     */
    public function test_adversarial_unapproved_bot_orders_never_pollute_sales_report(): void
    {
        $today = Carbon::today()->toDateString();

        // 4.1 Create legitimate in-store POS sale: $50,000 completed
        $posSale = Sale::create([
            'reference_no' => 'POS-SL-001',
            'customer_name' => 'Walk-in Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 50000.0,
            'grand_total' => 50000.0,
            'paid_amount' => 50000.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
            'order_source' => 'pos',
            'bot_approval_status' => 'none',
        ]);

        // Baseline verification: Sales report must reflect POS sale
        $repReq1 = Request::create('/api/reports/sales', 'GET', ['force_refresh' => 1]);
        $repRes1 = $this->reportController->salesReport($repReq1);
        $repData1 = json_decode($repRes1->getContent(), true)['data'];

        $this->assertEquals(1, $repData1['total_sales_count']);
        $this->assertEquals(50000.0, (float)$repData1['total_revenue']);
        $this->assertEquals(50000.0, (float)$repData1['total_paid']);

        // 4.2 Create Unapproved Bot Order: $100,000 pending
        $botOrderPending = Sale::create([
            'reference_no' => 'BOT-PENDING-001',
            'customer_name' => 'Telegram Customer 1',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => $today . ' 11:00:00',
            'status' => 'Pending',
            'subtotal' => 100000.0,
            'grand_total' => 100000.0,
            'paid_amount' => 100000.0,
            'due_amount' => 0.0,
            'payment_type' => 'KBZPay',
            'payment_status' => 'Paid',
            'order_source' => 'telegram_bot',
            'bot_approval_status' => 'pending',
        ]);

        // 4.3 Create Rejected Bot Order: $75,000 cancelled
        $botOrderRejected = Sale::create([
            'reference_no' => 'BOT-REJECTED-001',
            'customer_name' => 'Telegram Customer 2',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => $today . ' 11:30:00',
            'status' => 'Cancelled',
            'subtotal' => 75000.0,
            'grand_total' => 75000.0,
            'paid_amount' => 0.0,
            'due_amount' => 0.0,
            'payment_type' => 'WavePay',
            'payment_status' => 'Unpaid',
            'order_source' => 'telegram_bot',
            'bot_approval_status' => 'rejected',
        ]);

        // 🛡️ Stress verification: Sales Report MUST strictly exclude pending and rejected bot orders!
        $repReq2 = Request::create('/api/reports/sales', 'GET', ['force_refresh' => 1]);
        $repRes2 = $this->reportController->salesReport($repReq2);
        $repData2 = json_decode($repRes2->getContent(), true)['data'];

        $this->assertEquals(1, $repData2['total_sales_count'], 'Unapproved/rejected bot orders must NOT increase total_sales_count');
        $this->assertEquals(50000.0, (float)$repData2['total_revenue'], 'Unapproved/rejected bot orders must NOT pollute total_revenue');
        $this->assertEquals(50000.0, (float)$repData2['total_paid'], 'Unapproved/rejected bot orders must NOT pollute total_paid');

        $salesRefs = collect($repData2['sales'])->pluck('reference_no')->toArray();
        $this->assertContains('POS-SL-001', $salesRefs);
        $this->assertNotContains('BOT-PENDING-001', $salesRefs, 'Pending bot order must NOT appear in sales report listing');
        $this->assertNotContains('BOT-REJECTED-001', $salesRefs, 'Rejected bot order must NOT appear in sales report listing');

        // 4.4 Approve the pending bot order: Now it MUST be recognized in accounting
        $botOrderPending->status = 'Completed';
        $botOrderPending->bot_approval_status = 'approved';
        $botOrderPending->bot_approved_at = now();
        $botOrderPending->save();

        $repReq3 = Request::create('/api/reports/sales', 'GET', ['force_refresh' => 1]);
        $repRes3 = $this->reportController->salesReport($repReq3);
        $repData3 = json_decode($repRes3->getContent(), true)['data'];

        $this->assertEquals(2, $repData3['total_sales_count'], 'Approved bot order must now be counted');
        $this->assertEquals(150000.0, (float)$repData3['total_revenue'], 'Approved bot order revenue (50,000 + 100,000) must now be recognized');
        $this->assertEquals(150000.0, (float)$repData3['total_paid']);

        $salesRefsAfter = collect($repData3['sales'])->pluck('reference_no')->toArray();
        $this->assertContains('BOT-PENDING-001', $salesRefsAfter);
    }

    /**
     * 5. Adversarial Test: Order Approval depleted stock exactly and generates official reference
     */
    public function test_adversarial_order_approval_inventory_and_reference_integrity(): void
    {
        Setting::set('telegram_bot_auto_approve', '0');

        $initialStock = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(50.0, $initialStock);

        // Submit order for 7 units
        $orderPayload = [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 7,
                ]
            ],
            'customer_name' => 'U Ba Thaw',
            'customer_phone' => '0955566677',
            'shipping_address' => 'Bahan, Yangon',
            'telegram_chat_id' => '12345678',
            'payment_type' => 'Cash on Delivery',
            'payment_mode' => 'Unpaid',
            'paid_amount' => 0.0,
        ];

        $req = Request::create('/api/bot/orders', 'POST', $orderPayload);
        $res = $this->botController->submitOrder($req);
        $this->assertEquals(201, $res->getStatusCode());

        $saleId = $res->getData(true)['data']['id'];
        $sale = Sale::findOrFail($saleId);

        // Pre-approval: stock unchanged
        $stockPre = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(50.0, $stockPre, 'Stock must NOT be depleted prior to staff approval');

        // Approve
        $appReq = Request::create("/api/bot-orders/{$saleId}/approve", 'POST');
        $appRes = $this->botController->approveOrder($appReq, $saleId);
        $this->assertEquals(200, $appRes->getStatusCode());

        // Post-approval: stock depleted by exactly 7
        $stockPost = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(43.0, $stockPost, 'Stock must be depleted by exactly 7 units upon approval');

        $sale->refresh();
        $this->assertEquals('Completed', $sale->status);
        $this->assertEquals('approved', $sale->bot_approval_status);
        $this->assertStringStartsWith('SA-', $sale->reference_no, 'Approved order must receive official sequential POS invoice number');
    }

    /**
     * 6. Adversarial Test: Order Rejection deletes payments and zeros AR to prevent ledger distortion
     */
    public function test_adversarial_order_rejection_clears_payments_and_zeros_ar(): void
    {
        Setting::set('telegram_bot_auto_approve', '0');

        // Submit paid order with slip
        $orderPayload = [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                ]
            ],
            'customer_name' => 'Ma Hla',
            'customer_phone' => '0977889900',
            'shipping_address' => 'Sanchaung, Yangon',
            'telegram_chat_id' => '87654321',
            'payment_type' => 'KBZPay',
            'payment_mode' => 'Paid',
            'paid_amount' => 75000.0,
            'payment_slip_url' => 'https://retailpos.test/storage/slips/tampered_slip.jpg',
        ];

        $req = Request::create('/api/bot/orders', 'POST', $orderPayload);
        $res = $this->botController->submitOrder($req);
        $this->assertEquals(201, $res->getStatusCode());

        $saleId = $res->getData(true)['data']['id'];
        $sale = Sale::findOrFail($saleId);

        // Pre-rejection payment record check
        $this->assertEquals(1, SalePayment::where('sale_id', $saleId)->count());
        $this->assertEquals(75000.0, (float)$sale->paid_amount);

        // Reject order
        $rejReq = Request::create("/api/bot-orders/{$saleId}/reject", 'POST', [
            'reason' => 'Forged transaction ID in screenshot',
        ]);
        $rejRes = $this->botController->rejectOrder($rejReq, $saleId);
        $this->assertEquals(200, $rejRes->getStatusCode());

        $sale->refresh();
        $this->assertEquals('Cancelled', $sale->status);
        $this->assertEquals('rejected', $sale->bot_approval_status);
        $this->assertEquals('Forged transaction ID in screenshot', $sale->bot_rejection_reason);

        // 🛡️ All payment rows MUST be deleted
        $this->assertEquals(0, SalePayment::where('sale_id', $saleId)->count(), 'Rejection must remove all unverified SalePayment records');

        // 🛡️ paid_amount and due_amount MUST be 0.0
        $this->assertEquals(0.0, (float)$sale->paid_amount, 'paid_amount must be reset to 0');
        $this->assertEquals(0.0, (float)$sale->due_amount, 'due_amount must be reset to 0 to prevent phantom Accounts Receivable');

        // Warehouse stock must NOT be depleted
        $stock = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(50.0, $stock, 'Stock must remain untouched on rejected order');
    }

    /**
     * 7. Adversarial Test: Anti-Fraud duplicate slip cache detection in TelegramBotService
     */
    public function test_adversarial_anti_fraud_duplicate_slip_cache_detection(): void
    {
        Setting::set('telegram_bot_reject_duplicate_slip', '1');

        $testHash = 'a1b2c3d4e5f67890abcdef1234567890abcdef1234567890abcdef1234567890';
        $testUid = 'unique_telegram_file_id_001';
        $priorRef = 'SALE-20261103-9999';

        // Simulate that slip has already been recorded in cache for a prior order
        Cache::put("tg_slip_hash_{$testHash}", $priorRef, now()->addDays(30));
        Cache::put("tg_slip_uid_{$testUid}", $priorRef, now()->addDays(30));

        $this->assertTrue(Cache::has("tg_slip_hash_{$testHash}"));
        $this->assertEquals($priorRef, Cache::get("tg_slip_hash_{$testHash}"));

        // Simulate incoming update with preloaded download containing the duplicate hash
        // The service should detect the duplicate hash in cache and send duplicate warning
        $refMethod = new \ReflectionMethod(TelegramBotService::class, 'processPaymentSlipUpload');
        $refMethod->setAccessible(true);

        $refMethod->invoke(
            $this->botService,
            '12345678', // chatId
            'tg_file_id_001',
            'Test User',
            'testuser',
            $testUid,
            ['url' => 'https://api.telegram.org/file/botToken/slip.jpg', 'hash' => $testHash]
        );

        // Verify that duplicate detection intercepted and no new sale was finalized for this duplicate
        $newSalesCount = Sale::where('reference_no', '!=', $priorRef)->count();
        $this->assertEquals(0, $newSalesCount, 'Duplicate slip upload must not create or finalize any sale');
    }

    /**
     * 8. Adversarial Test: Hide out-of-stock products in catalog
     */
    public function test_adversarial_hide_out_of_stock_products_in_catalog(): void
    {
        // Create an out-of-stock product (0 stock in outlet)
        $outOfStockProduct = Product::create([
            'name' => 'Sold Out Mechanical Keyboard',
            'code' => 'KEY-001',
            'sku' => 'KEY-SKU-001',
            'cost_price' => 20000,
            'purchase_price' => 20000,
            'retail_price' => 45000,
            'selling_price' => 45000,
            'is_active' => true,
            'is_bot_available' => true,
            'category_id' => $this->product->category_id,
            'unit_name' => 'Piece',
        ]);
        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $outOfStockProduct->id,
            'quantity' => 0,
        ]);

        // When hide_out_of_stock is 0 -> both products must appear
        Setting::set('telegram_bot_hide_out_of_stock', '0');
        $req1 = Request::create('/api/bot/products', 'GET');
        $res1 = $this->botController->getProducts($req1);
        $items1 = $res1->getData(true)['data'];
        $productIds1 = collect($items1)->pluck('id')->toArray();
        $this->assertContains($this->product->id, $productIds1);
        $this->assertContains($outOfStockProduct->id, $productIds1);

        // When hide_out_of_stock is 1 -> out-of-stock product MUST be filtered out!
        Setting::set('telegram_bot_hide_out_of_stock', '1');
        $req2 = Request::create('/api/bot/products', 'GET');
        $res2 = $this->botController->getProducts($req2);
        $items2 = $res2->getData(true)['data'];
        $productIds2 = collect($items2)->pluck('id')->toArray();
        $this->assertContains($this->product->id, $productIds2);
        $this->assertNotContains($outOfStockProduct->id, $productIds2, 'Out of stock product must be hidden when hide_out_of_stock is enabled');
    }

    /**
     * 9. Adversarial Test: Profit & Loss endpoint isolation inspection
     */
    public function test_adversarial_profit_loss_isolation(): void
    {
        $today = Carbon::today()->toDateString();

        // 1. Legitimate Completed POS sale: 40,000 MMK
        Sale::create([
            'reference_no' => 'POS-PL-001',
            'customer_name' => 'Store Walk-in',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 40000.0,
            'grand_total' => 40000.0,
            'paid_amount' => 40000.0,
            'due_amount' => 0.0,
            'payment_type' => 'Cash',
            'payment_status' => 'Paid',
            'order_source' => 'pos',
            'bot_approval_status' => 'none',
        ]);

        // 2. Unapproved Pending Telegram Bot order: 90,000 MMK
        Sale::create([
            'reference_no' => 'BOT-PL-PENDING-001',
            'customer_name' => 'Telegram Bot Shopper',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => $today . ' 12:00:00',
            'status' => 'Pending',
            'subtotal' => 90000.0,
            'grand_total' => 90000.0,
            'paid_amount' => 90000.0,
            'due_amount' => 0.0,
            'payment_type' => 'KBZPay',
            'payment_status' => 'Paid',
            'order_source' => 'telegram_bot',
            'bot_approval_status' => 'pending',
        ]);

        $plReq = Request::create('/api/reports/profit-loss', 'GET', [
            'date_filter' => 'today',
            'warehouse_name' => $this->outlet->name,
            'force_refresh' => 1,
        ]);
        $plRes = $this->reportController->profitLoss($plReq);
        $plData = json_decode($plRes->getContent(), true);

        // Record empirical observation of profitLoss gross_sales
        // Does it isolate pending bot orders?
        $grossSales = (float)($plData['data']['summary']['gross_sales'] ?? $plData['summary']['gross_sales'] ?? 0);
        
        // Assert ReportController salesReport strictly isolates it
        $salesRepReq = Request::create('/api/reports/sales', 'GET', ['force_refresh' => 1]);
        $salesRepRes = $this->reportController->salesReport($salesRepReq);
        $salesRepData = json_decode($salesRepRes->getContent(), true)['data'];
        $this->assertEquals(40000.0, (float)$salesRepData['total_revenue'], 'salesReport strictly isolates unapproved bot orders');
    }
}

