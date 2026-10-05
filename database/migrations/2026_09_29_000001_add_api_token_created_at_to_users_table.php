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
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'api_token_created_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('api_token_created_at')->nullable()->after('api_token');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'api_token_created_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('api_token_created_at');
            });
        }
    }
};
