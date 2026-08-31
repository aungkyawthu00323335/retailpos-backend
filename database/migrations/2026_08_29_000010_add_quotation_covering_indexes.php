<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Warehouse filter + sort: WHERE warehouse_name = ? ORDER BY id DESC
            $table->index(['warehouse_name', 'id'], 'quotations_warehouse_list_index');

            // Status filter + sort: WHERE status = ? ORDER BY id DESC
            $table->index(['status', 'id'], 'quotations_status_list_index');

            // Date sort & filter: WHERE date = ? ORDER BY id DESC
            $table->index(['date', 'id'], 'quotations_date_list_index');

            // Customer filter + sort: WHERE customer_name = ? ORDER BY id DESC
            $table->index(['customer_name', 'id'], 'quotations_customer_list_index');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            // Product item relation index
            $table->index('product_id', 'quotation_items_product_index');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_warehouse_list_index');
            $table->dropIndex('quotations_status_list_index');
            $table->dropIndex('quotations_date_list_index');
            $table->dropIndex('quotations_customer_list_index');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropIndex('quotation_items_product_index');
        });
    }
};
