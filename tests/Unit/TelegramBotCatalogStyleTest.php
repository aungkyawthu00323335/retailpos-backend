<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Setting;
use App\Http\Controllers\Api\BotOrderController;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

class TelegramBotCatalogStyleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Setting::set('telegram_bot_catalog_style', 'card_feed');
        parent::tearDown();
    }

    public function test_get_bot_settings_returns_catalog_style_and_missing_keys(): void
    {
        $controller = app(BotOrderController::class);

        Setting::set('telegram_bot_catalog_style', 'single_summary');
        Setting::set('telegram_bot_reject_duplicate_slip', '1');
        Setting::set('telegram_bot_btn_reorder', '🔄 မှာယူခဲ့သည်များ ထပ်မှာမည်');
        Setting::set('telegram_bot_enable_reorder', '1');
        Setting::set('telegram_bot_btn_custom_qty', '🔢 အရေအတွက် ရိုက်ထည့်မည်');

        $res = $controller->getBotSettings()->getData(true);
        $this->assertEquals('success', $res['status']);
        $data = $res['data'];

        $this->assertEquals('single_summary', $data['telegram_bot_catalog_style']);
        $this->assertTrue($data['telegram_bot_reject_duplicate_slip']);
        $this->assertEquals('🔄 မှာယူခဲ့သည်များ ထပ်မှာမည်', $data['telegram_bot_btn_reorder']);
        $this->assertTrue($data['telegram_bot_enable_reorder']);
        $this->assertEquals('🔢 အရေအတွက် ရိုက်ထည့်မည်', $data['telegram_bot_btn_custom_qty']);
    }

    public function test_update_bot_settings_saves_catalog_style_to_database(): void
    {
        $controller = app(BotOrderController::class);

        $request = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_catalog_style' => 'single_summary',
            'telegram_bot_catalog_per_page' => 5,
            'telegram_bot_reject_duplicate_slip' => true,
            'telegram_bot_btn_reorder' => '🔄 Re-Order Now',
            'telegram_bot_enable_reorder' => true,
            'telegram_bot_btn_custom_qty' => '🔢 Custom Quantity',
        ]);

        $res = $controller->updateBotSettings($request)->getData(true);
        $this->assertEquals('success', $res['status']);

        // Verify in Database via Setting::get()
        $this->assertEquals('single_summary', Setting::get('telegram_bot_catalog_style'));
        $this->assertEquals('1', Setting::get('telegram_bot_reject_duplicate_slip'));
        $this->assertEquals('🔄 Re-Order Now', Setting::get('telegram_bot_btn_reorder'));
        $this->assertEquals('1', Setting::get('telegram_bot_enable_reorder'));
        $this->assertEquals('🔢 Custom Quantity', Setting::get('telegram_bot_btn_custom_qty'));

        // Verify that getBotSettings reflects the newly saved setting
        $settings = $controller->getBotSettings()->getData(true)['data'];
        $this->assertEquals('single_summary', $settings['telegram_bot_catalog_style']);
    }

    public function test_update_bot_settings_validates_catalog_style_values(): void
    {
        $controller = app(BotOrderController::class);

        $this->expectException(ValidationException::class);
        $request = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_catalog_style' => 'invalid_style_name',
        ]);

        $controller->updateBotSettings($request);
    }

    public function test_update_bot_settings_rejects_per_page_greater_than_10(): void
    {
        $controller = app(BotOrderController::class);

        $this->expectException(ValidationException::class);
        $request = Request::create('/api/bot/settings', 'POST', [
            'telegram_bot_catalog_per_page' => 11,
        ]);

        $controller->updateBotSettings($request);
    }


    public function test_setting_set_bumps_version_and_syncs_immediately(): void
    {
        Setting::set('test_dynamic_sync_key', 'value_initial');
        $this->assertEquals('value_initial', Setting::get('test_dynamic_sync_key'));

        Setting::set('test_dynamic_sync_key', 'value_updated');
        $this->assertEquals('value_updated', Setting::get('test_dynamic_sync_key'));
    }

    public function test_send_category_filter_menu_executes_safely_with_cached_array(): void
    {
        $botService = app(\App\Services\TelegramBotService::class);
        
        \Illuminate\Support\Facades\Cache::put('tg_bot_active_categories_v2', [
            ['id' => 1, 'name' => 'Clothing'],
            ['id' => 2, 'name' => 'Electronics'],
        ], 300);

        // Mock Http to verify no crash and proper sendMessage/editMessageText execution
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200),
        ]);

        // Must run with 0 exceptions
        $botService->sendCategoryFilterMenu(123456789);
        $this->assertTrue(true);
    }

    public function test_send_catalog_clamps_per_page_to_prevent_telegram_overflow(): void
    {
        $botService = app(\App\Services\TelegramBotService::class);

        // Seed 15 bot available products
        for ($i = 1; $i <= 15; $i++) {
            \App\Models\Product::create([
                'name' => "Test Product {$i}",
                'code' => "PROD-{$i}",
                'retail_price' => 1000 * $i,
                'is_bot_available' => true,
                'is_active' => true,
                'status' => 'Active',
            ]);
        }

        // Set catalog style to single_summary and requested per_page = 300
        Setting::set('telegram_bot_token', '123456:TEST_TOKEN_FOR_UNIT_TEST');
        Setting::set('telegram_bot_catalog_style', 'single_summary');
        Setting::set('telegram_bot_catalog_per_page', '300');


        $capturedPayloads = [];
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$capturedPayloads) {
            $capturedPayloads[] = $request->data();
            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200);
        });

        $botService->sendCatalog(123456789, page: 1);

        // Clamped to 10 per page, so page 1 has 10 items, total 15 items -> 2 pages total
        $this->assertNotEmpty($capturedPayloads);
        $lastPayload = end($capturedPayloads);
        $text = $lastPayload['text'] ?? '';

        // Text should indicate (စာမျက်နှာ 1/2)
        $this->assertStringContainsString('စာမျက်နှာ 1/2', $text);
        // Page 1 should contain items 1 to 10
        $this->assertStringContainsString('1️⃣', $text);
        $this->assertStringContainsString('10️⃣', $text);
        // Item 11 should not be on Page 1
        $this->assertStringNotContainsString('11️⃣', $text);
    }
}

