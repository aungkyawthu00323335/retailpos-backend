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
        Schema::create('delivery_regions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_mm')->nullable();
            $table->string('code', 50)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('delivery_townships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_region_id')->constrained('delivery_regions')->onDelete('cascade');
            $table->string('name');
            $table->string('name_mm')->nullable();
            $table->string('code', 50)->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(2000.00);
            $table->decimal('free_delivery_threshold', 12, 2)->nullable();
            $table->string('estimated_days', 50)->nullable()->default('1-2 Days');
            $table->boolean('is_cod_available')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['delivery_region_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_townships');
        Schema::dropIfExists('delivery_regions');
    }
};
