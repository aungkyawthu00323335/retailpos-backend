<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Q2: Add expiry_date to quotations ─────────────────────────────────
        // Enables automatic expiry tracking. An expired quotation should not be
        // included in active pipeline revenue reports (avoids overstating AR).
        if (Schema::hasTable('quotations') && !Schema::hasColumn('quotations', 'expiry_date')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('date')
                      ->comment('Date after which this quotation is no longer valid. Null = no expiry set.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'expiry_date')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropColumn('expiry_date');
            });
        }
    }
};
