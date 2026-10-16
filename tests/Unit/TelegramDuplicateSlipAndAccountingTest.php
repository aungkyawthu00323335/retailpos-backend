<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashRegister;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PerceptualHashService;
use App\Services\TelegramBotService;
use App\Services\ProductUnitConversionService;

class TelegramDuplicateSlipAndAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected PerceptualHashService $hashService;
    protected TelegramBotService $botService;
    protected ProductUnitConversionService $unitService;
    protected Outlet $outlet;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashService = app(PerceptualHashService::class);
        $this->botService = app(TelegramBotService::class);
        $this->unitService = app(ProductUnitConversionService::class);

        $this->outlet = Outlet::create([
            'name' => 'Main Warehouse Outlet',
            'address' => 'Yangon',
            'phone' => '0912345678',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
        Setting::set('telegram_bot_token', '123456:TEST_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_reject_duplicate_slip', '1');
        Setting::set('telegram_bot_slip_hash_algorithm', 'hybrid');
        Setting::set('telegram_bot_phash_threshold', '8');
        Setting::set('telegram_bot_enable_stock_reservation', '1');
        Setting::set('telegram_bot_stock_reservation_timeout', '60');
        Setting::set('telegram_bot_enable_tax', '0');

        $this->product = Product::create([
            'name' => 'Diamond Premium Tea',
            'code' => 'TEA-001',
            'sku' => 'TEA-SKU-001',
            'retail_price' => 5000,
            'selling_price' => 5000,
            'cost_price' => 3000,
            'unit_name' => 'Piece',
            'stock_quantity' => 20,
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 20.0000,
            'reserved_quantity' => 0.0000,
        ]);
    }

    private function generateDummyImage(string $text = 'Payment Voucher'): string
    {
        $img = imagecreatetruecolor(120, 120);
        $bg = imagecolorallocate($img, 245, 245, 245);
        imagefilledrectangle($img, 0, 0, 120, 120, $bg);
        $color = imagecolorallocate($img, 20, 20, 20);
        imagestring($img, 4, 10, 50, $text, $color);
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /**
     * F01: Persistent Slip Hash and Reservation Schema Verification
     */
    public function test_persistent_slip_hash_columns_exist_and_persist(): void
    {
        $this->assertTrue(Schema::hasColumn('sales', 'payment_slip_hash'));
        $this->assertTrue(Schema::hasColumn('sales', 'payment_slip_phash'));
        $this->assertTrue(Schema::hasColumn('sales', 'stock_reserved'));
        $this->assertTrue(Schema::hasColumn('sales', 'stock_reserved_at'));
        $this->assertTrue(Schema::hasColumn('sales', 'stock_released_at'));
        $this->assertTrue(Schema::hasColumn('stock_balances', 'reserved_quantity'));

        $sha = hash('sha256', 'slip_raw_content');
        $phash = 'a1b2c3d4e5f60718';

        $sale1 = Sale::create([
            'reference_no' => 'SLIP-TEST-001',
            'customer_name' => 'Test Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'payment_slip_hash' => $sha,
            'payment_slip_phash' => $phash,
            'stock_reserved' => true,
            'stock_reserved_at' => now(),
        ]);

        $this->assertDatabaseHas('sales', [
            'id' => $sale1->id,
            'payment_slip_hash' => $sha,
            'payment_slip_phash' => $phash,
            'stock_reserved' => 1,
        ]);

        // Accessor checks
        $this->assertFalse($sale1->is_duplicate_slip);
        $this->assertNull($sale1->duplicate_warning);

        $sale2 = Sale::create([
            'reference_no' => 'SLIP-TEST-002',
            'customer_name' => 'Test Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'payment_slip_hash' => $sha,
        ]);

        $this->assertTrue($sale2->is_duplicate_slip);
        $this->assertStringContainsString('SLIP-TEST-001', $sale2->duplicate_warning);
    }

    /**
     * F02: Dual Slip Hash Verification & Hamming Distance
     */
    public function test_perceptual_hash_service_computes_hashes_and_hamming_distance(): void
    {
        $bytes1 = $this->generateDummyImage('Original Slip 001');
        $sha1 = $this->hashService->computeSha256($bytes1);
        $phash1 = $this->hashService->computePhash($bytes1);
        $dhash1 = $this->hashService->computeDifferenceHash($bytes1);

        $this->assertEquals(64, strlen($sha1));
        $this->assertNotNull($phash1);
        $this->assertEquals(16, strlen($phash1));
        $this->assertNotNull($dhash1);
        $this->assertEquals(16, strlen($dhash1));

        // Exact same image has 0 Hamming distance
        $dist = PerceptualHashService::hammingDistance($phash1, $phash1);
        $this->assertEquals(0, $dist);

        // Different image has Hamming distance > 0
        $bytes2 = $this->generateDummyImage('Distinct Payment 999');
        $phash2 = $this->hashService->computePhash($bytes2);
        $diffDist = PerceptualHashService::hammingDistance($phash1, $phash2);
        $this->assertGreaterThan(0, $diffDist);
    }

    /**
     * F03: REST uploadSlip rejects duplicate with HTTP 422
     */
    public function test_rest_upload_slip_rejects_duplicate_with_http_422(): void
    {
        Storage::fake('public');
        $imageBytes = $this->generateDummyImage('Unique REST Voucher 1001');
        $file = UploadedFile::fake()->createWithContent('slip.jpg', $imageBytes);

        // 1st Upload: Success 200
        $res1 = $this->postJson('/api/bot/upload-slip', ['slip' => $file]);
        $res1->assertStatus(200);
        $res1->assertJson(['status' => 'success', 'message' => 'Slip uploaded']);
        $this->assertNotEmpty($res1->json('slip_hash'));

        // Register sale in DB
        Sale::create([
            'reference_no' => 'BOT-20261016-0001',
            'customer_name' => 'Test Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $res1->json('slip_hash'),
            'payment_slip_phash' => $res1->json('slip_phash'),
        ]);

        // 2nd Upload with identical file: Rejected with HTTP 422
        $file2 = UploadedFile::fake()->createWithContent('slip2.jpg', $imageBytes);
        $res2 = $this->postJson('/api/bot/upload-slip', ['slip' => $file2]);

        $res2->assertStatus(422);
        $res2->assertJson([
            'status' => 'error',
            'message' => 'Duplicate payment slip detected',
            'duplicate_order_ref' => 'BOT-20261016-0001',
        ]);
    }

    /**
     * F03: REST uploadSlip allows upload when duplicate check is disabled
     */
    public function test_rest_upload_slip_allows_when_duplicate_check_disabled(): void
    {
        Storage::fake('public');
        Setting::set('telegram_bot_reject_duplicate_slip', '0');

        $imageBytes = $this->generateDummyImage('Bypass Check 123');
        $file1 = UploadedFile::fake()->createWithContent('slip1.jpg', $imageBytes);

        $res1 = $this->postJson('/api/bot/upload-slip', ['slip' => $file1]);
        $res1->assertStatus(200);

        Sale::create([
            'reference_no' => 'BOT-BYPASS-0001',
            'customer_name' => 'Test Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $res1->json('slip_hash'),
        ]);

        $file2 = UploadedFile::fake()->createWithContent('slip2.jpg', $imageBytes);
        $res2 = $this->postJson('/api/bot/upload-slip', ['slip' => $file2]);
        $res2->assertStatus(200);
    }

    /**
     * F03: TelegramBotService rejects duplicate slip upload in bot chat
     */
    public function test_telegram_bot_service_rejects_duplicate_slip_upload(): void
    {
        $imageBytes = $this->generateDummyImage('Telegram Duplicate Slip 777');
        $sha = hash('sha256', $imageBytes);
        $phash = $this->hashService->computePhash($imageBytes);

        // Pre-existing order in database
        Sale::create([
            'reference_no' => 'ORD-ORIGINAL-999',
            'customer_name' => 'Test Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $sha,
            'payment_slip_phash' => $phash,
        ]);

        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/slip.jpg'],
            ], 200),
            'https://api.telegram.org/file/bot*/*' => Http::response($imageBytes, 200),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        $chatId = 998877;
        $update = [
            'update_id' => 5001,
            'message' => [
                'message_id' => 201,
                'from' => ['id' => $chatId, 'first_name' => 'Customer A'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'tg_file_001', 'file_unique_id' => 'tg_uid_001', 'width' => 100, 'height' => 100],
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return str_contains($text, 'Duplicate Slip Detected') && str_contains($text, 'ORD-ORIGINAL-999');
            }
            return false;
        });
    }

    /**
     * F04: Output Tax/VAT calculation in finalizeOrder when tax is enabled
     */
    public function test_finalize_order_calculates_output_tax_when_bot_tax_enabled(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Setting::set('telegram_bot_enable_tax', '1');
        Setting::set('telegram_bot_tax_rate', '5.0');

        $chatId = 123456;
        $session = [
            'is_cart' => true,
            'phone' => '09999999999',
            'address' => 'No 12, Main Road',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 1500.0,
            'discount' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 2,
                    'price' => 5000.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Tax Customer', 'tax_user');

        $sale = Sale::with('items')->where('customer_phone', '09999999999')->first();
        $this->assertNotNull($sale);
        $this->assertEquals(10000.0, $sale->subtotal);
        $this->assertEquals(5.0, $sale->tax_rate);
        $this->assertEquals(500.0, $sale->tax_amount);
        $this->assertEquals(12000.0, $sale->grand_total); // 10000 + 500 + 1500
        $this->assertEquals(12000.0, $sale->due_amount);
        $this->assertEquals(0.0, $sale->paid_amount);

        $this->assertEquals(500.0, $sale->items->first()->tax);

        // Unapproved bot order must NOT leak into Customer AR scope
        $customer = Customer::where('phone', '09999999999')->first();
        $this->assertNotNull($customer);
        $ar = Customer::applyReceivableScope($customer->sales())->sum('due_amount');
        $this->assertEquals(0.0, $ar);
    }

    /**
     * F04: Zero tax when bot tax is disabled
     */
    public function test_finalize_order_zero_tax_when_bot_tax_disabled(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Setting::set('telegram_bot_enable_tax', '0');

        $chatId = 123457;
        $session = [
            'is_cart' => true,
            'phone' => '09888888888',
            'address' => 'No 5, Side Road',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 1500.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 2,
                    'price' => 5000.0,
                    'subtotal' => 10000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'No Tax Customer', 'notax_user');

        $sale = Sale::where('customer_phone', '09888888888')->first();
        $this->assertNotNull($sale);
        $this->assertEquals(0.0, $sale->tax_rate);
        $this->assertEquals(0.0, $sale->tax_amount);
        $this->assertEquals(11500.0, $sale->grand_total);
    }

    /**
     * F05: Cashier shift link & SalePayment recording on approveOrder
     */
    public function test_approve_order_associates_cashier_active_shift_and_records_payment(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create([
            'name' => 'Ko Cashier',
            'role' => 'Cashier',
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-20261016-01',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'opening_cash' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'reference_no' => 'BOT-APPROVE-001',
            'customer_name' => 'Buyer One',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 10000,
            'tax_amount' => 500,
            'grand_total' => 10500,
            'due_amount' => 10500,
            'paid_amount' => 0,
            'payment_type' => 'KBZPay',
            'payment_status' => 'Unpaid',
            'stock_reserved' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price' => 5000,
            'base_unit_price' => 5000,
            'subtotal' => 10000,
        ]);

        $response = $this->actingAs($user)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => true,
            'payment_type' => 'KBZPay',
        ]);

        $response->assertStatus(200);

        $sale->refresh();
        $this->assertEquals('approved', $sale->bot_approval_status);
        $this->assertEquals('Completed', $sale->status);
        $this->assertEquals($register->id, $sale->cash_register_id);
        $this->assertEquals(10500.0, $sale->paid_amount);
        $this->assertEquals(0.0, $sale->due_amount);
        $this->assertEquals('Paid', $sale->payment_status);

        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(10500.0, $payment->amount);
        $this->assertEquals('KBZPay', $payment->payment_type);
        $this->assertEquals($user->id, $payment->user_id);
    }

    /**
     * F05: Unpaid approved order recognizes genuine Accounts Receivable
     */
    public function test_approve_order_unpaid_recognizes_accounts_receivable(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['name' => 'Admin Manager', 'role' => 'Super Admin']);

        $customer = Customer::create([
            'customer_id' => 'CUST-AR-001',
            'name' => 'Credit Customer',
            'phone' => '09777777777',
            'status' => 'Active',
        ]);

        $sale = Sale::create([
            'reference_no' => 'BOT-AR-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 20000,
            'grand_total' => 20000,
            'due_amount' => 20000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash on Delivery',
            'stock_reserved' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 4,
            'base_quantity' => 4,
            'unit_price' => 5000,
            'subtotal' => 20000,
        ]);

        // Before approval, Customer AR scope ignores pending bot orders
        $arBefore = Customer::applyReceivableScope($customer->sales())->sum('due_amount');
        $this->assertEquals(0.0, $arBefore);

        // Approve without payment
        $response = $this->actingAs($user)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => false,
        ]);
        $response->assertStatus(200);

        // After approval, genuine AR is recognized
        $arAfter = Customer::applyReceivableScope($customer->sales())->sum('due_amount');
        $this->assertEquals(20000.0, $arAfter);
    }

    /**
     * F06: Stock Balance Reservation on Checkout
     */
    public function test_bot_checkout_reserves_stock_in_stock_balances(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Setting::set('telegram_bot_enable_stock_reservation', '1');

        $chatId = 554433;
        $session = [
            'is_cart' => true,
            'phone' => '09444444444',
            'address' => 'Downtown Yangon',
            'payment_type' => 'Cash on Delivery',
            'payment_status' => 'Unpaid',
            'shipping_fee' => 0.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 3,
                    'price' => 5000.0,
                    'subtotal' => 15000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Reservation Customer', 'reserve_user');

        $sale = Sale::where('customer_phone', '09444444444')->first();
        $this->assertNotNull($sale);
        $this->assertTrue((bool)$sale->stock_reserved);
        $this->assertNotNull($sale->stock_reserved_at);

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();

        // 20 total - 3 reserved = 17 available, 3 reserved
        $this->assertEquals(17.0000, (float)$balance->quantity);
        $this->assertEquals(3.0000, (float)$balance->reserved_quantity);

        // Stock movement recorded
        $movement = StockMovement::where('movement_type', 'bot_reservation')
            ->where('reference_id', (string)$sale->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(3.0, (float)$movement->quantity_out);
    }

    /**
     * F06: Bot checkout fails when available stock is insufficient
     */
    public function test_bot_checkout_fails_when_stock_insufficient(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // Set stock to 2 units
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 2.0000, 'reserved_quantity' => 0.0000]);

        $chatId = 554434;
        $session = [
            'is_cart' => true,
            'phone' => '09333333333',
            'address' => 'Mandalay Road',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 5, // Requires 5, only 2 available
                    'price' => 5000.0,
                    'subtotal' => 25000.0,
                ],
            ],
        ];

        $this->botService->finalizeOrder($chatId, $session, 'Out of Stock Customer', 'oos_user');

        $sale = Sale::where('customer_phone', '09333333333')->first();
        $this->assertNull($sale);

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(2.0000, (float)$balance->quantity);
        $this->assertEquals(0.0000, (float)$balance->reserved_quantity);
    }

    /**
     * F07: Staff order rejection rolls back reserved stock
     */
    public function test_staff_rejection_rolls_back_reserved_stock(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['name' => 'Rejecting Staff', 'role' => 'Super Admin']);

        // Set stock balance: 16 available, 4 reserved
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 16.0000, 'reserved_quantity' => 4.0000]);

        $sale = Sale::create([
            'reference_no' => 'BOT-REJECT-001',
            'customer_name' => 'Reject Test Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 20000,
            'grand_total' => 20000,
            'due_amount' => 20000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 4,
            'base_quantity' => 4,
            'unit_price' => 5000,
            'subtotal' => 20000,
        ]);

        $res = $this->actingAs($user)->postJson("/api/bot/orders/{$sale->id}/reject", [
            'reason' => 'Address is outside delivery coverage area',
        ]);
        $res->assertStatus(200);

        $sale->refresh();
        $this->assertEquals('rejected', $sale->bot_approval_status);
        $this->assertEquals('Cancelled', $sale->status);
        $this->assertFalse((bool)$sale->stock_reserved);
        $this->assertNotNull($sale->stock_released_at);

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();

        // 16 + 4 rolled back = 20 available, 0 reserved
        $this->assertEquals(20.0000, (float)$balance->quantity);
        $this->assertEquals(0.0000, (float)$balance->reserved_quantity);

        $movement = StockMovement::where('movement_type', 'bot_reservation_rollback')
            ->where('reference_id', (string)$sale->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(4.0, (float)$movement->quantity_in);
    }

    /**
     * F07: Staff approval fulfills reserved stock without double-deduction
     */
    public function test_staff_approval_fulfills_reservation_without_double_deduction(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['name' => 'Fulfill Staff', 'role' => 'Super Admin']);

        // Stock was already reserved: available 17, reserved 3
        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 17.0000, 'reserved_quantity' => 3.0000]);

        $sale = Sale::create([
            'reference_no' => 'BOT-FULFILL-001',
            'customer_name' => 'Fulfill Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now(),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 15000,
            'grand_total' => 15000,
            'due_amount' => 15000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 3,
            'base_quantity' => 3,
            'unit_price' => 5000,
            'subtotal' => 15000,
        ]);

        $res = $this->actingAs($user)->postJson("/api/bot/orders/{$sale->id}/approve", [
            'is_paid' => true,
            'payment_type' => 'KBZPay',
        ]);
        $res->assertStatus(200);

        $sale->refresh();
        $this->assertEquals('approved', $sale->bot_approval_status);
        $this->assertEquals('Completed', $sale->status);
        $this->assertFalse((bool)$sale->stock_reserved);

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();

        // Quantity MUST stay 17 (NO double deduction), reserved drops to 0
        $this->assertEquals(17.0000, (float)$balance->quantity);
        $this->assertEquals(0.0000, (float)$balance->reserved_quantity);
    }

    /**
     * F07: Release expired reservations command releases stranded inventory
     */
    public function test_expired_reservation_command_releases_stock(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 15.0000, 'reserved_quantity' => 5.0000]);

        $expiredSale = Sale::create([
            'reference_no' => 'BOT-EXPIRED-001',
            'customer_name' => 'Expired Customer',
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'date' => now()->subMinutes(90),
            'status' => 'Pending',
            'bot_approval_status' => 'pending',
            'order_source' => 'telegram_bot',
            'subtotal' => 25000,
            'grand_total' => 25000,
            'due_amount' => 25000,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'stock_reserved' => true,
            'stock_reserved_at' => now()->subMinutes(90),
        ]);

        SaleItem::create([
            'sale_id' => $expiredSale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => $this->product->name,
            'quantity' => 5,
            'base_quantity' => 5,
            'unit_price' => 5000,
            'subtotal' => 25000,
        ]);

        Setting::set('telegram_bot_stock_reservation_timeout', 60);

        $exitCode = Artisan::call('bot:release-expired-reservations');
        $this->assertEquals(0, $exitCode);

        $expiredSale->refresh();
        $this->assertEquals('rejected', $expiredSale->bot_approval_status);
        $this->assertEquals('Cancelled', $expiredSale->status);
        $this->assertFalse((bool)$expiredSale->stock_reserved);
        $this->assertNotNull($expiredSale->stock_released_at);

        $balance = StockBalance::where('warehouse_id', $this->outlet->id)
            ->where('product_id', $this->product->id)
            ->first();

        // 15 + 5 restored = 20 available, 0 reserved
        $this->assertEquals(20.0000, (float)$balance->quantity);
        $this->assertEquals(0.0000, (float)$balance->reserved_quantity);
    }
}
