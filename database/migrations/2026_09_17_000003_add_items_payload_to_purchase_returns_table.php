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
                if (!Schema::hasColumn('purchase_returns', 'items_payload')) {
                    $table->longText('items_payload')->nullable()->after('notes');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_returns') && Schema::hasColumn('purchase_returns', 'items_payload')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropColumn('items_payload');
            });
        }
    }
};
