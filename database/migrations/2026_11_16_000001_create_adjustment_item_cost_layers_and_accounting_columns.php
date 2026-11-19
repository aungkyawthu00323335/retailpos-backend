<?php

use App\Support\AdjustmentReasonCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('adjustment_item_cost_layers')) {
            Schema::create('adjustment_item_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_adjustment_id')
                    ->constrained('stock_adjustments')
                    ->onDelete('cascade');
                $table->foreignId('stock_adjustment_item_id')
                    ->constrained('stock_adjustment_items')
                    ->onDelete('cascade');
                $table->unsignedBigInteger('inventory_batch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                $table->decimal('base_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000);
                $table->decimal('total_cost', 18, 4)->default(0.0000);
                $table->boolean('is_fallback_layer')->default(false);
                $table->timestamps();

                $table->index(['stock_adjustment_id', 'stock_adjustment_item_id'], 'adj_item_cost_layers_adj_idx');
                $table->foreign('inventory_batch_id', 'adj_cost_layer_batch_fk')
                    ->references('id')
                    ->on('inventory_batches')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_adjustments', 'accounting_category')) {
                    $table->string('accounting_category', 50)->default('general_adjustment')->after('reason_code');
                }
                if (!Schema::hasColumn('stock_adjustments', 'affects_pnl')) {
                    $table->boolean('affects_pnl')->default(true)->index()->after('accounting_category');
                }
            });

            // Backfill accounting classification from existing reason_code / type
            $rows = DB::table('stock_adjustments')
                ->select('id', 'reason_code', 'type')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $classified = AdjustmentReasonCatalog::classify($row->reason_code, $row->type);
                DB::table('stock_adjustments')
                    ->where('id', $row->id)
                    ->update([
                        'accounting_category' => $classified['accounting_category'],
                        'affects_pnl' => $classified['affects_pnl'],
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_item_cost_layers');

        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                foreach (['affects_pnl', 'accounting_category'] as $col) {
                    if (Schema::hasColumn('stock_adjustments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
