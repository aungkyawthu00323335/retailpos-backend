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
        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if (!Schema::hasColumn('quotations', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('customer_name');
                }
                if (!Schema::hasColumn('quotations', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('quotations', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('quotations', 'user_role')) {
                    $table->string('user_role')->nullable()->after('user_name');
                }
            });
        }

        if (Schema::hasTable('quotation_items')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                if (!Schema::hasColumn('quotation_items', 'product_variant_id')) {
                    $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                }
                if (!Schema::hasColumn('quotation_items', 'unit_id')) {
                    $table->unsignedBigInteger('unit_id')->nullable()->after('product_variant_id');
                }
                if (!Schema::hasColumn('quotation_items', 'unit_name')) {
                    $table->string('unit_name', 100)->nullable()->after('unit_id');
                }
                if (!Schema::hasColumn('quotation_items', 'conversion_rate')) {
                    $table->decimal('conversion_rate', 18, 8)->default(1.0)->after('unit_name');
                }
                if (!Schema::hasColumn('quotation_items', 'base_unit_price')) {
                    $table->decimal('base_unit_price', 18, 8)->default(0.0)->after('unit_price');
                }
                if (!Schema::hasColumn('quotation_items', 'base_quantity')) {
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
        if (Schema::hasTable('quotation_items')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $columns = ['base_quantity', 'base_unit_price', 'conversion_rate', 'unit_name', 'unit_id', 'product_variant_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('quotation_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                $columns = ['user_role', 'user_name', 'user_id', 'warehouse_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('quotations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
