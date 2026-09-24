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
        if (!Schema::hasColumn('customers', 'customer_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_id', 50)->nullable()->unique()->after('id');
            });
        }

        // Backfill existing customers with unique sequential formatted IDs: CUST-001, CUST-002, etc.
        $customers = DB::table('customers')
            ->whereNull('customer_id')
            ->orWhere('customer_id', '')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($customers as $c) {
            $formattedId = 'CUST-' . str_pad((string)$c->id, 3, '0', STR_PAD_LEFT);
            DB::table('customers')->where('id', $c->id)->update([
                'customer_id' => $formattedId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('customers', 'customer_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
        }
    }
};
