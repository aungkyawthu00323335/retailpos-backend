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
            'sales',
            ['status', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
            'idx_sales_sr_status_rcov'
        );
        $this->addIndexIfMissing(
            'sales',
            ['warehouse_id', 'status', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
            'idx_sales_sr_wh_status_rcov'
        );
        $this->addIndexIfMissing(
            'sales',
            ['status', 'payment_status', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount'],
            'idx_sales_sr_pay_rcov'
        );
    }

    public function down(): void
    {
        // Non-destructive index migration.
    }
};
