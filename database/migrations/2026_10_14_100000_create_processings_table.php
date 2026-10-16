<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->string('warehouse_name');
            $table->string('date'); // Y-m-d H:i:s or Y-m-d
            
            // Output Finished Good
            $table->unsignedBigInteger('output_product_id')->index();
            $table->unsignedBigInteger('output_variant_id')->nullable()->index();
            $table->string('output_product_code');
            $table->string('output_product_name');
            $table->string('output_variant_name')->nullable();
            $table->unsignedBigInteger('output_unit_id')->nullable();
            $table->string('output_unit_name')->default('Piece');
            $table->double('output_conversion_rate')->default(1.0);
            $table->double('output_quantity');
            $table->double('output_base_quantity');
            $table->double('output_unit_cost')->default(0); // Cost per output unit
            
            // Financial Summary
            $table->double('total_input_cost')->default(0);
            $table->double('additional_cost')->default(0);
            $table->double('total_cost')->default(0);
            
            $table->string('status')->default('completed'); // 'completed', 'draft'
            $table->text('notes')->nullable();
            
            // User / Audit
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('created_by')->nullable();
            $table->string('user_role')->nullable();
            
            $table->timestamps();

            $table->index(['warehouse_id', 'date']);
            $table->index('status');
        });

        Schema::create('processing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_id')->constrained('processings')->onDelete('cascade');
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('product_variant_id')->nullable()->index();
            $table->string('product_code');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name')->default('Piece');
            $table->double('conversion_rate')->default(1.0);
            $table->double('quantity');
            $table->double('base_quantity');
            $table->double('unit_cost')->default(0);
            $table->double('base_unit_cost')->default(0);
            $table->double('subtotal_cost')->default(0);
            $table->timestamps();

            $table->index(['processing_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_items');
        Schema::dropIfExists('processings');
    }
};
