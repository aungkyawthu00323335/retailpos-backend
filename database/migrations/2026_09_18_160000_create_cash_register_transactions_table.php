<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cash_register_transactions')) {
            Schema::create('cash_register_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cash_register_id');
                $table->string('type', 32); // 'drop' (safe drop / skim), 'float_in' (add float / change)
                $table->decimal('amount', 15, 2);
                $table->string('reason', 255)->nullable();
                $table->string('authorized_by', 255)->nullable();
                $table->boolean('manager_pin_verified')->default(false);
                $table->timestamps();

                $table->foreign('cash_register_id')->references('id')->on('cash_registers')->onDelete('cascade');
                $table->index(['cash_register_id', 'type']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_transactions');
    }
};