<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covering / leading indexes for fast live commercial-tax reconciliation queries.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'sale_items' => [
            'idx_si_tax_sale' => ['tax', 'sale_id'],
        ],
        'purchase_items' => [
            'idx_pi_tax_pur' => ['tax', 'purchase_id'],
        ],
        'sales' => [
            'idx_sales_tax_date_id' => ['tax_amount', 'date', 'id'],
        ],
        'purchases' => [
            'idx_pur_status_rec_tax_date' => ['status', 'is_tax_recoverable', 'tax_amount', 'date'],
        ],
        'sale_returns' => [
            'idx_sr_tax_date' => ['tax_amount', 'date'],
        ],
        'purchase_returns' => [
            'idx_pr_status_rec_tax_date' => ['status', 'is_tax_recoverable', 'tax_amount', 'date'],
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
