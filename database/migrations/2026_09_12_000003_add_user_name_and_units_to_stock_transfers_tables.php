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
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfers', 'from_warehouse_id')) {
                    $table->unsignedBigInteger('from_warehouse_id')->nullable()->after('from_warehouse');
                }
                if (!Schema::hasColumn('stock_transfers', 'to_warehouse_id')) {
                    $table->unsignedBigInteger('to_warehouse_id')->nullable()->after('to_warehouse');
                }
                if (!Schema::hasColumn('stock_transfers', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
                }
            });
        }

        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfer_items', 'unit_id')) {
                    $table->unsignedBigInteger('unit_id')->nullable()->after('product_variant_id');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'unit_name')) {
                    $table->string('unit_name', 100)->nullable()->after('unit_id');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'conversion_rate')) {
                    $table->decimal('conversion_rate', 18, 8)->default(1.0)->after('unit_name');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'base_unit_cost')) {
                    $table->decimal('base_unit_cost', 18, 8)->default(0.0)->after('unit_cost');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'base_quantity')) {
                    $table->decimal('base_quantity', 18, 4)->default(0.0)->after('quantity');
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
                $columns = ['unit_id', 'unit_name', 'conversion_rate', 'base_unit_cost', 'base_quantity'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('stock_transfer_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $columns = ['from_warehouse_id', 'to_warehouse_id', 'user_name'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('stock_transfers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
