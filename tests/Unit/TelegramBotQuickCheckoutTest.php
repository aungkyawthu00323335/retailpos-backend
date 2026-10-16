<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\Sale;
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
}
