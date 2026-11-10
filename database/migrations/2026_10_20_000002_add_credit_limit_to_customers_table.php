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
        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'credit_limit')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->decimal('credit_limit', 12, 2)->nullable()->default(null)->after('total_spent');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'credit_limit')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('credit_limit');
            });
        }
    }
};
