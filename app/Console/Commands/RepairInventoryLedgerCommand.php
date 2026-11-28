<?php

namespace App\Console\Commands;

use App\Services\InventoryLedgerRepairService;
use Illuminate\Console\Command;

class RepairInventoryLedgerCommand extends Command
{
    protected $signature = 'inventory:repair-ledger
                            {--skip-balance-reconcile : Only sync placeholder product names/units without reconciling seeded balances}';

    protected $description = 'Replace placeholder Retail Product # names across transaction ledgers, backfill return stock movements, purge orphaned pre-seed batches, and reconcile perpetual stock balances.';

    public function handle(InventoryLedgerRepairService $service): int
    {
        $t0 = microtime(true);
        $this->info('Starting Senior Accounting Inventory & Ledger Repair...');

        $summary = $service->repairAll(!$this->option('skip-balance-reconcile'));

        foreach ($summary['tables_synced'] as $tbl => $cnt) {
            $this->line("  [Catalog Sync] {$tbl}: {$cnt} rows updated");
        }
        foreach ($summary['return_payloads_synced'] as $tbl => $cnt) {
            $this->line("  [Return JSON]  {$tbl}: {$cnt} payloads updated");
        }
        $this->line("  [FIFO Cleanup] Orphaned pre-seed batches removed: {$summary['orphaned_batches_removed']}");
        $this->line("  [Ledger Sync]  Sale return movements backfilled: {$summary['sale_return_movements_backfilled']}");
        $this->line("  [Ledger Sync]  Purchase return movements backfilled: {$summary['purchase_return_movements_backfilled']}");
        $this->line("  [Reconcile]    Opening stock shortfalls topped up: {$summary['opening_shortfalls_topped_up']}");
        $this->line("  [Reconcile]    Stock balances reconciled: {$summary['stock_balances_reconciled']}");
        $this->line("  [Reconcile]    Products.stock_quantity synced: {$summary['products_stock_synced']}");

        $elapsed = round(microtime(true) - $t0, 2);
        $this->info("Completed cleanly in {$elapsed}s.");

        return self::SUCCESS;
    }
}
