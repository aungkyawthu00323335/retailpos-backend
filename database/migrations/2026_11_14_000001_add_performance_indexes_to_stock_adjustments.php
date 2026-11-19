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
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->index(['date', 'id'], 'idx_stk_adj_date_id');
                $table->index('created_by', 'idx_stk_adj_created_by');
                $table->index('user_id', 'idx_stk_adj_user_id');
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                $table->index(
                    ['stock_adjustment_id', 'type', 'subtotal', 'base_quantity'],
                    'idx_stk_adj_items_summary_cover'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->dropIndex('idx_stk_adj_date_id');
                $table->dropIndex('idx_stk_adj_created_by');
                $table->dropIndex('idx_stk_adj_user_id');
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                $table->dropIndex('idx_stk_adj_items_summary_cover');
            });
        }
    }
};
