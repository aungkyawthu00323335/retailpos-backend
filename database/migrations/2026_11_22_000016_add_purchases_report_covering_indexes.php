<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchases')) {
            return;
        }

        $existing = [];
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            foreach (DB::select('SHOW INDEX FROM purchases') as $row) {
                $existing[$row->Key_name] = true;
            }
        }

        Schema::table('purchases', function (Blueprint $table) use ($existing) {
            if (!isset($existing['idx_pur_wh_status_pay_rcov'])) {
                $table->index(
                    ['warehouse_id', 'status', 'payment_type', 'date', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
                    'idx_pur_wh_status_pay_rcov'
                );
            }
            if (!isset($existing['idx_pur_pstat_status_rcov'])) {
                $table->index(
                    ['payment_status', 'status', 'date', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
                    'idx_pur_pstat_status_rcov'
                );
            }
            if (!isset($existing['idx_pur_sup_status_pay_rcov'])) {
                $table->index(
                    ['supplier_id', 'status', 'payment_type', 'date', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
                    'idx_pur_sup_status_pay_rcov'
                );
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('idx_pur_wh_status_pay_rcov');
            $table->dropIndex('idx_pur_pstat_status_rcov');
            $table->dropIndex('idx_pur_sup_status_pay_rcov');
        });
    }
};
