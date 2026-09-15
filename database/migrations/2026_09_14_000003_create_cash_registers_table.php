<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cash_registers')) {
            Schema::create('cash_registers', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no', 64)->unique();
                $table->string('register_name', 100)->default('Register 01');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 255)->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_name', 255)->nullable();
                $table->decimal('opening_cash', 15, 2)->default(0.00);
                $table->decimal('closing_cash', 15, 2)->nullable();
                $table->decimal('expected_cash', 15, 2)->nullable();
                $table->decimal('cash_difference', 15, 2)->nullable();
                $table->string('status', 32)->default('open'); // 'open', 'closed'
                $table->dateTime('opened_at');
                $table->dateTime('closed_at')->nullable();
                $table->text('opening_notes')->nullable();
                $table->text('closing_notes')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['warehouse_id', 'status']);
                $table->index('opened_at');
                $table->index('status');
            });
        }

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'cash_register_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('cash_register_id')->nullable()->after('pos_flag')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'cash_register_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['cash_register_id']);
                $table->dropColumn('cash_register_id');
            });
        }

        Schema::dropIfExists('cash_registers');
    }
};
