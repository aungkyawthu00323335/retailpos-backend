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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('alternative_name')->nullable();
            $table->string('category_name')->default('General');
            $table->string('unit_name')->default('Plate');
            $table->decimal('purchase_price', 12, 2)->default(0.00);
            $table->decimal('low_qty', 10, 2)->default(0.00);
            $table->decimal('dine_in_price', 12, 2)->default(0.00);
            $table->decimal('take_away_price', 12, 2)->default(0.00);
            $table->decimal('delivery_price', 12, 2)->default(0.00);
            $table->text('image_url')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('Active');
            $table->string('added_by')->default('Admin User');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
