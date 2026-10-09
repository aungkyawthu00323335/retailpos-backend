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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_bot_available')) {
                $table->boolean('is_bot_available')->default(false)->after('is_active')->index();
            }
            if (!Schema::hasColumn('products', 'bot_price')) {
                $table->decimal('bot_price', 16, 2)->nullable()->after('is_bot_available');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_bot_available')) {
                $table->dropColumn('is_bot_available');
            }
            if (Schema::hasColumn('products', 'bot_price')) {
                $table->dropColumn('bot_price');
            }
        });
    }
};
