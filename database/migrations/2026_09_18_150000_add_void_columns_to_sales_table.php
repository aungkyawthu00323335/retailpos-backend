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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'void_reason')) {
                $table->string('void_reason')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('sales', 'voided_by')) {
                $table->string('voided_by')->nullable()->after('void_reason');
            }
            if (!Schema::hasColumn('sales', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('voided_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
            if (Schema::hasColumn('sales', 'voided_by')) {
                $table->dropColumn('voided_by');
            }
            if (Schema::hasColumn('sales', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });
    }
};
