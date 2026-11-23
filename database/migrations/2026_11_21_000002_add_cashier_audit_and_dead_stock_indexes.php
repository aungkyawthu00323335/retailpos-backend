<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-driver composite indexes for Dead & Slow Stock and Cashier Audit reports.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'sale_items' => [
            'idx_si_prod_sale' => ['product_id', 'sale_id'],
        ],
        'sale_payments' => [
            'idx_sp_paydate_sale' => ['payment_date', 'sale_id'],
        ],
        'sales' => [
            'idx_sales_date_user_wh' => ['date', 'user_name', 'warehouse_name'],
        ],
        'sale_returns' => [
            'idx_sr_date_status' => ['date', 'status'],
        ],
        'cash_registers' => [
            'idx_cr_closed_id' => ['closed_at', 'id'],
        ],
        'product_variants' => [
            'idx_pv_prod_del_price' => ['product_id', 'deleted_at', 'cost_price', 'selling_price'],
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

            foreach ($indexes as $indexName => $columns) {
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
