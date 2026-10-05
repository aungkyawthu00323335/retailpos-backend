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
                if (!Schema::hasColumn('purchase_returns', 'payments_payload')) {
                    $table->longText('payments_payload')->nullable()->after('items_payload');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_returns') && Schema::hasColumn('purchase_returns', 'payments_payload')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropColumn('payments_payload');
            });
        }
    }
};
