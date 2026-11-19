<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_return_allocations')) {
            return;
        }

        Schema::table('purchase_return_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_return_allocations', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('allocation_type');
                $table->index('released_at', 'pra_released_at_idx');
            }
        });

        try {
            Schema::table('purchase_return_allocations', function (Blueprint $table) {
                $table->dropUnique('pra_unique_offset_idx');
            });
        } catch (\Throwable $e) {
            // Index may already be absent on fresh schemas
        }

        try {
            Schema::table('purchase_return_allocations', function (Blueprint $table) {
                $table->index(
                    ['purchase_return_id', 'purchase_id', 'allocation_type'],
                    'pra_pr_po_type_idx'
                );
            });
        } catch (\Throwable $e) {
            // Ignore if non-unique index already exists
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_return_allocations')) {
            return;
        }

        try {
            Schema::table('purchase_return_allocations', function (Blueprint $table) {
                $table->dropIndex('pra_released_at_idx');
            });
        } catch (\Throwable $e) {}

        Schema::table('purchase_return_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_return_allocations', 'released_at')) {
                $table->dropColumn('released_at');
            }
        });
    }
};
