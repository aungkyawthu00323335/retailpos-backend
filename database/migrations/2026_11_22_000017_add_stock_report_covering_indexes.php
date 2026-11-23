<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function existingIndexes(string $table): array
    {
        $existing = [];
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
                $existing[$row->Key_name] = true;
            }
        }
        return $existing;
    }

    public function up(): void
    {
        if (Schema::hasTable('stock_balances')) {
            $existing = $this->existingIndexes('stock_balances');
            Schema::table('stock_balances', function (Blueprint $table) use ($existing) {
                if (!isset($existing['idx_sb_prod_var_wh_qty'])) {
                    $table->index(
                        ['product_id', 'product_variant_id', 'warehouse_id', 'quantity'],
                        'idx_sb_prod_var_wh_qty'
                    );
                }
            });
        }

        if (Schema::hasTable('inventory_batches')) {
            $existing = $this->existingIndexes('inventory_batches');
            Schema::table('inventory_batches', function (Blueprint $table) use ($existing) {
                if (!isset($existing['idx_ib_status_prod_var_rem'])) {
                    $table->index(
                        ['status', 'product_id', 'product_variant_id', 'remaining_quantity', 'unit_cost'],
                        'idx_ib_status_prod_var_rem'
                    );
                }
                if (!isset($existing['idx_ib_status_wh_prod_var_rem'])) {
                    $table->index(
                        ['status', 'warehouse_id', 'product_id', 'product_variant_id', 'remaining_quantity', 'unit_cost'],
                        'idx_ib_status_wh_prod_var_rem'
                    );
                }
            });
        }

        if (Schema::hasTable('products')) {
            $existing = $this->existingIndexes('products');
            Schema::table('products', function (Blueprint $table) use ($existing) {
                if (!isset($existing['idx_prod_del_var_stock_cover'])) {
                    $table->index(
                        ['deleted_at', 'has_variants', 'stock_quantity', 'alert_quantity', 'stock_alert', 'low_qty', 'cost_price', 'purchase_price', 'id'],
                        'idx_prod_del_var_stock_cover'
                    );
                }
            });
        }

        if (Schema::hasTable('product_variants')) {
            $existing = $this->existingIndexes('product_variants');
            Schema::table('product_variants', function (Blueprint $table) use ($existing) {
                if (!isset($existing['idx_pv_prod_cost_del'])) {
                    $table->index(
                        ['product_id', 'deleted_at', 'cost_price'],
                        'idx_pv_prod_cost_del'
                    );
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_balances')) {
            $existing = $this->existingIndexes('stock_balances');
            Schema::table('stock_balances', function (Blueprint $table) use ($existing) {
                if (isset($existing['idx_sb_prod_var_wh_qty']) || DB::getDriverName() === 'sqlite') {
                    $table->dropIndex('idx_sb_prod_var_wh_qty');
                }
            });
        }

        if (Schema::hasTable('inventory_batches')) {
            $existing = $this->existingIndexes('inventory_batches');
            Schema::table('inventory_batches', function (Blueprint $table) use ($existing) {
                if (isset($existing['idx_ib_status_prod_var_rem']) || DB::getDriverName() === 'sqlite') {
                    $table->dropIndex('idx_ib_status_prod_var_rem');
                }
                if (isset($existing['idx_ib_status_wh_prod_var_rem']) || DB::getDriverName() === 'sqlite') {
                    $table->dropIndex('idx_ib_status_wh_prod_var_rem');
                }
            });
        }

        if (Schema::hasTable('products')) {
            $existing = $this->existingIndexes('products');
            Schema::table('products', function (Blueprint $table) use ($existing) {
                if (isset($existing['idx_prod_del_var_stock_cover']) || DB::getDriverName() === 'sqlite') {
                    $table->dropIndex('idx_prod_del_var_stock_cover');
                }
            });
        }

        if (Schema::hasTable('product_variants')) {
            $existing = $this->existingIndexes('product_variants');
            Schema::table('product_variants', function (Blueprint $table) use ($existing) {
                if (isset($existing['idx_pv_prod_cost_del']) || DB::getDriverName() === 'sqlite') {
                    $table->dropIndex('idx_pv_prod_cost_del');
                }
            });
        }
    }
};
