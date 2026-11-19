<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotations')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $exists = !empty(DB::select(
                "SHOW INDEX FROM `quotations` WHERE Key_name = ?",
                ['idx_quo_date_id']
            ));
            if (!$exists) {
                Schema::table('quotations', function (Blueprint $table) {
                    $table->index(['date', 'id'], 'idx_quo_date_id');
                });
            }
            return;
        }

        try {
            Schema::table('quotations', function (Blueprint $table) {
                $table->index(['date', 'id'], 'idx_quo_date_id');
            });
        } catch (\Throwable $e) {
            // Index already exists
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('quotations')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $exists = !empty(DB::select(
                "SHOW INDEX FROM `quotations` WHERE Key_name = ?",
                ['idx_quo_date_id']
            ));
            if ($exists) {
                Schema::table('quotations', function (Blueprint $table) {
                    $table->dropIndex('idx_quo_date_id');
                });
            }
            return;
        }

        try {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropIndex('idx_quo_date_id');
            });
        } catch (\Throwable $e) {
            // Index does not exist
        }
    }
};
