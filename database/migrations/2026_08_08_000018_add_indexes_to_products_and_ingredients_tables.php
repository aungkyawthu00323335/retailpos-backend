<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('name');
                $table->index('category_name');
                $table->index('status');
                $table->index('purchase_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['name']);
                $table->dropIndex(['category_name']);
                $table->dropIndex(['status']);
                $table->dropIndex(['purchase_price']);
            });
        }
    }
};
