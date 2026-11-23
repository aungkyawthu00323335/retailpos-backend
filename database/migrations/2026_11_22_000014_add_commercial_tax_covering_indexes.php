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
            ['date', 'tax_amount', 'warehouse_id', 'status', 'grand_total', 'shipping', 'id'],
            'idx_sales_ct_cover'
        );
        $this->addIndexIfMissing(
            'sales',
            ['warehouse_id', 'date', 'tax_amount', 'status', 'grand_total', 'shipping', 'id'],
            'idx_sales_ct_wh_cover'
        );
        $this->addIndexIfMissing(
            'purchases',
            ['is_tax_recoverable', 'date', 'tax_amount', 'warehouse_id', 'status', 'grand_total', 'shipping', 'id'],
            'idx_pur_ct_cover'
        );
        $this->addIndexIfMissing(
            'purchases',
            ['warehouse_id', 'is_tax_recoverable', 'date', 'tax_amount', 'status', 'grand_total', 'shipping', 'id'],
            'idx_pur_ct_wh_cover'
        );
        $this->addIndexIfMissing(
            'sale_returns',
            ['date', 'tax_amount', 'warehouse_id', 'status', 'grand_total', 'shipping', 'id'],
            'idx_sr_ct_cover'
        );
        $this->addIndexIfMissing(
            'sale_returns',
            ['warehouse_id', 'date', 'tax_amount', 'status', 'grand_total', 'shipping', 'id'],
            'idx_sr_ct_wh_cover'
        );
        $this->addIndexIfMissing(
            'purchase_returns',
            ['is_tax_recoverable', 'date', 'tax_amount', 'warehouse_id', 'status', 'grand_total', 'shipping', 'id'],
            'idx_pr_ct_cover'
        );
        $this->addIndexIfMissing(
            'purchase_returns',
            ['warehouse_id', 'is_tax_recoverable', 'date', 'tax_amount', 'status', 'grand_total', 'shipping', 'id'],
            'idx_pr_ct_wh_cover'
        );
    }

    public function down(): void
    {
        // Non-destructive index migration.
    }
};
