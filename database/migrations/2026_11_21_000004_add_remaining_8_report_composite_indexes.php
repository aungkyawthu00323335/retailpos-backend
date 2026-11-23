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
        $this->addIndexIfMissing('purchases', ['status', 'date', 'supplier_id', 'grand_total', 'paid_amount', 'due_amount'], 'idx_pur_status_date_sup_amt');
        $this->addIndexIfMissing('purchase_returns', ['status', 'date', 'supplier_id', 'grand_total', 'paid_amount', 'due_amount'], 'idx_pr_status_date_sup_amt');
        $this->addIndexIfMissing('sales', ['status', 'date', 'customer_id', 'grand_total', 'paid_amount', 'due_amount'], 'idx_sales_status_date_cust_amt');
        $this->addIndexIfMissing('stock_transfers', ['status', 'from_warehouse_id', 'to_warehouse_id'], 'idx_st_status_warehouses');
        $this->addIndexIfMissing('products', ['deleted_at', 'has_variants', 'stock_quantity', 'alert_quantity'], 'idx_prod_del_stock_alert');
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
        $drop('purchases', 'idx_pur_status_date_sup_amt');
        $drop('purchase_returns', 'idx_pr_status_date_sup_amt');
        $drop('sales', 'idx_sales_status_date_cust_amt');
        $drop('stock_transfers', 'idx_st_status_warehouses');
        $drop('products', 'idx_prod_del_stock_alert');
    }
};
