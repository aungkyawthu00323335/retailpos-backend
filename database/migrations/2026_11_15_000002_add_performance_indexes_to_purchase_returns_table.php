<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasTable('purchase_returns')) {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM purchase_returns'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('purchase_returns', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_pr_date_id', $existing, true)) {
                $table->index(['date', 'id'], 'idx_pr_date_id');
            }
            if (!in_array('idx_pr_updated_at', $existing, true)) {
                $table->index('updated_at', 'idx_pr_updated_at');
            }
            if (!in_array('idx_pr_created_by', $existing, true)) {
                $table->index('created_by', 'idx_pr_created_by');
            }
            if (!in_array('idx_pr_user_id', $existing, true)) {
                $table->index('user_id', 'idx_pr_user_id');
            }
            if (!in_array('idx_pr_warehouse_id', $existing, true)) {
                $table->index('warehouse_id', 'idx_pr_warehouse_id');
            }
            if (!in_array('idx_pr_payment_type', $existing, true)) {
                $table->index('payment_type', 'idx_pr_payment_type');
            }
            if (!in_array('idx_pr_return_reason', $existing, true)) {
                $table->index('return_reason', 'idx_pr_return_reason');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasTable('purchase_returns')) {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM purchase_returns'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('purchase_returns', function (Blueprint $table) use ($existing) {
            foreach ([
                'idx_pr_date_id',
                'idx_pr_updated_at',
                'idx_pr_created_by',
                'idx_pr_user_id',
                'idx_pr_warehouse_id',
                'idx_pr_payment_type',
                'idx_pr_return_reason',
            ] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }
};
