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
        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'account_number')) {
                $table->string('account_number')->nullable()->after('description');
            }
            if (!Schema::hasColumn('payment_methods', 'account_name')) {
                $table->string('account_name')->nullable()->after('account_number');
            }
            if (!Schema::hasColumn('payment_methods', 'is_bot_available')) {
                $table->boolean('is_bot_available')->default(true)->after('account_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('payment_methods', 'account_number')) {
                $columnsToDrop[] = 'account_number';
            }
            if (Schema::hasColumn('payment_methods', 'account_name')) {
                $columnsToDrop[] = 'account_name';
            }
            if (Schema::hasColumn('payment_methods', 'is_bot_available')) {
                $columnsToDrop[] = 'is_bot_available';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
