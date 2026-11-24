<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($rows);
    }

    public function up(): void
    {
        if (Schema::hasTable('expense_payments') && Schema::hasColumn('expense_payments', 'created_at') && Schema::hasColumn('expense_payments', 'cash_register_id')) {
            if (!$this->indexExists('expense_payments', 'idx_ep_created_reg')) {
                Schema::table('expense_payments', function (Blueprint $table) {
                    $table->index(['created_at', 'cash_register_id'], 'idx_ep_created_reg');
                });
            }
        }

        if (Schema::hasTable('sale_returns') && Schema::hasColumn('sale_returns', 'warehouse_name') && Schema::hasColumn('sale_returns', 'created_at')) {
            if (!$this->indexExists('sale_returns', 'idx_sr_wh_created')) {
                Schema::table('sale_returns', function (Blueprint $table) {
                    $table->index(['warehouse_name', 'created_at'], 'idx_sr_wh_created');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_payments') && $this->indexExists('expense_payments', 'idx_ep_created_reg')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                $table->dropIndex('idx_ep_created_reg');
            });
        }
        if (Schema::hasTable('sale_returns') && $this->indexExists('sale_returns', 'idx_sr_wh_created')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropIndex('idx_sr_wh_created');
            });
        }
    }
};
