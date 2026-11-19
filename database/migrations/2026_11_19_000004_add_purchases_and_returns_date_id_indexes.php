<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases') && !Schema::hasIndex('purchases', 'purchases_date_id_idx')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->index(['date', 'id'], 'purchases_date_id_idx');
            });
        }

        if (Schema::hasTable('purchase_returns') && !Schema::hasIndex('purchase_returns', 'purchase_returns_date_id_idx')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->index(['date', 'id'], 'purchase_returns_date_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchases') && Schema::hasIndex('purchases', 'purchases_date_id_idx')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex('purchases_date_id_idx');
            });
        }

        if (Schema::hasTable('purchase_returns') && Schema::hasIndex('purchase_returns', 'purchase_returns_date_id_idx')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropIndex('purchase_returns_date_id_idx');
            });
        }
    }
};
