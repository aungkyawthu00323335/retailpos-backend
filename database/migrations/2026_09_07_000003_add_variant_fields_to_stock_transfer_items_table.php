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
        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfer_items', 'product_variant_id')) {
                    $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                    $table->index('product_variant_id', 'idx_stk_trf_items_variant_id');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'variant_name')) {
                    $table->string('variant_name')->nullable()->after('product_name');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                if (Schema::hasColumn('stock_transfer_items', 'product_variant_id')) {
                    $table->dropIndex('idx_stk_trf_items_variant_id');
                    $table->dropColumn('product_variant_id');
                }
                if (Schema::hasColumn('stock_transfer_items', 'variant_name')) {
                    $table->dropColumn('variant_name');
                }
            });
        }
    }
};
