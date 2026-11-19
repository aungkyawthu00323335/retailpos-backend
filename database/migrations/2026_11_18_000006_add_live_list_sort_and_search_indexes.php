<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additional non-redundant composite indexes for zero-cache live queries,
     * covering sorts, child-item searches, and active-outlet scope checks.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'outlets' => [
            'idx_outlets_active_id' => ['is_active', 'id'],
        ],
        'products' => [
            'idx_prod_del_sell_id' => ['deleted_at', 'selling_price', 'id'],
            'idx_prod_del_cost_id' => ['deleted_at', 'cost_price', 'id'],
            'idx_prod_del_retail_id' => ['deleted_at', 'retail_price', 'id'],
        ],
        'stock_adjustments' => [
            'idx_sa_updated_at' => ['updated_at'],
        ],
        'stock_adjustment_items' => [
            'idx_sai_prod_name' => ['product_name'],
        ],
        'quotations' => [
            'idx_quo_expiry_id' => ['expiry_date', 'id'],
            'idx_quo_grand_id' => ['grand_total', 'id'],
        ],
        'quotation_items' => [
            'idx_qi_code' => ['product_code'],
            'idx_qi_name' => ['product_name'],
        ],
        'purchases' => [
            'idx_pur_grand_id' => ['grand_total', 'id'],
        ],
        'purchase_items' => [
            'idx_pi_prod_code' => ['product_code'],
        ],
        'purchase_returns' => [
            'idx_pr_grand_id' => ['grand_total', 'id'],
        ],
        'sales' => [
            'idx_sales_grand_id' => ['grand_total', 'id'],
        ],
        'sale_returns' => [
            'idx_sr_grand_id' => ['grand_total', 'id'],
        ],
        'stock_transfers' => [
            'idx_trf_ship_id' => ['shipping_charge', 'id'],
        ],
        'stock_transfer_items' => [
            'idx_sti_prod_code' => ['product_code'],
            'idx_sti_prod_name' => ['product_name'],
        ],
        'expenses' => [
            'idx_exp_amt_id' => ['amount', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (!Schema::hasColumns($tableName, $columns)) {
                    continue;
                }
                if (Schema::hasIndex($tableName, $indexName) || Schema::hasIndex($tableName, $columns)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                    $table->index($columns, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            foreach (array_keys($indexes) as $indexName) {
                if (!Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }
    }
};
