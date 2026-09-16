<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitFifoBatchesCommand extends Command
{
    protected $signature = 'pos:init-fifo';
    protected $description = 'High-performance initialization of FIFO batches for existing stock balances and backfill historical sale COGS';

    public function handle(): int
    {
        $this->info('Starting Ultra-Fast FIFO Inventory Batch Initialization...');

        // 1. High-speed bulk insertion of opening stock into inventory_batches (1 query for 16,000+ items)
        $inserted = DB::affectingStatement("
            INSERT INTO inventory_batches (
                warehouse_id,
                product_id,
                product_variant_id,
                batch_number,
                source_type,
                source_reference,
                received_date,
                unit_cost,
                initial_quantity,
                remaining_quantity,
                status,
                created_by,
                notes,
                created_at,
                updated_at
            )
            SELECT 
                sb.warehouse_id,
                sb.product_id,
                sb.product_variant_id,
                CONCAT('LOT-INIT-', sb.warehouse_id, '-', sb.product_id, IF(sb.product_variant_id IS NOT NULL, CONCAT('-V', sb.product_variant_id), '')),
                'opening_stock',
                'SYSTEM-INIT',
                DATE_SUB(NOW(), INTERVAL 1 MONTH),
                COALESCE(NULLIF(pv.cost_price, 0), NULLIF(p.cost_price, 0), NULLIF(p.purchase_price, 0), 0),
                sb.quantity,
                sb.quantity,
                'active',
                1,
                'Initial opening stock batch',
                NOW(),
                NOW()
            FROM stock_balances sb
            JOIN products p ON sb.product_id = p.id
            LEFT JOIN product_variants pv ON sb.product_variant_id = pv.id
            WHERE sb.quantity > 0
            AND NOT EXISTS (
                SELECT 1 FROM inventory_batches ib 
                WHERE ib.warehouse_id = sb.warehouse_id 
                AND ib.product_id = sb.product_id 
                AND (ib.product_variant_id = sb.product_variant_id OR (ib.product_variant_id IS NULL AND sb.product_variant_id IS NULL))
            )
        ");
        $this->info("Initialized {$inserted} opening FIFO batches in single pass.");

        // 2. Backfill historical sale items COGS
        // IMPORTANT NOTE (Data Integrity Limitation):
        // Historical sales (before FIFO migration) cannot have true FIFO COGS because the original
        // purchase batch cost data at the time of those sales is unavailable.
        // This backfill uses current product cost_price as an APPROXIMATION for pre-migration sales.
        // All NEW sales (after this migration) use true FIFO depletion via FifoInventoryService::depleteFifo().
        // P&L reports covering pre-migration date ranges will show approximate COGS for historical records.
        $this->info('Backfilling historical sale items COGS (approximate — using current cost_price)...');
        DB::statement("
            UPDATE sale_items
            JOIN products ON sale_items.product_id = products.id
            LEFT JOIN product_variants ON sale_items.product_variant_id = product_variants.id
            SET 
                sale_items.base_unit_cost = COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0),
                sale_items.total_cogs = ROUND(sale_items.base_quantity * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0), 2)
            WHERE sale_items.total_cogs = 0 OR sale_items.total_cogs IS NULL
        ");

        // 3. Backfill sales header total_cogs
        $this->info('Backfilling sales header total_cogs...');
        DB::statement("
            UPDATE sales
            JOIN (
                SELECT sale_id, ROUND(SUM(total_cogs), 2) as sum_cogs
                FROM sale_items
                GROUP BY sale_id
            ) as item_cogs ON sales.id = item_cogs.sale_id
            SET sales.total_cogs = item_cogs.sum_cogs
            WHERE sales.total_cogs = 0 OR sales.total_cogs IS NULL
        ");

        $this->info('FIFO initialization and backfill completed successfully.');
        return 0;
    }
}
