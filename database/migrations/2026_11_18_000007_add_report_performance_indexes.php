<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-driver composite indexes for live zero-cache report & cash register queries.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'outlets' => [
            'idx_outlets_name' => ['name'],
        ],
        'sales' => [
            'idx_sales_wh_id_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_sales_wh_id_status_date' => ['warehouse_id', 'status', 'date'],
            'idx_sales_cust_status_date' => ['customer_id', 'status', 'date'],
            'idx_sales_pay_type_date' => ['payment_type', 'date'],
            'idx_sales_user_date' => ['user_id', 'date'],
            'idx_sales_reg_status' => ['cash_register_id', 'status'],
        ],
        'sale_items' => [
            'idx_si_variant_id' => ['product_variant_id'],
            'idx_si_sale_tax' => ['sale_id', 'tax'],
            'idx_si_rpt_cover' => ['sale_id', 'product_id', 'quantity', 'subtotal', 'total_cogs', 'tax'],
        ],
        'sale_payments' => [
            'idx_sp_reg_created' => ['cash_register_id', 'created_at'],
            'idx_sp_sale_created' => ['sale_id', 'created_at'],
        ],
        'sale_returns' => [
            'idx_sr_wh_id_status_date' => ['warehouse_id', 'status', 'date'],
            'idx_sr_wh_id_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_sr_cust_status_date' => ['customer_id', 'status', 'date'],
        ],
        'sale_return_cost_layers' => [
            'idx_srcl_ret_prod_var' => ['sale_return_id', 'product_id', 'product_variant_id'],
        ],
        'purchases' => [
            'idx_pur_wh_id_status_date' => ['warehouse_id', 'status', 'date'],
            'idx_pur_wh_id_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_pur_status_date_ptype' => ['status', 'date', 'payment_type'],
            'idx_pur_sup_status_date' => ['supplier_id', 'status', 'date'],
            'idx_pur_status_taxrec_date' => ['status', 'is_tax_recoverable', 'date'],
        ],
        'purchase_items' => [
            'idx_pi_variant_id' => ['product_variant_id'],
            'idx_pi_pur_tax' => ['purchase_id', 'tax'],
        ],
        'purchase_returns' => [
            'idx_pr_wh_id_status_date' => ['warehouse_id', 'status', 'date'],
            'idx_pr_wh_id_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_pr_sup_status_date' => ['supplier_id', 'status', 'date'],
            'idx_pr_status_taxrec_date' => ['status', 'is_tax_recoverable', 'date'],
        ],
        'purchase_return_allocations' => [
            'idx_pra_pr_rel_amt' => ['purchase_return_id', 'released_at', 'amount'],
            'idx_pra_po_rel_amt' => ['purchase_id', 'released_at', 'amount'],
        ],
        'purchase_return_cost_layers' => [
            'idx_prcl_ret_prod_var' => ['purchase_return_id', 'product_id', 'product_variant_id'],
        ],
        'expenses' => [
            'idx_exp_wh_id_date_id' => ['warehouse_id', 'date', 'id'],
            'idx_exp_wh_name_created' => ['warehouse_name', 'created_at'],
        ],
        'expense_payments' => [
            'idx_ep_reg_ptype' => ['cash_register_id', 'payment_type'],
            'idx_ep_exp_date_amt' => ['expense_id', 'payment_date', 'amount'],
        ],
        'inventory_batches' => [
            'idx_ib_status_rem_prod' => ['status', 'remaining_quantity', 'product_id'],
            'idx_ib_status_wh_rem_prod' => ['status', 'warehouse_id', 'remaining_quantity', 'product_id'],
        ],
        'stock_balances' => [
            'idx_sb_wh_prod_qty' => ['warehouse_id', 'product_id', 'quantity'],
            'idx_sb_wh_var_qty' => ['warehouse_id', 'product_variant_id', 'quantity'],
        ],
        'stock_movements' => [
            'idx_sm_type_ref_prod' => ['movement_type', 'reference_id', 'product_id'],
            'idx_sm_type_wh_created' => ['movement_type', 'warehouse_id', 'created_at'],
            'idx_sm_prod_id' => ['product_id'],
        ],
        'cash_registers' => [
            'idx_cr_opened_id' => ['opened_at', 'id'],
            'idx_cr_wh_opened' => ['warehouse_id', 'opened_at'],
            'idx_cr_user_opened' => ['user_id', 'opened_at'],
        ],
        'stock_adjustments' => [
            'idx_sa_wh_id_pnl_date' => ['warehouse_id', 'affects_pnl', 'date'],
        ],
        'stock_transfers' => [
            'idx_trf_status_from_date' => ['status', 'from_warehouse_id', 'date'],
            'idx_trf_status_to_date' => ['status', 'to_warehouse_id', 'date'],
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
