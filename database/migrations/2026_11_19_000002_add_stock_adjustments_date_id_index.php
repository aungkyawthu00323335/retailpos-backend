<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_adjustments')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $exists = !empty(DB::select(
                "SHOW INDEX FROM `stock_adjustments` WHERE Key_name = ?",
                ['idx_stk_adj_date_id']
            ));
            if (!$exists) {
                Schema::table('stock_adjustments', function (Blueprint $table) {
                    $table->index(['date', 'id'], 'idx_stk_adj_date_id');
                });
            }
            return;
        }

        // SQLite / other drivers
        try {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->index(['date', 'id'], 'idx_stk_adj_date_id');
            });
        } catch (\Throwable $e) {
            // Index already exists
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_adjustments')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $exists = !empty(DB::select(
                "SHOW INDEX FROM `stock_adjustments` WHERE Key_name = ?",
                ['idx_stk_adj_date_id']
            ));
            if ($exists) {
                Schema::table('stock_adjustments', function (Blueprint $table) {
                    $table->dropIndex('idx_stk_adj_date_id');
                });
            }
            return;
        }

        try {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->dropIndex('idx_stk_adj_date_id');
            });
        } catch (\Throwable $e) {
            // Index does not exist
        }
    }
};
