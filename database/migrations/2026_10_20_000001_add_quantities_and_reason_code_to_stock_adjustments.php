<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_adjustments', 'net_quantity')) {
                    $table->decimal('net_quantity', 14, 4)->default(0.0)->after('total_quantity');
                }
                if (!Schema::hasColumn('stock_adjustments', 'gross_quantity')) {
                    $table->decimal('gross_quantity', 14, 4)->default(0.0)->after('net_quantity');
                }
                if (!Schema::hasColumn('stock_adjustments', 'reason_code')) {
                    $table->string('reason_code', 100)->nullable()->after('type');
                    $table->index('reason_code', 'idx_stk_adj_reason_code');
                }
                if (!Schema::hasColumn('stock_adjustments', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('reference_no');
                }
            });

            // Populate existing records: set gross_quantity and net_quantity from total_quantity
            DB::statement("UPDATE stock_adjustments SET gross_quantity = ABS(total_quantity), net_quantity = total_quantity WHERE gross_quantity = 0.0 AND total_quantity != 0.0");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                if (Schema::hasColumn('stock_adjustments', 'reason_code')) {
                    $table->dropIndex('idx_stk_adj_reason_code');
                    $table->dropColumn('reason_code');
                }
                if (Schema::hasColumn('stock_adjustments', 'gross_quantity')) {
                    $table->dropColumn('gross_quantity');
                }
                if (Schema::hasColumn('stock_adjustments', 'net_quantity')) {
                    $table->dropColumn('net_quantity');
                }
            });
        }
    }
};
