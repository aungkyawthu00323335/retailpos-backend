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
        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_returns', 'payment_type')) {
                    $table->string('payment_type', 50)->default('Cash')->after('payment_status');
                }
            });

            // Populate varied realistic payment types across existing rows
            $types = ['Cash', 'Bank Transfer', 'Credit Note', 'KBZPay'];
            $rows = DB::table('purchase_returns')->select('id')->get();
            foreach ($rows as $r) {
                DB::table('purchase_returns')->where('id', $r->id)->update([
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
        if (Schema::hasTable('purchase_returns') && Schema::hasColumn('purchase_returns', 'payment_type')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropColumn('payment_type');
            });
        }
    }
};
