<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['id', 'date'], 'sales_id_date_index');
                $table->index(['customer_id', 'id'], 'sales_customer_id_index');
                $table->index(['customer_name', 'id'], 'sales_customer_name_index');
                $table->index(['warehouse_name', 'id'], 'sales_warehouse_id_index');
                $table->index(['status', 'id'], 'sales_status_id_index');
                $table->index(['payment_status', 'id'], 'sales_payment_status_id_index');
                $table->index(['warehouse_name', 'status', 'id'], 'sales_warehouse_status_id_index');
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->index(['sale_id', 'product_id'], 'sale_items_sale_product_index');
                $table->index(['product_code'], 'sale_items_product_code_index');
            });
        }

        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->index(['sale_id', 'payment_date'], 'sale_payments_sale_date_index');
                $table->index(['payment_type', 'payment_date'], 'sale_payments_type_date_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('sales_id_date_index');
                $table->dropIndex('sales_customer_id_index');
                $table->dropIndex('sales_customer_name_index');
                $table->dropIndex('sales_warehouse_id_index');
                $table->dropIndex('sales_status_id_index');
                $table->dropIndex('sales_payment_status_id_index');
                $table->dropIndex('sales_warehouse_status_id_index');
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex('sale_items_sale_product_index');
                $table->dropIndex('sale_items_product_code_index');
            });
        }

        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex('sale_payments_sale_date_index');
                $table->dropIndex('sale_payments_type_date_index');
            });
        }
    }
};
