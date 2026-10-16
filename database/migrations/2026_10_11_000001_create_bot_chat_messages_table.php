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
        Schema::create('bot_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id', 100)->index();
            $table->string('customer_name', 150)->nullable();
            $table->string('telegram_username', 100)->nullable();
            $table->string('sender_type', 30)->default('customer')->index(); // 'customer', 'ai', 'bot', 'staff'
            $table->unsignedBigInteger('staff_user_id')->nullable()->index();
            $table->string('message_type', 30)->default('text'); // 'text', 'photo', 'product_card', 'order_status', 'system'
            $table->text('message_text');
            $table->json('metadata')->nullable(); // payload for product_ids, images, attachment URLs, etc.
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('needs_human')->default(false)->index();
            $table->timestamps();

            $table->index(['telegram_chat_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_chat_messages');
    }
};
