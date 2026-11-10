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
        // 1. Products stock_quantity index for fast sorting & valuation
        if (Schema::hasTable('products') && !Schema::hasIndex('products', 'products_stock_quantity_idx')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('stock_quantity', 'products_stock_quantity_idx');
            });
        }

        // 2. Sales composite index for dashboard period & pulse queries
        if (Schema::hasTable('sales') && !Schema::hasIndex('sales', 'idx_sales_dashboard_query')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['warehouse_name', 'status', 'date'], 'idx_sales_dashboard_query');
            });
        }

        // 3. Purchases composite index for dashboard period queries
        if (Schema::hasTable('purchases') && !Schema::hasIndex('purchases', 'idx_purchases_dashboard_query')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->index(['warehouse_name', 'status', 'date'], 'idx_purchases_dashboard_query');
            });
        }

        // 4. Sale returns composite index
        if (Schema::hasTable('sale_returns') && !Schema::hasIndex('sale_returns', 'idx_sr_dashboard_query')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->index(['warehouse_name', 'status', 'date'], 'idx_sr_dashboard_query');
            });
        }

        // 5. Purchase returns composite index
        if (Schema::hasTable('purchase_returns') && !Schema::hasIndex('purchase_returns', 'idx_pr_dashboard_query')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->index(['warehouse_name', 'date'], 'idx_pr_dashboard_query');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasIndex('products', 'products_stock_quantity_idx')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_stock_quantity_idx');
            });
        }
        if (Schema::hasTable('sales') && Schema::hasIndex('sales', 'idx_sales_dashboard_query')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_dashboard_query');
            });
        }
        if (Schema::hasTable('purchases') && Schema::hasIndex('purchases', 'idx_purchases_dashboard_query')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex('idx_purchases_dashboard_query');
            });
        }
        if (Schema::hasTable('sale_returns') && Schema::hasIndex('sale_returns', 'idx_sr_dashboard_query')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropIndex('idx_sr_dashboard_query');
            });
        }
        if (Schema::hasTable('purchase_returns') && Schema::hasIndex('purchase_returns', 'idx_pr_dashboard_query')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropIndex('idx_pr_dashboard_query');
            });
        }
    }
};
