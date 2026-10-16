<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Setting;
use App\Services\TelegramAiAgentService;
use App\Http\Controllers\Api\BotOrderController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use Illuminate\Foundation\Testing\RefreshDatabase;

class TelegramBotOperatingModesTest extends TestCase
{
    use RefreshDatabase;
    protected function tearDown(): void
    {
        // Reset to clean default state
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_enabled', '1');
        parent::tearDown();
    }

    public function test_ai_agent_service_respects_operating_modes(): void
    {
        $service = app(TelegramAiAgentService::class);

        // Standard Mode
        Setting::set('telegram_bot_mode', 'standard');
        Setting::set('telegram_bot_ai_enabled', '0');
        $this->assertEquals('standard', $service->getOperatingMode());
        $this->assertFalse($service->isEnabled());

        // Hybrid Mode
        Setting::set('telegram_bot_mode', 'hybrid');
        Setting::set('telegram_bot_ai_enabled', '1');
        $this->assertEquals('hybrid', $service->getOperatingMode());
        $this->assertTrue($service->isEnabled());

        // Legacy 'ai_only' auto-migrates to hybrid
        Setting::set('telegram_bot_mode', 'ai_only');
        Setting::set('telegram_bot_ai_enabled', '1');
        $this->assertEquals('hybrid', $service->getOperatingMode());
        $this->assertTrue($service->isEnabled());
    }

    public function test_legacy_fallback_when_mode_setting_missing(): void
    {
        $service = app(TelegramAiAgentService::class);
        $controller = app(BotOrderController::class);

        Setting::forget('telegram_bot_mode');

        // When legacy ai_enabled is '0', mode should resolve to 'standard'
        Setting::set('telegram_bot_ai_enabled', '0');
        $this->assertEquals('standard', $service->getOperatingMode());
        $this->assertFalse($service->isEnabled());
        $settings = $controller->getBotSettings()->getData(true)['data'];
        $this->assertEquals('standard', $settings['telegram_bot_mode']);
        $this->assertFalse($settings['telegram_bot_ai_enabled']);

        // When legacy ai_enabled is '1', mode should resolve to 'hybrid'
        Setting::set('telegram_bot_ai_enabled', '1');
        $this->assertEquals('hybrid', $service->getOperatingMode());
        $this->assertTrue($service->isEnabled());
        $settings = $controller->getBotSettings()->getData(true)['data'];
        $this->assertEquals('hybrid', $settings['telegram_bot_mode']);
        $this->assertTrue($settings['telegram_bot_ai_enabled']);
    }

    public function test_update_bot_settings_saves_and_synchronizes_mode(): void
    {
        $controller = app(BotOrderController::class);

        // 1. Switch to standard
        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_mode' => 'standard',
        ]);
        $res = $controller->updateBotSettings($req);
        $data = $res->getData(true)['data'];
        $this->assertEquals('standard', $data['telegram_bot_mode']);
        $this->assertFalse($data['telegram_bot_ai_enabled']);
        $this->assertEquals('standard', Setting::get('telegram_bot_mode'));
        $this->assertEquals('0', Setting::get('telegram_bot_ai_enabled'));

        // 2. Switch to hybrid
        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_mode' => 'hybrid',
        ]);
        $res = $controller->updateBotSettings($req);
        $data = $res->getData(true)['data'];
        $this->assertEquals('hybrid', $data['telegram_bot_mode']);
        $this->assertTrue($data['telegram_bot_ai_enabled']);
        $this->assertEquals('hybrid', Setting::get('telegram_bot_mode'));
        $this->assertEquals('1', Setting::get('telegram_bot_ai_enabled'));

        // 3. Submitting legacy ai_only normalizes to hybrid
        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_mode' => 'ai_only',
        ]);
        $res = $controller->updateBotSettings($req);
        $data = $res->getData(true)['data'];
        $this->assertEquals('hybrid', $data['telegram_bot_mode']);
        $this->assertTrue($data['telegram_bot_ai_enabled']);
        $this->assertEquals('hybrid', Setting::get('telegram_bot_mode'));
        $this->assertEquals('1', Setting::get('telegram_bot_ai_enabled'));

        // 4. Backward compatibility: update legacy telegram_bot_ai_enabled
        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_ai_enabled' => false,
        ]);
        $res = $controller->updateBotSettings($req);
        $data = $res->getData(true)['data'];
        $this->assertEquals('standard', $data['telegram_bot_mode']);
        $this->assertFalse($data['telegram_bot_ai_enabled']);

        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_ai_enabled' => true,
        ]);
        $res = $controller->updateBotSettings($req);
        $data = $res->getData(true)['data'];
        $this->assertEquals('hybrid', $data['telegram_bot_mode']);
        $this->assertTrue($data['telegram_bot_ai_enabled']);
    }

    public function test_invalid_mode_validation_fails(): void
    {
        $this->expectException(ValidationException::class);
        $controller = app(BotOrderController::class);

        $req = Request::create('/api/bot-settings', 'POST', [
            'telegram_bot_mode' => 'invalid_super_bot',
        ]);
        $controller->updateBotSettings($req);
    }

    public function test_ai_autonomous_cart_addition(): void
    {
        $botService = app(\App\Services\TelegramBotService::class);
        $chatId = 888999111;
        $botService->clearCart($chatId);

        $outlet = \App\Models\Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-1', 'address' => 'Yangon']);
        Setting::set('telegram_bot_warehouse_id', $outlet->id);

        $cat = \App\Models\ProductCategory::create(['name' => 'Cosmetics', 'code' => 'COS']);
        $product = \App\Models\Product::create([
            'name' => 'Bella Lipstick Matte',
            'code' => 'BL-01',
            'selling_price' => 15000,
            'retail_price' => 15000,
            'is_bot_available' => true,
            'is_active' => true,
            'category_id' => $cat->id,
            'stock_alert_quantity' => 1,
        ]);

        \App\Models\StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        $aiRes = [
            'reply' => 'ဟုတ်ကဲ့ပါ မမိုးသော် ခြင်းတောင်းထဲ ထည့်ပေးထားပါပြီရှင်',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 2]
                ]
            ],
            'order_action' => ['type' => 'none'],
        ];

        $botService->executeAiAutonomousActions($chatId, 'Moe Thaw', 'နခမ်းနီ ၂ ခု ယူမယ်', 'moethaw', $aiRes);

        $cartSummary = $botService->getCartSummary($chatId);
        $this->assertEquals(1, $cartSummary['items_count']);
        $this->assertEquals(2, $cartSummary['total_qty']);
        $this->assertEquals(30000, $cartSummary['grand_total']);
    }

    public function test_ai_autonomous_order_creation(): void
    {
        $botService = app(\App\Services\TelegramBotService::class);
        $chatId = 888999222;
        $botService->clearCart($chatId);

        $outlet = \App\Models\Outlet::create(['name' => 'Main Outlet', 'code' => 'OUT-1', 'address' => 'Yangon']);
        Setting::set('telegram_bot_warehouse_id', $outlet->id);

        $cat = \App\Models\ProductCategory::create(['name' => 'Skincare', 'code' => 'SKN']);
        $product = \App\Models\Product::create([
            'name' => 'CeraVe Cleanser',
            'code' => 'CV-01',
            'selling_price' => 22000,
            'retail_price' => 22000,
            'is_bot_available' => true,
            'is_active' => true,
            'category_id' => $cat->id,
            'stock_alert_quantity' => 1,
        ]);

        \App\Models\StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        $region = \App\Models\DeliveryRegion::create([
            'name' => 'Yangon',
            'name_mm' => 'ရန်ကုန်',
            'code' => 'YGN',
            'delivery_fee' => 2000,
        ]);

        $township = \App\Models\DeliveryTownship::create([
            'delivery_region_id' => $region->id,
            'name' => 'Hlaing',
            'name_mm' => 'လှိုင်',
            'code' => 'HLG',
            'delivery_fee' => 2000,
            'is_active' => true,
            'is_cod_available' => true,
        ]);

        $aiRes = [
            'reply' => 'ဟုတ်ကဲ့ပါ မမိုးသော် အော်ဒါ တင်ပေးလိုက်ပါပြီရှင်',
            'cart_action' => [
                'type' => 'add_to_cart',
                'items' => [
                    ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 1]
                ]
            ],
            'order_action' => [
                'type' => 'create_order',
                'customer_name' => 'Moe Thaw',
                'phone' => '09777777554',
                'delivery_type' => 'city',
                'township_name' => 'လှိုင်',
                'address' => 'အမှတ် ၄၀ စံပယ်လမ်း',
                'payment_method' => 'Cash on Delivery',
            ],
        ];

        $botService->executeAiAutonomousActions($chatId, 'Moe Thaw', 'မျက်နှာသစ်ဆေး ၁ ခု လှိုင် ၀၉၇၇၇၇၇၇၅၅၄ COD', 'moethaw', $aiRes);

        $sale = \App\Models\Sale::where('telegram_chat_id', (string)$chatId)->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertEquals(24000, (float)$sale->grand_total); // 22,000 item + 2,000 delivery fee
        $this->assertEquals('Cash on Delivery', $sale->payment_type);
        $this->assertEquals('09777777554', $sale->customer_phone);

        // Cart should be cleared after autonomous order placement
        $cartSummary = $botService->getCartSummary($chatId);
        $this->assertEquals(0, $cartSummary['items_count']);
    }
}
