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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('phone')->constrained('roles')->nullOnDelete();
            $table->string('role')->default('Cashier')->after('role_id');
            $table->string('branch')->default('Main Branch Outlet')->after('role');
            $table->string('avatar')->nullable()->after('branch');
            $table->string('status')->default('Active')->after('avatar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['phone', 'role_id', 'role', 'branch', 'avatar', 'status']);
        });
    }
};
