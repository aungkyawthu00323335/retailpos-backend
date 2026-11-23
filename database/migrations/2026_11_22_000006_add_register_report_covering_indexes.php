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
            ['status', 'date', 'warehouse_id', 'grand_total'],
            'idx_sales_reg_rpt_cover'
        );
        $this->addIndexIfMissing(
            'sale_payments',
            ['sale_id', 'payment_type', 'amount'],
            'idx_sp_sale_pt_amt'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            try {
                Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('idx_sales_reg_rpt_cover'));
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('sale_payments')) {
            try {
                Schema::table('sale_payments', fn (Blueprint $t) => $t->dropIndex('idx_sp_sale_pt_amt'));
            } catch (\Throwable $e) {
            }
        }
    }
};
