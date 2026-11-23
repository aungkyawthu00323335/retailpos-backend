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
            'sale_items',
            ['product_id', 'sale_id', 'product_variant_id', 'quantity', 'base_quantity', 'subtotal', 'tax', 'total_cogs', 'base_unit_cost'],
            'idx_si_pm_cover'
        );
        $this->addIndexIfMissing(
            'products',
            ['id', 'cost_price', 'purchase_price', 'category_name'],
            'idx_prod_pm_cost_cat'
        );
        $this->addIndexIfMissing(
            'sale_returns',
            ['status', 'warehouse_id', 'date'],
            'idx_sr_pm_status_wh_date'
        );
    }

    public function down(): void
    {
        // Non-destructive index migration.
    }
};
