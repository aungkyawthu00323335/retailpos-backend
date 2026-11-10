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
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('expenses', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('expenses', 'user_role')) {
                    $table->string('user_role')->nullable()->after('user_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'user_id')) {
                    $table->dropColumn('user_id');
                }
                if (Schema::hasColumn('expenses', 'user_role')) {
                    $table->dropColumn('user_role');
                }
            });
        }
    }
};
