<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telegram_settings')) {
            Schema::create('telegram_settings', function (Blueprint $table) {
                $table->id();
                $table->text('bot_token')->nullable();
                $table->boolean('is_enabled')->default(false);
                $table->boolean('auto_send_register_close')->default(true);
                $table->boolean('include_profit')->default(true);
                $table->boolean('include_registers')->default(true);
                $table->string('message_language', 10)->default('my');
                $table->string('updated_by', 120)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('telegram_recipients')) {
            Schema::create('telegram_recipients', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('chat_id', 80);
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->boolean('notify_daily_summary')->default(true);
                $table->boolean('notify_register_close')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'outlet_id']);
            });
        }

        if (!Schema::hasTable('telegram_notification_logs')) {
            Schema::create('telegram_notification_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 40)->index();
                $table->string('recipient_name', 120)->nullable();
                $table->string('chat_id', 80);
                $table->string('outlet_name', 150)->nullable();
                $table->string('triggered_by', 120)->nullable();
                $table->string('status', 20)->index();
                $table->text('message_preview');
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_notification_logs');
        Schema::dropIfExists('telegram_recipients');
        Schema::dropIfExists('telegram_settings');
    }
};
