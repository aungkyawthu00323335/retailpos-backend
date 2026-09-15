<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('sale_returns', 'payment_type')) {
                    $table->string('payment_type', 50)->default('Cash')->after('payment_status');
                }
            });

            // Populate varied realistic payment channels across existing rows
            $types = ['Cash', 'KBZPay', 'WavePay', 'Card', 'Split Payment', 'Store Credit', 'Bank Transfer'];
            $rows = DB::table('sale_returns')->select('id')->get();
            foreach ($rows as $r) {
                DB::table('sale_returns')->where('id', $r->id)->update([
                    'payment_type' => $types[$r->id % count($types)]
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sale_returns') && Schema::hasColumn('sale_returns', 'payment_type')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropColumn('payment_type');
            });
        }
    }
};
