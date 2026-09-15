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
                if (!Schema::hasColumn('stock_adjustments', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('reference_no');
                }
                if (!Schema::hasColumn('stock_adjustments', 'user_name')) {
                    $table->string('user_name')->nullable()->after('created_by');
                }
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_adjustment_items', 'unit_id')) {
                    $table->unsignedBigInteger('unit_id')->nullable()->after('variant_name');
                }
                if (!Schema::hasColumn('stock_adjustment_items', 'unit_name')) {
                    $table->string('unit_name')->nullable()->after('unit_id');
                }
                if (!Schema::hasColumn('stock_adjustment_items', 'conversion_rate')) {
                    $table->decimal('conversion_rate', 12, 4)->default(1.0)->after('unit_name');
                }
                if (!Schema::hasColumn('stock_adjustment_items', 'base_unit_cost')) {
                    $table->decimal('base_unit_cost', 12, 4)->nullable()->after('unit_cost');
                }
                if (!Schema::hasColumn('stock_adjustment_items', 'base_quantity')) {
                    $table->decimal('base_quantity', 12, 4)->nullable()->after('quantity');
                }
                if (!Schema::hasColumn('stock_adjustment_items', 'subtotal')) {
                    $table->decimal('subtotal', 14, 4)->default(0.0)->after('unit_cost');
                }
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
                if (Schema::hasColumn('stock_adjustments', 'warehouse_id')) {
                    $table->dropColumn('warehouse_id');
                }
                if (Schema::hasColumn('stock_adjustments', 'user_name')) {
                    $table->dropColumn('user_name');
                }
            });
        }

        if (Schema::hasTable('stock_adjustment_items')) {
            Schema::table('stock_adjustment_items', function (Blueprint $table) {
                $columns = ['unit_id', 'unit_name', 'conversion_rate', 'base_unit_cost', 'base_quantity', 'subtotal'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('stock_adjustment_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
