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
        // 1. Index products.uuid
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'uuid')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('uuid', 'products_uuid_index');
            });
        }

        // 2. Add uuid to master data tables
        $tables = [
            'product_categories',
            'sub_categories',
            'brands',
            'suppliers',
            'product_units',
            'sale_units',
            'purchase_units',
            'outlets',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'uuid')) {
                        $table->uuid('uuid')->nullable()->unique();
                    }
                });
            }
        }

        // 3. Add code to outlets
        if (Schema::hasTable('outlets') && ! Schema::hasColumn('outlets', 'code')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->unique();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'uuid')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_uuid_index');
            });
        }

        if (Schema::hasTable('outlets') && Schema::hasColumn('outlets', 'code')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }

        $tables = [
            'product_categories',
            'sub_categories',
            'brands',
            'suppliers',
            'product_units',
            'sale_units',
            'purchase_units',
            'outlets',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'uuid')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('uuid');
                });
            }
        }
    }
};
