<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) {
                return;
            }
        }
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $exists = DB::select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$table, $indexName]
            );
            if (!empty($exists)) {
                return;
            }
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $idx) {
                if (($idx->name ?? '') === $indexName) {
                    return;
                }
            }
        }
        Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
            $t->index($columns, $indexName);
        });
    }

    public function up(): void
    {
        $this->addIndexIfMissing(
            'sale_payments',
            ['payment_date', 'payment_type', 'sale_id', 'amount'],
            'idx_sp_cf_paydate_type_sale_amt'
        );
        $this->addIndexIfMissing(
            'expense_payments',
            ['payment_date', 'payment_type', 'expense_id', 'amount'],
            'idx_ep_cf_paydate_type_exp_amt'
        );
        $this->addIndexIfMissing(
            'sales',
            ['date', 'status', 'warehouse_id', 'paid_amount'],
            'idx_sales_cf_date_status_wh_paid'
        );
        $this->addIndexIfMissing(
            'purchases',
            ['status', 'paid_amount', 'date', 'warehouse_id'],
            'idx_pur_cf_status_paid_date_wh'
        );
        $this->addIndexIfMissing(
            'purchase_returns',
            ['status', 'paid_amount', 'date', 'warehouse_id'],
            'idx_pr_cf_status_paid_date_wh'
        );
        $this->addIndexIfMissing(
            'sale_returns',
            ['paid_amount', 'status', 'date', 'warehouse_id'],
            'idx_sr_cf_paid_status_date_wh'
        );
        $this->addIndexIfMissing(
            'expenses',
            ['date', 'payment_status', 'warehouse_id', 'amount'],
            'idx_exp_cf_date_pstat_wh_amt'
        );
    }

    public function down(): void
    {
        // Non-destructive index migration.
    }
};
