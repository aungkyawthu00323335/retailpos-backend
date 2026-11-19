<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite filter/sort indexes across all 9 list endpoints and their hot item/stock subqueries.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'products' => [
            'idx_prod_del_hasvar_id' => ['deleted_at', 'has_variants', 'id'],
            'idx_prod_del_subcat_id' => ['deleted_at', 'sub_category_id', 'id'],
            'idx_prod_del_sku' => ['deleted_at', 'sku'],
            'idx_prod_del_barcode' => ['deleted_at', 'barcode'],
            'idx_prod_del_code' => ['deleted_at', 'code'],
        ],
        'product_variants' => [
            'idx_pvar_prod_del_sort' => ['product_id', 'deleted_at', 'sort_order'],
            'idx_pvar_sku_del' => ['sku', 'deleted_at'],
            'idx_pvar_barcode_del' => ['barcode', 'deleted_at'],
        ],
        'quotations' => [
            'idx_quo_wh_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_quo_cust_id' => ['customer_id', 'id'],
        ],
        'purchases' => [
            'idx_pur_status_id' => ['status', 'id'],
            'idx_pur_pay_status_id' => ['payment_status', 'id'],
            'idx_pur_sup_id' => ['supplier_id', 'id'],
            'idx_pur_pay_type' => ['payment_type'],
        ],
        'purchase_returns' => [
            'idx_pr_status_id' => ['status', 'id'],
            'idx_pr_pay_status_id' => ['payment_status', 'id'],
            'idx_pr_sup_id' => ['supplier_id', 'id'],
        ],
        'sale_payments' => [
            'idx_sp_sale_pay_type' => ['sale_id', 'payment_type'],
        ],
        'purchase_items' => [
            'idx_pi_pur_prod' => ['purchase_id', 'product_id'],
        ],
        'sale_returns' => [
            'idx_sr_status_id' => ['status', 'id'],
            'idx_sr_cust_id' => ['customer_id', 'id'],
        ],
        'stock_transfers' => [
            'idx_trf_from_wh_date_id' => ['from_warehouse_id', 'date', 'id'],
            'idx_trf_to_wh_date_id' => ['to_warehouse_id', 'date', 'id'],
            'idx_trf_status_id' => ['status', 'id'],
        ],
        'expenses' => [
            'idx_exp_cat_date_id' => ['expense_category_id', 'date', 'id'],
            'idx_exp_pay_status_id' => ['payment_status', 'id'],
            'idx_exp_pay_method_id' => ['payment_method', 'id'],
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
