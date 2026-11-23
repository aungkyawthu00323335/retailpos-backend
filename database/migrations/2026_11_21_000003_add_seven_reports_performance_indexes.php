<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-driver composite indexes for Cash Flow, Z Report, Register, AR/AP Aging, Stock Ledger, and Product Margins reports.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'purchases' => [
            'idx_pur_status_date_wh' => ['status', 'date', 'warehouse_id'],
            'idx_pur_status_paid_date' => ['status', 'paid_amount', 'date'],
        ],
        'purchase_returns' => [
            'idx_pr_status_date_wh' => ['status', 'date', 'warehouse_id'],
            'idx_pr_status_paid_date' => ['status', 'paid_amount', 'date'],
        ],
        'sale_returns' => [
            'idx_sr_date_wh_status' => ['date', 'warehouse_id', 'status'],
        ],
        'expenses' => [
            'idx_exp_date_wh_pstat' => ['date', 'warehouse_id', 'payment_status'],
        ],
        'expense_payments' => [
            'idx_ep_paydate_exp' => ['payment_date', 'expense_id'],
        ],
        'stock_movements' => [
            'idx_sm_type_created_wh' => ['movement_type', 'created_at', 'warehouse_id'],
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
