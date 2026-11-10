<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Expand decimal precision to 24, 8 to support large enterprise transaction amounts (>= 10 Billion Kyats)
            if (Schema::hasTable('sale_items') && Schema::hasColumn('sale_items', 'base_unit_price')) {
                DB::statement("ALTER TABLE sale_items MODIFY COLUMN base_unit_price DECIMAL(24, 8) DEFAULT 0.00000000");
            }

            if (Schema::hasTable('purchase_items') && Schema::hasColumn('purchase_items', 'base_unit_cost')) {
                DB::statement("ALTER TABLE purchase_items MODIFY COLUMN base_unit_cost DECIMAL(24, 8) DEFAULT 0.00000000");
            }

            if (Schema::hasTable('quotation_items') && Schema::hasColumn('quotation_items', 'base_unit_price')) {
                DB::statement("ALTER TABLE quotation_items MODIFY COLUMN base_unit_price DECIMAL(24, 8) DEFAULT 0.00000000");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            if (Schema::hasTable('sale_items') && Schema::hasColumn('sale_items', 'base_unit_price')) {
                DB::statement("ALTER TABLE sale_items MODIFY COLUMN base_unit_price DECIMAL(18, 8) DEFAULT 0.00000000");
            }

            if (Schema::hasTable('purchase_items') && Schema::hasColumn('purchase_items', 'base_unit_cost')) {
                DB::statement("ALTER TABLE purchase_items MODIFY COLUMN base_unit_cost DECIMAL(18, 8) DEFAULT 0.00000000");
            }

            if (Schema::hasTable('quotation_items') && Schema::hasColumn('quotation_items', 'base_unit_price')) {
                DB::statement("ALTER TABLE quotation_items MODIFY COLUMN base_unit_price DECIMAL(18, 8) DEFAULT 0.00000000");
            }
        }
    }
};
