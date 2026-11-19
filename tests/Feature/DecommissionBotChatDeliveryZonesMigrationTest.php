<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemCostLayer;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DecommissionBotChatDeliveryZonesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_purges_bot_orders_drops_removed_schema_and_preserves_shared_commerce_fields(): void
    {
        // Re-create legacy bot & delivery-zone schema temporarily to verify migration cleanup end-to-end
        Schema::table('sales', function (Blueprint $table) {
            foreach ([
                'order_source',
                'telegram_chat_id',
                'telegram_username',
                'telegram_order_id',
                'bot_approval_status',
                'bot_rejection_reason',
                'payment_slip_url',
                'shipping_address',
                'customer_phone',
                'bot_approved_by',
                'bot_approved_at',
                'bot_rejected_by',
                'bot_rejected_at',
            ] as $col) {
                if (!Schema::hasColumn('sales', $col)) {
                    $table->string($col)->nullable();
                }
            }
            $table->index(['order_source', 'bot_approval_status', 'date', 'id'], 'idx_sales_source_approval_date_id');
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_bot_available')) {
                $table->boolean('is_bot_available')->default(false);
            }
            if (!Schema::hasColumn('products', 'bot_price')) {
                $table->decimal('bot_price', 14, 2)->nullable();
            }
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'is_bot_available')) {
                $table->boolean('is_bot_available')->default(true);
            }
        });

        Schema::create('delivery_regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('delivery_townships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_region_id')->constrained('delivery_regions')->cascadeOnDelete();
            $table->string('name');
        });
        Schema::create('bot_restock_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
        });
        Schema::create('bot_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
        });

        $outlet = Outlet::create(['name' => 'HQ Outlet', 'code' => 'HQ', 'is_active' => true, 'is_warehouse' => true]);
        $user = User::factory()->create(['role' => 'Super Admin']);

        $register = CashRegister::create([
            'reference_no' => 'REG-MIG-001',
            'register_name' => 'Counter 1',
            'user_id' => $user->id,
            'cashier_id' => $user->id,
            'warehouse_id' => $outlet->id,
            'opening_cash' => 10000.00,
            'expected_cash' => 15000.00, // Includes 5000 cash bot sale
            'status' => 'Open',
            'opened_at' => now(),
        ]);

        $customer = Customer::create([
            'name' => 'Bot Buyer',
            'phone' => '0999888777',
            'total_visits' => 2,
            'total_spent' => 8000.00, // 3000 normal POS + 5000 Completed bot sale
            'status' => 'Active',
        ]);

        $product = Product::create([
            'code' => 'PRD-MIG-01',
            'sku' => 'PRD-MIG-01',
            'name' => 'Channel Priced Item',
            'cost_price' => 500.00,
            'purchase_price' => 500.00,
            'selling_price' => 1000.00,
            'retail_price' => 1000.00,
            'delivery_price' => 3500.00,
            'stock_quantity' => 5.0,
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $product->id)->update([
            'is_bot_available' => true,
            'bot_price' => 950.00,
        ]);

        StockBalance::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'quantity' => 5.0,
        ]);

        $batch = InventoryBatch::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'source_type' => 'purchase',
            'source_reference' => 'PO-MIG-01',
            'initial_quantity' => 10.0,
            'remaining_quantity' => 5.0,
            'unit_cost' => 500.00,
            'received_date' => now()->subDay(),
        ]);

        $pm = PaymentMethod::create([
            'name' => 'KBZPay Counter',
            'code' => 'KBZ',
            'account_number' => '09111222333',
            'account_name' => 'Daw Mya',
            'is_active' => true,
        ]);
        DB::table('payment_methods')->where('id', $pm->id)->update(['is_bot_available' => true]);

        // Normal POS sale (must be preserved along with sales.shipping)
        $posSale = Sale::create([
            'reference_no' => 'SA-POS-KEEP',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'shipping' => 2500.00,
            'subtotal' => 500.00,
            'grand_total' => 3000.00,
            'paid_amount' => 3000.00,
            'due_amount' => 0.00,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
        ]);
        DB::table('sales')->where('id', $posSale->id)->update(['order_source' => 'pos']);

        // Completed Telegram Bot sale (must be purged & reversed: +5 stock, -5000 cash, FIFO restored)
        $completedBotSale = Sale::create([
            'reference_no' => 'BOT-COMP-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'subtotal' => 5000.00,
            'grand_total' => 5000.00,
            'paid_amount' => 5000.00,
            'due_amount' => 0.00,
            'payment_status' => 'Paid',
            'payment_type' => 'Cash',
            'cash_register_id' => $register->id,
        ]);
        DB::table('sales')->where('id', $completedBotSale->id)->update([
            'order_source' => 'telegram_bot',
            'bot_approval_status' => 'approved',
            'telegram_chat_id' => '99887766',
        ]);

        $botItem = SaleItem::create([
            'sale_id' => $completedBotSale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 1000.00,
            'quantity' => 5.0,
            'base_quantity' => 5.0,
            'subtotal' => 5000.00,
        ]);
        SaleItemCostLayer::create([
            'sale_id' => $completedBotSale->id,
            'sale_item_id' => $botItem->id,
            'inventory_batch_id' => $batch->id,
            'base_quantity' => 5.0,
            'unit_cost' => 500.00,
            'total_cost' => 2500.00,
            'date' => now(),
            'created_at' => now(),
        ]);
        SalePayment::create([
            'sale_id' => $completedBotSale->id,
            'reference_no' => 'PAY-BOT-001',
            'payment_date' => now()->format('Y-m-d H:i:s'),
            'payment_type' => 'Cash',
            'amount' => 5000.00,
        ]);
        StockMovement::create([
            'warehouse_id' => $outlet->id,
            'product_id' => $product->id,
            'movement_type' => 'sale',
            'reference_type' => 'sale',
            'reference_id' => (string) $completedBotSale->id,
            'quantity_in' => 0.0,
            'quantity_out' => 5.0,
            'balance_after' => 5.0,
            'created_at' => now(),
        ]);

        // Pending Telegram Bot sale (not depleted yet -> must be deleted without inflating stock)
        $pendingBotSale = Sale::create([
            'reference_no' => 'BOT-PEND-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'subtotal' => 2000.00,
            'grand_total' => 2000.00,
            'paid_amount' => 0.00,
            'due_amount' => 2000.00,
            'payment_status' => 'Unpaid',
            'payment_type' => 'Cash',
        ]);
        DB::table('sales')->where('id', $pendingBotSale->id)->update([
            'order_source' => 'telegram_bot',
            'bot_approval_status' => 'pending',
        ]);
        SaleItem::create([
            'sale_id' => $pendingBotSale->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit_price' => 1000.00,
            'quantity' => 2.0,
            'base_quantity' => 2.0,
            'subtotal' => 2000.00,
        ]);

        // Linked SaleReturn on the purged bot sale
        $linkedReturn = SaleReturn::create([
            'reference_no' => 'SR-BOT-001',
            'sale_reference_no' => 'BOT-COMP-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $outlet->id,
            'warehouse_name' => $outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'grand_total' => 1000.00,
            'paid_amount' => 1000.00,
            'due_amount' => 0.00,
            'payment_status' => 'Paid',
            'items_payload' => json_encode([[
                'product_id' => $product->id,
                'quantity' => 1,
                'base_quantity' => 1,
                'conversion_rate' => 1,
            ]]),
        ]);

        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => '123:abc']);
        Setting::updateOrCreate(['key' => 'tg_ai_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'company_name'], ['value' => 'Keep Store']);

        $role = Role::create([
            'name' => 'Bot Clerk',
            'slug' => 'bot-clerk',
            'permissions' => [
                'sales.view',
                'bot_orders.view',
                'bot_orders.approve',
                'bot_chat.view',
                'delivery_zones.view',
                'telegram_bot.update',
                'Telegram Bot Settings',
                'Delivery Fees',
            ],
        ]);

        // Execute migration under test
        $migration = require base_path('database/migrations/2026_11_18_000002_decommission_bot_chat_and_delivery_zones.php');
        $migration->up();

        // Assert ALL telegram_bot orders & linked artifacts are purged
        $this->assertDatabaseHas('sales', ['id' => $posSale->id, 'shipping' => 2500.00]);
        $this->assertDatabaseMissing('sales', ['id' => $completedBotSale->id]);
        $this->assertDatabaseMissing('sales', ['id' => $pendingBotSale->id]);
        $this->assertDatabaseMissing('sale_returns', ['id' => $linkedReturn->id]);
        $this->assertSame(0, SaleItem::whereIn('sale_id', [$completedBotSale->id, $pendingBotSale->id])->count());
        $this->assertSame(0, SalePayment::where('sale_id', $completedBotSale->id)->count());
        $this->assertSame(0, SaleItemCostLayer::where('sale_id', $completedBotSale->id)->count());
        $this->assertSame(0, StockMovement::where('reference_type', 'sale')->where('reference_id', (string) $completedBotSale->id)->count());

        // Inventory: +5 from Completed bot sale, -1 from completed linked SaleReturn = 5 + 5 - 1 = 9
        $this->assertEquals(9.0, (float) StockBalance::where('product_id', $product->id)->value('quantity'));
        $this->assertEquals(9.0, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(10.0, (float) $batch->fresh()->remaining_quantity);

        // CashRegister expected_cash reversed by -5000 cash bot sale
        $this->assertEquals(10000.00, (float) $register->fresh()->expected_cash);

        // Customer totals recomputed from remaining non-bot Completed sales
        $this->assertSame(1, (int) $customer->fresh()->total_visits);
        $this->assertEquals(3000.00, (float) $customer->fresh()->total_spent);

        // Settings & Roles cleaned without deleting valid entries
        $this->assertDatabaseMissing('settings', ['key' => 'telegram_bot_token']);
        $this->assertDatabaseMissing('settings', ['key' => 'tg_ai_enabled']);
        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Keep Store']);
        $this->assertSame(['sales.view'], $role->fresh()->permissions);

        // Dropped tables & columns verified gone; shared columns preserved intact
        $this->assertFalse(Schema::hasTable('bot_chat_messages'));
        $this->assertFalse(Schema::hasTable('bot_restock_subscribers'));
        $this->assertFalse(Schema::hasTable('delivery_townships'));
        $this->assertFalse(Schema::hasTable('delivery_regions'));

        $this->assertFalse(Schema::hasColumn('sales', 'order_source'));
        $this->assertFalse(Schema::hasColumn('sales', 'bot_approval_status'));
        $this->assertTrue(Schema::hasColumn('sales', 'shipping'));

        $this->assertFalse(Schema::hasColumn('products', 'is_bot_available'));
        $this->assertFalse(Schema::hasColumn('products', 'bot_price'));
        $this->assertTrue(Schema::hasColumn('products', 'delivery_price'));
        $this->assertEquals(3500.00, (float) $product->fresh()->delivery_price);

        $this->assertFalse(Schema::hasColumn('payment_methods', 'is_bot_available'));
        $this->assertTrue(Schema::hasColumn('payment_methods', 'account_number'));
        $this->assertTrue(Schema::hasColumn('payment_methods', 'account_name'));
        $this->assertSame('09111222333', $pm->fresh()->account_number);
        $this->assertSame('Daw Mya', $pm->fresh()->account_name);
    }

    public function test_core_seeders_complete_without_bot_or_delivery_dependencies(): void
    {
        $this->seed(\Database\Seeders\WarehouseSeeder::class);
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $this->seed(\Database\Seeders\CustomerSeeder::class);
        $this->seed(\Database\Seeders\SupplierSeeder::class);
        $this->seed(\Database\Seeders\PaymentMethodSeeder::class);

        $this->assertFalse(Schema::hasTable('bot_chat_messages'));
        $this->assertFalse(Schema::hasTable('delivery_regions'));
        $this->assertTrue(PaymentMethod::query()->exists());
        $this->assertTrue(Role::query()->exists());
    }
}
