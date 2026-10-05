<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_payments', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('sale_payments', 'user_name')) {
                $table->string('user_name')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('sale_payments', 'user_role')) {
                $table->string('user_role')->nullable()->after('user_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('sale_payments', 'user_role')) {
                $columns[] = 'user_role';
            }
            if (Schema::hasColumn('sale_payments', 'user_name')) {
                $columns[] = 'user_name';
            }
            if (Schema::hasColumn('sale_payments', 'user_id')) {
                $columns[] = 'user_id';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
