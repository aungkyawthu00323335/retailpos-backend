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
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasColumn('purchases', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('purchases', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('purchases', 'user_role')) {
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
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (Schema::hasColumn('purchases', 'user_role')) {
                    $table->dropColumn('user_role');
                }
                if (Schema::hasColumn('purchases', 'user_name')) {
                    $table->dropColumn('user_name');
                }
                if (Schema::hasColumn('purchases', 'user_id')) {
                    $table->dropColumn('user_id');
                }
            });
        }
    }
};
