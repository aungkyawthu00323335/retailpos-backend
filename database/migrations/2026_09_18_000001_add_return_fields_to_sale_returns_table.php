<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                // Ensure warehouse_id column exists (was used in controller but never in migration)
                if (!Schema::hasColumn('sale_returns', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse_name')->index();
                }
                // Return reason: why was the item returned
                if (!Schema::hasColumn('sale_returns', 'return_reason')) {
                    $table->string('return_reason', 255)->nullable()->after('notes');
                }
                // Refund tender: how the refund is given back to the customer
                // May differ from original payment_type (e.g. original was KBZPay but refund via Cash)
                if (!Schema::hasColumn('sale_returns', 'refund_tender')) {
                    $table->string('refund_tender', 100)->default('Cash')->after('return_reason');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $cols = ['warehouse_id', 'return_reason', 'refund_tender'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('sale_returns', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
