<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\DeliveryTownship;
use App\Models\DeliveryRegion;
use App\Models\Sale;
use App\Models\BotChatMessage;
use App\Services\TelegramAiAgentService;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BotOrderController;

class TelegramAiAutonomousOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_enabled', '1');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_parse_llm_json_output_extracts_cart_and_order_actions(): void
    {
        $service = app(TelegramAiAgentService::class);

        $json = json_encode([
            'reply' => 'ဟုတ်ကဲ့ပါ မမိုးသော်ရှင်။ Bella Velvet Matte Lipstick Shade 01 (၂ ခု - ၃၀,၀၀၀ ကျပ်) ကို ခြင်းတောင်းထဲ ထည့်ပေးထားပါပြီရှင်။',
            'recommended_product_ids' => [225],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => 225, 'variant_id' => 143, 'quantity' => 2]
                ]
            ],
            'order_action' => [
                'type' => 'create_order',
                'customer_name' => 'မိုးသော်',
                'phone' => '09777777554',
                'delivery_type' => 'city',
                'township_name' => 'လှိုင်',
                'address' => 'အမှတ် ၄၀၊ စံပယ်လမ်း',
                'payment_method' => 'Cash on Delivery',
            ]
        ], JSON_UNESCAPED_UNICODE);

        $parsed = $service->parseLlmJsonOutput($json);

        $this->assertNotNull($parsed);
        $this->assertEquals('add_to_cart', $parsed['cart_action']['type']);
        $this->assertCount(1, $parsed['cart_action']['items']);
        $this->assertEquals(225, $parsed['cart_action']['items'][0]['product_id']);
        $this->assertEquals(143, $parsed['cart_action']['items'][0]['variant_id']);
        $this->assertEquals(2.0, $parsed['cart_action']['items'][0]['quantity']);

        $this->assertEquals('create_order', $parsed['order_action']['type']);
        $this->assertEquals('မိုးသော်', $parsed['order_action']['customer_name']);
        $this->assertEquals('09777777554', $parsed['order_action']['phone']);
        $this->assertEquals('လှိုင်', $parsed['order_action']['township_name']);
        $this->assertEquals('Cash on Delivery', $parsed['order_action']['payment_method']);
    }

    public function test_parse_llm_json_output_handles_markdown_and_fallbacks(): void
    {
        $service = app(TelegramAiAgentService::class);

        $rawMarkdown = "```json\n" . json_encode([
            'reply' => 'ခြင်းတောင်းထဲ ထည့်ပေးလိုက်ပါပြီရှင်။',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => 10, 'quantity' => 1]
                ]
            ]
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        $parsed = $service->parseLlmJsonOutput($rawMarkdown);
        $this->assertNotNull($parsed);
        $this->assertEquals('add_to_cart', $parsed['cart_action']['type']);
        $this->assertEquals(10, $parsed['cart_action']['items'][0]['product_id']);
        $this->assertEquals('none', $parsed['order_action']['type']);
    }

    public function test_rag_context_includes_active_delivery_townships(): void
    {
        $service = app(TelegramAiAgentService::class);

        $region = DeliveryRegion::create([
            'name' => 'Yangon',
            'name_mm' => 'ရန်ကုန်',
            'code' => 'YGN',
            'is_active' => true,
        ]);

        $township = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Hlaing',
            'name_mm' => 'လှိုင်',
            'code' => 'HLG',
            'delivery_fee' => 2500.0,
            'free_delivery_threshold' => 50000.0,
            'is_cod_available' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $context = $service->buildRagContext('လှိုင်မြို့နယ် ပို့ပေးပါ', 12345, 'မိုးသော်');

        $this->assertArrayHasKey('delivery', $context);
        $this->assertArrayHasKey('townships', $context['delivery']);
        $townships = $context['delivery']['townships'];

        $this->assertNotEmpty($townships);
        $found = collect($townships)->firstWhere('code', 'HLG');
        $this->assertNotNull($found);
        $this->assertEquals('လှိုင်', $found['name_mm']);
        $this->assertEquals(2500.0, $found['delivery_fee']);
    }

    public function test_autonomous_action_auto_adds_to_cart(): void
    {
        $botService = app(TelegramBotService::class);

        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-1']);
        Setting::set('telegram_bot_warehouse_id', $outlet->id);
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');

        $product = Product::create([
            'name' => 'Bella Velvet Matte Lipstick Shade 01',
            'code' => 'LIP-01',
            'cost_price' => 8000.0,
            'retail_price' => 15000.0,
            'stock_quantity' => 10,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $chatId = 987654321;
        $aiRes = [
            'reply' => 'Bella Lipstick Shade 01 (၂ ခု) ကို ခြင်းတောင်းထဲ ထည့်ပေးထားပါပြီရှင်။',
            'recommended_product_ids' => [$product->id],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 2]
                ]
            ],
            'order_action' => ['type' => 'none'],
        ];

        $botService->executeAiAutonomousActions($chatId, 'မိုးသော်', 'နခမ်းနီ ၂ ခု ဝယ်မယ်', '@moethaw', $aiRes);

        $cart = $botService->getCart($chatId);
        $this->assertNotEmpty($cart);
        $this->assertArrayHasKey("p_{$product->id}", $cart);
        $this->assertEquals(2.0, $cart["p_{$product->id}"]['quantity']);
        $this->assertEquals(30000.0, $cart["p_{$product->id}"]['subtotal']);
    }

    public function test_autonomous_action_creates_pos_order_zero_click(): void
    {
        $botService = app(TelegramBotService::class);

        $outlet = Outlet::create(['name' => 'Main Store', 'code' => 'STORE-1']);
        Setting::set('telegram_bot_warehouse_id', $outlet->id);
        Setting::set('telegram_bot_auto_approve', '1');
        Setting::set('telegram_bot_delivery_city_fee', '2000');

        $region = DeliveryRegion::create([
            'name' => 'Yangon',
            'name_mm' => 'ရန်ကုန်',
            'code' => 'YGN',
            'is_active' => true,
        ]);

        $township = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Hlaing',
            'name_mm' => 'လှိုင်',
            'code' => 'HLG',
            'delivery_fee' => 2000.0,
            'free_delivery_threshold' => 0.0,
            'is_cod_available' => true,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Bella Velvet Matte Lipstick Shade 01',
            'code' => 'LIP-01',
            'cost_price' => 8000.0,
            'retail_price' => 15000.0,
            'stock_quantity' => 10,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $chatId = 88812345;

        // Customer says natural text with Myanmar numerals
        $aiRes = [
            'reply' => 'ဟုတ်ကဲ့ပါ မမိုးသော်ရှင်။ Bella Velvet Matte Lipstick Shade 01 (၂ ခု) ကို လှိုင်မြို့နယ်သို့ အိမ်ရောက်ငွေချေဖြင့် အော်ဒါတင်ပေးလိုက်ပါပြီရှင်။',
            'recommended_product_ids' => [$product->id],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 2]
                ]
            ],
            'order_action' => [
                'type' => 'create_order',
                'customer_name' => 'မိုးသော်',
                'phone' => '၀၉၇၇၇၇၇၇၅၅၄', // Myanmar numerals test
                'delivery_type' => 'city',
                'township_name' => 'လှိုင်',
                'address' => 'အမှတ် ၄၀၊ စံပယ်လမ်း၊ လှိုင်',
                'payment_method' => 'Cash on Delivery',
            ],
        ];

        $botService->executeAiAutonomousActions(
            $chatId,
            'မိုးသော်',
            'နခမ်းနီ Shade 01 ၂ ခု ဝယ်မယ် လှိုင်မြို့နယ် ၀၉၇၇၇၇၇၇၅၅၄ COD',
            '@moethaw',
            $aiRes
        );

        // Verify POS Sale record was created directly in Database
        $sale = Sale::where('telegram_chat_id', (string)$chatId)->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertEquals('telegram_bot', $sale->order_source);
        $this->assertEquals('09777777554', $sale->customer_phone);
        $this->assertEquals(30000.0, (float)$sale->subtotal);
        $this->assertEquals(2000.0, (float)$sale->shipping);
        $this->assertEquals(32000.0, (float)$sale->grand_total);
        $this->assertEquals('Cash on Delivery', $sale->payment_type);
        $this->assertStringContainsString('[⚡ AI Autonomous Zero-Click Order]', $sale->notes);

        // Verify Cart was cleared after autonomous order completion
        $cart = $botService->getCart($chatId);
        $this->assertEmpty($cart);
    }

    public function test_detect_buy_intent_and_product_fallback(): void
    {
        $botService = app(TelegramBotService::class);

        $product = Product::create([
            'name' => 'Bella Compact Two-Way Waterproof Powder Cake',
            'code' => 'BOT-047',
            'cost_price' => 10000.0,
            'retail_price' => 18500.0,
            'stock_quantity' => 20,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $detected = $botService->detectBuyIntentAndProduct('Bella Compact Two-Way အဲ့တာလေး ယူမယ်နော်', 99999);
        $this->assertNotNull($detected);
        $this->assertEquals($product->id, $detected['product']->id);
        $this->assertEquals(1, $detected['quantity']);
    }

    public function test_detect_delivery_info_from_text(): void
    {
        $botService = app(TelegramBotService::class);

        $region = DeliveryRegion::create(['name' => 'Mon', 'code' => 'MN', 'is_active' => true]);
        DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Theinzayat',
            'name_mm' => 'သိမ်ဇရပ်',
            'code' => 'TZ',
            'delivery_fee' => 1000.0,
            'is_active' => true,
        ]);

        $info = $botService->detectDeliveryInfoFromText('မွန်ပြည်နယ် သိမ်ဇရပ် လေးလမ်းပါ ဖုန်း 09444386801 ပါ');
        $this->assertEquals('09444386801', $info['phone']);
        $this->assertEquals('သိမ်ဇရပ်', $info['township']);
    }

    public function test_anti_hallucination_gatekeeper_suppresses_false_confirmation(): void
    {
        $botService = app(TelegramBotService::class);

        $aiRes = [
            'reply' => 'ဟုတ်ကဲ့ပါရှင့်။ အော်ဒါလေးကို အောင်မြင်စွာ တင်ပေးလိုက်ပါပြီရှင့်။',
            'recommended_product_ids' => [],
            'cart_action' => ['type' => 'none', 'items' => []],
            'order_action' => ['type' => 'none'],
        ];

        // Customer sends text with no product ever selected and no cart
        $out = $botService->executeAiAutonomousActions(
            1234567,
            'Test Customer',
            'ဟဲလို အော်ဒါတင်ပေးပါ',
            'testcust',
            $aiRes
        );

        // Verify that false confirmation is suppressed!
        $this->assertStringNotContainsString('အောင်မြင်စွာ တင်ပေးလိုက်ပါပြီ', $out['reply']);
        $this->assertStringContainsString('ပို့ဆောင်ရမည့် မြို့နယ်၊ လိပ်စာ နှင့် ဖုန်းနံပါတ်လေးကို ပြောပြပေးပါဦးရှင့်', $out['reply']);

        // Verify no sale was created in DB
        $sale = Sale::where('telegram_chat_id', '1234567')->first();
        $this->assertNull($sale);

        // Case 2: Customer provided phone and address but no item in cart and no context
        $out2 = $botService->executeAiAutonomousActions(
            1234568,
            'Phone Customer',
            'သိမ်ဇရပ် ၄လမ်း ဖုန်း 09444386801 ပါ',
            'phonecust',
            $aiRes
        );
        $this->assertStringNotContainsString('အောင်မြင်စွာ တင်ပေးလိုက်ပါပြီ', $out2['reply']);
        $this->assertStringContainsString('မှာယူလိုသည့် ကုန်ပစ္စည်းကို အတည်ပြုရန် လိုအပ်နေပါသဖြင့်', $out2['reply']);
    }

    public function test_send_product_card_item_with_buttons_false_sends_photo_without_buttons(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
        ]);

        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_ai_show_photos', '1');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');
        $botService = app(TelegramBotService::class);

        $product = Product::create([
            'name' => 'Smooth Velvet Matte Lipstick',
            'code' => 'LIP-101',
            'retail_price' => 12000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/lip.jpg',
        ]);

        $chatId = 112233;

        // 1. When withButtons is false
        $sentWithoutButtons = $botService->sendProductCardItem($chatId, $product, 1, false);
        $this->assertTrue($sentWithoutButtons);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if (!str_contains($request->url(), '/sendPhoto')) {
                return false;
            }
            $data = $request->data();
            $caption = $data['caption'] ?? '';
            $hasGuide = str_contains($caption, '💡 မှာယူလိုပါက \'ဒီဟာ ယူမယ်\' (သို့မဟုတ်) အရောင်/ဆိုဒ်လေး ပြောပြပေးပါရှင်။');
            $noMarkup = !isset($data['reply_markup']);
            return $hasGuide && $noMarkup;
        });

        // 2. When withButtons is true
        $sentWithButtons = $botService->sendProductCardItem($chatId, $product, 1, true);
        $this->assertTrue($sentWithButtons);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($product) {
            if (!str_contains($request->url(), '/sendPhoto')) {
                return false;
            }
            $data = $request->data();
            $hasMarkup = isset($data['reply_markup']) && str_contains($data['reply_markup'], "buy_{$product->id}");
            return $hasMarkup;
        });
    }

    public function test_hybrid_mode_triggers_photo_sending_for_recommended_products_when_enabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 102]], 200),
        ]);

        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_show_photos', '1');

        $botService = app(TelegramBotService::class);

        $p1 = Product::create([
            'name' => 'Skin Clarifying Cleanser',
            'code' => 'CLN-01',
            'retail_price' => 18000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/cln.jpg',
        ]);

        $p2 = Product::create([
            'name' => 'Hydrating Water Gel Cream',
            'code' => 'CRM-02',
            'retail_price' => 25000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/crm.jpg',
        ]);

        $chatId = 554433;
        $aiRes = [
            'reply' => 'ဒီမျက်နှာသစ်ဆေးနဲ့ ခရင်မ်လေးတွေ အဆင်ပြေပါတယ်ရှင်။',
            'recommended_product_ids' => [$p1->id, $p2->id],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => ['type' => 'none', 'items' => []],
            'order_action' => ['type' => 'none'],
        ];

        $botService->executeAiAutonomousActions(
            $chatId,
            'Su Su',
            'အသားဖြူ မျက်နှာသစ်ဆေး ဘာတွေရှိလဲ',
            'susu',
            $aiRes
        );

        // Verify photo cards were sent for both recommended products with reply_markup in hybrid mode
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($p1) {
            return str_contains($request->url(), '/sendPhoto') 
                && str_contains($request->data()['caption'] ?? '', $p1->name)
                && isset($request->data()['reply_markup']);
        });

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($p2) {
            return str_contains($request->url(), '/sendPhoto') 
                && str_contains($request->data()['caption'] ?? '', $p2->name)
                && isset($request->data()['reply_markup']);
        });
    }

    public function test_hybrid_mode_does_not_send_photos_when_setting_disabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 103]], 200),
        ]);

        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_show_photos', '0');

        $botService = app(TelegramBotService::class);

        $product = Product::create([
            'name' => 'Anti Acne Serum',
            'code' => 'SRM-03',
            'retail_price' => 22000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/serum.jpg',
        ]);

        $chatId = 665544;
        $aiRes = [
            'reply' => 'ဝက်ခြံသက်သာစေမည့် serum လေး ရှိပါတယ်ရှင်။',
            'recommended_product_ids' => [$product->id],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => ['type' => 'none', 'items' => []],
            'order_action' => ['type' => 'none'],
        ];

        $botService->executeAiAutonomousActions(
            $chatId,
            'Zaw Zaw',
            'ဝက်ခြံ serum ရှိလား',
            'zawzaw',
            $aiRes
        );

        // Verify reply message was sent via sendMessage
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/sendMessage')
                && str_contains($request->data()['text'] ?? '', 'ဝက်ခြံသက်သာစေမည့် serum လေး ရှိပါတယ်ရှင်။');
        });

        // Verify NO sendPhoto calls were made
        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/sendPhoto');
        });
    }

    public function test_bot_order_controller_get_and_update_ai_show_photos_setting(): void
    {
        $controller = app(BotOrderController::class);

        Setting::set('telegram_bot_ai_show_photos', '1');
        $settingsRes = $controller->getBotSettings();
        $data = $settingsRes->getData(true)['data'];
        $this->assertArrayHasKey('telegram_bot_ai_show_photos', $data);
        $this->assertEquals('1', $data['telegram_bot_ai_show_photos']);

        $request = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_ai_show_photos' => '0',
        ]);
        $updateRes = $controller->updateBotSettings($request);
        $this->assertEquals(200, $updateRes->getStatusCode());
        $this->assertEquals('0', Setting::get('telegram_bot_ai_show_photos'));
    }

    public function test_send_product_card_item_with_buttons_false_when_no_image_sends_text_message_without_buttons(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 104]], 200),
        ]);

        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        $botService = app(TelegramBotService::class);

        $productWithoutImage = Product::create([
            'name' => 'Rose Water Facial Toner',
            'code' => 'TNR-01',
            'retail_price' => 15000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => null,
        ]);

        $chatId = 998877;
        $sent = $botService->sendProductCardItem($chatId, $productWithoutImage, 1, false);
        $this->assertTrue($sent);

        // Verify sendMessage was called (not sendPhoto) without reply_markup and has conversational guidance
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($productWithoutImage) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }
            $text = $request->data()['text'] ?? '';
            $hasName = str_contains($text, $productWithoutImage->name);
            $hasGuide = str_contains($text, '💡 မှာယူလိုပါက \'ဒီဟာ ယူမယ်\' (သို့မဟုတ်) အရောင်/ဆိုဒ်လေး ပြောပြပေးပါရှင်။');
            $noMarkup = !isset($request->data()['reply_markup']);
            return $hasName && $hasGuide && $noMarkup;
        });

        // Verify sendPhoto was NOT called
        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/sendPhoto');
        });
    }

    public function test_hybrid_mode_skips_inactive_products_even_if_recommended(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 105]], 200),
        ]);

        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_show_photos', '1');

        $botService = app(TelegramBotService::class);

        $activeProduct = Product::create([
            'name' => 'Active Glow Serum',
            'code' => 'ACT-01',
            'retail_price' => 30000.0,
            'is_bot_available' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/act.jpg',
        ]);

        $inactiveProduct = Product::create([
            'name' => 'Discontinued Night Cream',
            'code' => 'DISC-01',
            'retail_price' => 20000.0,
            'is_bot_available' => true,
            'is_active' => false,
            'status' => 'Inactive',
            'image_url' => 'https://example.com/disc.jpg',
        ]);

        $chatId = 887766;
        $aiRes = [
            'reply' => 'Glow serum လေး ရွေးချယ်ပေးထားပါတယ်ရှင်။',
            'recommended_product_ids' => [$activeProduct->id, $inactiveProduct->id],
            'needs_human' => false,
            'suggested_action' => 'none',
            'cart_action' => ['type' => 'none', 'items' => []],
            'order_action' => ['type' => 'none'],
        ];

        $botService->executeAiAutonomousActions(
            $chatId,
            'May Myat',
            'serum နဲ့ cream ဘာရှိလဲ',
            'maymyat',
            $aiRes
        );

        // Active product photo card MUST be sent with reply_markup in hybrid mode
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($activeProduct) {
            return str_contains($request->url(), '/sendPhoto') 
                && str_contains($request->data()['caption'] ?? '', $activeProduct->name)
                && isset($request->data()['reply_markup']);
        });

        // Inactive product photo card MUST NOT be sent
        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) use ($inactiveProduct) {
            return str_contains($request->url(), '/sendPhoto') 
                && str_contains($request->data()['caption'] ?? '', $inactiveProduct->name);
        });
    }

    public function test_bot_order_controller_accepts_boolean_and_integer_ai_show_photos_setting(): void
    {
        $controller = app(BotOrderController::class);

        // 1. Boolean false => stored as '0'
        $reqBoolFalse = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_ai_show_photos' => false,
        ]);
        $resBoolFalse = $controller->updateBotSettings($reqBoolFalse);
        $this->assertEquals(200, $resBoolFalse->getStatusCode());
        $this->assertEquals('0', Setting::get('telegram_bot_ai_show_photos'));

        // 2. Boolean true => stored as '1'
        $reqBoolTrue = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_ai_show_photos' => true,
        ]);
        $resBoolTrue = $controller->updateBotSettings($reqBoolTrue);
        $this->assertEquals(200, $resBoolTrue->getStatusCode());
        $this->assertEquals('1', Setting::get('telegram_bot_ai_show_photos'));

        // 3. Integer 0 => stored as '0'
        $reqIntZero = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_ai_show_photos' => 0,
        ]);
        $resIntZero = $controller->updateBotSettings($reqIntZero);
        $this->assertEquals(200, $resIntZero->getStatusCode());
        $this->assertEquals('0', Setting::get('telegram_bot_ai_show_photos'));
    }
}
