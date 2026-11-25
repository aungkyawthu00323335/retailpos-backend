<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIT_COST_TARGETS = [
        ['table' => 'inventory_batches', 'column' => 'unit_cost'],
        ['table' => 'sale_item_cost_layers', 'column' => 'unit_cost'],
        ['table' => 'sale_return_cost_layers', 'column' => 'unit_cost'],
        ['table' => 'purchase_return_cost_layers', 'column' => 'unit_cost'],
        ['table' => 'adjustment_item_cost_layers', 'column' => 'unit_cost'],
        ['table' => 'stock_transfer_cost_layers', 'column' => 'unit_cost'],
        ['table' => 'sale_items', 'column' => 'base_unit_cost'],
        ['table' => 'stock_adjustment_items', 'column' => 'base_unit_cost'],
        ['table' => 'stock_transfer_items', 'column' => 'base_unit_cost'],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::UNIT_COST_TARGETS as $target) {
            $table = $target['table'];
            $column = $target['column'];
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` DECIMAL(24, 8) NOT NULL DEFAULT 0.00000000"
                );
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::UNIT_COST_TARGETS as $target) {
            $table = $target['table'];
            $column = $target['column'];
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` DECIMAL(18, 4) NOT NULL DEFAULT 0.0000"
                );
            }
        }
    }
};
