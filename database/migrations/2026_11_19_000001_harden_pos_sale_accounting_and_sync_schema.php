<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite performance indexes across sync, register, sale, payment, and stock tables.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'products' => [
            'idx_prod_updated_id' => ['updated_at', 'id'],
        ],
        'product_variants' => [
            'idx_pvar_updated_prod' => ['updated_at', 'product_id'],
        ],
        'sales' => [
            'idx_sales_reg_status_id' => ['cash_register_id', 'status', 'id'],
            'idx_sales_wh_name_created' => ['warehouse_name', 'created_at'],
            'idx_sales_updated_id' => ['updated_at', 'id'],
        ],
        'sale_payments' => [
            'idx_sp_reg_paydate' => ['cash_register_id', 'payment_date'],
            'idx_sp_paydate_type' => ['payment_date', 'payment_type'],
        ],
        'stock_movements' => [
            'idx_sm_reftype_refid_wh_prod_id' => ['reference_type', 'reference_id', 'warehouse_id', 'product_id', 'id'],
        ],
        'sale_returns' => [
            'idx_sr_cash_reg_id' => ['cash_register_id'],
        ],
        'cash_registers' => [
            'idx_cr_wh_status_opened' => ['warehouse_id', 'status', 'opened_at'],
        ],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Add composite performance indexes
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

        // 2. Eliminate leading-wildcard payments_payload LIKE '%cash_register_id%' scans via indexed flag
        foreach (['purchases', 'purchase_returns', 'sale_returns'] as $docTable) {
            if (!Schema::hasTable($docTable)) {
                continue;
            }
            if (!Schema::hasColumn($docTable, 'has_register_split')) {
                Schema::table($docTable, function (Blueprint $table) {
                    $table->boolean('has_register_split')->default(false);
                });
            }
            $splitIdx = "idx_{$docTable}_reg_split_status";
            if (Schema::hasColumns($docTable, ['has_register_split', 'status'])
                && !Schema::hasIndex($docTable, $splitIdx)
                && !Schema::hasIndex($docTable, ['has_register_split', 'status'])) {
                Schema::table($docTable, function (Blueprint $table) use ($splitIdx) {
                    $table->index(['has_register_split', 'status'], $splitIdx);
                });
            }
            if (Schema::hasColumn($docTable, 'payments_payload')) {
                DB::table($docTable)
                    ->where('payments_payload', 'like', '%cash_register_id%')
                    ->update(['has_register_split' => true]);
            }
        }

        // 3. Offline sync & late reconciliation audit attributes on sales
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'is_offline_sync')) {
                    $table->boolean('is_offline_sync')->default(false)->after('pos_flag');
                }
                if (!Schema::hasColumn('sales', 'offline_synced_at')) {
                    $table->timestamp('offline_synced_at')->nullable()->after('is_offline_sync');
                }
                if (!Schema::hasColumn('sales', 'late_offline_reconciliation')) {
                    $table->boolean('late_offline_reconciliation')->default(false)->after('offline_synced_at');
                }
            });
        }

        // 4. Immutable Late-Offline Reconciliation Audit Log table
        if (!Schema::hasTable('late_offline_reconciliations')) {
            Schema::create('late_offline_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id')->unique('lor_sale_id_unique');
                $table->string('sale_reference_no', 64)->index('lor_sale_ref_idx');
                $table->unsignedBigInteger('warehouse_id')->nullable()->index('lor_wh_id_idx');
                $table->string('warehouse_name', 255)->nullable();
                $table->unsignedBigInteger('original_cash_register_id')->nullable()->index('lor_orig_reg_idx');
                $table->unsignedBigInteger('syncing_open_register_id')->nullable()->index('lor_sync_reg_idx');
                $table->unsignedBigInteger('user_id')->nullable()->index('lor_user_id_idx');
                $table->string('user_name', 255)->nullable();
                $table->dateTime('sale_date')->index('lor_sale_date_idx');
                $table->dateTime('synced_at')->index('lor_synced_at_idx');
                $table->decimal('sale_grand_total', 15, 2)->default(0.00);
                $table->decimal('cash_amount', 15, 2)->default(0.00);
                $table->decimal('non_cash_amount', 15, 2)->default(0.00);
                $table->string('payment_type', 64)->nullable();
                $table->string('reason', 255);
                $table->string('status', 32)->default('pending_review')->index('lor_status_idx');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 5. Deduplicate null-variant stock_balances rows & enforce COALESCE(product_variant_id, 0) uniqueness
        if (Schema::hasTable('stock_balances')) {
            $dupGroups = DB::table('stock_balances')
                ->whereNull('product_variant_id')
                ->select('warehouse_id', 'product_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('warehouse_id', 'product_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($dupGroups as $grp) {
                $rows = DB::table('stock_balances')
                    ->where('warehouse_id', $grp->warehouse_id)
                    ->where('product_id', $grp->product_id)
                    ->whereNull('product_variant_id')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get();

                if ($rows->count() > 1) {
                    $keepId = $rows->first()->id;
                    $dropIds = $rows->skip(1)->pluck('id')->all();
                    DB::table('stock_balances')->whereIn('id', $dropIds)->delete();
                }
            }

            if ($driver === 'sqlite') {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS stock_balances_wh_prod_var_coalesce_unique ON stock_balances (warehouse_id, product_id, COALESCE(product_variant_id, 0))');
            } elseif ($driver === 'mysql') {
                if (!Schema::hasColumn('stock_balances', 'variant_key')) {
                    DB::statement('ALTER TABLE stock_balances ADD COLUMN variant_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(product_variant_id, 0)) STORED');
                }
                if (!Schema::hasIndex('stock_balances', 'stock_balances_wh_prod_var_coalesce_unique')) {
                    Schema::table('stock_balances', function (Blueprint $table) {
                        $table->unique(['warehouse_id', 'product_id', 'variant_key'], 'stock_balances_wh_prod_var_coalesce_unique');
                    });
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasTable('stock_balances')) {
            if ($driver === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS stock_balances_wh_prod_var_coalesce_unique');
            } elseif ($driver === 'mysql') {
                if (Schema::hasIndex('stock_balances', 'stock_balances_wh_prod_var_coalesce_unique')) {
                    Schema::table('stock_balances', function (Blueprint $table) {
                        $table->dropUnique('stock_balances_wh_prod_var_coalesce_unique');
                    });
                }
                if (Schema::hasColumn('stock_balances', 'variant_key')) {
                    Schema::table('stock_balances', function (Blueprint $table) {
                        $table->dropColumn('variant_key');
                    });
                }
            }
        }

        Schema::dropIfExists('late_offline_reconciliations');

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                foreach (['late_offline_reconciliation', 'offline_synced_at', 'is_offline_sync'] as $col) {
                    if (Schema::hasColumn('sales', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        foreach (['purchases', 'purchase_returns', 'sale_returns'] as $docTable) {
            if (!Schema::hasTable($docTable)) {
                continue;
            }
            $splitIdx = "idx_{$docTable}_reg_split_status";
            if (Schema::hasIndex($docTable, $splitIdx)) {
                Schema::table($docTable, function (Blueprint $table) use ($splitIdx) {
                    $table->dropIndex($splitIdx);
                });
            }
            if (Schema::hasColumn($docTable, 'has_register_split')) {
                Schema::table($docTable, function (Blueprint $table) {
                    $table->dropColumn('has_register_split');
                });
            }
        }

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
