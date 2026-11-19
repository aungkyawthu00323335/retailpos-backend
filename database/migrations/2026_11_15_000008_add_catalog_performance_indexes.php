<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'products' => [
            'products_category_deleted_idx' => ['category_id', 'deleted_at'],
            'products_base_unit_deleted_idx' => ['base_unit_id', 'deleted_at'],
            'products_sale_unit_deleted_idx' => ['sale_unit_id', 'deleted_at'],
            'products_purchase_unit_deleted_idx' => ['purchase_unit_id', 'deleted_at'],
        ],
        'sale_items' => [
            'sale_items_unit_id_idx' => ['unit_id'],
        ],
        'purchase_items' => [
            'purchase_items_unit_id_idx' => ['unit_id'],
        ],
        'product_variant_values' => [
            'pvv_attr_variant_idx' => ['attribute_name', 'product_variant_id'],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::INDEXES as $tableName => $indexes) {
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

        foreach (self::INDEXES as $tableName => $indexes) {
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
