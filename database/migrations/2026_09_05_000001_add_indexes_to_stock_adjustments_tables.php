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
                $table->index('warehouse_name', 'idx_stk_adj_warehouse');
                $table->index('type', 'idx_stk_adj_type');
                $table->index('date', 'idx_stk_adj_date');
                $table->index('created_at', 'idx_stk_adj_created_at');
                $table->index(['warehouse_name', 'date'], 'idx_stk_adj_wh_date');
                $table->index(['warehouse_name', 'type'], 'idx_stk_adj_wh_type');
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                $table->index('product_id', 'idx_stk_adj_items_prod_id');
                $table->index('product_code', 'idx_stk_adj_items_prod_code');
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
                $table->dropIndex('idx_stk_adj_warehouse');
                $table->dropIndex('idx_stk_adj_type');
                $table->dropIndex('idx_stk_adj_date');
                $table->dropIndex('idx_stk_adj_created_at');
                $table->dropIndex('idx_stk_adj_wh_date');
                $table->dropIndex('idx_stk_adj_wh_type');
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                $table->dropIndex('idx_stk_adj_items_prod_id');
                $table->dropIndex('idx_stk_adj_items_prod_code');
            });
        }
    }
};
