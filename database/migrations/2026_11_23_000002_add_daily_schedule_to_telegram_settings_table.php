<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_settings')) {
            Schema::table('telegram_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('telegram_settings', 'auto_send_daily_enabled')) {
                    $table->boolean('auto_send_daily_enabled')->default(false)->after('auto_send_register_close');
                }
                if (!Schema::hasColumn('telegram_settings', 'auto_send_daily_time')) {
                    $table->string('auto_send_daily_time', 5)->default('20:00')->after('auto_send_daily_enabled');
                }
                if (!Schema::hasColumn('telegram_settings', 'last_auto_daily_sent_date')) {
                    $table->string('last_auto_daily_sent_date', 10)->nullable()->after('auto_send_daily_time');
                }
                if (!Schema::hasColumn('telegram_settings', 'last_auto_daily_sent_at')) {
                    $table->timestamp('last_auto_daily_sent_at')->nullable()->after('last_auto_daily_sent_date');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_settings')) {
            Schema::table('telegram_settings', function (Blueprint $table) {
                $cols = [];
                foreach ([
                    'auto_send_daily_enabled',
                    'auto_send_daily_time',
                    'last_auto_daily_sent_date',
                    'last_auto_daily_sent_at',
                ] as $col) {
                    if (Schema::hasColumn('telegram_settings', $col)) {
                        $cols[] = $col;
                    }
                }
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
