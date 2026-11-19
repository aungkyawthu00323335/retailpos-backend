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
        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->index('created_by', 'idx_quo_created_by');
                $table->index('user_id', 'idx_quo_user_id');
                $table->index('updated_at', 'idx_quo_updated_at');
            });
        }

        if (Schema::hasTable('quotation_items')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->index(
                    ['quotation_id', 'quantity'],
                    'idx_quo_items_cov_qty'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropIndex('idx_quo_created_by');
                $table->dropIndex('idx_quo_user_id');
                $table->dropIndex('idx_quo_updated_at');
            });
        }

        if (Schema::hasTable('quotation_items')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->dropIndex('idx_quo_items_cov_qty');
            });
        }
    }
};
