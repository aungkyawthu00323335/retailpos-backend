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
        // Brands
        if (!Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('code')->nullable();
                $table->text('image_url')->nullable();
                $table->text('description')->nullable();
                $table->string('status')->default('Active');
                $table->timestamps();
            });
        }

        // Add Retail columns to products table if missing
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'barcode_symbology')) {
                    $table->string('barcode_symbology')->default('CODE128')->after('code');
                }
                if (!Schema::hasColumn('products', 'brand_name')) {
                    $table->string('brand_name')->nullable()->after('name');
                }
                if (!Schema::hasColumn('products', 'purchase_unit')) {
                    $table->string('purchase_unit')->nullable()->after('unit_name');
                }
                if (!Schema::hasColumn('products', 'sale_unit')) {
                    $table->string('sale_unit')->nullable()->after('purchase_unit');
                }
                if (!Schema::hasColumn('products', 'retail_price')) {
                    $table->decimal('retail_price', 15, 2)->default(0.00)->after('purchase_price');
                }
                if (!Schema::hasColumn('products', 'stock_quantity')) {
                    $table->decimal('stock_quantity', 15, 2)->default(0.00)->after('retail_price');
                }
                if (!Schema::hasColumn('products', 'alert_quantity')) {
                    $table->decimal('alert_quantity', 15, 2)->default(10.00)->after('stock_quantity');
                }
                if (!Schema::hasColumn('products', 'order_tax')) {
                    $table->decimal('order_tax', 8, 2)->default(0.00)->after('alert_quantity');
                }
                if (!Schema::hasColumn('products', 'tax_type')) {
                    $table->string('tax_type')->default('Exclusive')->after('order_tax');
                }
            });
        }

        // Stock Adjustments
        if (!Schema::hasTable('stock_adjustments')) {
            Schema::create('stock_adjustments', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->string('warehouse_name');
                $table->string('date');
                $table->integer('total_products')->default(0);
                $table->decimal('total_quantity', 15, 2)->default(0);
                $table->string('type')->default('Addition');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('created_by')->nullable();
                $table->string('user_role')->nullable();
                $table->timestamps();
            });
        }

        // Stock Adjustment Items
        if (!Schema::hasTable('stock_adjustment_items')) {
            Schema::create('stock_adjustment_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->onDelete('cascade');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code');
                $table->string('product_name');
                $table->decimal('quantity', 15, 2)->default(0);
                $table->string('type')->default('Addition');
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // Stock Transfers
        if (!Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->string('from_warehouse');
                $table->string('to_warehouse');
                $table->string('date');
                $table->integer('items_count')->default(0);
                $table->decimal('total_quantity', 15, 2)->default(0);
                $table->decimal('shipping_charge', 15, 2)->default(0);
                $table->string('status')->default('Completed');
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Stock Transfer Items
        if (!Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->onDelete('cascade');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code');
                $table->string('product_name');
                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // Purchases
        if (!Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('supplier_name');
                $table->string('warehouse_name');
                $table->string('date');
                $table->string('status')->default('Received');
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('shipping', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('due_amount', 15, 2)->default(0);
                $table->string('payment_status')->default('Paid');
                $table->string('payment_type')->default('Cash');
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Purchase Items
        if (!Schema::hasTable('purchase_items')) {
            Schema::create('purchase_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code');
                $table->string('product_name');
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // Purchase Returns
        if (!Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->string('purchase_reference_no')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('supplier_name');
                $table->string('warehouse_name');
                $table->string('date');
                $table->string('status')->default('Completed');
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('due_amount', 15, 2)->default(0);
                $table->string('payment_status')->default('Paid');
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Sales
        if (!Schema::hasTable('sales')) {
            Schema::create('sales', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name');
                $table->string('warehouse_name');
                $table->string('date');
                $table->string('status')->default('Completed');
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('shipping', 15, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('due_amount', 15, 2)->default(0);
                $table->decimal('change_amount', 15, 2)->default(0);
                $table->string('payment_status')->default('Paid');
                $table->string('payment_type')->default('Cash');
                $table->text('notes')->nullable();
                $table->boolean('pos_flag')->default(true);
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Sale Items
        if (!Schema::hasTable('sale_items')) {
            Schema::create('sale_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code');
                $table->string('product_name');
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // Sale Payments
        if (!Schema::hasTable('sale_payments')) {
            Schema::create('sale_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
                $table->string('reference_no')->unique();
                $table->string('payment_date');
                $table->string('payment_type');
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('received_amount', 15, 2)->default(0);
                $table->decimal('change_amount', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Sale Returns
        if (!Schema::hasTable('sale_returns')) {
            Schema::create('sale_returns', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->string('sale_reference_no')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name');
                $table->string('warehouse_name');
                $table->string('date');
                $table->string('status')->default('Completed');
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('due_amount', 15, 2)->default(0);
                $table->string('payment_status')->default('Paid');
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Quotations
        if (!Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name');
                $table->string('warehouse_name');
                $table->string('date');
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('shipping', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->string('status')->default('Sent');
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Quotation Items
        if (!Schema::hasTable('quotation_items')) {
            Schema::create('quotation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained('quotations')->onDelete('cascade');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code');
                $table->string('product_name');
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // Expense Categories
        if (!Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('code')->nullable();
                $table->text('description')->nullable();
                $table->string('status')->default('Active');
                $table->timestamps();
            });
        }

        // Expenses
        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->string('warehouse_name');
                $table->unsignedBigInteger('expense_category_id')->nullable();
                $table->string('expense_category_name');
                $table->string('title');
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('date');
                $table->text('details')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Payment Methods (InfyPOS /app/payment-methods)
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('code')->nullable();
                $table->string('icon')->default('payments_rounded');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        // Currencies
        if (!Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->string('symbol');
                $table->decimal('rate', 15, 4)->default(1.0000);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        // Settings
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('brands');
    }
};
