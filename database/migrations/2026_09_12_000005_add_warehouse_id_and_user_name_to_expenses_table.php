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
                if (!Schema::hasColumn('expenses', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse_name');
                }
                if (!Schema::hasColumn('expenses', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
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
                if (Schema::hasColumn('expenses', 'warehouse_id')) {
                    $table->dropColumn('warehouse_id');
                }
                if (Schema::hasColumn('expenses', 'user_name')) {
                    $table->dropColumn('user_name');
                }
            });
        }
    }
};
