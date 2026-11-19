<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM sales'))->pluck('Key_name')->unique()->all();

        Schema::table('sales', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_sales_date_id', $existing, true)) {
                $table->index(['date', 'id'], 'idx_sales_date_id');
            }
            if (!in_array('idx_sales_updated_at', $existing, true)) {
                $table->index('updated_at', 'idx_sales_updated_at');
            }
            if (!in_array('idx_sales_created_by', $existing, true)) {
                $table->index('created_by', 'idx_sales_created_by');
            }
            if (!in_array('idx_sales_user_id', $existing, true)) {
                $table->index('user_id', 'idx_sales_user_id');
            }
            if (!in_array('idx_sales_warehouse_id_id', $existing, true)) {
                $table->index(['warehouse_id', 'id'], 'idx_sales_warehouse_id_id');
            }
            if (!in_array('idx_sales_payment_type', $existing, true)) {
                $table->index('payment_type', 'idx_sales_payment_type');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM sales'))->pluck('Key_name')->unique()->all();

        Schema::table('sales', function (Blueprint $table) use ($existing) {
            foreach ([
                'idx_sales_date_id',
                'idx_sales_updated_at',
                'idx_sales_created_by',
                'idx_sales_user_id',
                'idx_sales_warehouse_id_id',
                'idx_sales_payment_type',
            ] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }
};
