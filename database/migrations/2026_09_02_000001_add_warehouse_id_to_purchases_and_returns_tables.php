<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases') && !Schema::hasColumn('purchases', 'warehouse_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('supplier_name');
            });
        }

        if (Schema::hasTable('purchase_returns') && !Schema::hasColumn('purchase_returns', 'warehouse_id')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('supplier_name');
            });
        }

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'warehouse_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('customer_name');
            });
        }

        if (Schema::hasTable('sale_returns') && !Schema::hasColumn('sale_returns', 'warehouse_id')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('customer_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'warehouse_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropColumn('warehouse_id');
            });
        }

        if (Schema::hasTable('purchase_returns') && Schema::hasColumn('purchase_returns', 'warehouse_id')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropColumn('warehouse_id');
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'warehouse_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('warehouse_id');
            });
        }

        if (Schema::hasTable('sale_returns') && Schema::hasColumn('sale_returns', 'warehouse_id')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
