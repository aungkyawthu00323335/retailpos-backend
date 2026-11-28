<?php

use App\Services\InventoryLedgerRepairService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotently repairs placeholder 'Retail Product #...' snapshots, orphaned pre-seed
        // inventory_batches, missing return stock_movements, and drifted seeded stock_balances.
        // Safe on empty test databases and live production databases (never overwrites real names).
        app(InventoryLedgerRepairService::class)->repairAll(true);
    }

    public function down(): void
    {
        // Data-integrity remediation migration is non-destructive and intentionally irreversible.
    }
};
