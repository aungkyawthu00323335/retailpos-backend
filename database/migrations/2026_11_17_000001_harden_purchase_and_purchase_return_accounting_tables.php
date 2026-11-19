<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Expand suppliers.balance_due to DECIMAL(15,2) to match purchases & purchase_returns
        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'balance_due')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE suppliers MODIFY balance_due DECIMAL(15, 2) NOT NULL DEFAULT 0.00');
            }
        }

        // 2. Harden purchases accounting & register columns + normalize date to DATETIME
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasColumn('purchases', 'is_tax_recoverable')) {
                    $table->boolean('is_tax_recoverable')->default(false)->after('tax_amount');
                }
                if (!Schema::hasColumn('purchases', 'returned_credit_amount')) {
                    $table->decimal('returned_credit_amount', 15, 2)->default(0.00)->after('due_amount');
                }
                if (!Schema::hasColumn('purchases', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_type');
                    $table->index('cash_register_id', 'purchases_cash_register_id_idx');
                }
            });

            if ($driver === 'mysql') {
                DB::statement("UPDATE purchases SET date = CONCAT(SUBSTRING(TRIM(date), 1, 10), ' 00:00:00') WHERE CHAR_LENGTH(TRIM(date)) = 10");
                DB::statement('ALTER TABLE purchases MODIFY date DATETIME NOT NULL');
            }
        }

        // 3. Harden purchase_returns accounting, FIFO cost variance & register columns + DATETIME date
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_returns', 'is_tax_recoverable')) {
                    $table->boolean('is_tax_recoverable')->default(false)->after('tax_amount');
                }
                if (!Schema::hasColumn('purchase_returns', 'return_cost_total')) {
                    $table->decimal('return_cost_total', 15, 4)->default(0.0000)->after('due_amount');
                }
                if (!Schema::hasColumn('purchase_returns', 'cost_variance')) {
                    $table->decimal('cost_variance', 15, 4)->default(0.0000)->after('return_cost_total');
                }
                if (!Schema::hasColumn('purchase_returns', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_type');
                    $table->index('cash_register_id', 'pr_cash_register_id_idx');
                }
            });

            if ($driver === 'mysql') {
                DB::statement("UPDATE purchase_returns SET date = CONCAT(SUBSTRING(TRIM(date), 1, 10), ' 00:00:00') WHERE CHAR_LENGTH(TRIM(date)) = 10");
                DB::statement('ALTER TABLE purchase_returns MODIFY date DATETIME NOT NULL');
            }
        }

        // 4. Create purchase_return_allocations ledger for auditable PO <-> Credit Note offsets
        if (!Schema::hasTable('purchase_return_allocations')) {
            Schema::create('purchase_return_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_return_id')->index('pra_pr_id_idx');
                $table->unsignedBigInteger('purchase_id')->index('pra_purchase_id_idx');
                $table->unsignedBigInteger('supplier_id')->nullable()->index('pra_supplier_id_idx');
                $table->decimal('amount', 15, 2)->default(0.00);
                $table->string('allocation_type', 50)->default('credit_note_offset');
                $table->timestamps();

                $table->unique(['purchase_return_id', 'purchase_id', 'allocation_type'], 'pra_unique_offset_idx');
            });
        }

        // 5. Create purchase_return_cost_layers for exact FIFO batch restoration on void/edit/delete
        if (!Schema::hasTable('purchase_return_cost_layers')) {
            Schema::create('purchase_return_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_return_id')->index('prcl_pr_id_idx');
                $table->unsignedBigInteger('inventory_batch_id')->nullable()->index('prcl_batch_id_idx');
                $table->unsignedBigInteger('warehouse_id')->index('prcl_wh_id_idx');
                $table->unsignedBigInteger('product_id')->index('prcl_prod_id_idx');
                $table->unsignedBigInteger('product_variant_id')->nullable()->index('prcl_var_id_idx');
                $table->decimal('base_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000);
                $table->decimal('total_cost', 18, 4)->default(0.0000);
                $table->boolean('is_fallback_layer')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_cost_layers');
        Schema::dropIfExists('purchase_return_allocations');

        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                foreach (['cash_register_id', 'cost_variance', 'return_cost_total', 'is_tax_recoverable'] as $col) {
                    if (Schema::hasColumn('purchase_returns', $col)) {
                        if ($col === 'cash_register_id') {
                            $table->dropIndex('pr_cash_register_id_idx');
                        }
                        $table->dropColumn($col);
                    }
                }
            });
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE purchase_returns MODIFY date VARCHAR(255) NOT NULL');
            }
        }

        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                foreach (['cash_register_id', 'returned_credit_amount', 'is_tax_recoverable'] as $col) {
                    if (Schema::hasColumn('purchases', $col)) {
                        if ($col === 'cash_register_id') {
                            $table->dropIndex('purchases_cash_register_id_idx');
                        }
                        $table->dropColumn($col);
                    }
                }
            });
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE purchases MODIFY date VARCHAR(255) NOT NULL');
            }
        }

        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'balance_due')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE suppliers MODIFY balance_due DECIMAL(10, 2) NOT NULL DEFAULT 0.00');
            }
        }
    }
};
