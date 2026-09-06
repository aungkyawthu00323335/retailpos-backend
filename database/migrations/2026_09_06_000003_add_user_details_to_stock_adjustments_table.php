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
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_adjustments', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('notes');
                }
                if (!Schema::hasColumn('stock_adjustments', 'user_role')) {
                    $table->string('user_role')->nullable()->after('created_by');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                if (Schema::hasColumn('stock_adjustments', 'user_id')) {
                    $table->dropColumn('user_id');
                }
                if (Schema::hasColumn('stock_adjustments', 'user_role')) {
                    $table->dropColumn('user_role');
                }
            });
        }
    }
};
