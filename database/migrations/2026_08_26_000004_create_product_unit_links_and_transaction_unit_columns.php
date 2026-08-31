<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_unit_links')) {
            Schema::create('product_unit_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->string('unit_type', 20); // purchase or sale
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->string('unit_name', 100);
                $table->decimal('conversion_rate', 18, 8)->default(1.00000000);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['product_id', 'product_variant_id', 'unit_type'], 'product_unit_links_lookup');
                $table->index(['unit_type', 'unit_id'], 'product_unit_links_unit_lookup');
            });
        }

        if (Schema::hasTable('product_unit_links') && Schema::hasTable('product_variants')) {
            try {
                Schema::table('product_unit_links', function (Blueprint $table) {
                    $table->foreign('product_variant_id', 'product_unit_links_variant_fk')
                        ->references('id')->on('product_variants')->onDelete('cascade');
                });
            } catch (Throwable $e) {
                // The column/index is still useful on databases where the FK already exists.
            }
        }

        foreach (['purchase_items', 'sale_items'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'product_variant_id')) {
                    $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                }
                if (!Schema::hasColumn($tableName, 'unit_id')) {
                    $table->unsignedBigInteger('unit_id')->nullable()->after('product_variant_id');
                }
                if (!Schema::hasColumn($tableName, 'unit_name')) {
                    $table->string('unit_name', 100)->nullable()->after('unit_id');
                }
                if (!Schema::hasColumn($tableName, 'conversion_rate')) {
                    $table->decimal('conversion_rate', 18, 8)->default(1.00000000)->after('unit_name');
                }
                if (!Schema::hasColumn($tableName, 'base_quantity')) {
                    $table->decimal('base_quantity', 18, 4)->default(0.0000)->after('quantity');
                }
                if ($tableName === 'purchase_items' && !Schema::hasColumn($tableName, 'base_unit_cost')) {
                    $table->decimal('base_unit_cost', 18, 8)->default(0.00000000)->after('unit_cost');
                }
                if ($tableName === 'sale_items' && !Schema::hasColumn($tableName, 'base_unit_price')) {
                    $table->decimal('base_unit_price', 18, 8)->default(0.00000000)->after('unit_price');
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index(['product_id', 'product_variant_id'], $tableName . '_product_variant_lookup');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_items', 'sale_items'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                try {
                    $table->dropIndex($tableName . '_product_variant_lookup');
                } catch (Throwable $e) {
                }
                foreach (['product_variant_id', 'unit_id', 'unit_name', 'conversion_rate', 'base_quantity', 'base_unit_cost', 'base_unit_price'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('product_unit_links');
    }
};
