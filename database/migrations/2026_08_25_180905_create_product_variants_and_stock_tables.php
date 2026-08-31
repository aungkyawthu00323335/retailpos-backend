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
        // 1. Add missing enterprise columns to products table if not present
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'uuid')) {
                    $table->uuid('uuid')->nullable()->after('id');
                }
                if (!Schema::hasColumn('products', 'sku')) {
                    $table->string('sku')->nullable()->after('code');
                }
                if (!Schema::hasColumn('products', 'barcode')) {
                    $table->string('barcode')->nullable()->after('sku');
                }
                if (!Schema::hasColumn('products', 'category_id')) {
                    $table->unsignedBigInteger('category_id')->nullable()->after('barcode_symbology');
                }
                if (!Schema::hasColumn('products', 'brand_id')) {
                    $table->unsignedBigInteger('brand_id')->nullable()->after('category_id');
                }
                if (!Schema::hasColumn('products', 'base_unit_id')) {
                    $table->unsignedBigInteger('base_unit_id')->nullable()->after('brand_id');
                }
                if (!Schema::hasColumn('products', 'sale_unit_id')) {
                    $table->unsignedBigInteger('sale_unit_id')->nullable()->after('base_unit_id');
                }
                if (!Schema::hasColumn('products', 'purchase_unit_id')) {
                    $table->unsignedBigInteger('purchase_unit_id')->nullable()->after('sale_unit_id');
                }
                if (!Schema::hasColumn('products', 'cost_price')) {
                    $table->decimal('cost_price', 18, 2)->default(0.00)->after('purchase_price');
                }
                if (!Schema::hasColumn('products', 'selling_price')) {
                    $table->decimal('selling_price', 18, 2)->default(0.00)->after('retail_price');
                }
                if (!Schema::hasColumn('products', 'stock_alert')) {
                    $table->decimal('stock_alert', 18, 4)->default(0.00)->after('alert_quantity');
                }
                if (!Schema::hasColumn('products', 'has_variants')) {
                    $table->boolean('has_variants')->default(false)->after('stock_alert');
                }
                if (!Schema::hasColumn('products', 'note')) {
                    $table->text('note')->nullable()->after('description');
                }
                if (!Schema::hasColumn('products', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('status');
                }
                if (!Schema::hasColumn('products', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
                }
                if (!Schema::hasColumn('products', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('products', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // 2. Product Variants Table
        if (!Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->string('name');
                $table->string('sku')->unique();
                $table->string('barcode')->nullable()->unique();
                $table->decimal('cost_price', 18, 2)->default(0.00);
                $table->decimal('selling_price', 18, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Product Variant Values Pivot Table
        if (!Schema::hasTable('product_variant_values')) {
            Schema::create('product_variant_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_variant_id')->constrained('product_variants')->onDelete('cascade');
                $table->unsignedBigInteger('variation_attribute_id')->nullable();
                $table->unsignedBigInteger('variation_value_id')->nullable();
                $table->string('attribute_name')->nullable();
                $table->string('attribute_value')->nullable();
                $table->timestamps();
            });
        }

        // 4. Product Images Table
        if (!Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->string('disk')->default('public');
                $table->string('path', 500);
                $table->string('original_name')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // 5. Stock Balances Table
        if (!Schema::hasTable('stock_balances')) {
            Schema::create('stock_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('quantity', 18, 4)->default(0.0000);
                $table->timestamps();

                $table->unique(['warehouse_id', 'product_id', 'product_variant_id'], 'stock_balances_unique');
            });
        }

        // 6. Stock Movements Audit Table
        if (!Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->string('movement_type', 50); // e.g. opening_stock, sale, purchase, adjustment
                $table->string('reference_type', 100)->nullable(); // e.g. product_create
                $table->string('reference_id', 100)->nullable();
                $table->decimal('quantity_in', 18, 4)->default(0.0000);
                $table->decimal('quantity_out', 18, 4)->default(0.0000);
                $table->decimal('balance_after', 18, 4)->default(0.0000);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
    }
};