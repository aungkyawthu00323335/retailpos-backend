<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the Product List screen.
 *
 * PROBLEM: Every default page load (`ORDER BY id DESC`, with `deleted_at IS NULL`)
 * forced the query planner to do a full table scan because the only way to satisfy
 * both the soft-delete filter and the sort was to read every row, filter, then sort.
 *
 * SOLUTION: Two composite "covering" indexes that let the query planner resolve the
 * WHERE + ORDER BY in a single index scan without touching the table heap:
 *
 *   1. `products_active_list_index`  — (deleted_at, id)
 *      Used by every unfiltered page load (default sort = id DESC).
 *
 *   2. `products_category_list_index` — (deleted_at, category_name, id)
 *      Used when the user selects a category filter in the dropdown.
 *      The extra `id` column at the end turns it from a filter index into a
 *      covering sort index so no separate filesort step is needed.
 *
 * FULLTEXT note: SQLite does not support FULLTEXT indexes; the LIKE-based search
 * will continue to do a partial scan. On MySQL/MariaDB in production the FULLTEXT
 * index below can be added to accelerate multi-column keyword search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Default sort: WHERE deleted_at IS NULL ORDER BY id DESC
            $table->index(['deleted_at', 'id'], 'products_active_list_index');

            // Category filter: WHERE deleted_at IS NULL AND category_name = ? ORDER BY id DESC
            $table->index(
                ['deleted_at', 'category_name', 'id'],
                'products_category_list_index',
            );

            // Brand filter: WHERE deleted_at IS NULL AND brand_name = ? ORDER BY id DESC
            $table->index(
                ['deleted_at', 'brand_name', 'id'],
                'products_brand_list_index',
            );

            // Status filter: WHERE deleted_at IS NULL AND status = ? ORDER BY id DESC
            $table->index(
                ['deleted_at', 'status', 'id'],
                'products_status_list_index',
            );
        });

        // FULLTEXT search index (MySQL/MariaDB only — skip for SQLite).
        // Accelerates the multi-column LIKE search for name/code/sku/barcode.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE products ADD FULLTEXT INDEX products_search_fulltext (name, code, sku, barcode, category_name, brand_name)"
            );
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_list_index');
            $table->dropIndex('products_category_list_index');
            $table->dropIndex('products_brand_list_index');
            $table->dropIndex('products_status_list_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP INDEX products_search_fulltext');
        }
    }
};
