<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($rows);
    }

    public function up(): void
    {
        if (
            Schema::hasTable('sale_payments')
            && Schema::hasColumn('sale_payments', 'payment_type')
            && Schema::hasColumn('sale_payments', 'amount')
            && Schema::hasColumn('sale_payments', 'sale_id')
            && !$this->indexExists('sale_payments', 'idx_sp_pt_amt_sale')
        ) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->index(['payment_type', 'amount', 'sale_id'], 'idx_sp_pt_amt_sale');
            });
        }

        if (
            Schema::hasTable('sales')
            && Schema::hasColumn('sales', 'late_offline_reconciliation')
            && Schema::hasColumn('sales', 'status')
            && !$this->indexExists('sales', 'idx_sales_late_status')
        ) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['late_offline_reconciliation', 'status'], 'idx_sales_late_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_payments') && $this->indexExists('sale_payments', 'idx_sp_pt_amt_sale')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex('idx_sp_pt_amt_sale');
            });
        }
        if (Schema::hasTable('sales') && $this->indexExists('sales', 'idx_sales_late_status')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_late_status');
            });
        }
    }
};
