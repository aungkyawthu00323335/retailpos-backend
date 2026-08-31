<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            $existing = collect(Schema::getIndexes('products'))
                ->pluck('name')
                ->all();

            $indexes = [
                'products_code_lookup_index' => ['code'],
                'products_sku_lookup_index' => ['sku'],
                'products_barcode_lookup_index' => ['barcode'],
                'products_brand_name_index' => ['brand_name'],
                'products_status_category_brand_index' => [
                    'status', 'category_name', 'brand_name',
                ],
            ];

            foreach ($indexes as $name => $columns) {
                if (in_array($name, $existing, true)) {
                    continue;
                }
                Schema::table('products', function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }

        if (Schema::hasTable('product_images')) {
            $existing = collect(Schema::getIndexes('product_images'))
                ->pluck('name')
                ->all();
            if (!in_array('product_images_product_primary_index', $existing, true)) {
                Schema::table('product_images', function (Blueprint $table) {
                    $table->index(
                        ['product_id', 'is_primary'],
                        'product_images_product_primary_index',
                    );
                });
            }
        }

        if (Schema::hasTable('product_variants')) {
            $existing = collect(Schema::getIndexes('product_variants'))
                ->pluck('name')
                ->all();
            if (!in_array('product_variants_product_lookup_index', $existing, true)) {
                Schema::table('product_variants', function (Blueprint $table) {
                    $table->index('product_id', 'product_variants_product_lookup_index');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                foreach ([
                    'products_code_lookup_index',
                    'products_sku_lookup_index',
                    'products_barcode_lookup_index',
                    'products_brand_name_index',
                    'products_status_category_brand_index',
                ] as $index) {
                    try {
                        $table->dropIndex($index);
                    } catch (Throwable $e) {
                    }
                }
            });
        }

        if (Schema::hasTable('product_images')) {
            Schema::table('product_images', function (Blueprint $table) {
                try {
                    $table->dropIndex('product_images_product_primary_index');
                } catch (Throwable $e) {
                }
            });
        }

        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                try {
                    $table->dropIndex('product_variants_product_lookup_index');
                } catch (Throwable $e) {
                }
            });
        }
    }
};
