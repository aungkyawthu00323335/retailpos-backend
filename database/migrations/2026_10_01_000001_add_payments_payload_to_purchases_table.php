<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasColumn('purchases', 'payments_payload')) {
                    $table->longText('payments_payload')->nullable()->after('payment_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'payments_payload')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropColumn('payments_payload');
            });
        }
    }
};
