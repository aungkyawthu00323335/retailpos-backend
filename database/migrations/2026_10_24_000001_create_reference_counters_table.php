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
        if (!Schema::hasTable('reference_counters')) {
            Schema::create('reference_counters', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('type', 50)->comment('sale, purchase, quotation, transfer, etc.');
                $table->string('prefix', 50)->default('');
                $table->unsignedBigInteger('outlet_id')->default(0)->comment('0 for global sequence, or specific outlet_id');
                $table->unsignedBigInteger('last_seq')->default(0);
                $table->timestamps();

                $table->unique(['type', 'prefix', 'outlet_id'], 'uq_ref_counter_scope');
                $table->index(['type', 'outlet_id'], 'idx_ref_counter_lookup');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_counters');
    }
};
