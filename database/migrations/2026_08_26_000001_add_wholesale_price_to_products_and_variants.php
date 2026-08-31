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
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'supplier_id')) {
                    $table->unsignedBigInteger('supplier_id')->nullable()->after('brand_name');
                }
                if (!Schema::hasColumn('products', 'supplier_name')) {
                    $table->string('supplier_name')->nullable()->after('supplier_id');
                }
                if (!Schema::hasColumn('products', 'wholesale_price')) {
                    $table->decimal('wholesale_price', 18, 2)->default(0.00)->after('selling_price');
                }
            });
        }

        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                if (!Schema::hasColumn('product_variants', 'wholesale_price')) {
                    $table->decimal('wholesale_price', 18, 2)->default(0.00)->after('selling_price');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'supplier_name')) {
                    $table->dropColumn('supplier_name');
                }
                if (Schema::hasColumn('products', 'supplier_id')) {
                    $table->dropColumn('supplier_id');
                }
                if (Schema::hasColumn('products', 'wholesale_price')) {
                    $table->dropColumn('wholesale_price');
                }
            });
        }

        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                if (Schema::hasColumn('product_variants', 'wholesale_price')) {
                    $table->dropColumn('wholesale_price');
                }
            });
        }
    }
};
