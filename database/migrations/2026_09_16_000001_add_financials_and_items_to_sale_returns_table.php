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
                if (!Schema::hasColumn('sale_returns', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->default(0)->after('status');
                }
                if (!Schema::hasColumn('sale_returns', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate');
                }
                if (!Schema::hasColumn('sale_returns', 'discount')) {
                    $table->decimal('discount', 15, 2)->default(0)->after('tax_amount');
                }
                if (!Schema::hasColumn('sale_returns', 'shipping')) {
                    $table->decimal('shipping', 15, 2)->default(0)->after('discount');
                }
                if (!Schema::hasColumn('sale_returns', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->default(0)->after('shipping');
                }
                if (!Schema::hasColumn('sale_returns', 'items_payload')) {
                    $table->longText('items_payload')->nullable()->after('notes');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $cols = ['tax_rate', 'tax_amount', 'discount', 'shipping', 'subtotal', 'items_payload'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('sale_returns', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
