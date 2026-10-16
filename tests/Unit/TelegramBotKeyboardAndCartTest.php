<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Setting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TelegramBotKeyboardAndCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('telegram_bot_token', '123456:TEST_BOT_TOKEN');
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_enabled', '1');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');
        Setting::set('telegram_bot_cart_header_title', '🛒 ယူထားတဲ့ စာရင်း');
        Setting::set('telegram_bot_cart_total_label', '💰 စုစုပေါင်း:');
        Setting::set('telegram_bot_cart_btn_inc', '➕');
        Setting::set('telegram_bot_cart_btn_dec', '➖');
        Setting::set('telegram_bot_cart_btn_del', '🗑️');
        Setting::set('telegram_bot_cart_btn_done', '✅ Done');
        Setting::set('telegram_bot_cart_btn_checkout_inline', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်');
        Setting::set('telegram_bot_btn_checkout', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်');
        Setting::set('telegram_bot_btn_edit_cart', '✏️ စာရင်း ပြင်မယ်');
        Setting::set('telegram_bot_btn_continue_shopping', 'တခြားပစ္စည်းကြည့်ဦးမယ်');
        Setting::set('telegram_bot_cart_show_header', '1');
        Setting::set('telegram_bot_cart_show_total', '1');
        Setting::set('telegram_bot_cart_show_editor', '1');
        Setting::set('telegram_bot_cart_show_done_btn', '1');
        Setting::set('telegram_bot_cart_show_checkout_btn', '1');
        Setting::set('telegram_bot_cart_show_edit_btn', '1');
        Setting::set('telegram_bot_cart_show_continue_btn', '1');
        Setting::set('telegram_bot_cart_show_toast', '1');
        Setting::set('telegram_bot_cart_toast_inc', 'အရေအတွက် တိုးလိုက်ပါသည် (+1)');
        Setting::set('telegram_bot_cart_toast_dec', 'အရေအတွက် လျှော့ချလိုက်ပါသည် (-1)');
        Setting::set('telegram_bot_cart_toast_del', 'ပစ္စည်းအား ခြင်းတောင်းမှ ဖယ်ရှားလိုက်ပါပြီ');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_get_main_keyboard_returns_exact_2x3_grid(): void
    {
        $botService = app(TelegramBotService::class);
        $keyboard = $botService->getMainKeyboard(12345);

        $this->assertArrayHasKey('keyboard', $keyboard);
        $rows = $keyboard['keyboard'];

        // Assert 3 rows
        $this->assertCount(3, $rows);

        // Row 1: Catalog and Cart (2 buttons)
        $this->assertCount(2, $rows[0]);
        $this->assertEquals('🛍️ ကုန်ပစ္စည်းများ', $rows[0][0]['text']);
        $this->assertStringContainsString('ဝယ်မည့်ပစ္စည်းများ', $rows[0][1]['text']);

        // Row 2: Orders and Order Now (2 buttons)
        $this->assertCount(2, $rows[1]);
        $this->assertEquals('📦 တင်ထားသော Order များ', $rows[1][0]['text']);
        $this->assertEquals('📦 မှာမည့်ပစ္စည်းများ Order တင်မည်', $rows[1][1]['text']);

        // Row 3: Payment and Contact in ONE ROW (2 buttons)
        $this->assertCount(2, $rows[2]);
        $this->assertEquals('ℹ️ ငွေလွှဲရန် အချက်အလက်', $rows[2][0]['text']);
        $this->assertEquals('📞 ဆိုင်နှင့် ဆက်သွယ်ရန်', $rows[2][1]['text']);
    }

    public function test_send_cart_view_matches_image_2_format_and_buttons(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 991]], 200),
        ]);

        $botService = app(TelegramBotService::class);
        $chatId = 778899;

        // Add 2 items to cart
        $p1 = Product::create([
            'name' => 'A & C Skirt',
            'code' => 'SKT-01',
            'retail_price' => 20000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'name' => 'T Shirt',
            'code' => 'TSH-01',
            'retail_price' => 7900.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $p1->id,
            'name' => 'အဖြူ / 0',
            'sku' => 'SKT-01-W-0',
            'selling_price' => 20000.0,
            'is_active' => true,
        ]);

        $botService->addToCart($chatId, $p1, $v1, 4);
        $botService->addToCart($chatId, $p2, null, 1);

        // Display Cart View (Mode 1 / Review Mode)
        $botService->sendCartView($chatId, 'Benjamin');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'No HTTP request was sent to Telegram API');
        $request = $recorded[0][0];
        $data = $request->data();
        $text = $data['text'] ?? '';
        $markup = json_decode($data['reply_markup'] ?? '{}', true);

        // Check header and text matching tree alignment format
        $this->assertStringContainsString('ယူထားတဲ့ စာရင်း', $text);
        $this->assertStringContainsString('A & C Skirt (အဖြူ / 0)', $text);
        $this->assertStringContainsString('4 ခု × 20,000 = *80,000 ကျပ်*', $text);
        $this->assertStringContainsString('T Shirt', $text);
        $this->assertStringContainsString('1 ခု × 7,900 = *7,900 ကျပ်*', $text);
        $this->assertStringContainsString('💰 စုစုပေါင်း: *87,900 ကျပ်*', $text);

        // Check buttons matching Image 2
        $rows = $markup['inline_keyboard'] ?? [];
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Order တင်မည်', $rows[0][0]['text']);
        $this->assertEquals('cart_checkout', $rows[0][0]['callback_data']);
        $this->assertStringContainsString('စာရင်း ပြင်မယ်', $rows[0][1]['text']);
        $this->assertEquals('cart_edit', $rows[0][1]['callback_data']);
        $this->assertStringContainsString('တခြားပစ္စည်းကြည့်ဦးမယ်', $rows[1][0]['text']);
        $this->assertEquals('nav_all_products', $rows[1][0]['callback_data']);
    }

    public function test_custom_cart_settings_and_visibility_toggles(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 995]], 200),
        ]);

        Setting::set('telegram_bot_cart_header_title', '✨ ကျွန်ုပ်၏ ခြင်းတောင်း');
        Setting::set('telegram_bot_cart_total_label', '💵 ကျသင့်ငွေ စုစုပေါင်း:');
        Setting::set('telegram_bot_cart_show_checkout_btn', '0'); // Hide checkout
        Setting::set('telegram_bot_cart_btn_inc', '➕ တိုးမည်');
        Setting::set('telegram_bot_cart_btn_dec', '➖ လျှော့မည်');
        Setting::set('telegram_bot_cart_btn_del', '❌ ဖျက်မည်');
        Setting::set('telegram_bot_cart_btn_done', '👍 အတည်ပြုပြီး');

        $botService = app(TelegramBotService::class);
        $chatId = 889900;

        $p = Product::create([
            'name' => 'Vitamin C Serum',
            'code' => 'VIT-C',
            'retail_price' => 15000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 1);

        // 1. Review Mode: Custom Header and Total, Hidden Checkout Button
        $botService->sendCartView($chatId, 'Benjamin');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        $req1 = $recorded[0][0];
        $text1 = $req1->data()['text'] ?? '';
        $markup1 = json_decode($req1->data()['reply_markup'] ?? '{}', true);

        $this->assertStringContainsString('✨ ကျွန်ုပ်၏ ခြင်းတောင်း', $text1);
        $this->assertStringContainsString('💵 ကျသင့်ငွေ စုစုပေါင်း: *15,000 ကျပ်*', $text1);
        // Checkout button is hidden! Only edit cart and continue shopping remain
        $row1 = $markup1['inline_keyboard'][0] ?? [];
        $this->assertCount(1, $row1);
        $this->assertEquals('cart_edit', $row1[0]['callback_data']);

        // 2. Edit Mode: Custom +, -, Del, Done buttons
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 996]], 200),
        ]);
        $botService->sendCartView($chatId, 'Benjamin', null, true);

        $recorded2 = Http::recorded();
        $this->assertNotEmpty($recorded2);
        $req2 = $recorded2[count($recorded2) - 1][0];
        $markup2 = json_decode($req2->data()['reply_markup'] ?? '{}', true);
        $rows2 = $markup2['inline_keyboard'] ?? [];

        $itemRow = $rows2[0];
        $this->assertEquals('➖ လျှော့မည်', $itemRow[0]['text']);
        $this->assertEquals('➕ တိုးမည်', $itemRow[2]['text']);
        $this->assertEquals('❌ ဖျက်မည်', $itemRow[3]['text']);
        $this->assertEquals('👍 အတည်ပြုပြီး', $rows2[1][0]['text']);
    }

    public function test_cart_inc_dec_returns_in_app_toast_notifications(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 997]], 200),
        ]);

        Setting::set('telegram_bot_cart_show_toast', '1');
        Setting::set('telegram_bot_cart_toast_inc', 'အရေအတွက် တိုးလိုက်ပါသည် (+1)');
        Setting::set('telegram_bot_cart_toast_dec', 'အရေအတွက် လျှော့ချလိုက်ပါသည် (-1)');

        $botService = app(TelegramBotService::class);
        $chatId = 334455;

        $p = Product::create([
            'name' => 'Omega 3',
            'code' => 'OMG-3',
            'retail_price' => 30000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 1);

        // Click [+] button
        $updateInc = [
            'update_id' => 201,
            'callback_query' => [
                'id' => 'cb_inc_123',
                'from' => ['id' => $chatId, 'first_name' => 'Benjamin'],
                'message' => ['message_id' => 501, 'chat' => ['id' => $chatId]],
                'data' => "cart_inc_p_{$p->id}",
            ],
        ];

        $botService->handleIncomingUpdate($updateInc);

        // Verify answerCallbackQuery was sent with toast text
        $answers = collect(Http::recorded())->filter(function ($pair) {
            return str_contains($pair[0]->url(), 'answerCallbackQuery');
        });

        $this->assertNotEmpty($answers);
        $cbData = $answers->first()[0]->data();
        $this->assertEquals('cb_inc_123', $cbData['callback_query_id']);
        $this->assertEquals('အရေအတွက် တိုးလိုက်ပါသည် (+1)', $cbData['text']);
        $this->assertFalse($cbData['show_alert'] ?? false, 'Toast must have show_alert = false for in-app toast');
    }

    public function test_send_cart_view_edit_mode_matches_image_3(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 992]], 200),
        ]);

        $botService = app(TelegramBotService::class);
        $chatId = 778899;

        $p = Product::create([
            'name' => 'T Shirt',
            'code' => 'TSH-01',
            'retail_price' => 7900.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 2);

        // Display Cart View in Edit Mode (Image 3)
        $botService->sendCartView($chatId, 'Benjamin', null, true);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $data = $request->data();
            $markup = json_decode($data['reply_markup'] ?? '{}', true);
            $rows = $markup['inline_keyboard'] ?? [];

            // Row 1: [-] [T Shirt x2] [+] [Trash]
            $itemRow = $rows[0] ?? [];
            $hasDec = isset($itemRow[0]) && $itemRow[0]['text'] === '➖' && str_starts_with($itemRow[0]['callback_data'], 'cart_dec_');
            $hasTitle = isset($itemRow[1]) && str_contains($itemRow[1]['text'], 'T Shirt ×2');
            $hasInc = isset($itemRow[2]) && $itemRow[2]['text'] === '➕' && str_starts_with($itemRow[2]['callback_data'], 'cart_inc_');
            $hasDel = isset($itemRow[3]) && $itemRow[3]['text'] === '🗑️' && str_starts_with($itemRow[3]['callback_data'], 'cart_del_');

            // Bottom Row: [Done]
            $doneRow = $rows[1] ?? [];
            $hasDone = isset($doneRow[0]) && str_contains($doneRow[0]['text'], 'Done') && $doneRow[0]['callback_data'] === 'cart_done';

            return $hasDec && $hasTitle && $hasInc && $hasDel && $hasDone;
        });
    }

    public function test_order_ten_mee_text_message_initiates_direct_checkout_without_cart_review(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 993]], 200),
        ]);

        $botService = app(TelegramBotService::class);
        $chatId = 554411;

        $p = Product::create([
            'name' => 'Sunscreen Cream',
            'code' => 'SUN-01',
            'retail_price' => 12000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 1);

        // Customer sends text "📦 မှာမည့်ပစ္စည်းများ Order တင်မည်"
        $update = [
            'update_id' => 101,
            'message' => [
                'message_id' => 50,
                'chat' => ['id' => $chatId, 'first_name' => 'Benjamin'],
                'from' => ['id' => $chatId, 'first_name' => 'Benjamin'],
                'text' => '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်',
            ],
        ];

        $botService->handleIncomingUpdate($update);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $data = $request->data();
            $text = $data['text'] ?? '';
            $markup = json_decode($data['reply_markup'] ?? '{}', true);

            // Must NOT show cart view 'ယူထားတဲ့ စာရင်း'
            $notCartView = !str_contains($text, 'ယူထားတဲ့ စာရင်း');
            // Must directly initiate checkout with Order summary and ask for phone
            $hasOrderSummary = str_contains($text, 'အော်ဒါ အနှစ်ချုပ်') || str_contains($text, 'Sunscreen Cream');
            $asksPhone = str_contains($text, 'ဖုန်းနံပါတ်');

            // Keyboard must contain request_contact button
            $keyboard = $markup['keyboard'] ?? [];
            $hasSharePhone = isset($keyboard[0][0]['request_contact']) && $keyboard[0][0]['request_contact'] === true;

            return $notCartView && $hasOrderSummary && $asksPhone && $hasSharePhone;
        });

        // Verify session is placed in waiting_phone step
        $session = Cache::get("tg_session_{$chatId}");
        $this->assertNotNull($session);
        $this->assertEquals('waiting_phone', $session['step']);
    }

    public function test_customer_clicks_custom_cart_button_triggers_cart_review(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 994]], 200),
        ]);

        $botService = app(TelegramBotService::class);
        $chatId = 554422;

        $p = Product::create([
            'name' => 'Skincare Serum',
            'code' => 'SER-01',
            'retail_price' => 25000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 1);

        // Customer clicks custom button "🛒မှာမည့်ပစ္စည်းများ" (no space, containing 'မှာ')
        $update = [
            'update_id' => 102,
            'message' => [
                'message_id' => 51,
                'chat' => ['id' => $chatId, 'first_name' => 'Benjamin'],
                'from' => ['id' => $chatId, 'first_name' => 'Benjamin'],
                'text' => '🛒မှာမည့်ပစ္စည်းများ',
            ],
        ];

        $botService->handleIncomingUpdate($update);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        // Find the sendMessage request
        $sendMessageReq = collect($recorded)->first(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq, 'sendMessage was not called');
        $text = $sendMessageReq[0]->data()['text'] ?? '';
        $this->assertStringContainsString('ယူထားတဲ့ စာရင်း', $text);
        $this->assertStringContainsString('Skincare Serum', $text);
    }

    public function test_catalog_command_renders_standard_menu_when_direct_categories_is_disabled(): void
    {
        Http::fake();
        $botService = app(TelegramBotService::class);
        Setting::set('telegram_bot_direct_categories', '0');

        $chatId = 778899;
        $update = [
            'update_id' => 103,
            'message' => [
                'message_id' => 60,
                'chat' => ['id' => $chatId, 'first_name' => 'Ko Aung'],
                'from' => ['id' => $chatId, 'first_name' => 'Ko Aung'],
                'text' => '🛍️ ကုန်ပစ္စည်းများ',
            ],
        ];

        $botService->handleIncomingUpdate($update);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sendMessageReq = collect($recorded)->first(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq, 'sendMessage was not called');
        $data = $sendMessageReq[0]->data();
        $this->assertTrue(
            str_contains($data['text'], 'ကျွန်မတို့ဆိုင်၏ ကုန်ပစ္စည်း ကတ်တလောက်') ||
            str_contains($data['text'], 'ကုန်ပစ္စည်းများ ကြည့်ရှု/မှာယူရန် ရွေးချယ်ပါ')
        );
        
        $replyMarkup = json_decode($data['reply_markup'], true);
        $callbacks = collect($replyMarkup['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
        $this->assertContains('nav_all_products', $callbacks);
        $this->assertContains('nav_categories', $callbacks);
        $this->assertContains('nav_brands', $callbacks);
        $this->assertContains('nav_search', $callbacks);
    }

    public function test_catalog_menu_button_visibility_and_custom_message(): void
    {
        Http::fake();
        $botService = app(TelegramBotService::class);
        Setting::set('telegram_bot_direct_categories', '0');
        Setting::set('telegram_bot_catalog_menu_msg', '🔥 *Special Store Deals* - Welcome {user_name}!');
        Setting::set('telegram_bot_show_catalog_btn_brands', '0');
        Setting::set('telegram_bot_show_catalog_btn_search', '0');
        Setting::set('telegram_bot_btn_all_products', '🎁 All Goodies');

        $chatId = 778899;
        $botService->sendCatalogMenu($chatId);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sendMessageReq = collect($recorded)->last(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq);
        $data = $sendMessageReq[0]->data();
        $this->assertStringContainsString('Special Store Deals', $data['text']);

        $replyMarkup = json_decode($data['reply_markup'], true);
        $flatButtons = collect($replyMarkup['inline_keyboard'])->flatten(1);
        $callbacks = $flatButtons->pluck('callback_data')->all();
        $texts = $flatButtons->pluck('text')->all();

        // Must contain visible buttons
        $this->assertContains('nav_all_products', $callbacks);
        $this->assertContains('nav_categories', $callbacks);
        $this->assertContains('nav_home', $callbacks);
        $this->assertContains('🎁 All Goodies', $texts);

        // Must NOT contain hidden buttons
        $this->assertNotContains('nav_brands', $callbacks);
        $this->assertNotContains('nav_search', $callbacks);
    }

    public function test_catalog_command_renders_categories_directly_when_direct_categories_is_enabled(): void
    {
        Http::fake();
        $botService = app(TelegramBotService::class);
        Setting::set('telegram_bot_direct_categories', '1');

        $cat = ProductCategory::create([
            'name' => 'Fashion & Clothes',
            'is_active' => true,
        ]);

        Product::create([
            'name' => 'Cotton T-Shirt',
            'code' => 'TSHIRT-01',
            'retail_price' => 15000.0,
            'category_id' => $cat->id,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $chatId = 889900;
        $update = [
            'update_id' => 104,
            'message' => [
                'message_id' => 70,
                'chat' => ['id' => $chatId, 'first_name' => 'Ma Thidar'],
                'from' => ['id' => $chatId, 'first_name' => 'Ma Thidar'],
                'text' => '🛍️ ကုန်ပစ္စည်းများ',
            ],
        ];

        $botService->handleIncomingUpdate($update);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sendMessageReq = collect($recorded)->first(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq, 'sendMessage was not called');
        $data = $sendMessageReq[0]->data();
        $this->assertStringContainsString('ကုန်ပစ္စည်း အမျိုးအစားများ (Categories)', $data['text']);
        
        $replyMarkup = json_decode($data['reply_markup'], true);
        $callbacks = collect($replyMarkup['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
        // Top direct buttons
        $this->assertContains('nav_all_products', $callbacks);
        $this->assertContains('nav_search', $callbacks);
        // Category button
        $this->assertContains("f_cat_{$cat->id}", $callbacks);
        // Bottom nav
        $this->assertContains('nav_home', $callbacks);
    }

    public function test_catalog_large_items_per_page_safe_clamping_and_button_guard(): void
    {
        Http::fake();
        $botService = app(TelegramBotService::class);
        Setting::set('telegram_bot_catalog_style', 'single_summary');
        Setting::set('telegram_bot_catalog_per_page', 300); // Merchant configured 300!

        // Create 35 products
        for ($i = 1; $i <= 35; $i++) {
            Product::create([
                'name' => "Product Item #{$i}",
                'code' => "PROD-{$i}",
                'retail_price' => 1000 * $i,
                'is_bot_available' => true,
                'is_active' => true,
            ]);
        }

        $chatId = 998877;
        $botService->sendCatalog($chatId);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sendMessageReq = collect($recorded)->last(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq);
        $data = $sendMessageReq[0]->data();

        // 1. Check text length <= 4096 (Telegram hard limit)
        $this->assertLessThanOrEqual(4096, mb_strlen($data['text'], 'UTF-8'));

        // 2. Check total buttons count <= 100 (Telegram hard limit)
        $replyMarkup = json_decode($data['reply_markup'], true);
        $flatButtons = collect($replyMarkup['inline_keyboard'])->flatten(1);
        $this->assertLessThanOrEqual(95, $flatButtons->count());

        // 3. Check that pagination works smoothly with safe 10 per page (35 total => 4 pages)
        $allText = collect($recorded)
            ->filter(fn($pair) => str_contains($pair[0]->url(), 'sendMessage'))
            ->map(fn($pair) => $pair[0]->data()['text'])
            ->implode("\n");
        $this->assertStringContainsString('1/4', $allText);
    }

    public function test_cart_updated_message_honors_custom_templates_and_placeholders(): void
    {
        $botService = app(TelegramBotService::class);
        $chatId = 884422;

        \App\Models\Setting::set('telegram_bot_cart_updated_header', '🛒 *စိတ်ကြိုက် ပြင်ထားသော ထိပ်စီး*');
        \App\Models\Setting::set('telegram_bot_cart_item_added_tpl', '✨ ထည့်ပြီးပါပြီ: {product_name} x {quantity} {unit}!');
        \App\Models\Setting::set('telegram_bot_cart_items_heading', '📋 *ဝယ်ထားသော ပစ္စည်းများ:*');
        \App\Models\Setting::set('telegram_bot_cart_updated_footer_prompt', 'အမြန်မှာယူရန် အောက်ပါခလုတ်ကို နှိပ်ပါ');

        $p = Product::create([
            'name' => 'သဘာဝ ရှားစောင်းလက်ပတ် ဂျယ်လ်',
            'code' => 'PROD-001',
            'retail_price' => 8500.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $cartRes = $botService->addToCart($chatId, $p, null, 2);

        $message = $botService->formatCartUpdatedMessage($cartRes, $p->name, 2, 'ခု');

        $this->assertStringContainsString('🛒 *စိတ်ကြိုက် ပြင်ထားသော ထိပ်စီး*', $message);
        $this->assertStringContainsString('✨ ထည့်ပြီးပါပြီ: သဘာဝ ရှားစောင်းလက်ပတ် ဂျယ်လ် x 2 ခု!', $message);
        $this->assertStringContainsString('📋 *ဝယ်ထားသော ပစ္စည်းများ:*', $message);
        $this->assertStringContainsString('အမြန်မှာယူရန် အောက်ပါခလုတ်ကို နှိပ်ပါ', $message);
    }

    public function test_cart_updated_buttons_honor_show_hide_switches_and_custom_labels(): void
    {
        Http::fake();
        $botService = app(TelegramBotService::class);
        $chatId = 332211;

        // Custom button labels
        \App\Models\Setting::set('telegram_bot_btn_checkout', 'ငွေရှင်းမည်');
        \App\Models\Setting::set('telegram_bot_btn_cart', 'ခြင်းတောင်းကြည့်မယ်');
        \App\Models\Setting::set('telegram_bot_btn_continue_shopping', 'နောက်ထပ်ဝယ်မယ်');

        // Turn OFF clear cart and main menu buttons
        \App\Models\Setting::set('telegram_bot_show_cart_updated_btn_checkout', '1');
        \App\Models\Setting::set('telegram_bot_show_cart_updated_btn_view', '1');
        \App\Models\Setting::set('telegram_bot_show_cart_updated_btn_continue', '1');
        \App\Models\Setting::set('telegram_bot_show_cart_updated_btn_clear', '0'); // Hidden!
        \App\Models\Setting::set('telegram_bot_show_cart_updated_btn_menu', '0');  // Hidden!

        $p = Product::create([
            'name' => 'Rose Water Mist',
            'code' => 'ROSE-01',
            'retail_price' => 15000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        // Customer triggers quick qty button qty_1_{productId}
        $update = [
            'update_id' => 881,
            'callback_query' => [
                'id' => 'cb_881',
                'from' => ['id' => $chatId, 'first_name' => 'Aung Aung'],
                'message' => [
                    'message_id' => 199,
                    'chat' => ['id' => $chatId],
                ],
                'data' => "qty_1_{$p->id}",
            ],
        ];

        $botService->handleIncomingUpdate($update);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sendMessageReq = collect($recorded)->first(function ($pair) {
            return str_contains($pair[0]->url(), 'sendMessage');
        });

        $this->assertNotNull($sendMessageReq, 'sendMessage was not triggered on quick qty');
        $data = $sendMessageReq[0]->data();
        $replyMarkup = json_decode($data['reply_markup'], true);

        $flatButtons = collect($replyMarkup['inline_keyboard'])->flatten(1);
        $callbacks = $flatButtons->pluck('callback_data')->all();
        $buttonTexts = $flatButtons->pluck('text')->all();

        // 1. Visible buttons should exist
        $this->assertContains('cart_checkout', $callbacks);
        $this->assertContains('cart_view', $callbacks);
        $this->assertContains('nav_all_products', $callbacks);

        // 2. Hidden buttons should NOT exist
        $this->assertNotContains('cart_clear', $callbacks);
        $this->assertNotContains('catalog_main', $callbacks);

        // 3. Custom button texts should be applied
        $this->assertTrue(collect($buttonTexts)->some(fn($t) => str_contains($t, 'ငွေရှင်းမည်')));
        $this->assertTrue(collect($buttonTexts)->some(fn($t) => str_contains($t, 'ခြင်းတောင်းကြည့်မယ်')));
        $this->assertTrue(collect($buttonTexts)->some(fn($t) => str_contains($t, 'နောက်ထပ်ဝယ်မယ်')));
    }

    public function test_send_cart_view_custom_buttons_and_footer_msg(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9991]], 200),
        ]);

        Setting::set('telegram_bot_cart_header_title', '🛍️ လူကြီးမင်း ရွေးထားသော စာရင်း');
        Setting::set('telegram_bot_cart_total_label', '💵 ကျသင့်ငွေ စုစုပေါင်း:');
        Setting::set('telegram_bot_cart_footer_msg', '📌 မှတ်ချက်: ပစ္စည်းရောက်မှ ငွေချေလိုပါက Order တင်မည် ကို နှိပ်ပါ');
        Setting::set('telegram_bot_cart_btn_checkout_inline', '🚀 အော်ဒါတင်တော့မယ်');
        Setting::set('telegram_bot_btn_edit_cart', '✏️ ပြင်ဆင်မယ်');
        Setting::set('telegram_bot_btn_continue_shopping', '🛒 ဆက်ကြည့်မယ်');
        Setting::set('telegram_bot_cart_show_checkout_btn', '1');
        Setting::set('telegram_bot_cart_show_edit_btn', '1');
        Setting::set('telegram_bot_cart_show_continue_btn', '1');

        $botService = app(TelegramBotService::class);
        $chatId = 998877;

        $p = Product::create([
            'name' => 'Skin Cream',
            'code' => 'CRM-01',
            'retail_price' => 12000.0,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $botService->addToCart($chatId, $p, null, 2);

        $botService->sendCartView($chatId, 'Ko Kyaw');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        $data = $recorded[0][0]->data();
        $text = $data['text'] ?? '';
        $markup = json_decode($data['reply_markup'] ?? '{}', true);

        // Verify custom messages and footer
        $this->assertStringContainsString('🛍️ လူကြီးမင်း ရွေးထားသော စာရင်း', $text);
        $this->assertStringContainsString('💵 ကျသင့်ငွေ စုစုပေါင်း: *24,000 ကျပ်*', $text);
        $this->assertStringContainsString('📌 မှတ်ချက်: ပစ္စည်းရောက်မှ ငွေချေလိုပါက Order တင်မည် ကို နှိပ်ပါ', $text);

        // Verify custom 3 buttons
        $rows = $markup['inline_keyboard'] ?? [];
        $this->assertCount(2, $rows);
        $this->assertEquals('🚀 အော်ဒါတင်တော့မယ်', $rows[0][0]['text']);
        $this->assertEquals('cart_checkout', $rows[0][0]['callback_data']);
        $this->assertEquals('✏️ ပြင်ဆင်မယ်', $rows[0][1]['text']);
        $this->assertEquals('cart_edit', $rows[0][1]['callback_data']);
        $this->assertEquals('🛒 ဆက်ကြည့်မယ်', $rows[1][0]['text']);
        $this->assertEquals('nav_all_products', $rows[1][0]['callback_data']);
    }
}


