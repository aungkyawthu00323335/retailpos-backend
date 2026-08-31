<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to optimize expense queries.
     */
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                // Single column indexes for fast filtering and sorting
                $table->index('warehouse_name', 'expenses_warehouse_name_index');
                $table->index('expense_category_id', 'expenses_category_id_index');
                $table->index('expense_category_name', 'expenses_category_name_index');
                $table->index('date', 'expenses_date_index');
                $table->index('created_at', 'expenses_created_at_index');

                // Composite indexes for combined filtering (e.g. warehouse + date, category + date)
                $table->index(['warehouse_name', 'date'], 'expenses_warehouse_date_index');
                $table->index(['expense_category_id', 'date'], 'expenses_category_date_index');
                $table->index(['warehouse_name', 'id'], 'expenses_warehouse_id_index');
            });
        }

        if (Schema::hasTable('expense_categories')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->index('status', 'expense_categories_status_index');
                $table->index('created_at', 'expense_categories_created_at_index');
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
                $table->dropIndex('expenses_warehouse_name_index');
                $table->dropIndex('expenses_category_id_index');
                $table->dropIndex('expenses_category_name_index');
                $table->dropIndex('expenses_date_index');
                $table->dropIndex('expenses_created_at_index');
                $table->dropIndex('expenses_warehouse_date_index');
                $table->dropIndex('expenses_category_date_index');
                $table->dropIndex('expenses_warehouse_id_index');
            });
        }

        if (Schema::hasTable('expense_categories')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropIndex('expense_categories_status_index');
                $table->dropIndex('expense_categories_created_at_index');
            });
        }
    }
};
