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
                $table->index('reference_no', 'idx_pr_ref_no');
                $table->index('purchase_reference_no', 'idx_pr_po_ref_no');
                $table->index('date', 'idx_pr_date');
                $table->index('warehouse_name', 'idx_pr_wh_name');
                $table->index('status', 'idx_pr_status');
                $table->index('payment_status', 'idx_pr_payment_status');
                $table->index('supplier_id', 'idx_pr_supplier_id');
                $table->index('outlet_id', 'idx_pr_outlet_id');
                $table->index(['warehouse_name', 'date'], 'idx_pr_wh_date');
                $table->index(['status', 'payment_status'], 'idx_pr_status_pay');
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
                $table->dropIndex('idx_pr_ref_no');
                $table->dropIndex('idx_pr_po_ref_no');
                $table->dropIndex('idx_pr_date');
                $table->dropIndex('idx_pr_wh_name');
                $table->dropIndex('idx_pr_status');
                $table->dropIndex('idx_pr_payment_status');
                $table->dropIndex('idx_pr_supplier_id');
                $table->dropIndex('idx_pr_outlet_id');
                $table->dropIndex('idx_pr_wh_date');
                $table->dropIndex('idx_pr_status_pay');
            });
        }
    }
};
