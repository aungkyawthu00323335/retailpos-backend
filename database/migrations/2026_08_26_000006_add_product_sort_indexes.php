<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        $existing = collect(Schema::getIndexes('products'))
            ->pluck('name')
            ->all();

        foreach ([
            'products_low_qty_sort_index' => 'low_qty',
            'products_has_variants_sort_index' => 'has_variants',
        ] as $name => $column) {
            if (in_array($name, $existing, true)) {
                continue;
            }
            Schema::table('products', function (Blueprint $table) use ($name, $column) {
                $table->index($column, $name);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'products_low_qty_sort_index',
                'products_has_variants_sort_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable $e) {
                }
            }
        });
    }
};
