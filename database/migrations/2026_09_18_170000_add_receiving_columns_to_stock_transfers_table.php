<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfers', 'received_by')) {
                    $table->string('received_by')->nullable()->after('created_by');
                }
                if (!Schema::hasColumn('stock_transfers', 'received_at')) {
                    $table->dateTime('received_at')->nullable()->after('received_by');
                }
                if (!Schema::hasColumn('stock_transfers', 'receiving_notes')) {
                    $table->text('receiving_notes')->nullable()->after('received_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $columns = ['received_by', 'received_at', 'receiving_notes'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('stock_transfers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};