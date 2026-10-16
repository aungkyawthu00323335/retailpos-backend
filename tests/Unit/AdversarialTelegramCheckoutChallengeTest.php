<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\BotOrderController;
use App\Models\Customer;
use App\Models\DeliveryRegion;
use App\Models\DeliveryTownship;
use App\Models\Product;
use App\Models\Setting;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdversarialTelegramCheckoutChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramBotService $botService;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->botService = app(TelegramBotService::class);

        $outlet = \App\Models\Outlet::create([
            'name' => 'Main Warehouse',
            'address' => 'Yangon',
            'phone' => '0911111111',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$outlet->id);
        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_allow_out_of_stock_orders', '1');

        $this->product = Product::create([
            'name' => 'Premium Jasmine Rice',
            'code' => 'PJR-001',
            'sku' => 'PJR-SKU',
            'retail_price' => 35000,
            'selling_price' => 35000,
            'cost_price' => 25000,
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        \App\Models\StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);
    }

    // =========================================================================
    // SECTION 1: Phone Extraction Adversarial Stress Testing (F10)
    // =========================================================================

    public function test_phone_extraction_with_noisy_burmese_text_and_house_numbers(): void
    {
        // 1. House, street, room numbers mixed before phone
        $text1 = 'အမှတ် ၄၅/ခ၊ လမ်း ၅၀၊ အခန်း ၁၀၁၊ ဆက်သွယ်ရန်ဖုန်း ၀၉-၁၂၃၄၅၆၇၈၉ ပါရှင်။';
        $this->assertEquals('09123456789', $this->botService->extractPhoneNumberFromText($text1));

        // 2. Room number has 09 or 9 right next to Burmese text
        $text2 = 'တိုက် ၉၊ အခန်း ၀၉၊ ဖုန်း 09988776655 သို့ ပို့ပေးပါ။';
        $this->assertEquals('09988776655', $this->botService->extractPhoneNumberFromText($text2));

        // 3. English prefixes and room notation
        $text3 = 'Floor 4, Room 901, contact: +959777112233 delivery ASAP';
        $this->assertEquals('09777112233', $this->botService->extractPhoneNumberFromText($text3));

        // 4. Dashed and spaced Myanmar digits (standard prefix 09 with spaced payload)
        $text4 = 'လိပ်စာ အမှတ် (၁၂)၊ ဖုန်း ၀၉ - ၇ ၈ ၉ - ၀ ၁ ၂ ၃ ၄ ၅';
        $extracted4 = $this->botService->extractPhoneNumberFromText($text4);
        $this->assertEquals('09789012345', $extracted4);
    }

    public function test_phone_extraction_burmese_letter_wa_distinction_and_variations(): void
    {
        // 1. Burmese letter Wa (U+101D) at the very start of phone number
        $text1 = "ဝ၉၂၅၀၀၁၂၃၄၅ သို့ ဆက်ပေးပါ";
        $this->assertEquals('09250012345', $this->botService->extractPhoneNumberFromText($text1));

        // 2. Burmese letter Wa inside the digits (e.g. 09-45 Wa 123456)
        $text2 = "၀၉-၄၅ဝ၁၂၃၄၅၆";
        $this->assertEquals('09450123456', $this->botService->extractPhoneNumberFromText($text2));

        // 3. Myanmar names containing legitimate letter Wa (ဝေဝေ, မဝင်းဝင်း) MUST NOT break phone extraction
        $text3 = "မဝင်းဝင်းဆီ ပို့ပေးပါ ဖုန်း ၀၉-၉၇၁၂၃၄၅၆၇";
        $this->assertEquals('09971234567', $this->botService->extractPhoneNumberFromText($text3));

        $text4 = "ဝေဝေ (တိုက် ၃) ၀၉၄၄၁၁၂၂၃၃၄";
        $this->assertEquals('09441122334', $this->botService->extractPhoneNumberFromText($text4));
    }

    public function test_phone_extraction_international_prefixes_and_fallback_formats(): void
    {
        // 1. +95 9 format
        $this->assertEquals('09791112223', $this->botService->extractPhoneNumberFromText('+95 9 791 112 223'));

        // 2. 959 without plus
        $this->assertEquals('09791112223', $this->botService->extractPhoneNumberFromText('Ph 959791112223'));

        // 3. Fallback pure digit string with dot or slash
        $this->assertEquals('09791112223', $this->botService->extractPhoneNumberFromText('09.791.112.223'));

        // 4. Parentheses
        $this->assertEquals('09791112223', $this->botService->extractPhoneNumberFromText('(09) 791-112-223'));
    }

    public function test_phone_extraction_rejects_non_phone_inputs_and_extreme_lengths(): void
    {
        // 1. Empty / Whitespace
        $this->assertNull($this->botService->extractPhoneNumberFromText(''));
        $this->assertNull($this->botService->extractPhoneNumberFromText("   \t\n  "));

        // 2. Only house and street numbers (too short)
        $this->assertNull($this->botService->extractPhoneNumberFromText('အမှတ် ၁၂၃၊ လမ်း ၅၀၊ အခန်း ၄'));

        // 3. Six digit postal or landline number without mobile prefix
        $this->assertNull($this->botService->extractPhoneNumberFromText('123456'));

        // 4. Random letters with no digits
        $this->assertNull($this->botService->extractPhoneNumberFromText('ရန်ကုန်မြို့ လှည်းတန်းလမ်းဆုံအနီး မာလာဆောင်ရှေ့'));
    }

    // =========================================================================
    // SECTION 2: Township Extraction Adversarial Stress Testing (F10)
    // =========================================================================

    public function test_township_extraction_conflicting_origin_and_destination_mentions(): void
    {
        $region = DeliveryRegion::create(['name' => 'Yangon', 'name_mm' => 'ရန်ကုန်', 'is_active' => true]);

        $kamayut = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Kamayut',
            'name_mm' => 'ကမာရွတ်',
            'delivery_fee' => 2000,
            'is_active' => true,
        ]);

        $bahan = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Bahan',
            'name_mm' => 'ဗဟန်း',
            'delivery_fee' => 2500,
            'is_active' => true,
        ]);

        $sanchaung = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Sanchaung',
            'name_mm' => 'စမ်းချောင်း',
            'delivery_fee' => 2200,
            'is_active' => true,
        ]);

        // Case 1: Burmese "ပို့ရန်:" destination prefix should beat earlier origin mention
        $text1 = 'မူလနေရပ် စမ်းချောင်း၊ ပို့ရန်: ကမာရွတ် လှည်းတန်းလမ်း';
        $res1 = $this->botService->extractTownshipFromText($text1, 10000);
        $this->assertNotNull($res1);
        $this->assertEquals($kamayut->id, $res1['township_id']);

        // Case 2: Burmese "ပို့ရမည့်လိပ်စာ:" destination prefix
        $text2 = 'ပစ္စည်းယူရန် ဗဟန်း၊ ပို့ရမည့်လိပ်စာ: စမ်းချောင်း၊ ရှင်စောပုလမ်း';
        $res2 = $this->botService->extractTownshipFromText($text2, 10000);
        $this->assertNotNull($res2);
        $this->assertEquals($sanchaung->id, $res2['township_id']);

        // Case 3: English "Delivery to:" destination prefix
        $text3 = 'Sender in Bahan, Delivery to: Kamayut Center';
        $res3 = $this->botService->extractTownshipFromText($text3, 10000);
        $this->assertNotNull($res3);
        $this->assertEquals($kamayut->id, $res3['township_id']);
    }

    public function test_township_extraction_greedy_longest_match_and_substring_disambiguation(): void
    {
        $region = DeliveryRegion::create(['name' => 'Yangon', 'name_mm' => 'ရန်ကုန်', 'is_active' => true]);

        $dagon = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Dagon',
            'name_mm' => 'ဒဂုံ',
            'delivery_fee' => 2000,
            'is_active' => true,
        ]);

        $southDagon = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'South Dagon',
            'name_mm' => 'တောင်ဒဂုံ',
            'delivery_fee' => 3500,
            'is_active' => true,
        ]);

        $northDagon = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'North Dagon',
            'name_mm' => 'မြောက်ဒဂုံ',
            'delivery_fee' => 3200,
            'is_active' => true,
        ]);

        $hlaing = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Hlaing',
            'name_mm' => 'လှိုင်',
            'delivery_fee' => 2000,
            'is_active' => true,
        ]);

        $hlaingtharyar = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Hlaingtharyar',
            'name_mm' => 'လှိုင်သာယာ',
            'delivery_fee' => 4000,
            'is_active' => true,
        ]);

        // 1. "တောင်ဒဂုံ" must match South Dagon (id), NOT general Dagon
        $resSouth = $this->botService->extractTownshipFromText('အမှတ် ၇၈၊ တောင်ဒဂုံ ၅၆ ရပ်ကွက်', 5000);
        $this->assertEquals($southDagon->id, $resSouth['township_id']);

        // 2. "North Dagon" in English must match North Dagon, NOT Dagon
        $resNorth = $this->botService->extractTownshipFromText('No 12, north dagon township', 5000);
        $this->assertEquals($northDagon->id, $resNorth['township_id']);

        // 3. General "ဒဂုံ" alone must match Dagon
        $resDagon = $this->botService->extractTownshipFromText('ဒဂုံ မြို့မဈေးအနီး', 5000);
        $this->assertEquals($dagon->id, $resDagon['township_id']);

        // 4. "လှိုင်သာယာ" must match Hlaingtharyar, NOT Hlaing
        $resHty = $this->botService->extractTownshipFromText('လှိုင်သာယာ စက်မှုဇုန်', 5000);
        $this->assertEquals($hlaingtharyar->id, $resHty['township_id']);

        // 5. "လှိုင်" alone must match Hlaing
        $resHlaing = $this->botService->extractTownshipFromText('လှိုင် ဘူတာရုံလမ်း', 5000);
        $this->assertEquals($hlaing->id, $resHlaing['township_id']);
    }

    public function test_township_extraction_case_insensitivity_and_colloquial_aliases(): void
    {
        $region = DeliveryRegion::create(['name' => 'Yangon', 'name_mm' => 'ရန်ကုန်', 'is_active' => true]);

        $bahan = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Bahan',
            'name_mm' => 'ဗဟန်း',
            'delivery_fee' => 2500,
            'is_active' => true,
        ]);

        $tamwe = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Tamwe',
            'name_mm' => 'တာမွေ',
            'delivery_fee' => 2400,
            'is_active' => true,
        ]);

        // 1. Mixed casing: "bAhAn", "BAHAN"
        $resBahanUpper = $this->botService->extractTownshipFromText('DELIVERY TO: BAHAN', 5000);
        $this->assertEquals($bahan->id, $resBahanUpper['township_id']);

        $resBahanMixed = $this->botService->extractTownshipFromText('address in BaHaN road', 5000);
        $this->assertEquals($bahan->id, $resBahanMixed['township_id']);

        // 2. Colloquial alias: "ဆရာစံ" -> Bahan
        $resSayasan = $this->botService->extractTownshipFromText('ဆရာစံလမ်းသွယ်၊ အမှတ် ၄', 5000);
        $this->assertEquals($bahan->id, $resSayasan['township_id']);

        // 3. Colloquial alias: "ကျောက်မြောင်း" -> Tamwe
        $resKyauk = $this->botService->extractTownshipFromText('ကျောက်မြောင်း ဈေးနားမှာပါ', 5000);
        $this->assertEquals($tamwe->id, $resKyauk['township_id']);
    }

    public function test_township_extraction_unmapped_and_empty_database_fallback(): void
    {
        Setting::set('telegram_bot_delivery_city_fee', '3500');

        // 1. Text with totally unmapped township
        $resUnknown = $this->botService->extractTownshipFromText('မန္တလေးမြို့ ပြင်ဦးလွင် ကန်တော်ကြီးအနီး', 10000);
        $this->assertNotNull($resUnknown);
        $this->assertNull($resUnknown['township_id']);
        $this->assertEquals(3500.0, (float)$resUnknown['delivery_fee']);
        $this->assertEquals('Standard City Delivery', $resUnknown['display_name']);

        // 2. Empty database table (no DeliveryTownship records)
        DeliveryTownship::query()->delete();
        $resEmptyTable = $this->botService->extractTownshipFromText('ကမာရွတ် လှည်းတန်း', 10000);
        $this->assertNotNull($resEmptyTable);
        $this->assertNull($resEmptyTable['township_id']);
        $this->assertEquals(3500.0, (float)$resEmptyTable['delivery_fee']);
    }

    public function test_township_extraction_free_delivery_threshold_edge_values(): void
    {
        $region = DeliveryRegion::create(['name' => 'Yangon', 'name_mm' => 'ရန်ကုန်', 'is_active' => true]);
        $kamayut = DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Kamayut',
            'name_mm' => 'ကမာရွတ်',
            'delivery_fee' => 2500,
            'free_delivery_threshold' => 50000,
            'is_active' => true,
        ]);

        // Just below threshold (49,999) -> delivery fee applies
        $resBelow = $this->botService->extractTownshipFromText('ကမာရွတ်', 49999.0);
        $this->assertEquals(2500.0, (float)$resBelow['delivery_fee']);
        $this->assertFalse($resBelow['is_free']);

        // Exactly at threshold (50,000) -> free delivery
        $resExact = $this->botService->extractTownshipFromText('ကမာရွတ်', 50000.0);
        $this->assertEquals(0.0, (float)$resExact['delivery_fee']);
        $this->assertTrue($resExact['is_free']);

        // Above threshold (50,001) -> free delivery
        $resAbove = $this->botService->extractTownshipFromText('ကမာရွတ်', 50001.0);
        $this->assertEquals(0.0, (float)$resAbove['delivery_fee']);
        $this->assertTrue($resAbove['is_free']);
    }

    // =========================================================================
    // SECTION 3: Multi-Step Progress Indicator Boundary Stress (F11)
    // =========================================================================

    public function test_step_indicator_extreme_boundary_clamping(): void
    {
        Setting::set('telegram_bot_show_step_indicator', '1');

        $step1 = '▶ [1. Cart] ➔ [2. Delivery] ➔ [3. Payment] ➔ [4. Receipt]';
        $step4 = '[1. Cart] ➔ [2. Delivery] ➔ [3. Payment] ➔ ▶ [4. Receipt]';

        // Extreme negative numbers clamp to Step 1
        $this->assertEquals($step1, $this->botService->renderStepIndicator(-9999));
        $this->assertEquals($step1, $this->botService->renderStepIndicator(-1));
        $this->assertEquals($step1, $this->botService->renderStepIndicator(0));

        // Normal steps 1 to 4
        $this->assertEquals($step1, $this->botService->renderStepIndicator(1));
        $this->assertEquals('[1. Cart] ➔ ▶ [2. Delivery] ➔ [3. Payment] ➔ [4. Receipt]', $this->botService->renderStepIndicator(2));
        $this->assertEquals('[1. Cart] ➔ [2. Delivery] ➔ ▶ [3. Payment] ➔ [4. Receipt]', $this->botService->renderStepIndicator(3));
        $this->assertEquals($step4, $this->botService->renderStepIndicator(4));

        // Extreme positive numbers clamp to Step 4
        $this->assertEquals($step4, $this->botService->renderStepIndicator(5));
        $this->assertEquals($step4, $this->botService->renderStepIndicator(100));
        $this->assertEquals($step4, $this->botService->renderStepIndicator(999999));

        // Verify active indicator count invariant: exactly ONE '▶' marker in any rendered step
        for ($i = -5; $i <= 10; $i++) {
            $rendered = $this->botService->renderStepIndicator($i);
            $this->assertEquals(1, substr_count($rendered, '▶'), "Failed on step {$i}");
            $this->assertEquals(3, substr_count($rendered, ' ➔ '), "Arrows missing on step {$i}");
        }
    }

    public function test_step_indicator_cleanly_disables_when_setting_is_off(): void
    {
        Setting::set('telegram_bot_show_step_indicator', '0');

        $this->assertEquals('', $this->botService->renderStepIndicator(1));
        $this->assertEquals('', $this->botService->renderStepIndicator(2));
        $this->assertEquals('', $this->botService->renderStepIndicator(3));
        $this->assertEquals('', $this->botService->renderStepIndicator(4));

        // Re-enable and verify it returns header
        Setting::set('telegram_bot_show_step_indicator', '1');
        $this->assertNotEmpty($this->botService->renderStepIndicator(2));
    }

    // =========================================================================
    // SECTION 4: 1-Tap Checkout & Settings Dynamic Toggling (F09)
    // =========================================================================

    public function test_one_tap_checkout_dynamic_toggle_and_contact_sharing_state_transition(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 888]], 200),
        ]);

        $chatId = 889900;
        $this->botService->addToCart($chatId, $this->product, null, 1);

        // State 1: Enabled ('1') -> request_contact button MUST be present
        Setting::set('telegram_bot_one_tap_checkout', '1');
        $this->botService->initiateCartCheckout($chatId, 'Ko Thant');

        $recorded1 = Http::recorded();
        $this->assertNotEmpty($recorded1);
        $payload1 = $recorded1[count($recorded1) - 1][0]->data();
        $markup1 = json_decode($payload1['reply_markup'], true);
        $this->assertArrayHasKey('keyboard', $markup1);
        $this->assertTrue($markup1['keyboard'][0][0]['request_contact']);

        // State 2: Toggle to Disabled ('0') -> request_contact button MUST NOT be present
        Setting::set('telegram_bot_one_tap_checkout', '0');
        $this->botService->initiateCartCheckout($chatId, 'Ko Thant');

        $recorded2 = Http::recorded();
        $payload2 = $recorded2[count($recorded2) - 1][0]->data();
        $markup2 = json_decode($payload2['reply_markup'], true);
        $hasRequestContact = false;
        foreach ($markup2['keyboard'] as $row) {
            foreach ($row as $btn) {
                if (!empty($btn['request_contact'])) {
                    $hasRequestContact = true;
                }
            }
        }
        $this->assertFalse($hasRequestContact);

        // State 3: Address keyboard toggle respects one_tap_checkout and location mode
        Setting::set('telegram_bot_one_tap_checkout', '1');
        Setting::set('telegram_bot_location_input_mode', 'both_button_and_text');
        $kbEnabled = $this->botService->getAddressKeyboard();
        $this->assertTrue($kbEnabled['keyboard'][0][0]['request_location']);

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

        // State 4: Handle contact update transitions step from waiting_phone to waiting_address
        $session = [
            'is_cart' => true,
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Rice', 'price' => 35000, 'quantity' => 1, 'subtotal' => 35000]],
            'step' => 'waiting_phone',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $update = [
            'update_id' => 9999,
            'message' => [
                'message_id' => 101,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Ko Thant'],
                'contact' => [
                    'phone_number' => '+959450011223',
                    'first_name' => 'Ko Thant',
                ],
                'date' => time(),
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        $savedSession = Cache::get("tg_session_{$chatId}");
        $this->assertEquals('09450011223', $savedSession['phone']);
        $this->assertEquals('waiting_address', $savedSession['step']);
    }

    public function test_bot_order_controller_api_round_trip_for_checkout_and_stepper_settings(): void
    {
        $controller = app(BotOrderController::class);

        // 1. Initial State via API
        $req1 = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_one_tap_checkout' => true,
            'telegram_bot_show_step_indicator' => true,
            'telegram_bot_auto_township_extract' => true,
            'telegram_bot_default_language' => 'my',
        ]);
        $res1 = $controller->updateBotSettings($req1)->getData(true);
        $this->assertEquals('success', $res1['status']);
        $this->assertEquals('1', Setting::get('telegram_bot_one_tap_checkout'));
        $this->assertEquals('1', Setting::get('telegram_bot_show_step_indicator'));

        // 2. Read back via GET
        $getRes = $controller->getBotSettings()->getData(true);
        $this->assertTrue($getRes['data']['telegram_bot_one_tap_checkout']);
        $this->assertTrue($getRes['data']['telegram_bot_show_step_indicator']);

        // 3. Toggle off via API
        $req2 = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_one_tap_checkout' => false,
            'telegram_bot_show_step_indicator' => false,
        ]);
        $res2 = $controller->updateBotSettings($req2)->getData(true);
        $this->assertEquals('success', $res2['status']);
        $this->assertEquals('0', Setting::get('telegram_bot_one_tap_checkout'));
        $this->assertEquals('0', Setting::get('telegram_bot_show_step_indicator'));

        // 4. Verify effect on service immediately
        $this->assertEquals('', $this->botService->renderStepIndicator(2));
        $kb = $this->botService->getAddressKeyboard();
        $this->assertArrayNotHasKey('request_location', $kb['keyboard'][0][0]);
    }
}
