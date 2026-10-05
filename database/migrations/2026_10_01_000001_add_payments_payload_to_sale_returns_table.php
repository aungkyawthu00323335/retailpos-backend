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
                if (!Schema::hasColumn('sale_returns', 'payments_payload')) {
                    $table->longText('payments_payload')->nullable()->after('items_payload');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (Schema::hasColumn('sale_returns', 'payments_payload')) {
                    $table->dropColumn('payments_payload');
                }
            });
        }
    }
};
