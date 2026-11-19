<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MYSQL_INDEXES = [
        'stock_adjustments' => [
            'idx_stk_adj_type_date_id' => ['type', 'date', 'id'],
            'idx_stk_adj_wh_date_id' => ['warehouse_name', 'date', 'id'],
            'idx_stk_adj_reason_date_id' => ['reason_code', 'date', 'id'],
            'idx_stk_adj_created_at_id' => ['created_at', 'id'],
            'idx_stk_adj_value_id' => ['total_value', 'id'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            if (!Schema::hasColumn('stock_adjustments', 'total_value')) {
                Schema::table('stock_adjustments', function (Blueprint $table) {
                    $table->decimal('total_value', 16, 2)->nullable()->after('gross_quantity');
                });
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement("
                    UPDATE stock_adjustments sa
                    LEFT JOIN (
                        SELECT stock_adjustment_id,
                               ROUND(COALESCE(SUM(CASE WHEN LOWER(type) = 'subtraction'
                                   THEN -COALESCE(subtotal, quantity * unit_cost)
                                   ELSE COALESCE(subtotal, quantity * unit_cost)
                               END), 0), 2) AS net_val
                        FROM stock_adjustment_items
                        GROUP BY stock_adjustment_id
                    ) agg ON agg.stock_adjustment_id = sa.id
                    SET sa.total_value = COALESCE(agg.net_val, 0.00)
                    WHERE sa.total_value IS NULL
                ");
            } else {
                DB::statement("
                    UPDATE stock_adjustments
                    SET total_value = COALESCE((
                        SELECT ROUND(COALESCE(SUM(CASE WHEN LOWER(type) = 'subtraction'
                            THEN -COALESCE(subtotal, quantity * unit_cost)
                            ELSE COALESCE(subtotal, quantity * unit_cost)
                        END), 0), 2)
                        FROM stock_adjustment_items
                        WHERE stock_adjustment_items.stock_adjustment_id = stock_adjustments.id
                    ), 0)
                    WHERE total_value IS NULL
                ");
            }
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::MYSQL_INDEXES as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (!empty(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]))) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                    $table->index($columns, $indexName);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::MYSQL_INDEXES as $tableName => $indexes) {
                if (!Schema::hasTable($tableName)) {
                    continue;
                }

                foreach (array_keys($indexes) as $indexName) {
                    if (empty(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]))) {
                        continue;
                    }

                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
            }
        }

        if (Schema::hasTable('stock_adjustments') && Schema::hasColumn('stock_adjustments', 'total_value')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->dropColumn('total_value');
            });
        }
    }
};
