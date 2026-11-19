<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasIndex('purchases', ['supplier_id'])) {
                    $table->index('supplier_id');
                }
                if (!Schema::hasIndex('purchases', ['warehouse_name'])) {
                    $table->index('warehouse_name');
                }
                if (!Schema::hasIndex('purchases', ['date'])) {
                    $table->index('date');
                }
                if (!Schema::hasIndex('purchases', ['status'])) {
                    $table->index('status');
                }
                if (!Schema::hasIndex('purchases', ['payment_status'])) {
                    $table->index('payment_status');
                }
                if (!Schema::hasIndex('purchases', ['warehouse_name', 'date'])) {
                    $table->index(['warehouse_name', 'date']);
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasIndex('sales', ['date'])) {
                    $table->index('date');
                }
                if (!Schema::hasIndex('sales', ['date', 'warehouse_name'])) {
                    $table->index(['date', 'warehouse_name']);
                }
                if (!Schema::hasIndex('sales', 'sales_cust_ar_idx')) {
                    $table->index(['customer_id', 'status', 'due_amount'], 'sales_cust_ar_idx');
                }
            });
        }

        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (!Schema::hasIndex('sale_returns', ['customer_id'])) {
                    $table->index('customer_id');
                }
                if (!Schema::hasIndex('sale_returns', ['warehouse_name'])) {
                    $table->index('warehouse_name');
                }
                if (!Schema::hasIndex('sale_returns', ['date'])) {
                    $table->index('date');
                }
                if (!Schema::hasIndex('sale_returns', ['status'])) {
                    $table->index('status');
                }
                if (!Schema::hasIndex('sale_returns', ['sale_reference_no'])) {
                    $table->index('sale_reference_no');
                }
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (!Schema::hasIndex('stock_transfers', ['from_warehouse'])) {
                    $table->index('from_warehouse');
                }
                if (!Schema::hasIndex('stock_transfers', ['to_warehouse'])) {
                    $table->index('to_warehouse');
                }
                if (!Schema::hasIndex('stock_transfers', ['date'])) {
                    $table->index('date');
                }
                if (!Schema::hasIndex('stock_transfers', ['status'])) {
                    $table->index('status');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (!Schema::hasIndex('stock_movements', ['warehouse_id'])) {
                    $table->index('warehouse_id');
                }
                if (!Schema::hasIndex('stock_movements', ['product_variant_id'])) {
                    $table->index('product_variant_id');
                }
                if (!Schema::hasIndex('stock_movements', ['movement_type'])) {
                    $table->index('movement_type');
                }
                if (!Schema::hasIndex('stock_movements', ['warehouse_id', 'product_id'])) {
                    $table->index(['warehouse_id', 'product_id']);
                }
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (!Schema::hasIndex('sale_items', ['product_name'])) {
                    $table->index('product_name');
                }
            });
        }

        if (Schema::hasTable('purchase_items')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                if (!Schema::hasIndex('purchase_items', ['product_name'])) {
                    $table->index('product_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex(['supplier_id']);
                $table->dropIndex(['warehouse_name']);
                $table->dropIndex(['date']);
                $table->dropIndex(['status']);
                $table->dropIndex(['payment_status']);
                $table->dropIndex(['warehouse_name', 'date']);
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['date']);
                $table->dropIndex(['date', 'warehouse_name']);
                $table->dropIndex('sales_cust_ar_idx');
            });
        }

        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropIndex(['customer_id']);
                $table->dropIndex(['warehouse_name']);
                $table->dropIndex(['date']);
                $table->dropIndex(['status']);
                $table->dropIndex(['sale_reference_no']);
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropIndex(['from_warehouse']);
                $table->dropIndex(['to_warehouse']);
                $table->dropIndex(['date']);
                $table->dropIndex(['status']);
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropIndex(['warehouse_id']);
                $table->dropIndex(['product_variant_id']);
                $table->dropIndex(['movement_type']);
                $table->dropIndex(['warehouse_id', 'product_id']);
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex(['product_name']);
            });
        }

        if (Schema::hasTable('purchase_items')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->dropIndex(['product_name']);
            });
        }
    }
};
