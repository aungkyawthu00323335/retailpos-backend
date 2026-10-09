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
        Schema::create('bot_restock_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id', 64)->index();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->onDelete('cascade');
            $table->string('customer_name', 191)->nullable();
            $table->string('username', 191)->nullable();
            $table->boolean('is_notified')->default(false)->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_notified']);
            $table->index(['telegram_chat_id', 'product_id', 'is_notified'], 'idx_chat_prod_notified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_restock_subscribers');
    }
};
