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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'redeemed_points')) {
                $table->integer('redeemed_points')->default(0)->after('discount');
            }
            if (!Schema::hasColumn('sales', 'points_discount')) {
                $table->decimal('points_discount', 12, 2)->default(0.00)->after('redeemed_points');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'redeemed_points')) {
                $table->dropColumn('redeemed_points');
            }
            if (Schema::hasColumn('sales', 'points_discount')) {
                $table->dropColumn('points_discount');
            }
        });
    }
};
