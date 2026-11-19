<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create sale_return_cost_layers for deterministic FIFO cost restoration & void rollback
        if (!Schema::hasTable('sale_return_cost_layers')) {
            Schema::create('sale_return_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_return_id')->index('srcl_sr_id_idx');
                $table->unsignedBigInteger('inventory_batch_id')->nullable()->index('srcl_restored_batch_idx');
                $table->unsignedBigInteger('source_batch_id')->nullable()->index('srcl_source_batch_idx');
                $table->unsignedBigInteger('warehouse_id')->index('srcl_wh_id_idx');
                $table->unsignedBigInteger('product_id')->index('srcl_prod_id_idx');
                $table->unsignedBigInteger('product_variant_id')->nullable()->index('srcl_var_id_idx');
                $table->decimal('base_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000);
                $table->decimal('total_cost', 18, 4)->default(0.0000);
                $table->boolean('is_fallback_layer')->default(false);
                $table->timestamps();
            });
        }

        // 2. Create stock_transfer_cost_layers for multi-layer FIFO preservation & rollback on transfers
        if (!Schema::hasTable('stock_transfer_cost_layers')) {
            Schema::create('stock_transfer_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stock_transfer_id')->index('stcl_trf_id_idx');
                $table->unsignedBigInteger('stock_transfer_item_id')->nullable()->index('stcl_item_id_idx');
                $table->unsignedBigInteger('source_inventory_batch_id')->nullable()->index('stcl_src_batch_idx');
                $table->unsignedBigInteger('destination_inventory_batch_id')->nullable()->index('stcl_dst_batch_idx');
                $table->unsignedBigInteger('from_warehouse_id')->index('stcl_from_wh_idx');
                $table->unsignedBigInteger('to_warehouse_id')->index('stcl_to_wh_idx');
                $table->unsignedBigInteger('product_id')->index('stcl_prod_id_idx');
                $table->unsignedBigInteger('product_variant_id')->nullable()->index('stcl_var_id_idx');
                $table->decimal('base_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000);
                $table->decimal('total_cost', 18, 4)->default(0.0000);
                $table->boolean('is_fallback_layer')->default(false);
                $table->timestamps();
            });
        }

        // 3. Add returned_credit_amount on sales for auditable credit-note AR reductions
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'returned_credit_amount')) {
                    $table->decimal('returned_credit_amount', 15, 2)->default(0.00)->after('due_amount');
                }
            });
        }

        // 4. Add cash_register_id on sale_payments so shift collections & AR settlements isolate cleanly per register
        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('sale_payments', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_type');
                    $table->index('cash_register_id', 'sp_cash_register_id_idx');
                }
            });
        }

        // 5. Harden sale_returns schema (sale_id FK, returned FIFO cost, variance, applied credit, register ID)
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('sale_returns', 'sale_id')) {
                    $table->unsignedBigInteger('sale_id')->nullable()->after('reference_no');
                    $table->index('sale_id', 'sr_sale_id_idx');
                }
                if (!Schema::hasColumn('sale_returns', 'applied_credit_amount')) {
                    $table->decimal('applied_credit_amount', 15, 2)->default(0.00)->after('due_amount');
                }
                if (!Schema::hasColumn('sale_returns', 'return_cost_total')) {
                    $table->decimal('return_cost_total', 15, 4)->default(0.0000)->after('applied_credit_amount');
                }
                if (!Schema::hasColumn('sale_returns', 'cost_variance')) {
                    $table->decimal('cost_variance', 15, 4)->default(0.0000)->after('return_cost_total');
                }
                if (!Schema::hasColumn('sale_returns', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_type');
                    $table->index('cash_register_id', 'sr_cash_register_id_idx');
                }
            });

            if (Schema::hasTable('sales')) {
                $returnsToLink = DB::table('sale_returns')
                    ->whereNull('sale_id')
                    ->whereNotNull('sale_reference_no')
                    ->select('id', 'sale_reference_no')
                    ->get();
                foreach ($returnsToLink as $ret) {
                    $clean = trim((string)$ret->sale_reference_no);
                    if ($clean === '') continue;
                    $saleId = DB::table('sales')
                        ->whereIn('reference_no', [$clean, str_replace('-', '_', $clean), str_replace('_', '-', $clean)])
                        ->value('id');
                    if ($saleId) {
                        DB::table('sale_returns')->where('id', $ret->id)->update(['sale_id' => $saleId]);
                    }
                }
            }
        }

        // 6. Add cash_register_id & transfer_id on expenses (plus cash_register_id on expense_payments)
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('expenses', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_method_id');
                    $table->index('cash_register_id', 'exp_cash_register_id_idx');
                }
                if (!Schema::hasColumn('expenses', 'transfer_id')) {
                    $table->unsignedBigInteger('transfer_id')->nullable()->after('cash_register_id');
                    $table->index('transfer_id', 'exp_transfer_id_idx');
                }
            });

            if (Schema::hasTable('stock_transfers')) {
                $trfExpenses = DB::table('expenses')
                    ->whereNull('transfer_id')
                    ->where('reference_no', 'like', 'EXP_TRF-%')
                    ->select('id', 'reference_no')
                    ->get();
                foreach ($trfExpenses as $exp) {
                    $trfRef = substr((string)$exp->reference_no, strlen('EXP_TRF-'));
                    if ($trfRef !== '') {
                        $trfId = DB::table('stock_transfers')->where('reference_no', $trfRef)->value('id');
                        if ($trfId) {
                            DB::table('expenses')->where('id', $exp->id)->update(['transfer_id' => $trfId]);
                        }
                    }
                }
            }
        }

        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('expense_payments', 'cash_register_id')) {
                    $table->unsignedBigInteger('cash_register_id')->nullable()->after('payment_type');
                    $table->index('cash_register_id', 'ep_cash_register_id_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_payments') && Schema::hasColumn('expense_payments', 'cash_register_id')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                $table->dropIndex('ep_cash_register_id_idx');
                $table->dropColumn('cash_register_id');
            });
        }

        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'transfer_id')) {
                    $table->dropIndex('exp_transfer_id_idx');
                    $table->dropColumn('transfer_id');
                }
                if (Schema::hasColumn('expenses', 'cash_register_id')) {
                    $table->dropIndex('exp_cash_register_id_idx');
                    $table->dropColumn('cash_register_id');
                }
            });
        }

        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                foreach (['cash_register_id', 'cost_variance', 'return_cost_total', 'applied_credit_amount', 'sale_id'] as $col) {
                    if (Schema::hasColumn('sale_returns', $col)) {
                        if ($col === 'cash_register_id') $table->dropIndex('sr_cash_register_id_idx');
                        if ($col === 'sale_id') $table->dropIndex('sr_sale_id_idx');
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('sale_payments') && Schema::hasColumn('sale_payments', 'cash_register_id')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex('sp_cash_register_id_idx');
                $table->dropColumn('cash_register_id');
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'returned_credit_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('returned_credit_amount');
            });
        }

        Schema::dropIfExists('stock_transfer_cost_layers');
        Schema::dropIfExists('sale_return_cost_layers');
    }
};
