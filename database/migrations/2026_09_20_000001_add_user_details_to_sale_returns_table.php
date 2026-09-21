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
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('sale_returns', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('sale_returns', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('sale_returns', 'user_role')) {
                    $table->string('user_role')->nullable()->after('user_name');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (Schema::hasColumn('sale_returns', 'user_role')) {
                    $table->dropColumn('user_role');
                }
                if (Schema::hasColumn('sale_returns', 'user_name')) {
                    $table->dropColumn('user_name');
                }
                if (Schema::hasColumn('sale_returns', 'user_id')) {
                    $table->dropColumn('user_id');
                }
            });
        }
    }
};
