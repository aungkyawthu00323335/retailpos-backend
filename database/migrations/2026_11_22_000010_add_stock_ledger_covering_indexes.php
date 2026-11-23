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
        $this->addIndexIfMissing('purchases', ['status', 'date'], 'idx_pur_status_date');
        $this->addIndexIfMissing('sales', ['status', 'date'], 'idx_sales_status_date');
        $this->addIndexIfMissing(
            'sale_items',
            ['sale_id', 'product_id', 'product_variant_id', 'base_quantity', 'quantity', 'conversion_rate', 'total_cogs'],
            'idx_si_sl_cover'
        );
        $this->addIndexIfMissing(
            'purchase_items',
            ['purchase_id', 'product_id', 'product_variant_id', 'base_quantity', 'quantity', 'conversion_rate', 'base_unit_cost', 'unit_cost'],
            'idx_pi_sl_cover'
        );
        $this->addIndexIfMissing(
            'stock_adjustment_items',
            ['stock_adjustment_id', 'product_id', 'product_variant_id', 'base_quantity', 'quantity', 'conversion_rate', 'base_unit_cost', 'unit_cost'],
            'idx_sai_sl_cover'
        );
        $this->addIndexIfMissing(
            'stock_transfer_items',
            ['stock_transfer_id', 'product_id', 'product_variant_id', 'base_quantity', 'quantity', 'conversion_rate', 'base_unit_cost', 'unit_cost'],
            'idx_sti_sl_cover'
        );
    }

    public function down(): void
    {
        // Non-destructive index migration.
    }
};
