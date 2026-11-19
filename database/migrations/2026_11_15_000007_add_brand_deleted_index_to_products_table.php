<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (
            Schema::hasTable('products') &&
            Schema::hasColumn('products', 'brand_id') &&
            Schema::hasColumn('products', 'deleted_at')
        ) {
            $existing = collect(DB::select('SHOW INDEX FROM products'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('products', function (Blueprint $table) use ($existing) {
                if (!in_array('products_brand_deleted_idx', $existing, true)) {
                    $table->index(['brand_id', 'deleted_at'], 'products_brand_deleted_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('products')) {
            $existing = collect(DB::select('SHOW INDEX FROM products'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('products', function (Blueprint $table) use ($existing) {
                if (in_array('products_brand_deleted_idx', $existing, true)) {
                    $table->dropIndex('products_brand_deleted_idx');
                }
            });
        }
    }
};
