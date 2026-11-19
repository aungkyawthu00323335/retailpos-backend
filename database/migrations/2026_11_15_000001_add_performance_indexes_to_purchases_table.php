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

        $existing = collect(DB::select('SHOW INDEX FROM purchases'))->pluck('Key_name')->unique()->all();

        Schema::table('purchases', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_pur_date_id', $existing, true)) {
                $table->index(['date', 'id'], 'idx_pur_date_id');
            }
            if (!in_array('idx_pur_updated_at', $existing, true)) {
                $table->index('updated_at', 'idx_pur_updated_at');
            }
            if (!in_array('idx_pur_created_by', $existing, true)) {
                $table->index('created_by', 'idx_pur_created_by');
            }
            if (!in_array('idx_pur_user_id', $existing, true)) {
                $table->index('user_id', 'idx_pur_user_id');
            }
            if (!in_array('idx_pur_warehouse_id', $existing, true)) {
                $table->index('warehouse_id', 'idx_pur_warehouse_id');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM purchases'))->pluck('Key_name')->unique()->all();

        Schema::table('purchases', function (Blueprint $table) use ($existing) {
            foreach (['idx_pur_date_id', 'idx_pur_updated_at', 'idx_pur_created_by', 'idx_pur_user_id', 'idx_pur_warehouse_id'] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }
};
