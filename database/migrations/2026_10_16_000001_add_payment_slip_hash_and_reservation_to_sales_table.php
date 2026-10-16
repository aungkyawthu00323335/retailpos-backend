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
        // 1. Sales Table Enhancements (F01, F06, F07)
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'payment_slip_hash')) {
                $table->string('payment_slip_hash', 64)->nullable()->index()->after('payment_slip_url');
            }
            if (!Schema::hasColumn('sales', 'payment_slip_phash')) {
                $table->string('payment_slip_phash', 64)->nullable()->index()->after('payment_slip_hash');
            }
            if (!Schema::hasColumn('sales', 'stock_reserved')) {
                $table->boolean('stock_reserved')->default(false)->index()->after('bot_rejection_reason');
            }
            if (!Schema::hasColumn('sales', 'stock_reserved_at')) {
                $table->timestamp('stock_reserved_at')->nullable()->index()->after('stock_reserved');
            }
            if (!Schema::hasColumn('sales', 'stock_released_at')) {
                $table->timestamp('stock_released_at')->nullable()->after('stock_reserved_at');
            }
        });

        // 2. Stock Balances Table Enhancements (F06)
        if (Schema::hasTable('stock_balances')) {
            Schema::table('stock_balances', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_balances', 'reserved_quantity')) {
                    $table->decimal('reserved_quantity', 18, 4)->default(0.0000)->after('quantity');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $cols = ['payment_slip_hash', 'payment_slip_phash', 'stock_reserved', 'stock_reserved_at', 'stock_released_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('stock_balances')) {
            Schema::table('stock_balances', function (Blueprint $table) {
                if (Schema::hasColumn('stock_balances', 'reserved_quantity')) {
                    $table->dropColumn('reserved_quantity');
                }
            });
        }
    }
};
