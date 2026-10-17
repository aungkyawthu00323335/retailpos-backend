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
        if (Schema::hasTable('variations') && ! Schema::hasColumn('variations', 'uuid')) {
            Schema::table('variations', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('variations') && Schema::hasColumn('variations', 'uuid')) {
            Schema::table('variations', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
};
