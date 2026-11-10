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
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('expenses', 'payment_method')) {
                    $table->string('payment_method')->nullable()->default('Cash')->after('amount');
                    $table->index('payment_method');
                }
                if (!Schema::hasColumn('expenses', 'payment_method_id')) {
                    $table->unsignedBigInteger('payment_method_id')->nullable()->after('payment_method');
                    $table->index('payment_method_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'payment_method_id')) {
                    $table->dropIndex(['payment_method_id']);
                    $table->dropColumn('payment_method_id');
                }
                if (Schema::hasColumn('expenses', 'payment_method')) {
                    $table->dropIndex(['payment_method']);
                    $table->dropColumn('payment_method');
                }
            });
        }
    }
};
