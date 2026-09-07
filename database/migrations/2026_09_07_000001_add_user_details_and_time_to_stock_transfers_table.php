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
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfers', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('stock_transfers', 'user_role')) {
                    $table->string('user_role')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('stock_transfers', 'transfer_time')) {
                    $table->string('transfer_time')->nullable()->after('date');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (Schema::hasColumn('stock_transfers', 'user_id')) {
                    $table->dropColumn('user_id');
                }
                if (Schema::hasColumn('stock_transfers', 'user_role')) {
                    $table->dropColumn('user_role');
                }
                if (Schema::hasColumn('stock_transfers', 'transfer_time')) {
                    $table->dropColumn('transfer_time');
                }
            });
        }
    }
};
