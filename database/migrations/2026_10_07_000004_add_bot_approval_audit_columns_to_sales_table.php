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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'bot_approved_by')) {
                $table->string('bot_approved_by', 150)->nullable()->after('bot_rejection_reason');
            }
            if (!Schema::hasColumn('sales', 'bot_approved_at')) {
                $table->timestamp('bot_approved_at')->nullable()->after('bot_approved_by');
            }
            if (!Schema::hasColumn('sales', 'bot_rejected_by')) {
                $table->string('bot_rejected_by', 150)->nullable()->after('bot_approved_at');
            }
            if (!Schema::hasColumn('sales', 'bot_rejected_at')) {
                $table->timestamp('bot_rejected_at')->nullable()->after('bot_rejected_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'bot_approved_by',
                'bot_approved_at',
                'bot_rejected_by',
                'bot_rejected_at',
            ]);
        });
    }
};
