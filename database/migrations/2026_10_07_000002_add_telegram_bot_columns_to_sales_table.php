<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'order_source')) {
                $table->string('order_source', 50)->default('pos')->after('pos_flag')->index();
            }
            if (!Schema::hasColumn('sales', 'telegram_chat_id')) {
                $table->string('telegram_chat_id', 100)->nullable()->after('order_source')->index();
            }
            if (!Schema::hasColumn('sales', 'telegram_username')) {
                $table->string('telegram_username', 100)->nullable()->after('telegram_chat_id');
            }
            if (!Schema::hasColumn('sales', 'telegram_order_id')) {
                $table->string('telegram_order_id', 100)->nullable()->after('telegram_username')->index();
            }
            if (!Schema::hasColumn('sales', 'bot_approval_status')) {
                $table->string('bot_approval_status', 50)->default('none')->after('telegram_order_id')->index();
            }
            if (!Schema::hasColumn('sales', 'bot_rejection_reason')) {
                $table->text('bot_rejection_reason')->nullable()->after('bot_approval_status');
            }
            if (!Schema::hasColumn('sales', 'payment_slip_url')) {
                $table->text('payment_slip_url')->nullable()->after('bot_rejection_reason');
            }
            if (!Schema::hasColumn('sales', 'shipping_address')) {
                $table->text('shipping_address')->nullable()->after('payment_slip_url');
            }
            if (!Schema::hasColumn('sales', 'customer_phone')) {
                $table->string('customer_phone', 50)->nullable()->after('shipping_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $cols = [
                'order_source',
                'telegram_chat_id',
                'telegram_username',
                'telegram_order_id',
                'bot_approval_status',
                'bot_rejection_reason',
                'payment_slip_url',
                'shipping_address',
                'customer_phone',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
