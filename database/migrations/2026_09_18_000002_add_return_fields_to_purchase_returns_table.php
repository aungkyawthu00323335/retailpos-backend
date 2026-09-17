<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                // Return reason: why goods are returned to supplier
                if (!Schema::hasColumn('purchase_returns', 'return_reason')) {
                    $table->string('return_reason', 255)->nullable()->after('notes');
                }
                // payment_type: how supplier refunds (Cash, Bank Transfer, Credit Note, Cheque)
                if (!Schema::hasColumn('purchase_returns', 'payment_type')) {
                    $table->string('payment_type', 100)->default('Cash')->after('return_reason');
                }
                // Financial detail columns (matching sale_returns for consistency)
                if (!Schema::hasColumn('purchase_returns', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->default(0)->after('payment_type');
                }
                if (!Schema::hasColumn('purchase_returns', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->default(0)->after('subtotal');
                }
                if (!Schema::hasColumn('purchase_returns', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate');
                }
                if (!Schema::hasColumn('purchase_returns', 'discount')) {
                    $table->decimal('discount', 15, 2)->default(0)->after('tax_amount');
                }
                if (!Schema::hasColumn('purchase_returns', 'shipping')) {
                    $table->decimal('shipping', 15, 2)->default(0)->after('discount');
                }
                // items_payload may already exist from prior migration — skip if so
                if (!Schema::hasColumn('purchase_returns', 'items_payload')) {
                    $table->longText('items_payload')->nullable()->after('shipping');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $cols = ['return_reason', 'payment_type', 'subtotal', 'tax_rate', 'tax_amount', 'discount', 'shipping'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('purchase_returns', $col)) {
                        $table->dropColumn($col);
                    }
                }
                // Do NOT drop items_payload here — it may have been created by a prior migration
            });
        }
    }
};
