<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_movements') && !Schema::hasColumn('stock_movements', 'unit_cost')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->decimal('unit_cost', 18, 4)->nullable()->after('balance_after');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'unit_cost')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('unit_cost');
            });
        }
    }
};
