<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Setting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Services\TelegramBotService;

class AdversarialUiAndMarkdownTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramBotService $botService;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botService = app(TelegramBotService::class);

        $this->outlet = Outlet::create([
            'name' => 'Adversarial Test Warehouse',
            'address' => 'Yangon',
            'phone' => '0999999999',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
        Setting::set('telegram_bot_token', '123456:ADVERSARIAL_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');
        Setting::set('telegram_bot_compact_markdown', '1');
    }

    // =========================================================================
    // 1. ADVERSARIAL MARKDOWN ESCAPING TESTS (F08)
    // =========================================================================

    public function test_escape_markdown_with_hostile_strings_and_entities(): void
    {
        // 1. Hostile string specified in requirements: *bold* _italic_ `code` [link](url) \backslash
        $hostile = '*bold* _italic_ `code` [link](url) \\backslash';
        $escaped = $this->botService->escapeMarkdown($hostile);

        $this->assertEquals('\*bold\* \_italic\_ \`code\` \[link](url) \\backslash', $escaped);
        $this->assertStringContainsString('\*bold\*', $escaped);
        $this->assertStringContainsString('\_italic\_', $escaped);
        $this->assertStringContainsString('\`code\`', $escaped);
        $this->assertStringContainsString('\[link](url)', $escaped);

        // 2. Unmatched markdown special characters
        $unmatched = '*unclosed _italic `code [link';
        $escapedUnmatched = $this->botService->escapeMarkdown($unmatched);
        $this->assertEquals('\*unclosed \_italic \`code \[link', $escapedUnmatched);

        // 3. Consecutive & nested markdown characters
        $nested = '***Triple*** ___Under___ ```Block``` [[DoubleBracket]]';
        $escapedNested = $this->botService->escapeMarkdown($nested);
        $this->assertEquals('\*\*\*Triple\*\*\* \_\_\_Under\_\_\_ \`\`\`Block\`\`\` \[\[DoubleBracket]]', $escapedNested);

        // 4. Myanmar script mixed with hostile markdown
        $myanmarHostile = 'မင်္ဂလာပါ *အထူးလျှော့စျေး* _၅၀%_ `RMT-001` [ဝယ်ယူရန်](https://example.com)';
        $escapedMyanmar = $this->botService->escapeMarkdown($myanmarHostile);
        $this->assertEquals('မင်္ဂလာပါ \*အထူးလျှော့စျေး\* \_၅၀%\_ \`RMT-001\` \[ဝယ်ယူရန်](https://example.com)', $escapedMyanmar);

        // 5. Null, empty string, and whitespace
        $this->assertEquals('', $this->botService->escapeMarkdown(null));
        $this->assertEquals('', $this->botService->escapeMarkdown(''));
        $this->assertEquals('   ', $this->botService->escapeMarkdown('   '));
    }

    public function test_send_cart_view_escapes_hostile_product_and_variant_names(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1001]], 200),
        ]);

        $chatId = 880011;

        $hostileProduct = Product::create([
            'name' => '*Hostile* _Product_ `Code` [Link](url) \\ Special',
            'code' => 'HSTL-001',
            'retail_price' => 12000,
            'is_bot_available' => true,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $hostileProduct->id,
            'name' => '*Red* _XL_ `V-Code` [VarLink]',
            'sku' => 'VAR-HSTL-01',
            'selling_price' => 13500,
            'is_active' => true,
        ]);

        // Add to cart with variant
        $this->botService->addToCart($chatId, $hostileProduct, $variant->id, 2);

        $this->botService->sendCartView($chatId, 'Adversary Customer');

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

        // Product name and variant name must be escaped inside markdown bold blocks
        $this->assertStringContainsString('\*Hostile\*', $sentText);
        $this->assertStringContainsString('\_Product\_', $sentText);
        $this->assertStringContainsString('\`Code\`', $sentText);
        $this->assertStringContainsString('\[Link]', $sentText);
        $this->assertStringContainsString('\*Red\*', $sentText);
        $this->assertStringContainsString('\_XL\_', $sentText);
        $this->assertStringContainsString('\`V-Code\`', $sentText);
        $this->assertStringContainsString('\[VarLink]', $sentText);
    }

    public function test_send_catalog_compact_markdown_escapes_hostile_metadata(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1002]], 200),
        ]);

        Setting::set('telegram_bot_catalog_style', 'single_summary');
        Setting::set('telegram_bot_compact_markdown', '1');
        Setting::set('telegram_bot_catalog_show_code', '1');
        Setting::set('telegram_bot_catalog_show_category', '1');
        Setting::set('telegram_bot_catalog_show_brand', '1');
        Setting::set('telegram_bot_catalog_show_variants', '1');
        Setting::set('telegram_bot_catalog_show_details', '1');

        $cat = ProductCategory::create([
            'name' => '*Category* _Under_ [CatLink]',
            'status' => 'active',
        ]);

        $brand = Brand::create([
            'name' => '*Brand* _Luxury_ `B-Code`',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => '*Adversarial* _Phone_ `X` [PhoneLink]',
            'code' => 'CODE`WITH`BACKTICKS*TEST',
            'category_id' => $cat->id,
            'brand_id' => $brand->id,
            'description' => '*Awesome* _Description_ with `special` [chars]',
            'retail_price' => 250000,
            'is_bot_available' => true,
            'is_active' => true,
            'has_variants' => true,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'name' => '*Var1* _Blue_',
            'sku' => 'VAR-TEST-01',
            'selling_price' => 250000,
            'is_active' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $chatId = 880022;
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

        // Title escaped
        $this->assertStringContainsString('\*Adversarial\*', $sentText);
        $this->assertStringContainsString('\_Phone\_', $sentText);
        $this->assertStringContainsString('\[PhoneLink]', $sentText);

        // Code backticks sanitized to prevent markdown code injection
        $this->assertStringNotContainsString('```', $sentText);
        $this->assertStringContainsString('`CODEWITHBACKTICKSTEST`', str_replace('*', '', $sentText));

        // Category & Brand escaped
        $this->assertStringContainsString('📂 \*Category\*', $sentText);
        $this->assertStringContainsString('🏷️ \*Brand\*', $sentText);

        // Variants escaped
        $this->assertStringContainsString('\*Var1\*', $sentText);
        $this->assertStringContainsString('\_Blue\_', $sentText);

        // Description snippet escaped
        $this->assertStringContainsString('\*Awesome\*', $sentText);
        $this->assertStringContainsString('\_Description\_', $sentText);
    }

    // =========================================================================
    // 2. EXTREME CART SIZE CLAMPING TESTS (F08)
    // =========================================================================

    public function test_extreme_cart_size_50_items_strictly_clamped_under_4096_chars(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1003]], 200),
        ]);

        $chatId = 880033;

        // Create 50 distinct items with realistic names
        for ($i = 1; $i <= 50; $i++) {
            $p = Product::create([
                'name' => "Special Retail Myanmar Product SKU Item #{$i} Premium Quality",
                'code' => "SKU-50-{$i}",
                'retail_price' => 5000 + ($i * 100),
                'is_bot_available' => true,
                'is_active' => true,
            ]);
            $this->botService->addToCart($chatId, $p, null, 1);
        }

        $this->botService->sendCartView($chatId, 'Stress Test Customer');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sentPayload = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentPayload = $req->data();
                break;
            }
        }

        $this->assertNotNull($sentPayload);
        $msgText = $sentPayload['text'];

        // Strict Telegram 4096-char ceiling
        $charLength = mb_strlen($msgText, 'UTF-8');
        $this->assertLessThanOrEqual(4096, $charLength, "Message text length ({$charLength}) exceeded Telegram 4096 limit!");

        // Must display omission notice indicating omitted items
        $this->assertStringContainsString('နှင့် အခြားပစ္စည်း', $msgText);
        $this->assertMatchesRegularExpression('/နှင့် အခြားပစ္စည်း \*\d+ မျိုး\*/', $msgText);

        // Grand total must still be present and accurate
        $this->assertStringContainsString('💰 စုစုပေါင်း:', $msgText);
    }

    public function test_extreme_cart_size_with_massive_names_clamped_safely(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1004]], 200),
        ]);

        $chatId = 880034;

        // 35 items with 200-character titles each (7,000 raw chars of title alone)
        for ($i = 1; $i <= 35; $i++) {
            $hugeName = "Ultra-Deluxe Mega-Product " . str_repeat("အလွန်ကောင်းမွန်သောပစ္စည်း-{$i}-", 6);
            $p = Product::create([
                'name' => mb_substr($hugeName, 0, 150),
                'code' => "HUGE-{$i}",
                'retail_price' => 20000,
                'is_bot_available' => true,
                'is_active' => true,
            ]);
            $this->botService->addToCart($chatId, $p, null, 1);
        }

        $this->botService->sendCartView($chatId, 'Long Title Customer');

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sentPayload = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentPayload = $req->data();
                break;
            }
        }

        $this->assertNotNull($sentPayload);
        $msgText = $sentPayload['text'];

        $charLength = mb_strlen($msgText, 'UTF-8');
        $this->assertLessThanOrEqual(4096, $charLength, "Message length ({$charLength}) exceeded 4096 limit under massive titles!");
        $this->assertStringContainsString('နှင့် အခြားပစ္စည်း', $msgText);
    }

    // =========================================================================
    // 3. CALLBACK TOAST LOCALIZATION TESTS (F12)
    // =========================================================================

    public function test_resolve_user_locale_matrix(): void
    {
        // 1. English variants
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'en']));
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'en-US']));
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'EN']));
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'en_GB']));

        // 2. Myanmar variants
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'my']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'my-MM']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'MY']));

        // 3. Unrecognized language codes with default 'my'
        Setting::set('telegram_bot_default_language', 'my');
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'ja']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'ru']));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'fr']));
        $this->assertEquals('my', $this->botService->resolveUserLocale([]));
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => '']));

        // 4. Unrecognized language codes when default is configured to 'en'
        Setting::set('telegram_bot_default_language', 'en');
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'zh']));
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'de']));

        // 5. Cached locale takes priority over from['language_code']
        $chatId = 998877;
        Cache::put("tg_locale_{$chatId}", 'en', 3600);
        $this->assertEquals('en', $this->botService->resolveUserLocale(['language_code' => 'my'], $chatId));

        Cache::put("tg_locale_{$chatId}", 'my', 3600);
        $this->assertEquals('my', $this->botService->resolveUserLocale(['language_code' => 'en'], $chatId));
    }

    public function test_get_localized_toast_dictionary_coverage_and_clamping(): void
    {
        $keys = [
            'cart_added', 'cart_inc', 'cart_dec', 'cart_removed', 'cart_cleared',
            'order_cancelled', 'out_of_stock', 'select_variant', 'select_qty',
            'custom_qty_prompt', 'staff_notified', 'ai_resumed',
            'restock_subscribed', 'restock_already',
        ];

        foreach ($keys as $key) {
            // Test 'en'
            $enToast = $this->botService->getLocalizedToast($key, 'en', ['qty' => 5]);
            $this->assertNotEmpty($enToast, "Toast key '{$key}' returned empty for 'en'");
            $this->assertLessThanOrEqual(100, mb_strlen($enToast, 'UTF-8'), "Toast '{$key}' ('en') exceeded 100 chars");

            // Test 'my'
            $myToast = $this->botService->getLocalizedToast($key, 'my', ['qty' => 5]);
            $this->assertNotEmpty($myToast, "Toast key '{$key}' returned empty for 'my'");
            $this->assertLessThanOrEqual(100, mb_strlen($myToast, 'UTF-8'), "Toast '{$key}' ('my') exceeded 100 chars");

            // Test fallback on unrecognized locale ('de', 'alien', '')
            $fallbackToast = $this->botService->getLocalizedToast($key, 'de', ['qty' => 5]);
            $this->assertNotEmpty($fallbackToast, "Toast key '{$key}' returned empty on unrecognized locale");
            $this->assertEquals($myToast, $fallbackToast, "Toast key '{$key}' did not fall back to 'my' dictionary");
        }

        // Specific Parameter checks
        $enParam = $this->botService->getLocalizedToast('cart_added', 'en', ['qty' => 3]);
        $this->assertStringContainsString('3', $enParam);
        $this->assertEquals('✅ Added 3 item(s) to cart', $enParam);

        $myParam = $this->botService->getLocalizedToast('cart_added', 'my', ['qty' => 3]);
        $this->assertStringContainsString('3', $myParam);
        $this->assertEquals('✅ ခြင်းတောင်းထဲသို့ 3 ခု ထည့်ပြီးပါပြီ', $myParam);

        // Custom setting toast clamped strictly to 100 chars
        $hugeCustomToast = str_repeat('အလွန်ရှည်လျားသော စာတို အသိပေးချက် မက်ဆေ့ခ်ျဖြစ်ပါသည် ', 5); // >150 chars
        Setting::set('telegram_bot_cart_toast_inc', $hugeCustomToast);
        $customInc = $this->botService->getLocalizedToast('cart_inc', 'my');
        $this->assertLessThanOrEqual(100, mb_strlen($customInc, 'UTF-8'));
    }

    public function test_callback_query_delivers_localized_toasts(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $chatId = 880055;
        $prod = Product::create([
            'name' => 'Toast Test Product',
            'code' => 'TST-001',
            'retail_price' => 5000,
            'is_bot_available' => true,
            'is_active' => true,
        ]);
        $this->botService->addToCart($chatId, $prod, null, 1);

        Setting::set('telegram_bot_cart_show_toast', '1');
        Setting::set('telegram_bot_cart_toast_inc', ''); // use dictionary

        // 1. Simulate cart_inc callback from English user
        $updateEnglish = [
            'update_id' => 1001,
            'callback_query' => [
                'id' => 'cb_req_en_123',
                'from' => [
                    'id' => $chatId,
                    'first_name' => 'Alice',
                    'language_code' => 'en',
                ],
                'message' => [
                    'message_id' => 7701,
                    'chat' => ['id' => $chatId],
                ],
                'data' => "cart_inc_p_{$prod->id}",
            ],
        ];

        $this->botService->handleIncomingUpdate($updateEnglish);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        // Verify answerCallbackQuery payload contains English toast with show_alert => false
        $foundAnswer = false;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/answerCallbackQuery')) {
                $payload = $req->data();
                if (($payload['callback_query_id'] ?? '') === 'cb_req_en_123') {
                    $foundAnswer = true;
                    $this->assertEquals('cb_req_en_123', $payload['callback_query_id']);
                    $this->assertEquals('➕ Quantity increased (+1)', $payload['text']);
                    $this->assertFalse($payload['show_alert'] ?? false);
                    $this->assertLessThanOrEqual(100, mb_strlen($payload['text'], 'UTF-8'));
                    break;
                }
            }
        }
        $this->assertTrue($foundAnswer, "Did not find answerCallbackQuery for English user");

        // 2. Simulate cart_inc callback from Myanmar user
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $updateMyanmar = [
            'update_id' => 1002,
            'callback_query' => [
                'id' => 'cb_req_my_456',
                'from' => [
                    'id' => $chatId,
                    'first_name' => 'Ko Kyaw',
                    'language_code' => 'my',
                ],
                'message' => [
                    'message_id' => 7702,
                    'chat' => ['id' => $chatId],
                ],
                'data' => "cart_inc_p_{$prod->id}",
            ],
        ];

        $this->botService->handleIncomingUpdate($updateMyanmar);

        $recordedMy = Http::recorded();
        $foundMyAnswer = false;
        foreach ($recordedMy as [$req, $res]) {
            if (str_contains($req->url(), '/answerCallbackQuery')) {
                $payload = $req->data();
                if (($payload['callback_query_id'] ?? '') === 'cb_req_my_456') {
                    $foundMyAnswer = true;
                    $this->assertEquals('cb_req_my_456', $payload['callback_query_id']);
                    $this->assertEquals('➕ အရေအတွက် တိုးလိုက်ပါသည် (+1)', $payload['text']);
                    $this->assertFalse($payload['show_alert'] ?? false);
                    $this->assertLessThanOrEqual(100, mb_strlen($payload['text'], 'UTF-8'));
                    break;
                }
            }
        }
        $this->assertTrue($foundMyAnswer, "Did not find answerCallbackQuery for Myanmar user");
    }

    // =========================================================================
    // 4. TELEGRAM API BUTTON LIMIT STRESS TEST (<100 BUTTONS) (F08)
    // =========================================================================

    public function test_cart_edit_mode_strictly_caps_button_count_under_100(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1005]], 200),
        ]);

        $chatId = 880066;

        // Populate cart with 50 items
        for ($i = 1; $i <= 50; $i++) {
            $p = Product::create([
                'name' => "Button Limit Stress Item {$i}",
                'code' => "BTN-{$i}",
                'retail_price' => 1000,
                'is_bot_available' => true,
                'is_active' => true,
            ]);
            $this->botService->addToCart($chatId, $p, null, 1);
        }

        // Call sendCartView in isEditMode = true
        $this->botService->sendCartView($chatId, 'Button Tester', null, true);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        $sentReq = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentReq = $req;
                break;
            }
        }

        $this->assertNotNull($sentReq);
        $markup = json_decode($sentReq->data()['reply_markup'] ?? '{}', true);
        $keyboard = $markup['inline_keyboard'] ?? [];

        // Count total buttons across all rows
        $totalButtons = 0;
        foreach ($keyboard as $row) {
            $totalButtons += count($row);
        }

        // Telegram Bot API enforces limit: Inline keyboard must not exceed 100 buttons
        $this->assertLessThan(100, $totalButtons, "Total buttons ({$totalButtons}) reached or exceeded Telegram 100 button ceiling!");

        // Implementation caps editor rows to 20: 20 item rows (up to 4 buttons each = 80) + 1 Done button row = 81 buttons max
        $this->assertLessThanOrEqual(81, $totalButtons, "Total buttons ({$totalButtons}) exceeded 81 capped limit");
        $this->assertLessThanOrEqual(21, count($keyboard), "Total rows exceeded 21 (20 items + 1 Done)");

        // The final row must be the Done button
        $lastRow = end($keyboard);
        $this->assertEquals('cart_done', $lastRow[0]['callback_data']);
    }

    public function test_cart_review_mode_button_count_under_normal_and_extreme_sizes(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1006]], 200),
        ]);

        $chatId = 880067;

        // Populate cart with 100 items
        for ($i = 1; $i <= 100; $i++) {
            $p = Product::create([
                'name' => "Item {$i}",
                'code' => "I-{$i}",
                'retail_price' => 100,
                'is_bot_available' => true,
                'is_active' => true,
            ]);
            $this->botService->addToCart($chatId, $p, null, 1);
        }

        // Review mode (isEditMode = false)
        $this->botService->sendCartView($chatId, 'Review Tester', null, false);

        $recorded = Http::recorded();
        $sentReq = null;
        foreach ($recorded as [$req, $res]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $sentReq = $req;
                break;
            }
        }

        $this->assertNotNull($sentReq);
        $markup = json_decode($sentReq->data()['reply_markup'] ?? '{}', true);
        $keyboard = $markup['inline_keyboard'] ?? [];

        $totalButtons = 0;
        foreach ($keyboard as $row) {
            $totalButtons += count($row);
        }

        // Review mode only has action rows ([Checkout, Edit Cart], [Continue Shopping]) = <= 3 buttons
        $this->assertLessThanOrEqual(5, $totalButtons);
    }
}
