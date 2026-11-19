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

        $existing = collect(DB::select('SHOW INDEX FROM sale_returns'))->pluck('Key_name')->unique()->all();

        Schema::table('sale_returns', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_sr_date_id', $existing, true)) {
                $table->index(['date', 'id'], 'idx_sr_date_id');
            }
            if (!in_array('idx_sr_updated_at', $existing, true)) {
                $table->index('updated_at', 'idx_sr_updated_at');
            }
            if (!in_array('idx_sr_created_by', $existing, true)) {
                $table->index('created_by', 'idx_sr_created_by');
            }
            if (!in_array('idx_sr_user_id', $existing, true)) {
                $table->index('user_id', 'idx_sr_user_id');
            }
            if (!in_array('idx_sr_warehouse_id', $existing, true)) {
                $table->index('warehouse_id', 'idx_sr_warehouse_id');
            }
            if (!in_array('idx_sr_payment_status', $existing, true)) {
                $table->index('payment_status', 'idx_sr_payment_status');
            }
            if (!in_array('idx_sr_payment_type', $existing, true)) {
                $table->index('payment_type', 'idx_sr_payment_type');
            }
            if (!in_array('idx_sr_refund_tender', $existing, true)) {
                $table->index('refund_tender', 'idx_sr_refund_tender');
            }
            if (!in_array('idx_sr_return_reason', $existing, true)) {
                $table->index('return_reason', 'idx_sr_return_reason');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM sale_returns'))->pluck('Key_name')->unique()->all();

        Schema::table('sale_returns', function (Blueprint $table) use ($existing) {
            foreach ([
                'idx_sr_date_id',
                'idx_sr_updated_at',
                'idx_sr_created_by',
                'idx_sr_user_id',
                'idx_sr_warehouse_id',
                'idx_sr_payment_status',
                'idx_sr_payment_type',
                'idx_sr_refund_tender',
                'idx_sr_return_reason',
            ] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }
};
