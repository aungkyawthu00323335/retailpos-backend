<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Normalize existing date strings to standard 'YYYY-MM-DD HH:MM:SS' format
        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'date')) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement("UPDATE quotations SET date = CONCAT(TRIM(date), ' 00:00:00') WHERE LENGTH(TRIM(date)) = 10 AND date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'");
                
                // Alter column type to DATETIME
                DB::statement("ALTER TABLE quotations MODIFY COLUMN date DATETIME NOT NULL");
            } else {
                // SQLite or other drivers
                Schema::table('quotations', function (Blueprint $table) {
                    $table->dateTime('date')->change();
                });
            }

            // Ensure covering index on (date, id) exists
            $indexes = Schema::getIndexes('quotations');
            $hasIndex = false;
            foreach ($indexes as $index) {
                if ($index['name'] === 'quotations_date_list_index') {
                    $hasIndex = true;
                    break;
                }
            }
            if (!$hasIndex) {
                Schema::table('quotations', function (Blueprint $table) {
                    $table->index(['date', 'id'], 'quotations_date_list_index');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quotations') && Schema::hasColumn('quotations', 'date')) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement("ALTER TABLE quotations MODIFY COLUMN date VARCHAR(255) NOT NULL");
            } else {
                Schema::table('quotations', function (Blueprint $table) {
                    $table->string('date')->change();
                });
            }
        }
    }
};
