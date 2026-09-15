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
        Schema::table('stock_balances', function (Blueprint $table) {
            // Covering index for product stock aggregation by warehouse
            $table->index(['product_id', 'warehouse_id', 'quantity'], 'stock_balances_prod_wh_qty_idx');
            // Covering index for variant stock aggregation by warehouse
            $table->index(['product_variant_id', 'warehouse_id', 'quantity'], 'stock_balances_var_wh_qty_idx');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Composite lookup for active variant list by product
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_variants_prod_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropIndex('stock_balances_prod_wh_qty_idx');
            $table->dropIndex('stock_balances_var_wh_qty_idx');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('product_variants_prod_active_idx');
        });
    }
};
