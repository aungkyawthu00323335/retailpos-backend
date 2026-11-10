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
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category_id')) {
                $table->index('category_id', 'products_category_id_index');
            }
            if (Schema::hasColumn('products', 'brand_id')) {
                $table->index('brand_id', 'products_brand_id_index');
            }
            if (Schema::hasColumn('products', 'sub_category_id')) {
                $table->index('sub_category_id', 'products_sub_category_id_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_id_index');
            $table->dropIndex('products_brand_id_index');
            $table->dropIndex('products_sub_category_id_index');
        });
    }
};
