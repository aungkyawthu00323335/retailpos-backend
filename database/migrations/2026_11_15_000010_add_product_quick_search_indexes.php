<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MYSQL_INDEXES = [
        'products' => [
            'products_alt_name_idx' => ['alternative_name'],
        ],
        'product_variants' => [
            'product_variants_name_idx' => ['name'],
        ],
        'product_variant_values' => [
            'pvv_attr_value_idx' => ['attribute_value'],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::MYSQL_INDEXES as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (! empty(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]))) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                    $table->index($columns, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::MYSQL_INDEXES as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (array_keys($indexes) as $indexName) {
                if (empty(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]))) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }
    }
};
