<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\Customer;
use App\Models\StockBalance;
use App\Models\DeliveryRegion;
use App\Models\DeliveryTownship;
use App\Http\Controllers\Api\BotOrderController;
use App\Services\TelegramBotService;

class TelegramBotQuickCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramBotService $botService;
    protected Outlet $outlet;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botService = app(TelegramBotService::class);

        $this->outlet = Outlet::create([
            'name' => 'Main Warehouse',
            'address' => 'Yangon',
            'phone' => '0911111111',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');

        $this->product = Product::create([
            'name' => 'Royal Myanmar Tea',
            'code' => 'RMT-001',
            'sku' => 'RMT-SKU',
            'retail_price' => 5000,
            'selling_price' => 5000,
            'cost_price' => 3500,
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);
    }

    public function test_quick_cod_orders_immediately_after_address_input(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200),
        ]);

        Setting::set('telegram_bot_checkout_quick_cod', '1');
        Setting::set('telegram_bot_delivery_city_fee', '1500');

        $chatId = 8881234;

        // Cart with 2 items
        $this->botService->addToCart($chatId, $this->product, null, 2);

        // Put session in waiting_address state
        $cartSummary = $this->botService->getCartSummary($chatId);
        $session = [
            'is_cart' => true,
            'items' => array_values($cartSummary['cart']),
            'grand_total' => $cartSummary['grand_total'],
            'total_qty' => $cartSummary['total_qty'],
            'phone' => '099348374837',
            'step' => 'waiting_address',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        // Customer submits address
        $update = [
            'update_id' => 1001,
            'message' => [
                'message_id' => 12,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Ko Aung', 'username' => 'aung_customer'],
                'text' => 'No 45, Insein Road, Yangon',
                'date' => time(),
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        // Assert sale was created immediately with COD and city shipping fee
        $sale = Sale::where('telegram_chat_id', (string)$chatId)->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertEquals('Cash on Delivery', $sale->payment_type);
        $this->assertEquals('Unpaid', $sale->payment_status);
        $this->assertEquals(1500, (float)$sale->shipping);
        $this->assertEquals(11500, (float)$sale->grand_total); // 10000 + 1500
        $this->assertEquals('No 45, Insein Road, Yangon', $sale->shipping_address);
        $this->assertEquals('099348374837', $sale->customer_phone);

        // Assert cart was cleared
        $this->assertEmpty($this->botService->getCart($chatId));
    }

    public function test_skip_township_transitions_to_payment_selection(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200),
        ]);

        Setting::set('telegram_bot_checkout_quick_cod', '0');
        Setting::set('telegram_bot_checkout_skip_township', '1');
        Setting::set('telegram_bot_delivery_city_fee', '2000');

        $chatId = 8885678;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        $cartSummary = $this->botService->getCartSummary($chatId);
        $session = [
            'is_cart' => true,
            'items' => array_values($cartSummary['cart']),
            'grand_total' => $cartSummary['grand_total'],
            'total_qty' => $cartSummary['total_qty'],
            'phone' => '099348374837',
            'step' => 'waiting_address',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $update = [
            'update_id' => 1002,
            'message' => [
                'message_id' => 15,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Daw Mya', 'username' => 'mya_customer'],
                'text' => 'Kamayut, Yangon',
                'date' => time(),
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        // Session should be waiting_payment now with 2000 shipping fee applied
        $updatedSession = Cache::get("tg_session_{$chatId}");
        $this->assertNotNull($updatedSession);
        $this->assertEquals('waiting_payment', $updatedSession['step']);
        $this->assertEquals(2000, (float)$updatedSession['shipping_fee']);
        $this->assertEquals(7000, (float)$updatedSession['grand_total']); // 5000 + 2000

        // No sale created yet since customer will choose COD or KPay
        $this->assertNull(Sale::where('telegram_chat_id', (string)$chatId)->first());
    }

    public function test_confirm_without_slip_finalizes_order_when_slip_not_required(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200),
        ]);

        Setting::set('telegram_bot_require_payment_slip', '0');

        $chatId = 8889999;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        $cartSummary = $this->botService->getCartSummary($chatId);
        $session = [
            'is_cart' => true,
            'items' => array_values($cartSummary['cart']),
            'grand_total' => 5000,
            'total_qty' => 1,
            'phone' => '099348374837',
            'address' => 'Bahan, Yangon',
            'delivery_method' => 'Standard Delivery',
            'shipping_fee' => 0,
            'payment_type' => 'KBZPay',
            'step' => 'waiting_slip',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        // Customer clicks [✅ ငွေလွှဲပြီးပါပြီ (Order အတည်ပြုမည်)]
        $update = [
            'update_id' => 1003,
            'callback_query' => [
                'id' => 'cb_confirm_1',
                'from' => ['id' => $chatId, 'first_name' => 'Ko Aung', 'username' => 'aung_customer'],
                'message' => [
                    'message_id' => 55,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => 'pay_confirm_no_slip',
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        $sale = Sale::where('telegram_chat_id', (string)$chatId)->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertEquals('KBZPay', $sale->payment_type);
        $this->assertEquals('Pending Review', $sale->payment_status);
    }

    // =========================================================================
    // F08: Compact Markdown Layout, Escaping & Clamping Tests
    // =========================================================================

    public function test_escape_markdown_escapes_special_characters(): void
    {
        $this->assertEquals('', $this->botService->escapeMarkdown(null));
        $this->assertEquals('', $this->botService->escapeMarkdown(''));

        $input = 'Special_Edition [Promo] 5* Product with `code`';
        $escaped = $this->botService->escapeMarkdown($input);
        $this->assertEquals('Special\_Edition \[Promo] 5\* Product with \`code\`', $escaped);
    }

    public function test_send_cart_view_clamps_items_when_length_exceeds_threshold(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 991]], 200),
        ]);

        $chatId = 771122;

        // Populate cart with 30 items having long names
        for ($i = 1; $i <= 30; $i++) {
            $p = Product::create([
                'name' => "Premium Luxury Extra Long Title Product Item Number {$i} For Customer Testing Purpose",
                'code' => "PROD-L-{$i}",
                'retail_price' => 15000,
                'is_bot_available' => true,
                'is_active' => true,
            ]);
            $this->botService->addToCart($chatId, $p, null, 1);
        }

        $this->botService->sendCartView($chatId, 'Test Customer');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        // Find sendMessage call
        $sentPayload = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentPayload = $req->data();
                break;
            }
        }

        $this->assertNotNull($sentPayload);
        $msgText = $sentPayload['text'];

        // Must stay safely below Telegram 4096 character limit
        $this->assertLessThanOrEqual(4096, mb_strlen($msgText, 'UTF-8'));
        // Must contain omitted items notice
        $this->assertStringContainsString('နှင့် အခြားပစ္စည်း', $msgText);
    }

    public function test_send_catalog_compact_markdown_renders_two_line_layout_when_enabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 992]], 200),
        ]);

        Setting::set('telegram_bot_catalog_style', 'single_summary');
        Setting::set('telegram_bot_compact_markdown', '1');

        $chatId = 771133;
        $this->botService->sendCatalog($chatId, 1);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sentText = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentText = $req->data()['text'];
                break;
            }
        }

        $this->assertNotNull($sentText);
        // Compact layout contains product name followed by — *5,000 MMK*
        $this->assertStringContainsString('Royal Myanmar Tea* — *5,000 MMK*', $sentText);
        $this->assertStringContainsString('▫️', $sentText);
    }

    public function test_send_product_photo_card_clamps_caption_to_1000_characters(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 993]], 200),
        ]);

        $chatId = 771144;
        $longProduct = Product::create([
            'name' => 'Massive Description Coffee',
            'code' => 'COF-001',
            'retail_price' => 7500,
            'description' => str_repeat('အထူးအရည်အသွေးမြင့် ကော်ဖီစေ့ဖြစ်ပါသည်။ ', 60), // ~1300 chars
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $this->botService->sendProductPhotoCard($chatId, $longProduct);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $caption = null;
        foreach ($recorded as [$req, $res]) {
            $data = $req->data();
            if (isset($data['caption'])) {
                $caption = $data['caption'];
                break;
            } elseif (isset($data['text'])) {
                $caption = $data['text'];
                break;
            }
        }

        $this->assertNotNull($caption);
        $this->assertLessThanOrEqual(1000, mb_strlen($caption, 'UTF-8'));
    }

    // =========================================================================
    // F09: 1-Tap Quick Checkout Flow Tests
    // =========================================================================

    public function test_initiate_cart_checkout_presents_one_tap_contact_keyboard_when_enabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 994]], 200),
        ]);

        Setting::set('telegram_bot_one_tap_checkout', '1');
        $chatId = 772211;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        $this->botService->initiateCartCheckout($chatId, 'Ko Aung');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $payload = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $payload = $req->data();
                break;
            }
        }

        $this->assertNotNull($payload);
        // Progress step indicator for delivery step
        $this->assertStringContainsString('▶ [2. Delivery]', $payload['text']);

        // Keyboard has request_contact and input_field_placeholder
        $markup = json_decode($payload['reply_markup'], true);
        $this->assertNotNull($markup);
        $this->assertArrayHasKey('keyboard', $markup);
        $this->assertTrue($markup['keyboard'][0][0]['request_contact']);
        $this->assertArrayHasKey('input_field_placeholder', $markup);
    }

    public function test_initiate_cart_checkout_hides_one_tap_keyboard_when_disabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 995]], 200),
        ]);

        Setting::set('telegram_bot_one_tap_checkout', '0');
        $chatId = 772222;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        $this->botService->initiateCartCheckout($chatId, 'Ko Aung');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $payload = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $payload = $req->data();
                break;
            }
        }

        $this->assertNotNull($payload);
        $markup = json_decode($payload['reply_markup'], true);
        $this->assertNotNull($markup);

        // request_contact must NOT be present
        $hasRequestContact = false;
        foreach ($markup['keyboard'] as $row) {
            foreach ($row as $btn) {
                if (!empty($btn['request_contact'])) {
                    $hasRequestContact = true;
                }
            }
        }
        $this->assertFalse($hasRequestContact);
    }

    public function test_get_address_keyboard_includes_location_button_when_enabled(): void
    {
        Setting::set('telegram_bot_one_tap_checkout', '1');
        $kb = $this->botService->getAddressKeyboard();
        $this->assertArrayHasKey('keyboard', $kb);
        $this->assertTrue($kb['keyboard'][0][0]['request_location']);
        $this->assertArrayHasKey('input_field_placeholder', $kb);

        Setting::set('telegram_bot_one_tap_checkout', '0');
        $kbDisabled = $this->botService->getAddressKeyboard();
        $hasLoc = false;
        foreach ($kbDisabled['keyboard'] as $row) {
            foreach ($row as $btn) {
                if (!empty($btn['request_location'])) {
                    $hasLoc = true;
                }
            }
        }
        $this->assertFalse($hasLoc);
    }

    public function test_handle_contact_message_sets_phone_and_cleans_up_keyboard(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 996]], 200),
        ]);

        $chatId = 772233;
        $session = [
            'is_cart' => true,
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Tea', 'price' => 5000, 'quantity' => 1, 'subtotal' => 5000]],
            'step' => 'waiting_phone',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $update = [
            'update_id' => 1010,
            'message' => [
                'message_id' => 45,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Mg Mg', 'username' => 'mgmg'],
                'contact' => [
                    'phone_number' => '+959123456789',
                    'first_name' => 'Mg Mg',
                ],
                'date' => time(),
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        $updatedSession = Cache::get("tg_session_{$chatId}");
        $this->assertNotNull($updatedSession);
        $this->assertEquals('09123456789', $updatedSession['phone']);
        $this->assertEquals('waiting_address', $updatedSession['step']);
    }

    public function test_handle_contact_message_out_of_checkout_flow_saves_customer_record(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 997]], 200),
        ]);

        $chatId = 772244;
        Cache::forget("tg_session_{$chatId}");

        $update = [
            'update_id' => 1011,
            'message' => [
                'message_id' => 46,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Daw Nu', 'username' => 'dawnu'],
                'contact' => [
                    'phone_number' => '09778899001',
                    'first_name' => 'Daw Nu',
                ],
                'date' => time(),
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        $customer = Customer::where('phone', '09778899001')->first();
        $this->assertNotNull($customer);
    }

    // =========================================================================
    // F10: Smart Phone & Township Extraction Tests
    // =========================================================================

    public function test_extract_phone_number_from_text_converts_myanmar_digits_and_normalizes(): void
    {
        // Myanmar digits with dashes and noise
        $this->assertEquals('09123456789', $this->botService->extractPhoneNumberFromText('ဖုန်း ၀၉-၁၂၃၄၅၆၇၈၉ ပါရှင်။ တိုက် ၄ အခန်း ၂'));

        // Myanmar letter Wa mistyped as 0
        $this->assertEquals('09987654321', $this->botService->extractPhoneNumberFromText('ဝ၉၉၈၇၆၅၄၃၂၁ သို့ ဆက်ပေးပါ'));

        // Standard English format with prefix
        $this->assertEquals('09777888999', $this->botService->extractPhoneNumberFromText('+95 9 777 888 999'));

        // Digits with 959...
        $this->assertEquals('09443322110', $this->botService->extractPhoneNumberFromText('Ph: 959443322110'));

        // Non-phone text
        $this->assertNull($this->botService->extractPhoneNumberFromText('ရန်ကုန်မြို့ လှည်းတန်းလမ်းဆုံအနီး'));
    }

    public function test_extract_township_from_text_identifies_townships_and_calculates_fees(): void
    {
        $region = DeliveryRegion::create([
            'name' => 'Yangon',
            'name_mm' => 'ရန်ကုန်',
            'is_active' => true,
        ]);

        $kamayut = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Kamayut',
            'name_mm' => 'ကမာရွတ်',
            'delivery_fee' => 2000,
            'free_delivery_threshold' => 30000,
            'is_active' => true,
        ]);

        $bahan = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Bahan',
            'name_mm' => 'ဗဟန်း',
            'delivery_fee' => 2500,
            'is_active' => true,
        ]);

        // 1. Match Kamayut via Burmese alias
        $res1 = $this->botService->extractTownshipFromText('အမှတ် ၁၂၊ လှည်းတန်းလမ်း၊ ကမာရွတ်မြို့နယ်၊ ရန်ကုန်', 10000);
        $this->assertNotNull($res1);
        $this->assertEquals($kamayut->id, $res1['township_id']);
        $this->assertEquals(2000.0, (float)$res1['delivery_fee']);
        $this->assertFalse($res1['is_free']);

        // 2. Free Delivery threshold calculation
        $res2 = $this->botService->extractTownshipFromText('ကမာရွတ် လှည်းတန်း', 35000);
        $this->assertNotNull($res2);
        $this->assertEquals(0.0, (float)$res2['delivery_fee']);
        $this->assertTrue($res2['is_free']);

        // 3. Destination priority: segments like "ပို့ရမည့်လိပ်စာ: [X]"
        $res3 = $this->botService->extractTownshipFromText('မူလနေရပ် မရမ်းကုန်း၊ ပို့ရမည့်လိပ်စာ: ဗဟန်း၊ ဆရာစံလမ်း', 10000);
        $this->assertNotNull($res3);
        $this->assertEquals($bahan->id, $res3['township_id']);

        // 4. Unknown township fallback to city fee
        Setting::set('telegram_bot_delivery_city_fee', '1800');
        $res4 = $this->botService->extractTownshipFromText('ပြင်ဦးလွင် ကန်တော်ကြီးအနီး', 10000);
        $this->assertNotNull($res4);
        $this->assertNull($res4['township_id']);
        $this->assertEquals(1800.0, (float)$res4['delivery_fee']);
    }

    // =========================================================================
    // F11: Multi-Step Progress Indicator Tests
    // =========================================================================

    public function test_render_step_indicator_formats_current_step_and_arrows(): void
    {
        Setting::set('telegram_bot_show_step_indicator', '1');

        $step1 = $this->botService->renderStepIndicator(1);
        $this->assertEquals('▶ [1. Cart] ➔ [2. Delivery] ➔ [3. Payment] ➔ [4. Receipt]', $step1);

        $step2 = $this->botService->renderStepIndicator(2);
        $this->assertEquals('[1. Cart] ➔ ▶ [2. Delivery] ➔ [3. Payment] ➔ [4. Receipt]', $step2);

        $step3 = $this->botService->renderStepIndicator(3);
        $this->assertEquals('[1. Cart] ➔ [2. Delivery] ➔ ▶ [3. Payment] ➔ [4. Receipt]', $step3);

        $step4 = $this->botService->renderStepIndicator(4);
        $this->assertEquals('[1. Cart] ➔ [2. Delivery] ➔ [3. Payment] ➔ ▶ [4. Receipt]', $step4);

        // Clamping bounds
        $step0 = $this->botService->renderStepIndicator(0);
        $this->assertEquals($step1, $step0);

        $step99 = $this->botService->renderStepIndicator(99);
        $this->assertEquals($step4, $step99);

        // Disabled toggle
        Setting::set('telegram_bot_show_step_indicator', '0');
        $this->assertEquals('', $this->botService->renderStepIndicator(2));
    }

    // =========================================================================
    // F12: Instant Localized Callback Toasts Tests
    // =========================================================================

    public function test_resolve_user_locale_detects_language_code_and_cache(): void
    {
        Setting::set('telegram_bot_default_language', 'my');

        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'en-US']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'my-MM']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'ja']));

        Setting::set('telegram_bot_default_language', 'en');
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'ja']));

        // Cache overrides language_code
        Cache::put('tg_locale_999888', 'en', now()->addMinutes(10));
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'my'], 999888));
    }

    public function test_get_localized_toast_returns_bilingual_strings_under_100_chars(): void
    {
        $keys = [
            'cart_added', 'cart_inc', 'cart_dec', 'cart_removed', 'cart_cleared',
            'order_cancelled', 'out_of_stock', 'select_variant', 'select_qty',
            'custom_qty_prompt', 'staff_notified', 'ai_resumed', 'restock_subscribed', 'restock_already'
        ];

        foreach ($keys as $key) {
            $myToast = $this->botService->getLocalizedToast($key, 'my', ['qty' => 3]);
            $this->assertNotEmpty($myToast);
            $this->assertLessThanOrEqual(100, mb_strlen($myToast, 'UTF-8'));

            $enToast = $this->botService->getLocalizedToast($key, 'en', ['qty' => 3]);
            $this->assertNotEmpty($enToast);
            $this->assertLessThanOrEqual(100, mb_strlen($enToast, 'UTF-8'));
        }

        // Verify parameter replacement
        $enAdded = $this->botService->getLocalizedToast('cart_added', 'en', ['qty' => 5]);
        $this->assertStringContainsString('5', $enAdded);
    }

    public function test_callback_query_delivers_localized_toast_without_message_jump(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $chatId = 773311;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        // English user clicks cart_clear
        $update = [
            'update_id' => 1020,
            'callback_query' => [
                'id' => 'cb_clear_en',
                'chat_instance' => 'ci_1',
                'from' => ['id' => $chatId, 'first_name' => 'John', 'language_code' => 'en'],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => 'cart_clear',
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $toastText = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/answerCallbackQuery')) {
                $toastText = $req->data()['text'] ?? null;
                break;
            }
        }

        $this->assertNotNull($toastText);
        $this->assertEquals('🗑️ Cart cleared', $toastText);
    }

    // =========================================================================
    // BotOrderController Settings Registration Tests
    // =========================================================================

    public function test_bot_settings_registers_and_validates_milestone_2_keys(): void
    {
        $controller = app(BotOrderController::class);

        // Test GET settings returns M2 keys
        Setting::set('telegram_bot_compact_markdown', '1');
        Setting::set('telegram_bot_one_tap_checkout', '1');
        Setting::set('telegram_bot_auto_township_extract', '1');
        Setting::set('telegram_bot_show_step_indicator', '1');
        Setting::set('telegram_bot_default_language', 'my');

        $getRes = $controller->getBotSettings()->getData(true);
        $this->assertEquals('success', $getRes['status']);
        $data = $getRes['data'];

        $this->assertTrue($data['telegram_bot_compact_markdown']);
        $this->assertTrue($data['telegram_bot_one_tap_checkout']);
        $this->assertTrue($data['telegram_bot_auto_township_extract']);
        $this->assertTrue($data['telegram_bot_show_step_indicator']);
        $this->assertEquals('my', $data['telegram_bot_default_language']);

        // Test POST settings saves M2 keys to database
        $postReq = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_compact_markdown' => false,
            'telegram_bot_one_tap_checkout' => false,
            'telegram_bot_auto_township_extract' => false,
            'telegram_bot_show_step_indicator' => false,
            'telegram_bot_default_language' => 'en',
        ]);

        $updateRes = $controller->updateBotSettings($postReq)->getData(true);
        $this->assertEquals('success', $updateRes['status']);

        $this->assertEquals('0', Setting::get('telegram_bot_compact_markdown'));
        $this->assertEquals('0', Setting::get('telegram_bot_one_tap_checkout'));
        $this->assertEquals('0', Setting::get('telegram_bot_auto_township_extract'));
        $this->assertEquals('0', Setting::get('telegram_bot_show_step_indicator'));
        $this->assertEquals('en', Setting::get('telegram_bot_default_language'));
    }
}
