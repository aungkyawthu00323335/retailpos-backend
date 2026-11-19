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

        $existing = collect(DB::select('SHOW INDEX FROM stock_transfers'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('stock_transfers', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_trf_date_id', $existing, true)) {
                $table->index(['date', 'id'], 'idx_trf_date_id');
            }
            if (!in_array('idx_trf_updated_at', $existing, true)) {
                $table->index('updated_at', 'idx_trf_updated_at');
            }
            if (!in_array('idx_trf_created_at', $existing, true)) {
                $table->index('created_at', 'idx_trf_created_at');
            }
            if (!in_array('idx_trf_created_by', $existing, true)) {
                $table->index('created_by', 'idx_trf_created_by');
            }
            if (!in_array('idx_trf_user_id', $existing, true)) {
                $table->index('user_id', 'idx_trf_user_id');
            }
            if (!in_array('idx_trf_from_wh_id', $existing, true)) {
                $table->index('from_warehouse_id', 'idx_trf_from_wh_id');
            }
            if (!in_array('idx_trf_to_wh_id', $existing, true)) {
                $table->index('to_warehouse_id', 'idx_trf_to_wh_id');
            }
            if (!in_array('idx_trf_status_date', $existing, true)) {
                $table->index(['status', 'date'], 'idx_trf_status_date');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM stock_transfers'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('stock_transfers', function (Blueprint $table) use ($existing) {
            foreach ([
                'idx_trf_date_id',
                'idx_trf_updated_at',
                'idx_trf_created_at',
                'idx_trf_created_by',
                'idx_trf_user_id',
                'idx_trf_from_wh_id',
                'idx_trf_to_wh_id',
                'idx_trf_status_date',
            ] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }
};
