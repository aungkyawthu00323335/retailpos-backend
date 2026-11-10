<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockBalance;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Customer;
use App\Http\Controllers\Api\BotOrderController;

class TelegramBotSimplifiedSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected BotOrderController $controller;
    protected Outlet $outlet;
    protected Product $product;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(BotOrderController::class);

        // Fake outgoing Telegram HTTP requests to isolate unit test
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'TestRetailBot', 'message_id' => 101]], 200),
        ]);

        $this->outlet = Outlet::create([
            'name' => 'Main Retail Outlet',
            'code' => 'OUT-01',
            'address' => 'No. 123 Bogyoke Road, Yangon',
            'phone' => '0912345678',
            'is_active' => true,
        ]);

        $cat = ProductCategory::create([
            'name' => 'Beverages',
            'code' => 'BEV',
        ]);

        $this->product = Product::create([
            'name' => 'Special Roasted Coffee',
            'code' => 'SRC-001',
            'sku' => 'SRC-SKU-001',
            'cost_price' => 3000,
            'purchase_price' => 3000,
            'retail_price' => 5000,
            'selling_price' => 5000,
            'is_active' => true,
            'is_bot_available' => true,
            'category_id' => $cat->id,
            'unit_name' => 'Bag',
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 20,
        ]);

        $this->customer = Customer::create([
            'name' => 'Daw Thuzar',
            'phone' => '0987654321',
            'address' => 'Kamayut, Yangon',
            'telegram_chat_id' => '77889900',
        ]);

        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
    }

    /**
     * R2.1: getBotSettings returns streamlined settings with expected keys and types.
     */
    public function test_get_bot_settings_returns_streamlined_settings_with_expected_keys_and_types(): void
    {
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_token', '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
        Setting::set('telegram_bot_username', '@RetailPOSBot');
        Setting::set('telegram_bot_auto_approve', '0');
        Setting::set('telegram_bot_allow_unpaid', '1');
        Setting::set('telegram_bot_allow_paid', '1');
        Setting::set('telegram_bot_allow_partial', '0');
        Setting::set('telegram_bot_min_partial_pct', '30');
        Setting::set('telegram_bot_require_payment_slip', '1');
        Setting::set('telegram_bot_reject_duplicate_slip', '1');
        Setting::set('telegram_admin_chat_ids', '99887766');
        Setting::set('telegram_bot_hide_out_of_stock', '1');
        Setting::set('telegram_bot_catalog_per_page', '4');
        Setting::set('telegram_bot_catalog_style', 'card_feed');
        Setting::set('telegram_bot_show_product_code', '1');
        Setting::set('telegram_bot_show_product_category', '1');
        Setting::set('telegram_bot_show_product_brand', '1');
        Setting::set('telegram_bot_show_product_price', '1');
        Setting::set('telegram_bot_show_product_stock', '1');
        Setting::set('telegram_bot_show_product_image', '1');
        Setting::set('telegram_bot_welcome_msg', 'Welcome to our Retail POS Store!');
        Setting::set('telegram_bot_delivery_store_pickup_fee', '0');
        Setting::set('telegram_bot_delivery_city_fee', '2000');
        Setting::set('telegram_bot_delivery_express_fee', '3500');

        $response = $this->controller->getBotSettings();
        $resData = $response->getData(true);

        $this->assertEquals('success', $resData['status']);
        $settings = $resData['data'];

        // 1. Bot Connection
        $this->assertIsBool($settings['telegram_bot_enabled']);
        $this->assertTrue($settings['telegram_bot_enabled']);
        $this->assertIsString($settings['telegram_bot_token']);
        $this->assertEquals('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', $settings['telegram_bot_token']);
        $this->assertIsString($settings['telegram_bot_username']);
        $this->assertEquals('@RetailPOSBot', $settings['telegram_bot_username']);
        $this->assertIsInt($settings['telegram_bot_warehouse_id']);
        $this->assertEquals($this->outlet->id, $settings['telegram_bot_warehouse_id']);
        $this->assertEquals('Main Retail Outlet', $settings['telegram_bot_warehouse_name']);

        // 2. Order & Accounting Policy
        $this->assertIsBool($settings['telegram_bot_auto_approve']);
        $this->assertFalse($settings['telegram_bot_auto_approve']);
        $this->assertIsBool($settings['telegram_bot_allow_unpaid']);
        $this->assertTrue($settings['telegram_bot_allow_unpaid']);
        $this->assertIsBool($settings['telegram_bot_allow_paid']);
        $this->assertTrue($settings['telegram_bot_allow_paid']);
        $this->assertIsBool($settings['telegram_bot_allow_partial']);
        $this->assertFalse($settings['telegram_bot_allow_partial']);
        $this->assertIsNumeric($settings['telegram_bot_min_partial_pct']);
        $this->assertEquals(30.0, (float)$settings['telegram_bot_min_partial_pct']);
        $this->assertIsBool($settings['telegram_bot_require_payment_slip']);
        $this->assertTrue($settings['telegram_bot_require_payment_slip']);
        $this->assertIsBool($settings['telegram_bot_reject_duplicate_slip']);
        $this->assertTrue($settings['telegram_bot_reject_duplicate_slip']);
        $this->assertEquals('99887766', $settings['telegram_admin_chat_ids']);

        // 3. Catalog & Delivery Controls
        $this->assertIsBool($settings['telegram_bot_hide_out_of_stock']);
        $this->assertTrue($settings['telegram_bot_hide_out_of_stock']);
        $this->assertIsInt($settings['telegram_bot_catalog_per_page']);
        $this->assertEquals(4, $settings['telegram_bot_catalog_per_page']);
        $this->assertIsString($settings['telegram_bot_catalog_style']);
        $this->assertEquals('card_feed', $settings['telegram_bot_catalog_style']);
        $this->assertIsBool($settings['telegram_bot_show_product_code']);
        $this->assertIsBool($settings['telegram_bot_show_product_category']);
        $this->assertIsBool($settings['telegram_bot_show_product_brand']);
        $this->assertIsBool($settings['telegram_bot_show_product_price']);
        $this->assertIsBool($settings['telegram_bot_show_product_stock']);
        $this->assertIsBool($settings['telegram_bot_show_product_image']);
        $this->assertEquals('Welcome to our Retail POS Store!', $settings['telegram_bot_welcome_msg']);
        $this->assertIsNumeric($settings['telegram_bot_delivery_store_pickup_fee']);
        $this->assertEquals(0.0, (float)$settings['telegram_bot_delivery_store_pickup_fee']);
        $this->assertIsNumeric($settings['telegram_bot_delivery_city_fee']);
        $this->assertEquals(2000.0, (float)$settings['telegram_bot_delivery_city_fee']);
        $this->assertIsNumeric($settings['telegram_bot_delivery_express_fee']);
        $this->assertEquals(3500.0, (float)$settings['telegram_bot_delivery_express_fee']);
    }

    /**
     * R2.2: updateBotSettings accepts and persists streamlined settings payload.
     */
    public function test_update_bot_settings_persists_streamlined_payload(): void
    {
        $payload = [
            'telegram_bot_enabled' => true,
            'telegram_bot_token' => '987654:XYZ_UpdatedToken1234567890',
            'telegram_bot_warehouse_id' => $this->outlet->id,
            'telegram_bot_auto_approve' => false,
            'telegram_bot_allow_unpaid' => true,
            'telegram_bot_allow_paid' => true,
            'telegram_bot_allow_partial' => true,
            'telegram_bot_min_partial_pct' => 25.0,
            'telegram_bot_require_payment_slip' => true,
            'telegram_bot_reject_duplicate_slip' => true,
            'telegram_admin_chat_ids' => '11223344,55667788',
            'telegram_bot_hide_out_of_stock' => true,
            'telegram_bot_catalog_per_page' => 5,
            'telegram_bot_catalog_style' => 'single_summary',
            'telegram_bot_show_product_code' => false,
            'telegram_bot_show_product_category' => true,
            'telegram_bot_show_product_brand' => false,
            'telegram_bot_show_product_price' => true,
            'telegram_bot_show_product_stock' => false,
            'telegram_bot_show_product_image' => true,
            'telegram_bot_welcome_msg' => 'မင်္ဂလာပါ Retail POS မှ ကြိုဆိုပါသည်',
            'telegram_bot_delivery_store_pickup_fee' => 0.0,
            'telegram_bot_delivery_city_fee' => 3000.0,
            'telegram_bot_delivery_express_fee' => 4500.0,
        ];

        $request = Request::create('/api/bot-settings', 'POST', $payload);
        $response = $this->controller->updateBotSettings($request);
        $resData = $response->getData(true);

        $this->assertEquals('success', $resData['status']);
        $data = $resData['data'];

        // Assert response values match payload
        $this->assertTrue($data['telegram_bot_enabled']);
        $this->assertEquals('987654:XYZ_UpdatedToken1234567890', $data['telegram_bot_token']);
        $this->assertEquals($this->outlet->id, $data['telegram_bot_warehouse_id']);
        $this->assertFalse($data['telegram_bot_auto_approve']);
        $this->assertTrue($data['telegram_bot_allow_unpaid']);
        $this->assertTrue($data['telegram_bot_allow_paid']);
        $this->assertTrue($data['telegram_bot_allow_partial']);
        $this->assertEquals(25.0, $data['telegram_bot_min_partial_pct']);
        $this->assertTrue($data['telegram_bot_require_payment_slip']);
        $this->assertTrue($data['telegram_bot_reject_duplicate_slip']);
        $this->assertEquals('11223344,55667788', $data['telegram_admin_chat_ids']);
        $this->assertTrue($data['telegram_bot_hide_out_of_stock']);
        $this->assertEquals(5, $data['telegram_bot_catalog_per_page']);
        $this->assertEquals('single_summary', $data['telegram_bot_catalog_style']);
        $this->assertFalse($data['telegram_bot_show_product_code']);
        $this->assertFalse($data['telegram_bot_show_product_brand']);
        $this->assertFalse($data['telegram_bot_show_product_stock']);
        $this->assertEquals('မင်္ဂလာပါ Retail POS မှ ကြိုဆိုပါသည်', $data['telegram_bot_welcome_msg']);
        $this->assertEquals(3000.0, $data['telegram_bot_delivery_city_fee']);
        $this->assertEquals(4500.0, $data['telegram_bot_delivery_express_fee']);

        // Assert persistent database state via Setting::get()
        $this->assertEquals('1', Setting::get('telegram_bot_enabled'));
        $this->assertEquals('987654:XYZ_UpdatedToken1234567890', Setting::get('telegram_bot_token'));
        $this->assertEquals('single_summary', Setting::get('telegram_bot_catalog_style'));
        $this->assertEquals('5', Setting::get('telegram_bot_catalog_per_page'));
        $this->assertEquals('3000', Setting::get('telegram_bot_delivery_city_fee'));
    }

    /**
     * R2.3: Partial update succeeds without altering unsubmitted keys.
     */
    public function test_partial_update_succeeds_without_affecting_unsubmitted_keys(): void
    {
        Setting::set('telegram_bot_catalog_per_page', '3');
        Setting::set('telegram_bot_delivery_city_fee', '2500');
        Setting::set('telegram_bot_catalog_style', 'card_feed');

        // Submit only 1 field
        $request = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_catalog_per_page' => 7,
        ]);
        $response = $this->controller->updateBotSettings($request);
        $resData = $response->getData(true);

        $this->assertEquals('success', $resData['status']);
        $this->assertEquals(7, Setting::get('telegram_bot_catalog_per_page'));
        // Unsubmitted keys remain intact
        $this->assertEquals('2500', Setting::get('telegram_bot_delivery_city_fee'));
        $this->assertEquals('card_feed', Setting::get('telegram_bot_catalog_style'));
    }

    /**
     * R2.4: Omitted legacy keys gracefully fall back to sensible defaults.
     */
    public function test_omitted_legacy_keys_gracefully_fallback_to_defaults(): void
    {
        // Explicitly clear legacy keys from settings table
        Setting::forget('telegram_bot_btn_catalog');
        Setting::forget('telegram_bot_cart_header_title');
        Setting::forget('telegram_bot_cart_toast_inc');

        // Setting::get returns sensible defaults
        $this->assertEquals('🛍️ ကုန်ပစ္စည်းများ', Setting::get('telegram_bot_btn_catalog', '🛍️ ကုန်ပစ္စည်းများ'));
        $this->assertEquals('🛒 ယူထားတဲ့ စာရင်း', Setting::get('telegram_bot_cart_header_title', '🛒 ယူထားတဲ့ စာရင်း'));

        // Controller getBotSettings handles omitted legacy keys without error
        $response = $this->controller->getBotSettings();
        $this->assertEquals('success', $response->getData(true)['status']);
        $settings = $response->getData(true)['data'];

        $this->assertEquals('🛍️ ကုန်ပစ္စည်းများ', $settings['telegram_bot_btn_catalog']);
        $this->assertEquals('🛒 ယူထားတဲ့ စာရင်း', $settings['telegram_bot_cart_header_title']);
    }

    /**
     * R2.5: Bot order slip submission and approval/rejection workflows preserve accounting data integrity.
     */
    public function test_bot_order_slip_submission_and_accounting_integrity_workflows(): void
    {
        Setting::set('telegram_bot_auto_approve', '0'); // Require manual review
        Setting::set('telegram_bot_allow_paid', '1');
        Setting::set('telegram_bot_require_payment_slip', '1');

        $initialStock = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(20.0, $initialStock);

        // Step 1: Submit a Paid bot order with payment slip attached
        $orderPayload = [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ]
            ],
            'customer_name' => 'Daw Thuzar',
            'customer_phone' => '0987654321',
            'shipping_address' => 'Kamayut, Yangon',
            'telegram_chat_id' => '77889900',
            'telegram_username' => 'thuzar_customer',
            'payment_type' => 'KBZPay',
            'payment_mode' => 'Paid',
            'paid_amount' => 10000.0,
            'payment_slip_url' => 'https://retailpos.test/storage/bot_slips/sample_slip.jpg',
            'notes' => 'Please deliver in afternoon',
        ];

        $req = Request::create('/api/bot/orders', 'POST', $orderPayload);
        $orderRes = $this->controller->submitOrder($req);
        $this->assertEquals(201, $orderRes->getStatusCode());
        $orderData = $orderRes->getData(true)['data'];

        $saleId = $orderData['id'];
        $sale = Sale::findOrFail($saleId);

        // 🛡️ Pre-Approval Invariant:
        // Status must be Pending; Stock must NOT be depleted; Payment record exists
        $this->assertEquals('Pending', $sale->status);
        $this->assertEquals('pending', $sale->bot_approval_status);
        $this->assertEquals(10000.0, (float)$sale->grand_total);
        $this->assertEquals(10000.0, (float)$sale->paid_amount);
        $this->assertEquals(0.0, (float)$sale->due_amount);
        $this->assertEquals($this->outlet->id, $sale->warehouse_id);

        $stockAfterOrder = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(20.0, $stockAfterOrder, 'Pending bot order must NOT deplete warehouse stock');

        $paymentsCount = SalePayment::where('sale_id', $sale->id)->count();
        $this->assertEquals(1, $paymentsCount, 'Paid bot order must record initial unverified payment');

        // Step 2: Approve Order Workflow
        $approveReq = Request::create("/api/bot-orders/{$sale->id}/approve", 'POST');
        $approveRes = $this->controller->approveOrder($approveReq, $sale->id);
        $this->assertEquals(200, $approveRes->getStatusCode());

        $sale->refresh();
        $this->assertEquals('Completed', $sale->status);
        $this->assertEquals('approved', $sale->bot_approval_status);
        $this->assertNotNull($sale->bot_approved_at);
        $this->assertStringStartsWith('SA-', $sale->reference_no, 'Approved order must receive sequential POS invoice reference');

        // Stock must now be depleted via FIFO
        $stockAfterApprove = (float)StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals(18.0, $stockAfterApprove, 'Approved bot order must deplete exactly 2 units from warehouse');

        // Step 3: Rejection Workflow Integrity Check on Second Order
        $rejectOrderPayload = [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ]
            ],
            'customer_name' => 'Ko Aung',
            'customer_phone' => '0911223344',
            'shipping_address' => 'Sanchaung, Yangon',
            'telegram_chat_id' => '66554433',
            'payment_type' => 'WavePay',
            'payment_mode' => 'Paid',
            'paid_amount' => 5000.0,
            'payment_slip_url' => 'https://retailpos.test/storage/bot_slips/fake_slip.jpg',
        ];

        $req2 = Request::create('/api/bot/orders', 'POST', $rejectOrderPayload);
        $orderRes2 = $this->controller->submitOrder($req2);
        $this->assertEquals(201, $orderRes2->getStatusCode());
        $saleId2 = $orderRes2->getData(true)['data']['id'];
        $sale2 = Sale::findOrFail($saleId2);

        $this->assertEquals(1, SalePayment::where('sale_id', $sale2->id)->count());

        // Reject order due to fraudulent/unverified slip
        $rejectReq = Request::create("/api/bot-orders/{$sale2->id}/reject", 'POST', [
            'reason' => 'Invalid payment slip screenshot (unverified)',
        ]);
        $rejectRes = $this->controller->rejectOrder($rejectReq, $sale2->id);
        $this->assertEquals(200, $rejectRes->getStatusCode());

        $sale2->refresh();
        $this->assertEquals('Cancelled', $sale2->status);
        $this->assertEquals('rejected', $sale2->bot_approval_status);
        $this->assertEquals('Invalid payment slip screenshot (unverified)', $sale2->bot_rejection_reason);

        // 🛡️ Accounting Ledger Invariant:
        // Preliminary payment rows must be completely deleted; paid and due amounts must be zero
        $this->assertEquals(0, SalePayment::where('sale_id', $sale2->id)->count(), 'Rejection must delete unverified SalePayment records');
        $this->assertEquals(0.0, (float)$sale2->paid_amount, 'Rejected order must have 0 paid amount');
        $this->assertEquals(0.0, (float)$sale2->due_amount, 'Rejected order must have 0 due amount to avoid inflating Accounts Receivable');
    }

    /**
     * R2.6: Payment instructions message can be customized, cleared, or reset to defaults.
     */
    public function test_payment_instructions_message_customization_and_clearing(): void
    {
        // 1. Initially empty or default in getBotSettings
        $res = $this->controller->getBotSettings();
        $this->assertEquals('success', $res->getData(true)['status']);
        $settings = $res->getData(true)['data'];
        $this->assertArrayHasKey('telegram_bot_payment_instructions_msg', $settings);

        // 2. Customize via updateBotSettings
        $customMsg = "ℹ️ {shop_name} Payment Info:\n\n{payment_methods}\n\nCall {shop_phone} for inquiries.";
        $updateReq = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_payment_instructions_msg' => $customMsg,
        ]);
        $updateRes = $this->controller->updateBotSettings($updateReq);
        $this->assertEquals('success', $updateRes->getData(true)['status']);
        $this->assertEquals($customMsg, Setting::get('telegram_bot_payment_instructions_msg'));

        // 3. Clear/delete custom message
        $clearReq = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_payment_instructions_msg' => '',
        ]);
        $clearRes = $this->controller->updateBotSettings($clearReq);
        $this->assertEquals('success', $clearRes->getData(true)['status']);
        $this->assertEquals('', Setting::get('telegram_bot_payment_instructions_msg'));
    }

    /**
     * R2.7: TelegramBotService::sendPaymentInfo properly replaces placeholders or falls back gracefully.
     */
    public function test_telegram_bot_service_send_payment_info_placeholders_and_fallback(): void
    {
        $botService = app(\App\Services\TelegramBotService::class);
        Setting::set('company_name', 'Golden Shwe Store');
        Setting::set('company_phone', '09999888777');
        Setting::set('telegram_bot_token', 'fake:token123');

        // Create a test active payment method
        \App\Models\PaymentMethod::create([
            'name' => 'KPay Special',
            'code' => 'kpay',
            'account_name' => 'Ko Golden',
            'account_number' => '09999888777',
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        // A. Custom message with {payment_methods} and {shop_name}
        $customTpl = "🏦 *{shop_name}*\n\n{payment_methods}\n\nThanks!";
        Setting::set('telegram_bot_payment_instructions_msg', $customTpl);

        $sentMessages = [];
        Http::fake([
            'https://api.telegram.org/*/sendMessage*' => function (\Illuminate\Http\Client\Request $request) use (&$sentMessages) {
                $sentMessages[] = $request['text'];
                return Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200);
            },
        ]);

        $botService->sendPaymentInfo('123456');
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString('Golden Shwe Store', $sentMessages[0]);
        $this->assertStringContainsString('KPay Special', $sentMessages[0]);
        $this->assertStringContainsString('09999888777', $sentMessages[0]);
        $this->assertStringContainsString('Thanks!', $sentMessages[0]);

        // B. Cleared message (empty string) - falls back to standard default
        Setting::set('telegram_bot_payment_instructions_msg', '');
        $sentMessages = [];

        $botService->sendPaymentInfo('123456');
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString('ငွေပေးချေရန် နည်းလမ်းများနှင့် အချက်အလက်များ', $sentMessages[0]);
        $this->assertStringContainsString('KPay Special', $sentMessages[0]);
    }
}
