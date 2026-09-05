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
        if (!Schema::hasColumn('users', 'user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_id', 50)->nullable()->unique()->after('id');
            });
        }

        // Backfill existing users with sequential 6-digit numeric IDs starting at 100001
        $users = DB::table('users')->whereNull('user_id')->orWhere('user_id', '')->orderBy('id', 'asc')->get();
        $startId = 100001;
        foreach ($users as $u) {
            DB::table('users')->where('id', $u->id)->update([
                'user_id' => (string)$startId,
            ]);
            $startId++;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }
};
