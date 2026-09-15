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
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_returns', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('supplier_name');
                }
                if (!Schema::hasColumn('purchase_returns', 'outlet_id')) {
                    $table->unsignedBigInteger('outlet_id')->nullable()->after('warehouse_id');
                }
                if (!Schema::hasColumn('purchase_returns', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('purchase_returns', 'user_name')) {
                    $table->string('user_name')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('purchase_returns', 'user_role')) {
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
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                if (Schema::hasColumn('purchase_returns', 'user_role')) {
                    $table->dropColumn('user_role');
                }
                if (Schema::hasColumn('purchase_returns', 'user_name')) {
                    $table->dropColumn('user_name');
                }
                if (Schema::hasColumn('purchase_returns', 'user_id')) {
                    $table->dropColumn('user_id');
                }
                if (Schema::hasColumn('purchase_returns', 'outlet_id')) {
                    $table->dropColumn('outlet_id');
                }
            });
        }
    }
};
