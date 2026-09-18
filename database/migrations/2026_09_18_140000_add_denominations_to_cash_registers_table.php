<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_registers') && !Schema::hasColumn('cash_registers', 'denominations')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                $table->json('denominations')->nullable()->after('closing_notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_registers') && Schema::hasColumn('cash_registers', 'denominations')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                $table->dropColumn('denominations');
            });
        }
    }
};
