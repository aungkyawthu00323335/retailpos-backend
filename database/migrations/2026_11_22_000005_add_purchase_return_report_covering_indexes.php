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
            'purchase_returns',
            ['status', 'warehouse_id', 'date', 'grand_total', 'paid_amount', 'due_amount'],
            'idx_pr_rpt_status_wh_date_amt'
        );
        $this->addIndexIfMissing(
            'purchase_returns',
            ['supplier_name'],
            'idx_pr_supplier_name'
        );
        $this->addIndexIfMissing(
            'purchase_returns',
            ['user_name'],
            'idx_pr_user_name'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_returns')) {
            foreach (['idx_pr_rpt_status_wh_date_amt', 'idx_pr_supplier_name', 'idx_pr_user_name'] as $idx) {
                try {
                    Schema::table('purchase_returns', fn (Blueprint $t) => $t->dropIndex($idx));
                } catch (\Throwable $e) {
                }
            }
        }
    }
};
