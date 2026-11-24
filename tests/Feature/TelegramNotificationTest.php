<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\TelegramNotificationLog;
use App\Models\TelegramRecipient;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithRole(string $roleName, array $permissions, ?array $assignedOutlets = null): array
    {
        $role = Role::create([
            'name' => $roleName,
            'slug' => strtolower(str_replace(' ', '-', $roleName)) . '-' . uniqid(),
            'display_name' => $roleName,
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        $token = 'test-token-' . uniqid('', true);
        $user = User::create([
            'name' => $roleName . ' User',
            'email' => strtolower(str_replace(' ', '_', $roleName)) . '_' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role' => $roleName,
            'role_id' => $role->id,
            'status' => 'Active',
            'api_token' => $token,
            'api_token_created_at' => now(),
        ]);

        if (!empty($assignedOutlets)) {
            $user->outlets()->sync($assignedOutlets);
        }

        return [$user, $token];
    }

    public function test_can_save_and_retrieve_masked_bot_token_and_recipients(): void
    {
        $outlet = Outlet::create(['name' => 'Yangon Flagship', 'code' => 'YGN-01']);

        $saveRes = $this->postJson('/api/telegram/settings', [
            'bot_token' => '123456789:ABCdefGHIjklMNOpqrSTUvwxYZ9876',
            'is_enabled' => true,
            'auto_send_register_close' => true,
            'include_profit' => true,
            'include_registers' => true,
            'message_language' => 'my',
            'recipients' => [
                [
                    'name' => 'Owner (U Aung)',
                    'chat_id' => '998877665',
                    'outlet_id' => null,
                    'is_active' => true,
                    'notify_daily_summary' => true,
                    'notify_register_close' => true,
                ],
                [
                    'name' => 'Yangon Manager',
                    'chat_id' => '-1001234567890',
                    'outlet_id' => $outlet->id,
                    'is_active' => true,
                    'notify_daily_summary' => true,
                    'notify_register_close' => false,
                ],
            ],
        ]);

        $saveRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('settings.has_bot_token', true)
            ->assertJsonPath('settings.is_enabled', true)
            ->assertJsonPath('settings.message_language', 'my')
            ->assertJsonCount(2, 'recipients');

        $masked = $saveRes->json('settings.masked_bot_token');
        $this->assertStringContainsString('********', $masked);
        $this->assertStringNotContainsString('ABCdefGHIjklMNOpqrSTUvwxYZ', $masked);

        // Updating settings with masked token preserves the underlying real token
        $secondSave = $this->postJson('/api/telegram/settings', [
            'bot_token' => $masked,
            'is_enabled' => true,
            'auto_send_register_close' => false,
            'include_profit' => true,
            'include_registers' => true,
            'message_language' => 'en',
            'recipients' => [
                [
                    'id' => $saveRes->json('recipients.0.id'),
                    'name' => 'Owner Updated',
                    'chat_id' => '998877665',
                    'outlet_id' => null,
                    'is_active' => true,
                    'notify_daily_summary' => true,
                    'notify_register_close' => true,
                ],
            ],
        ]);

        $secondSave->assertStatus(200)
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.name', 'Owner Updated');

        $this->assertSame(
            '123456789:ABCdefGHIjklMNOpqrSTUvwxYZ9876',
            TelegramSetting::current()->getPlainBotToken()
        );
    }

    public function test_daily_summary_preview_and_send_dispatches_outlet_profit_user_and_register_data(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
        ]);

        $today = Carbon::today()->toDateString();
        $outletA = Outlet::create(['name' => 'Yangon Flagship', 'code' => 'YGN-01']);
        $outletB = Outlet::create(['name' => 'Naypyidaw Capital Superstore', 'code' => 'NPT-01']);

        $setting = TelegramSetting::current();
        $setting->setPlainBotToken('777777:TEST_BOT_TOKEN_SECRET');
        $setting->is_enabled = true;
        $setting->include_profit = true;
        $setting->include_registers = true;
        $setting->message_language = 'my';
        $setting->save();

        TelegramRecipient::create([
            'name' => 'Owner All Outlets',
            'chat_id' => '111222333',
            'outlet_id' => null,
            'is_active' => true,
            'notify_daily_summary' => true,
            'notify_register_close' => true,
        ]);
        TelegramRecipient::create([
            'name' => 'NPT Branch Manager',
            'chat_id' => '444555666',
            'outlet_id' => $outletB->id,
            'is_active' => true,
            'notify_daily_summary' => true,
            'notify_register_close' => true,
        ]);

        // Register in Outlet B matching screenshot details
        $reg = CashRegister::create([
            'reference_no' => 'REG-NPT-01',
            'register_name' => 'Register 01',
            'user_id' => 1,
            'user_name' => 'Admin POS',
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'opening_cash' => 0.0,
            'status' => 'closed',
            'opened_at' => $today . ' 06:21:36',
            'closed_at' => $today . ' 18:00:00',
            'expected_cash' => 0.0,
            'closing_cash' => 0.0,
            'cash_difference' => 0.0,
        ]);

        // Outlet A Sale: 100,000 (COGS 60,000)
        Sale::create([
            'reference_no' => 'SL-YGN-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 60000,
            'user_name' => 'Cashier Aye',
            'user_role' => 'Cashier',
        ]);

        // Outlet B Sale on Register 01: 75,352 Credit Card (COGS 50,000)
        $saleB = Sale::create([
            'reference_no' => 'SL-NPT-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outletB->id,
            'warehouse_name' => $outletB->name,
            'date' => $today . ' 11:00:00',
            'status' => 'Completed',
            'subtotal' => 75352,
            'grand_total' => 75352,
            'paid_amount' => 75352,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Credit Card',
            'cash_register_id' => $reg->id,
            'total_cogs' => 50000,
            'user_name' => 'Admin POS',
            'user_role' => 'Lead Cashier',
        ]);
        SalePayment::create([
            'reference_no' => 'PAY-NPT-01',
            'sale_id' => $saleB->id,
            'cash_register_id' => $reg->id,
            'amount' => 75352,
            'payment_type' => 'Credit Card',
            'payment_date' => $today,
        ]);

        // Outlet A Sale Return: 10,000 (Returned COGS 6,000)
        SaleReturn::create([
            'reference_no' => 'SR-YGN-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => $today . ' 12:00:00',
            'status' => 'Completed',
            'grand_total' => 10000,
            'return_cost_total' => 6000,
        ]);

        // Outlet A Purchase: 45,000
        Purchase::create([
            'reference_no' => 'PU-YGN-01',
            'supplier_name' => 'Golden Supplier',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => $today . ' 09:30:00',
            'status' => 'Received',
            'grand_total' => 45000,
            'paid_amount' => 45000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'user_name' => 'Buyer Ko Min',
            'user_role' => 'Manager',
        ]);

        // Outlet A Expense: 5,000
        Expense::create([
            'reference_no' => 'EX-YGN-01',
            'title' => 'Electricity',
            'expense_category_name' => 'Utilities',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => $today,
            'amount' => 5000,
            'payment_status' => 'Paid',
        ]);

        // 1. Preview check
        $previewRes = $this->getJson("/api/telegram/preview-daily-summary?date={$today}");
        $previewRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.summary.gross_sales', 175352)
            ->assertJsonPath('data.summary.sale_returns', 10000)
            ->assertJsonPath('data.summary.net_sales', 165352)
            ->assertJsonPath('data.summary.gross_purchases', 45000)
            ->assertJsonPath('data.summary.expenses', 5000)
            // COGS = 60,000 + 50,000 - 6,000 = 104,000; Gross Profit = 165,352 - 104,000 = 61,352; Net Profit = 56,352
            ->assertJsonPath('data.summary.gross_profit', 61352)
            ->assertJsonPath('data.summary.net_profit', 56352);

        $previewText = (string)$previewRes->json('data.message_preview');
        $this->assertStringContainsString('Yangon Flagship', $previewText);
        $this->assertStringContainsString('Naypyidaw Capital Superstore', $previewText);
        $this->assertStringContainsString('Cashier Aye', $previewText);
        $this->assertStringContainsString('Buyer Ko Min', $previewText);
        $this->assertStringContainsString('Register 01', $previewText);
        $this->assertStringContainsString('Credit Card', $previewText);
        $this->assertStringContainsString('75,352', $previewText);

        // 2. Send Noti dispatch check (verifying branch-scoped isolation!)
        $sendRes = $this->postJson('/api/telegram/send-daily-summary', [
            'date' => $today,
        ]);

        $sendRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('sent_count', 2)
            ->assertJsonPath('failed_count', 0);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        // First call -> Owner (chat_id 111222333) gets BOTH outlets
        $ownerPayload = $recorded[0][0]->data();
        $this->assertSame('111222333', $ownerPayload['chat_id']);
        $this->assertStringContainsString('Yangon Flagship', $ownerPayload['text']);
        $this->assertStringContainsString('Naypyidaw Capital Superstore', $ownerPayload['text']);
        $this->assertStringContainsString('56,352', $ownerPayload['text']);

        // Second call -> NPT Manager (chat_id 444555666) ONLY gets Naypyidaw Superstore (no Yangon leak!)
        $nptPayload = $recorded[1][0]->data();
        $this->assertSame('444555666', $nptPayload['chat_id']);
        $this->assertStringContainsString('Naypyidaw Capital Superstore', $nptPayload['text']);
        $this->assertStringNotContainsString('Yangon Flagship', $nptPayload['text']);

        $this->assertSame(2, TelegramNotificationLog::where('event_type', 'daily_summary')->where('status', 'sent')->count());
    }

    public function test_auto_send_on_register_close_and_manual_send_register_summary(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 202]], 200),
        ]);

        $outlet = Outlet::create(['name' => 'Naypyidaw Capital Superstore', 'code' => 'NPT-01']);

        $setting = TelegramSetting::current();
        $setting->setPlainBotToken('888888:REG_TOKEN');
        $setting->is_enabled = true;
        $setting->auto_send_register_close = true;
        $setting->save();

        TelegramRecipient::create([
            'name' => 'Owner',
            'chat_id' => '555666777',
            'outlet_id' => null,
            'is_active' => true,
            'notify_daily_summary' => true,
            'notify_register_close' => true,
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-AUTO-01',
            'register_name' => 'Register 01',
            'user_id' => 1,
            'user_name' => 'Admin POS',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'opening_cash' => 50000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(4),
        ]);

        // Auto-send triggers on register close
        $closeRes = $this->postJson("/api/cash-register/close/{$register->id}", [
            'closing_cash' => 50000.0,
            'closing_notes' => 'Balanced shift',
        ]);
        $closeRes->assertStatus(200);

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('555666777', $recorded[0][0]->data()['chat_id']);
        $this->assertStringContainsString('Register 01', $recorded[0][0]->data()['text']);
        $this->assertStringContainsString('Naypyidaw Capital Superstore', $recorded[0][0]->data()['text']);
        $this->assertStringContainsString('50,000', $recorded[0][0]->data()['text']);

        // Manual send register summary from Register Details modal
        $manualRes = $this->postJson("/api/telegram/send-register/{$register->id}");
        $manualRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('sent_count', 1);

        $this->assertSame(2, TelegramNotificationLog::where('event_type', 'register_close')->count());
    }

    public function test_register_close_never_fails_when_telegram_api_errors(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $outlet = Outlet::create(['name' => 'Main Outlet', 'code' => 'MAIN-01']);

        $setting = TelegramSetting::current();
        $setting->setPlainBotToken('bad:token');
        $setting->is_enabled = true;
        $setting->auto_send_register_close = true;
        $setting->save();

        TelegramRecipient::create([
            'name' => 'Owner',
            'chat_id' => '999000111',
            'outlet_id' => null,
            'is_active' => true,
            'notify_daily_summary' => true,
            'notify_register_close' => true,
        ]);

        $register = CashRegister::create([
            'reference_no' => 'REG-FAILSAFE-01',
            'register_name' => 'Register 02',
            'user_id' => 1,
            'user_name' => 'Cashier B',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'opening_cash' => 10000.0,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        // Closing MUST succeed despite Telegram returning 401
        $closeRes = $this->postJson("/api/cash-register/close/{$register->id}", [
            'closing_cash' => 10000.0,
        ]);
        $closeRes->assertStatus(200)
            ->assertJsonPath('register.status', 'Closed');

        $this->assertDatabaseHas('telegram_notification_logs', [
            'event_type' => 'register_close',
            'chat_id' => '999000111',
            'status' => 'failed',
            'error_message' => 'Unauthorized',
        ]);
    }

    public function test_cost_blind_and_branch_scoped_rbac_enforced(): void
    {
        $today = Carbon::today()->toDateString();
        $outletA = Outlet::create(['name' => 'Branch A', 'code' => 'BR-A']);
        $outletB = Outlet::create(['name' => 'Branch B', 'code' => 'BR-B']);

        Sale::create([
            'reference_no' => 'SL-A1',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outletA->id,
            'warehouse_name' => $outletA->name,
            'date' => $today,
            'status' => 'Completed',
            'subtotal' => 20000,
            'grand_total' => 20000,
            'paid_amount' => 20000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 12000,
        ]);

        // User with general_settings.view, NO cost/profit permissions, restricted strictly to Branch A
        [, $token] = $this->makeUserWithRole(
            'Branch Clerk',
            ['general_settings.view', 'reports.view'],
            [$outletA->id]
        );

        // Unauthorized outlet B -> 403
        $forbiddenRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/telegram/preview-daily-summary?date={$today}&warehouse_id={$outletB->id}");
        $forbiddenRes->assertStatus(403);

        // Authorized outlet A -> 200, profit/COGS strictly masked!
        $okRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/telegram/preview-daily-summary?date={$today}&warehouse_id={$outletA->id}");
        $okRes->assertStatus(200)
            ->assertJsonPath('data.summary.cost_masked', true)
            ->assertJsonPath('data.summary.gross_profit', null)
            ->assertJsonPath('data.summary.net_profit', null);

        $previewText = (string)$okRes->json('data.message_preview');
        $this->assertStringNotContainsString('8,000', $previewText);
    }

    public function test_can_send_test_ping_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 303]], 200),
        ]);

        $res = $this->postJson('/api/telegram/test', [
            'bot_token' => '12345:TEST_TOKEN',
            'chat_id' => '987654321',
            'recipient_name' => 'Owner Phone',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('sent_count', 1);

        $this->assertDatabaseHas('telegram_notification_logs', [
            'event_type' => 'test',
            'chat_id' => '987654321',
            'status' => 'sent',
        ]);
    }

    public function test_bot_token_is_redacted_from_error_messages_and_logs(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => function () {
                throw new \RuntimeException(
                    'cURL error 60 for https://api.telegram.org/bot999888777:SECRET_TOKEN_XYZ/sendMessage'
                );
            },
        ]);

        $res = $this->postJson('/api/telegram/test', [
            'bot_token' => '999888777:SECRET_TOKEN_XYZ',
            'chat_id' => '987654321',
            'recipient_name' => 'Owner Phone',
        ]);

        $res->assertStatus(422);
        $this->assertStringNotContainsString('SECRET_TOKEN_XYZ', (string)$res->json('message'));
        $this->assertStringContainsString('bot[REDACTED]', (string)$res->json('message'));

        $log = TelegramNotificationLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('SECRET_TOKEN_XYZ', (string)$log->error_message);
        $this->assertStringContainsString('bot[REDACTED]', (string)$log->error_message);
    }

    public function test_custom_daily_schedule_saves_fires_at_configured_time_and_prevents_duplicates(): void
    {
        Cache::flush();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 404]], 200),
        ]);

        // 1. Save custom schedule time (20:00 / 8:00 PM daily)
        $saveRes = $this->postJson('/api/telegram/settings', [
            'bot_token' => '555666:SCHEDULE_TOKEN_XYZ',
            'is_enabled' => true,
            'auto_send_register_close' => true,
            'auto_send_daily_enabled' => true,
            'auto_send_daily_time' => '20:00',
            'include_profit' => true,
            'include_registers' => true,
            'message_language' => 'my',
            'recipients' => [
                [
                    'name' => 'Owner Schedule',
                    'chat_id' => '777888999',
                    'outlet_id' => null,
                    'is_active' => true,
                    'notify_daily_summary' => true,
                    'notify_register_close' => true,
                ],
            ],
        ]);

        $saveRes->assertStatus(200)
            ->assertJsonPath('settings.auto_send_daily_enabled', true)
            ->assertJsonPath('settings.auto_send_daily_time', '20:00');

        $service = app(TelegramNotificationService::class);
        $tz = $service->getStoreTimezone();
        $today = Carbon::now($tz)->toDateString();

        // 2. Before scheduled time (19:59) -> skipped, 0 sends
        $beforeRes = $service->dispatchScheduledDailyIfDue(false, Carbon::parse("{$today} 19:59:00", $tz));
        $this->assertTrue((bool)($beforeRes['skipped'] ?? false));
        $this->assertSame('before_scheduled_time', $beforeRes['reason']);
        $this->assertCount(0, Http::recorded());

        // 3. At scheduled time (20:00) -> fires ONCE!
        $atTimeRes = $service->dispatchScheduledDailyIfDue(false, Carbon::parse("{$today} 20:00:00", $tz));
        $this->assertTrue((bool)($atTimeRes['ok'] ?? false));
        $this->assertSame(1, $atTimeRes['sent_count']);
        $this->assertCount(1, Http::recorded());
        $this->assertSame($today, TelegramSetting::current()->last_auto_daily_sent_date);

        // 4. Subsequent tick (20:05) -> strictly skipped (idempotency guard prevents double-send!)
        $afterRes = $service->dispatchScheduledDailyIfDue(false, Carbon::parse("{$today} 20:05:00", $tz));
        $this->assertTrue((bool)($afterRes['skipped'] ?? false));
        $this->assertSame('already_sent_today', $afterRes['reason']);
        $this->assertCount(1, Http::recorded());

        // 5. Heartbeat API endpoint also respects already_sent_today
        $apiCheck = $this->postJson('/api/telegram/check-scheduled');
        $apiCheck->assertStatus(200)
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('reason', 'already_sent_today');
    }

    public function test_daily_summary_reconciles_with_profit_loss_to_zero_diff(): void
    {
        Cache::flush();
        $tz = app(TelegramNotificationService::class)->getStoreTimezone();
        $today = Carbon::now($tz)->toDateString();
        $outlet = Outlet::create(['name' => 'Central Store', 'code' => 'CTR-01']);

        // Sale 1: 31,500 Ks incl. 1,500 Ks Commercial Tax (ex-tax revenue = 30,000, COGS = 6,050)
        Sale::create([
            'reference_no' => 'SL-TAX-01',
            'customer_name' => 'Walk-in',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 10:00:00',
            'status' => 'Completed',
            'subtotal' => 30000,
            'tax_amount' => 1500,
            'shipping' => 0,
            'grand_total' => 31500,
            'paid_amount' => 31500,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'total_cogs' => 6050,
            'user_name' => 'Cashier A',
        ]);

        // Sale 2: Partial Credit Sale 20,000 Ks (ex-tax 20,000, COGS 11,000, Paid 12,000, Due 8,000)
        Sale::create([
            'reference_no' => 'SL-CRED-02',
            'customer_name' => 'Credit Customer',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today . ' 11:30:00',
            'status' => 'Completed',
            'subtotal' => 20000,
            'tax_amount' => 0,
            'shipping' => 0,
            'grand_total' => 20000,
            'paid_amount' => 12000,
            'due_amount' => 8000,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'total_cogs' => 11000,
            'user_name' => 'Cashier A',
        ]);

        // Operating Expense: 3,500 Ks
        Expense::create([
            'reference_no' => 'EX-CTR-01',
            'title' => 'Packaging',
            'expense_category_name' => 'Supplies',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'amount' => 3500,
            'payment_status' => 'Paid',
        ]);

        // Operating Inventory Shrinkage Loss: 2,000 Ks
        $adj = StockAdjustment::create([
            'reference_no' => 'ADJ-LOSS-01',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'type' => 'subtraction',
            'reason_code' => 'damaged',
            'affects_pnl' => true,
            'total_products' => 1,
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adj->id,
            'product_id' => 1,
            'product_name' => 'Test Item',
            'product_code' => 'SKU-TEST-01',
            'quantity' => 1,
            'unit_cost' => 2000,
            'subtotal' => 2000,
            'type' => 'subtraction',
        ]);

        // Purchase Return with IAS 2 cost_variance (+500 Ks)
        PurchaseReturn::create([
            'reference_no' => 'PR-VAR-01',
            'supplier_name' => 'Supplier X',
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => $today,
            'status' => 'Completed',
            'grand_total' => 10000,
            'cost_variance' => 500,
        ]);

        $plRes = $this->getJson("/api/reports/profit-loss?start_date={$today}&end_date={$today}");
        $plRes->assertStatus(200);
        $pl = $plRes->json('data.summary');

        $tgRes = $this->getJson("/api/telegram/preview-daily-summary?date={$today}");
        $tgRes->assertStatus(200);
        $tg = $tgRes->json('data.summary');

        // Exact 0.00 Ks accounting reconciliation between Official P&L and Telegram Summary!
        $this->assertEqualsWithDelta((float)$pl['total_gross_sales'], (float)$tg['gross_sales'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_net_sales'], (float)$tg['net_sales'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_tax_collected'], (float)$tg['tax_collected'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_cogs'], (float)$tg['cogs'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_gross_profit'], (float)$tg['gross_profit'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_expenses'], (float)$tg['expenses'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['inventory_loss'], (float)$tg['inventory_loss'], 0.01);
        $this->assertEqualsWithDelta((float)$pl['total_net_profit'], (float)$tg['net_profit'], 0.01);

        // Verify expected values explicitly:
        // Invoiced = 51,500 | Tax = 1,500 | Net Sales = 50,000 | COGS = 17,050 | GP = 32,950
        // Net Profit = 32,950 - 3,500 (Exp) - 2,000 (Shrinkage) + 500 (PR Variance) = 27,950
        $this->assertEqualsWithDelta(51500.0, (float)$tg['invoiced_sales'], 0.01);
        $this->assertEqualsWithDelta(43500.0, (float)$tg['sales_paid'], 0.01);
        $this->assertEqualsWithDelta(8000.0, (float)$tg['sales_due'], 0.01);
        $this->assertEqualsWithDelta(27950.0, (float)$tg['net_profit'], 0.01);

        // Verify clean formatted preview includes tax, COGS, net profit, and credit due
        $preview = (string)$tgRes->json('data.message_preview');
        $this->assertStringContainsString('51,500', $preview);
        $this->assertStringContainsString('1,500', $preview);
        $this->assertStringContainsString('17,050', $preview);
        $this->assertStringContainsString('27,950', $preview);
        $this->assertStringContainsString('8,000', $preview);
    }
}
