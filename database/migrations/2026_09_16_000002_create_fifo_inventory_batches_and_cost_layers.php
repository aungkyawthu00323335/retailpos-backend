<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create inventory_batches table for FIFO tracking
        if (!Schema::hasTable('inventory_batches')) {
            Schema::create('inventory_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id')->index();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_item_id')->nullable()->index();
                $table->string('batch_number', 100)->nullable()->index();
                $table->string('source_type', 50)->default('purchase')->index(); // opening_stock, purchase, adjustment_addition, sale_return
                $table->string('source_reference', 100)->nullable();
                $table->dateTime('received_date')->index();
                $table->decimal('unit_cost', 18, 4)->default(0.0000); // base unit cost
                $table->decimal('initial_quantity', 18, 4)->default(0.0000); // base unit qty
                $table->decimal('remaining_quantity', 18, 4)->default(0.0000)->index(); // base unit qty remaining
                $table->string('status', 30)->default('active')->index(); // active, exhausted
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(
                    ['warehouse_id', 'product_id', 'product_variant_id', 'status', 'received_date'],
                    'fifo_batch_lookup_idx'
                );
            });
        }

        // 2. Create sale_item_cost_layers table for granular FIFO COGS depletion breakdown
        if (!Schema::hasTable('sale_item_cost_layers')) {
            Schema::create('sale_item_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id')->index();
                $table->foreignId('sale_item_id')->constrained('sale_items')->onDelete('cascade');
                $table->unsignedBigInteger('inventory_batch_id')->nullable()->index();
                $table->decimal('base_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000); // base unit cost from batch
                $table->decimal('total_cost', 18, 4)->default(0.0000); // base_quantity * unit_cost
                $table->dateTime('date')->nullable()->index();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['sale_id', 'sale_item_id'], 'sale_item_cost_idx');
            });
        }

        // 3. Add COGS snapshot columns to sales table
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'total_cogs')) {
                    $table->decimal('total_cogs', 18, 4)->default(0.0000)->after('grand_total');
                }
            });
        }

        // 4. Add COGS snapshot columns to sale_items table
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (!Schema::hasColumn('sale_items', 'base_unit_cost')) {
                    $table->decimal('base_unit_cost', 18, 4)->default(0.0000)->after('base_unit_price');
                }
                if (!Schema::hasColumn('sale_items', 'total_cogs')) {
                    $table->decimal('total_cogs', 18, 4)->default(0.0000)->after('subtotal');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (Schema::hasColumn('sale_items', 'total_cogs')) {
                    $table->dropColumn('total_cogs');
                }
                if (Schema::hasColumn('sale_items', 'base_unit_cost')) {
                    $table->dropColumn('base_unit_cost');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'total_cogs')) {
                    $table->dropColumn('total_cogs');
                }
            });
        }

        Schema::dropIfExists('sale_item_cost_layers');
        Schema::dropIfExists('inventory_batches');
    }
};
