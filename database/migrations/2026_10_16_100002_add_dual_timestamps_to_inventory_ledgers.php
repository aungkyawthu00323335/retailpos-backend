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
        $ledgerTables = ['products', 'stock_movements', 'purchases', 'inventory_batches'];

        foreach ($ledgerTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'created_at_local')) {
                        $table->dateTime('created_at_local')->nullable()->index();
                    }
                    if (! Schema::hasColumn($tableName, 'synced_at')) {
                        $table->dateTime('synced_at')->nullable()->index();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ledgerTables = ['products', 'stock_movements', 'purchases', 'inventory_batches'];

        foreach ($ledgerTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'synced_at')) {
                        $table->dropColumn('synced_at');
                    }
                    if (Schema::hasColumn($tableName, 'created_at_local')) {
                        $table->dropColumn('created_at_local');
                    }
                });
            }
        }
    }
};
