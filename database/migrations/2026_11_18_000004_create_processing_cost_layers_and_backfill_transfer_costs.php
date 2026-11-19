<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('processing_item_cost_layers')) {
            Schema::create('processing_item_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('processing_id')
                    ->constrained('processings')
                    ->onDelete('cascade');
                $table->foreignId('processing_item_id')
                    ->constrained('processing_items')
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

                $table->index(['processing_id', 'processing_item_id'], 'proc_item_cost_layers_proc_idx');
                $table->foreign('inventory_batch_id', 'proc_cost_layer_batch_fk')
                    ->references('id')
                    ->on('inventory_batches')
                    ->nullOnDelete();
            });
        }

        // Non-destructive historical repair: populate unit_cost on stock_transfer_in movements where NULL
        if (Schema::hasTable('stock_movements')
            && Schema::hasColumn('stock_movements', 'unit_cost')
            && Schema::hasTable('stock_transfer_items')
        ) {
            $nullMoves = DB::table('stock_movements')
                ->where('reference_type', 'stock_transfer')
                ->where('movement_type', 'stock_transfer_in')
                ->whereNull('unit_cost')
                ->select('id', 'reference_id', 'product_id', 'product_variant_id')
                ->orderBy('id')
                ->get();

            foreach ($nullMoves as $move) {
                $itemQuery = DB::table('stock_transfer_items')
                    ->where('stock_transfer_id', (int)$move->reference_id)
                    ->where('product_id', (int)$move->product_id);
                if (!empty($move->product_variant_id)) {
                    $itemQuery->where('product_variant_id', (int)$move->product_variant_id);
                } else {
                    $itemQuery->whereNull('product_variant_id');
                }
                $tItem = $itemQuery->first();
                if ($tItem && (float)($tItem->base_unit_cost ?? 0) > 0) {
                    DB::table('stock_movements')
                        ->where('id', $move->id)
                        ->update(['unit_cost' => round((float)$tItem->base_unit_cost, 4)]);
                } elseif ($tItem && (float)($tItem->unit_cost ?? 0) > 0) {
                    $convRate = max(0.0001, (float)($tItem->conversion_rate ?? 1.0));
                    DB::table('stock_movements')
                        ->where('id', $move->id)
                        ->update(['unit_cost' => round((float)$tItem->unit_cost / $convRate, 4)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_item_cost_layers');
    }
};
