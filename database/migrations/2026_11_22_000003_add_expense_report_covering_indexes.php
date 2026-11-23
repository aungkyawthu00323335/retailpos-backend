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
            'expenses',
            ['payment_status', 'date', 'warehouse_id', 'amount', 'paid_amount', 'due_amount'],
            'idx_exp_rpt_pstat_date_amt'
        );
        $this->addIndexIfMissing(
            'expenses',
            ['expense_category_name', 'payment_method', 'amount'],
            'idx_exp_cat_pm_amt'
        );
    }

    public function down(): void
    {
        $drop = function (string $table, string $indexName) {
            if (Schema::hasTable($table)) {
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
                } catch (\Throwable $e) {
                }
            }
        };
        $drop('expenses', 'idx_exp_rpt_pstat_date_amt');
        $drop('expenses', 'idx_exp_cat_pm_amt');
    }
};
