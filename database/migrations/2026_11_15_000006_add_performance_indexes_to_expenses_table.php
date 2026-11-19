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

        if (Schema::hasTable('expenses')) {
            $existing = collect(DB::select('SHOW INDEX FROM expenses'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('expenses', function (Blueprint $table) use ($existing) {
                if (!in_array('idx_exp_date_id', $existing, true)) {
                    $table->index(['date', 'id'], 'idx_exp_date_id');
                }
                if (!in_array('idx_exp_updated_at', $existing, true)) {
                    $table->index('updated_at', 'idx_exp_updated_at');
                }
                if (!in_array('idx_exp_created_by', $existing, true)) {
                    $table->index('created_by', 'idx_exp_created_by');
                }
                if (!in_array('idx_exp_user_id', $existing, true)) {
                    $table->index('user_id', 'idx_exp_user_id');
                }
                if (!in_array('idx_exp_user_name', $existing, true)) {
                    $table->index('user_name', 'idx_exp_user_name');
                }
                if (!in_array('idx_exp_warehouse_id', $existing, true)) {
                    $table->index('warehouse_id', 'idx_exp_warehouse_id');
                }
            });
        }

        if (Schema::hasTable('expense_payments')) {
            $existingPay = collect(DB::select('SHOW INDEX FROM expense_payments'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('expense_payments', function (Blueprint $table) use ($existingPay) {
                if (!in_array('idx_exp_pay_exp_type_amt', $existingPay, true)) {
                    $table->index(['expense_id', 'payment_type', 'amount'], 'idx_exp_pay_exp_type_amt');
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('expenses')) {
            $existing = collect(DB::select('SHOW INDEX FROM expenses'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('expenses', function (Blueprint $table) use ($existing) {
                foreach ([
                    'idx_exp_date_id',
                    'idx_exp_updated_at',
                    'idx_exp_created_by',
                    'idx_exp_user_id',
                    'idx_exp_user_name',
                    'idx_exp_warehouse_id',
                ] as $idx) {
                    if (in_array($idx, $existing, true)) {
                        $table->dropIndex($idx);
                    }
                }
            });
        }

        if (Schema::hasTable('expense_payments')) {
            $existingPay = collect(DB::select('SHOW INDEX FROM expense_payments'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('expense_payments', function (Blueprint $table) use ($existingPay) {
                if (in_array('idx_exp_pay_exp_type_amt', $existingPay, true)) {
                    $table->dropIndex('idx_exp_pay_exp_type_amt');
                }
            });
        }
    }
};
