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
        if (Schema::hasTable('payment_methods')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                // Add performance indexes if missing
                try {
                    $table->index('is_active', 'pm_is_active_idx');
                } catch (\Throwable $e) {}

                try {
                    $table->index('is_default', 'pm_is_default_idx');
                } catch (\Throwable $e) {}

                try {
                    $table->index(['is_active', 'id'], 'pm_active_id_idx');
                } catch (\Throwable $e) {}
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payment_methods')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                try {
                    $table->dropIndex('pm_is_active_idx');
                } catch (\Throwable $e) {}
                try {
                    $table->dropIndex('pm_is_default_idx');
                } catch (\Throwable $e) {}
                try {
                    $table->dropIndex('pm_active_id_idx');
                } catch (\Throwable $e) {}
            });
        }
    }
};
