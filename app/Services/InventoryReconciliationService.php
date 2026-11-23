<?php

namespace App\Services;

use App\Support\AdjustmentReasonCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Senior Accounting Audit & FIFO Valuation Reconciliation Engine.
 * Compares Book Stock Balances against active FIFO Batch Subledgers,
 * computes authoritative FIFO Asset Valuation (with legacy unbatched fallback),
 * and audits Stock Adjustment Cost Layers & P&L vs Non-P&L classifications.
 */
class InventoryReconciliationService
{
    /** @var array<string, bool> */
    private static array $tableExistsCache = [];
    /** @var array<string, array<string, int>> */
    private static array $columnListingCache = [];

    private function schemaKey(): string
    {
        $conn = DB::connection();
        return $conn->getName() . ':' . spl_object_id($conn->getPdo());
    }

    private function cachedHasTable(string $table): bool
    {
        $key = $this->schemaKey() . ':' . $table;
        if (!array_key_exists($key, self::$tableExistsCache)) {
            self::$tableExistsCache[$key] = Schema::hasTable($table);
        }
        return self::$tableExistsCache[$key];
    }

    private function cachedColumnMap(string $table): array
    {
        $key = $this->schemaKey() . ':' . $table;
        if (!array_key_exists($key, self::$columnListingCache)) {
            self::$columnListingCache[$key] = array_flip(Schema::getColumnListing($table));
        }
        return self::$columnListingCache[$key];
    }

    /**
     * Fast SQL-level portfolio valuation totals without loading all products/variants/batches into PHP.
     */
    public function computeValuationSummary(
        ?int $warehouseId = null,
        ?array $allowedWarehouseIds = null,
        ?array $productIds = null,
        ?int $knownActiveProductCount = null,
        bool $includeInTransit = true,
        $productIdsSubQuery = null
    ): array {
        if ($productIds !== null) {
            $productIds = array_values(array_unique(array_map('intval', $productIds)));
            if (empty($productIds)) {
                return [
                    'total_book_qty' => 0.0,
                    'total_fifo_qty' => 0.0,
                    'total_unbatched_qty' => 0.0,
                    'in_transit_qty' => 0.0,
                    'in_transit_valuation' => 0.0,
                    'total_inventory_valuation_with_in_transit' => 0.0,
                    'master_valuation' => 0.0,
                    'fifo_batch_valuation' => 0.0,
                    'effective_valuation' => 0.0,
                    'retail_valuation' => 0.0,
                    'valuation_variance' => 0.0,
                ];
            }
            $chunkLimit = DB::connection()->getDriverName() === 'sqlite' ? 400 : 2000;
            if (count($productIds) > $chunkLimit) {
                $acc = [
                    'total_book_qty' => 0.0,
                    'total_fifo_qty' => 0.0,
                    'total_unbatched_qty' => 0.0,
                    'in_transit_qty' => 0.0,
                    'in_transit_valuation' => 0.0,
                    'master_valuation' => 0.0,
                    'fifo_batch_valuation' => 0.0,
                    'effective_valuation' => 0.0,
                    'retail_valuation' => 0.0,
                ];
                foreach (array_chunk($productIds, $chunkLimit) as $chunk) {
                    $part = $this->computeValuationSummary($warehouseId, $allowedWarehouseIds, $chunk, null, $includeInTransit);
                    foreach ($acc as $k => $_) {
                        $acc[$k] += (float)$part[$k];
                    }
                }
                return [
                    'total_book_qty' => round($acc['total_book_qty'], 4),
                    'total_fifo_qty' => round($acc['total_fifo_qty'], 4),
                    'total_unbatched_qty' => round($acc['total_unbatched_qty'], 4),
                    'in_transit_qty' => round($acc['in_transit_qty'], 4),
                    'in_transit_valuation' => round($acc['in_transit_valuation'], 2),
                    'total_inventory_valuation_with_in_transit' => round($acc['effective_valuation'] + $acc['in_transit_valuation'], 2),
                    'master_valuation' => round($acc['master_valuation'], 2),
                    'fifo_batch_valuation' => round($acc['fifo_batch_valuation'], 2),
                    'effective_valuation' => round($acc['effective_valuation'], 2),
                    'retail_valuation' => round($acc['retail_valuation'], 2),
                    'valuation_variance' => round($acc['fifo_batch_valuation'] - $acc['master_valuation'], 2),
                ];
            }
        }

        $fifoBase = DB::table('inventory_batches as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->whereNull('p.deleted_at')
            ->where('ib.status', 'active')
            ->where('ib.remaining_quantity', '>', 0.0001);
        if ($warehouseId !== null) {
            $fifoBase->where('ib.warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $fifoBase->whereIn('ib.warehouse_id', $allowedWarehouseIds);
        }
        if ($productIds !== null) {
            $fifoBase->whereIn('ib.product_id', $productIds);
        } elseif ($productIdsSubQuery !== null) {
            $fifoBase->whereIn('ib.product_id', clone $productIdsSubQuery);
        }

        $fifoTotals = (clone $fifoBase)->selectRaw('
            COALESCE(SUM(ib.remaining_quantity), 0) as fifo_qty,
            COALESCE(SUM(ib.remaining_quantity * ib.unit_cost), 0) as fifo_value
        ')->first();

        $totalFifoQty = (float)($fifoTotals->fifo_qty ?? 0.0);
        $fifoBatchValuation = (float)($fifoTotals->fifo_value ?? 0.0);

        $sbGrouped = DB::table('stock_balances')
            ->select([
                'product_id',
                DB::raw('COALESCE(product_variant_id, 0) as variant_key'),
                DB::raw('SUM(quantity) as book_qty'),
            ])
            ->groupBy('product_id', 'product_variant_id');
        if ($warehouseId !== null) {
            $sbGrouped->where('warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $sbGrouped->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        if ($productIds !== null) {
            $sbGrouped->whereIn('product_id', $productIds);
        } elseif ($productIdsSubQuery !== null) {
            $sbGrouped->whereIn('product_id', clone $productIdsSubQuery);
        }

        $fifoSkuSub = DB::table('inventory_batches')
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0.0001)
            ->select([
                'product_id',
                DB::raw('COALESCE(product_variant_id, 0) as variant_key'),
                DB::raw('SUM(remaining_quantity) as fifo_qty'),
            ])
            ->groupBy('product_id', 'product_variant_id');
        if ($warehouseId !== null) {
            $fifoSkuSub->where('warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $fifoSkuSub->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        if ($productIds !== null) {
            $fifoSkuSub->whereIn('product_id', $productIds);
        } elseif ($productIdsSubQuery !== null) {
            $fifoSkuSub->whereIn('product_id', clone $productIdsSubQuery);
        }

        $hasVariantsTable = $this->cachedHasTable('product_variants');

        // When warehouseId is fixed, (warehouse_id, product_id, variant_key) is unique in stock_balances
        // so direct table scan avoids derived-table grouping overhead completely.
        if ($warehouseId !== null) {
            $balJoin = DB::table('stock_balances as sb')
                ->join('products as p', 'p.id', '=', 'sb.product_id')
                ->where('sb.warehouse_id', $warehouseId)
                ->whereNull('p.deleted_at');
            if ($productIds !== null) {
                $balJoin->whereIn('sb.product_id', $productIds);
            } elseif ($productIdsSubQuery !== null) {
                $balJoin->whereIn('sb.product_id', clone $productIdsSubQuery);
            }
            $bookCol = 'sb.quantity';
            if ($totalFifoQty > 0.0001) {
                $balJoin->leftJoinSub($fifoSkuSub, 'fb', function ($join) {
                    $join->on('fb.product_id', '=', 'sb.product_id')
                        ->whereRaw('fb.variant_key = COALESCE(sb.product_variant_id, 0)');
                });
                $unbatchedExpr = "CASE WHEN ({$bookCol} - COALESCE(fb.fifo_qty, 0)) > 0.0001 THEN ({$bookCol} - COALESCE(fb.fifo_qty, 0)) ELSE 0 END";
            } else {
                $unbatchedExpr = "CASE WHEN {$bookCol} > 0.0001 THEN {$bookCol} ELSE 0 END";
            }
        } else {
            $balJoin = DB::query()
                ->fromSub($sbGrouped, 'sb')
                ->join('products as p', 'p.id', '=', 'sb.product_id')
                ->whereNull('p.deleted_at');
            $bookCol = 'sb.book_qty';
            if ($totalFifoQty > 0.0001) {
                $balJoin->leftJoinSub($fifoSkuSub, 'fb', function ($join) {
                    $join->on('fb.product_id', '=', 'sb.product_id')
                        ->on('fb.variant_key', '=', 'sb.variant_key');
                });
                $unbatchedExpr = "CASE WHEN ({$bookCol} - COALESCE(fb.fifo_qty, 0)) > 0.0001 THEN ({$bookCol} - COALESCE(fb.fifo_qty, 0)) ELSE 0 END";
            } else {
                $unbatchedExpr = "CASE WHEN {$bookCol} > 0.0001 THEN {$bookCol} ELSE 0 END";
            }
        }

        $costExpr = 'COALESCE(NULLIF(p.cost_price, 0), NULLIF(p.purchase_price, 0), 0)';
        $retailExpr = 'COALESCE(NULLIF(p.retail_price, 0), NULLIF(p.selling_price, 0), 0)';
        if ($hasVariantsTable) {
            if ($warehouseId !== null) {
                $balJoin->leftJoin('product_variants as pv', 'pv.id', '=', 'sb.product_variant_id');
            } else {
                $balJoin->leftJoin('product_variants as pv', 'pv.id', '=', 'sb.variant_key');
            }
            $varCols = $this->cachedColumnMap('product_variants');
            $vCost = isset($varCols['cost_price']) ? 'NULLIF(pv.cost_price, 0), ' : '';
            $vPurch = isset($varCols['purchase_price']) ? 'NULLIF(pv.purchase_price, 0), ' : '';
            $vSell = isset($varCols['selling_price']) ? 'NULLIF(pv.selling_price, 0), ' : '';
            $costExpr = "COALESCE({$vCost}{$vPurch}NULLIF(p.cost_price, 0), NULLIF(p.purchase_price, 0), 0)";
            $retailExpr = "COALESCE({$vSell}NULLIF(p.retail_price, 0), NULLIF(p.selling_price, 0), 0)";
        }

        $sbTotals = $balJoin->selectRaw("
            COUNT(DISTINCT p.id) as sb_prod_count,
            COALESCE(SUM({$bookCol}), 0) as total_book_qty,
            COALESCE(SUM({$unbatchedExpr}), 0) as total_unbatched_qty,
            COALESCE(SUM({$bookCol} * ({$costExpr})), 0) as master_valuation,
            COALESCE(SUM(({$unbatchedExpr}) * ({$costExpr})), 0) as unbatched_valuation,
            COALESCE(SUM({$bookCol} * ({$retailExpr})), 0) as retail_valuation
        ")->first();

        $sbProdCount = (int)($sbTotals->sb_prod_count ?? 0);
        $totalBookQty = (float)($sbTotals->total_book_qty ?? 0.0);
        $totalUnbatchedQty = (float)($sbTotals->total_unbatched_qty ?? 0.0);
        $masterValuation = (float)($sbTotals->master_valuation ?? 0.0);
        $unbatchedValuation = (float)($sbTotals->unbatched_valuation ?? 0.0);
        $retailValuation = (float)($sbTotals->retail_valuation ?? 0.0);
        $effectiveValuation = $fifoBatchValuation + $unbatchedValuation;

        // Legacy fallback ONLY for products with zero stock_balances rows anywhere
        $expectedProdCount = $productIds !== null ? count($productIds) : $knownActiveProductCount;
        $skipLegacyFallback = ($expectedProdCount !== null && $sbProdCount >= $expectedProdCount);
        if ($warehouseId === null && empty($allowedWarehouseIds) && !$skipLegacyFallback) {
            $legacyQ = DB::table('products as p')
                ->whereNull('p.deleted_at')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('stock_balances as sb')
                        ->whereColumn('sb.product_id', 'p.id');
                });
            if ($productIds !== null) {
                $legacyQ->whereIn('p.id', $productIds);
            }
            $legacyIds = $legacyQ->pluck('p.id')->all();
            if (!empty($legacyIds)) {
                $legacyVal = $this->computeValuation(null, $legacyIds, null);
                $totalBookQty += (float)$legacyVal['total_book_qty'];
                $totalUnbatchedQty += (float)$legacyVal['total_unbatched_qty'];
                $masterValuation += (float)$legacyVal['master_valuation'];
                $retailValuation += (float)$legacyVal['retail_valuation'];
                // Exclude legacy batch part already counted in $fifoBatchValuation
                $effectiveValuation += ((float)$legacyVal['effective_valuation'] - (float)$legacyVal['fifo_batch_valuation']);
            }
        }

        $inTransitQty = 0.0;
        $inTransitValuation = 0.0;
        if ($includeInTransit && $this->cachedHasTable('stock_transfers') && $this->cachedHasTable('stock_transfer_items')) {
            $inTransitQ = DB::table('stock_transfer_items')
                ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                ->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id')
                ->where('stock_transfers.status', 'Sent');
            if ($warehouseId !== null) {
                $inTransitQ->where(function ($q) use ($warehouseId) {
                    $q->where('stock_transfers.from_warehouse_id', $warehouseId)
                      ->orWhere('stock_transfers.to_warehouse_id', $warehouseId);
                });
            } elseif (!empty($allowedWarehouseIds)) {
                $inTransitQ->where(function ($q) use ($allowedWarehouseIds) {
                    $q->whereIn('stock_transfers.from_warehouse_id', $allowedWarehouseIds)
                      ->orWhereIn('stock_transfers.to_warehouse_id', $allowedWarehouseIds);
                });
            }
            if ($productIds !== null) {
                $inTransitQ->whereIn('stock_transfer_items.product_id', $productIds);
            }
            $trfAgg = $inTransitQ->selectRaw("
                COALESCE(SUM(COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))), 0) as qty,
                COALESCE(SUM(CASE
                    WHEN COALESCE(stock_transfer_items.subtotal, 0) > 0 THEN stock_transfer_items.subtotal
                    ELSE COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))
                        * COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), NULLIF(stock_transfer_items.unit_cost, 0), COALESCE(products.cost_price, 0))
                END), 0) as val
            ")->first();
            $inTransitQty = (float)($trfAgg->qty ?? 0.0);
            $inTransitValuation = (float)($trfAgg->val ?? 0.0);
        }

        return [
            'total_book_qty' => round($totalBookQty, 4),
            'total_fifo_qty' => round($totalFifoQty, 4),
            'total_unbatched_qty' => round($totalUnbatchedQty, 4),
            'in_transit_qty' => round($inTransitQty, 4),
            'in_transit_valuation' => round($inTransitValuation, 2),
            'total_inventory_valuation_with_in_transit' => round($effectiveValuation + $inTransitValuation, 2),
            'master_valuation' => round($masterValuation, 2),
            'fifo_batch_valuation' => round($fifoBatchValuation, 2),
            'effective_valuation' => round($effectiveValuation, 2),
            'retail_valuation' => round($retailValuation, 2),
            'valuation_variance' => round($fifoBatchValuation - $masterValuation, 2),
        ];
    }

    /**
     * Authoritative inventory valuation anchored on active FIFO inventory_batches,
     * falling back to master cost_price only for positive unbatched legacy quantities.
     */
    public function computeValuation(?int $warehouseId = null, ?array $productIds = null, ?array $allowedWarehouseIds = null): array
    {
        $productsQuery = DB::table('products')->whereNull('deleted_at')->select([
            'id',
            'code',
            'name',
            'stock_quantity',
            'cost_price',
            'purchase_price',
            'retail_price',
            'selling_price',
            'has_variants',
            'alert_quantity',
        ]);
        if ($productIds !== null) {
            if (empty($productIds)) {
                return [
                    'total_book_qty' => 0.0,
                    'total_fifo_qty' => 0.0,
                    'total_unbatched_qty' => 0.0,
                    'in_transit_qty' => 0.0,
                    'in_transit_valuation' => 0.0,
                    'total_inventory_valuation_with_in_transit' => 0.0,
                    'master_valuation' => 0.0,
                    'fifo_batch_valuation' => 0.0,
                    'effective_valuation' => 0.0,
                    'retail_valuation' => 0.0,
                    'valuation_variance' => 0.0,
                    'by_product' => [],
                    'by_sku' => [],
                    'sku_discrepancies' => [],
                ];
            }
            $productsQuery->whereIn('id', $productIds);
        }
        $products = $productsQuery->get()->keyBy('id');

        // Select only columns that exist in the current database schema (stock_quantity / purchase_price are optional legacy columns).
        $variants = collect();
        if (Schema::hasTable('product_variants')) {
            $variantColumns = array_flip(Schema::getColumnListing('product_variants'));
            $desiredVariantColumns = [
                'id',
                'product_id',
                'name',
                'sku',
                'cost_price',
                'selling_price',
                'stock_quantity',
                'purchase_price',
            ];
            $selectVariantColumns = array_values(array_filter(
                $desiredVariantColumns,
                static fn (string $column): bool => isset($variantColumns[$column])
            ));

            if (!empty($selectVariantColumns)) {
                $variantsQuery = DB::table('product_variants')->select($selectVariantColumns);
                if (isset($variantColumns['deleted_at'])) {
                    $variantsQuery->whereNull('deleted_at');
                }
                if ($productIds !== null) {
                    $variantsQuery->whereIn('product_id', $productIds);
                }
                $variants = $variantsQuery->get();
            }
        }
        $variantsById = $variants->keyBy('id');
        $variantsByProduct = $variants->groupBy('product_id');

        // Which products use per-warehouse stock_balances vs legacy product-level stock_quantity
        $balanceProductIdQuery = DB::table('stock_balances')->distinct();
        if ($productIds !== null) {
            $balanceProductIdQuery->whereIn('product_id', $productIds);
        }
        $productsWithAnyBalance = array_flip(
            $balanceProductIdQuery->pluck('product_id')->map(fn ($id) => (int)$id)->all()
        );

        // Book quantities keyed by "product_id|variant_id" (variant_id = 0 when base/non-variant)
        $bookBySku = [];

        $balQuery = DB::table('stock_balances')
            ->select([
                'product_id',
                DB::raw('COALESCE(product_variant_id, 0) as variant_key'),
                DB::raw('SUM(quantity) as qty'),
            ])
            ->groupBy('product_id', DB::raw('COALESCE(product_variant_id, 0)'));
        if ($warehouseId !== null) {
            $balQuery->where('warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $balQuery->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        if ($productIds !== null) {
            $balQuery->whereIn('product_id', $productIds);
        }
        foreach ($balQuery->get() as $row) {
            $pid = (int)$row->product_id;
            $vid = (int)$row->variant_key;
            $bookBySku["{$pid}|{$vid}"] = (float)$row->qty;
        }

        // Legacy fallback when no stock_balances rows exist at all for a product
        if ($warehouseId === null && empty($allowedWarehouseIds)) {
            foreach ($products as $pid => $prod) {
                if (isset($productsWithAnyBalance[(int)$pid])) {
                    continue;
                }
                $prodVars = $variantsByProduct->get($pid);
                if ($prod->has_variants && $prodVars && $prodVars->isNotEmpty()) {
                    foreach ($prodVars as $v) {
                        $bookBySku["{$pid}|{$v->id}"] = (float)(isset($v->stock_quantity) ? $v->stock_quantity : 0.0);
                    }
                } else {
                    $bookBySku["{$pid}|0"] = (float)($prod->stock_quantity ?? 0.0);
                }
            }
        }

        // FIFO batch subledger sums keyed by "product_id|variant_id"
        $batchQuery = DB::table('inventory_batches')
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0.0001)
            ->select([
                'product_id',
                DB::raw('COALESCE(product_variant_id, 0) as variant_key'),
                DB::raw('SUM(remaining_quantity) as fifo_qty'),
                DB::raw('SUM(remaining_quantity * unit_cost) as fifo_value'),
            ])
            ->groupBy('product_id', DB::raw('COALESCE(product_variant_id, 0)'));
        if ($warehouseId !== null) {
            $batchQuery->where('warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $batchQuery->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        if ($productIds !== null) {
            $batchQuery->whereIn('product_id', $productIds);
        }

        $fifoBySku = [];
        foreach ($batchQuery->get() as $b) {
            $pid = (int)$b->product_id;
            $vid = (int)$b->variant_key;
            $fifoBySku["{$pid}|{$vid}"] = [
                'fifo_qty' => (float)$b->fifo_qty,
                'fifo_value' => (float)$b->fifo_value,
            ];
        }

        $allKeys = array_unique(array_merge(array_keys($bookBySku), array_keys($fifoBySku)));
        $totalBookQty = 0.0;
        $totalFifoQty = 0.0;
        $totalUnbatchedQty = 0.0;
        $masterValuation = 0.0;
        $fifoBatchValuation = 0.0;
        $effectiveValuation = 0.0;
        $retailValuation = 0.0;
        $byProduct = [];
        $bySku = [];
        $skuDiscrepancies = [];

        foreach ($allKeys as $key) {
            [$pidStr, $vidStr] = explode('|', $key);
            $pid = (int)$pidStr;
            $vid = (int)$vidStr;
            $prod = $products->get($pid);
            if (!$prod) {
                continue;
            }
            $var = $vid > 0 ? $variantsById->get($vid) : null;
            $varCost = $var && isset($var->cost_price) ? (float)$var->cost_price : 0.0;
            $varPurchase = $var && isset($var->purchase_price) ? (float)$var->purchase_price : 0.0;
            $varSelling = $var && isset($var->selling_price) ? (float)$var->selling_price : 0.0;

            $unitMasterCost = (float)($varCost ?: ($varPurchase ?: ($prod->cost_price ?: ($prod->purchase_price ?: 0.0))));
            $unitRetailPrice = (float)($varSelling ?: ($prod->retail_price ?: ($prod->selling_price ?: 0.0)));

            $bookQty = (float)($bookBySku[$key] ?? 0.0);
            $fifoQty = (float)($fifoBySku[$key]['fifo_qty'] ?? 0.0);
            $fifoVal = (float)($fifoBySku[$key]['fifo_value'] ?? 0.0);

            $qtyDiff = round($bookQty - $fifoQty, 4);
            $unbatchedQty = $qtyDiff > 0.0001 ? $qtyDiff : 0.0;
            $skuMasterVal = $bookQty * $unitMasterCost;
            // FIFO batches already present value those exact units at their true FIFO unit_cost;
            // positive unbatched stock falls back conservatively to master cost.
            $skuEffectiveVal = $fifoVal + ($unbatchedQty * $unitMasterCost);
            $skuRetailVal = $bookQty * $unitRetailPrice;

            $totalBookQty += $bookQty;
            $totalFifoQty += $fifoQty;
            $totalUnbatchedQty += $unbatchedQty;
            $masterValuation += $skuMasterVal;
            $fifoBatchValuation += $fifoVal;
            $effectiveValuation += $skuEffectiveVal;
            $retailValuation += $skuRetailVal;

            if (!isset($byProduct[$pid])) {
                $byProduct[$pid] = [
                    'product_id' => $pid,
                    'book_qty' => 0.0,
                    'fifo_qty' => 0.0,
                    'master_valuation' => 0.0,
                    'fifo_valuation' => 0.0,
                    'effective_valuation' => 0.0,
                    'retail_valuation' => 0.0,
                ];
            }
            $byProduct[$pid]['book_qty'] += $bookQty;
            $byProduct[$pid]['fifo_qty'] += $fifoQty;
            $byProduct[$pid]['master_valuation'] += $skuMasterVal;
            $byProduct[$pid]['fifo_valuation'] += $fifoVal;
            $byProduct[$pid]['effective_valuation'] += $skuEffectiveVal;
            $byProduct[$pid]['retail_valuation'] += $skuRetailVal;

            $bySku[$key] = [
                'product_id' => $pid,
                'product_variant_id' => $vid > 0 ? $vid : null,
                'book_qty' => round($bookQty, 4),
                'fifo_qty' => round($fifoQty, 4),
                'master_valuation' => round($skuMasterVal, 2),
                'fifo_valuation' => round($fifoVal, 2),
                'effective_valuation' => round($skuEffectiveVal, 2),
                'retail_valuation' => round($skuRetailVal, 2),
            ];

            if (abs($qtyDiff) > 0.0001 || abs($fifoVal - $skuMasterVal) > 0.01) {
                $skuDiscrepancies[] = [
                    'product_id' => $pid,
                    'product_variant_id' => $vid > 0 ? $vid : null,
                    'product_code' => (string)($var?->sku ?: ($prod->code ?? '')),
                    'product_name' => (string)$prod->name,
                    'variant_name' => $var ? (string)$var->name : null,
                    'book_qty' => round($bookQty, 4),
                    'fifo_qty' => round($fifoQty, 4),
                    'qty_variance' => $qtyDiff,
                    'master_unit_cost' => round($unitMasterCost, 4),
                    'master_valuation' => round($skuMasterVal, 2),
                    'fifo_valuation' => round($fifoVal, 2),
                    'effective_valuation' => round($skuEffectiveVal, 2),
                    'valuation_variance' => round($fifoVal - $skuMasterVal, 2),
                    'status' => abs($qtyDiff) > 0.0001
                        ? ($qtyDiff > 0 ? 'unbatched_stock' : 'excess_fifo_batch')
                        : 'cost_layer_variance',
                ];
            }
        }

        // In-transit stock ('Sent' transfers depleted from source warehouse, not yet received at destination)
        $inTransitQty = 0.0;
        $inTransitValuation = 0.0;
        if (Schema::hasTable('stock_transfers') && Schema::hasTable('stock_transfer_items')) {
            $inTransitQ = DB::table('stock_transfer_items')
                ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                ->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id')
                ->where('stock_transfers.status', 'Sent');
            if ($warehouseId !== null) {
                $inTransitQ->where(function ($q) use ($warehouseId) {
                    $q->where('stock_transfers.from_warehouse_id', $warehouseId)
                      ->orWhere('stock_transfers.to_warehouse_id', $warehouseId);
                });
            } elseif (!empty($allowedWarehouseIds)) {
                $inTransitQ->where(function ($q) use ($allowedWarehouseIds) {
                    $q->whereIn('stock_transfers.from_warehouse_id', $allowedWarehouseIds)
                      ->orWhereIn('stock_transfers.to_warehouse_id', $allowedWarehouseIds);
                });
            }
            if ($productIds !== null) {
                $inTransitQ->whereIn('stock_transfer_items.product_id', $productIds);
            }
            $trfAgg = $inTransitQ->selectRaw("
                COALESCE(SUM(COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))), 0) as qty,
                COALESCE(SUM(CASE
                    WHEN COALESCE(stock_transfer_items.subtotal, 0) > 0 THEN stock_transfer_items.subtotal
                    ELSE COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))
                        * COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), NULLIF(stock_transfer_items.unit_cost, 0), COALESCE(products.cost_price, 0))
                END), 0) as val
            ")->first();
            $inTransitQty = (float)($trfAgg->qty ?? 0.0);
            $inTransitValuation = (float)($trfAgg->val ?? 0.0);
        }

        return [
            'total_book_qty' => round($totalBookQty, 4),
            'total_fifo_qty' => round($totalFifoQty, 4),
            'total_unbatched_qty' => round($totalUnbatchedQty, 4),
            'in_transit_qty' => round($inTransitQty, 4),
            'in_transit_valuation' => round($inTransitValuation, 2),
            'total_inventory_valuation_with_in_transit' => round($effectiveValuation + $inTransitValuation, 2),
            'master_valuation' => round($masterValuation, 2),
            'fifo_batch_valuation' => round($fifoBatchValuation, 2),
            'effective_valuation' => round($effectiveValuation, 2),
            'retail_valuation' => round($retailValuation, 2),
            'valuation_variance' => round($fifoBatchValuation - $masterValuation, 2),
            'by_product' => $byProduct,
            'by_sku' => $bySku,
            'sku_discrepancies' => $skuDiscrepancies,
        ];
    }

    /**
     * Full Senior Accounting Audit payload comparing StockBalances, Products,
     * FIFO InventoryBatches, AdjustmentItemCostLayers, and P&L vs Non-P&L Adjustments.
     */
    public function audit(?int $warehouseId = null, ?array $allowedWarehouseIds = null, int $perPage = 50): array
    {
        $valuation = $this->computeValuation($warehouseId, null, $allowedWarehouseIds);
        $safePerPage = max(1, min($perPage, 200));

        // 1. Master Stock vs Sum of StockBalances drift check pushed to SQL (global stock check when unrestricted by warehouse)
        $masterVsBalanceDrift = [];
        $sbTotalsSub = DB::table('stock_balances')
            ->select('product_id', DB::raw('SUM(quantity) as bal_qty'))
            ->groupBy('product_id');
        if ($warehouseId === null && empty($allowedWarehouseIds)) {
            $driftRows = DB::table('products as p')
                ->joinSub($sbTotalsSub, 'sb', 'sb.product_id', '=', 'p.id')
                ->whereNull('p.deleted_at')
                ->select([
                    'p.id as product_id',
                    'p.code as product_code',
                    'p.name as product_name',
                    'p.stock_quantity as master_stock_qty',
                    'sb.bal_qty as warehouse_balances_sum',
                ])
                ->whereRaw('ABS(p.stock_quantity - sb.bal_qty) > 0.0001')
                ->orderByDesc(DB::raw('ABS(p.stock_quantity - sb.bal_qty)'))
                ->limit($safePerPage)
                ->get();
            foreach ($driftRows as $dr) {
                $masterQty = (float)$dr->master_stock_qty;
                $balQty = (float)$dr->warehouse_balances_sum;
                $masterVsBalanceDrift[] = [
                    'product_id' => (int)$dr->product_id,
                    'product_code' => (string)$dr->product_code,
                    'product_name' => (string)$dr->product_name,
                    'master_stock_qty' => round($masterQty, 4),
                    'warehouse_balances_sum' => round($balQty, 4),
                    'drift' => round($masterQty - $balQty, 4),
                ];
            }
        }

        // 2. Adjustment Subtraction Cost Layer Verification
        $subItemTypeSql = "LOWER(COALESCE(NULLIF(TRIM(sai.type), ''), NULLIF(TRIM(sa.type), ''), 'addition'))";
        $subItemsQuery = DB::table('stock_adjustment_items as sai')
            ->join('stock_adjustments as sa', 'sa.id', '=', 'sai.stock_adjustment_id')
            ->leftJoin(
                DB::raw('(SELECT stock_adjustment_item_id, COUNT(*) as layer_count, COALESCE(SUM(total_cost), 0) as layer_total_cost, COALESCE(SUM(base_quantity), 0) as layer_qty FROM adjustment_item_cost_layers GROUP BY stock_adjustment_item_id) as acl'),
                'acl.stock_adjustment_item_id',
                '=',
                'sai.id'
            )
            ->whereRaw("{$subItemTypeSql} = 'subtraction'");

        if ($warehouseId !== null) {
            $subItemsQuery->where('sa.warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $subItemsQuery->whereIn('sa.warehouse_id', $allowedWarehouseIds);
        }

        $missingLayerCount = 0;
        $layerCostMismatchCount = 0;
        $verifiedLayerCount = 0;
        $layerAlerts = [];

        foreach ($subItemsQuery->select([
            'sai.id',
            'sai.stock_adjustment_id',
            'sa.reference_no',
            'sa.date',
            'sai.product_code',
            'sai.product_name',
            'sai.base_quantity',
            'sai.quantity',
            'sai.conversion_rate',
            'sai.subtotal',
            DB::raw('COALESCE(acl.layer_count, 0) as layer_count'),
            DB::raw('COALESCE(acl.layer_total_cost, 0) as layer_total_cost'),
            DB::raw('COALESCE(acl.layer_qty, 0) as layer_qty'),
        ])->get() as $row) {
            if ((int)$row->layer_count === 0) {
                $missingLayerCount++;
                $layerAlerts[] = [
                    'reference_no' => (string)$row->reference_no,
                    'date' => (string)$row->date,
                    'product_code' => (string)$row->product_code,
                    'product_name' => (string)$row->product_name,
                    'issue' => 'missing_cost_layer_legacy',
                    'item_subtotal' => round((float)$row->subtotal, 2),
                    'layer_total' => 0.0,
                ];
            } else {
                $conv = (float)($row->conversion_rate ?? 1.0);
                $expectedBaseQty = (float)$row->base_quantity > 0.0001
                    ? (float)$row->base_quantity
                    : ((float)$row->quantity * ($conv > 0 ? $conv : 1.0));
                $qtyDiff = abs($expectedBaseQty - (float)$row->layer_qty);
                $diff = abs((float)$row->subtotal - (float)$row->layer_total_cost);
                if ($qtyDiff > 0.0001) {
                    $layerCostMismatchCount++;
                    $layerAlerts[] = [
                        'reference_no' => (string)$row->reference_no,
                        'date' => (string)$row->date,
                        'product_code' => (string)$row->product_code,
                        'product_name' => (string)$row->product_name,
                        'issue' => 'cost_layer_qty_mismatch',
                        'expected_base_qty' => round($expectedBaseQty, 4),
                        'layer_qty' => round((float)$row->layer_qty, 4),
                        'item_subtotal' => round((float)$row->subtotal, 2),
                        'layer_total' => round((float)$row->layer_total_cost, 2),
                    ];
                } elseif ($diff > 0.05) {
                    $layerCostMismatchCount++;
                    $layerAlerts[] = [
                        'reference_no' => (string)$row->reference_no,
                        'date' => (string)$row->date,
                        'product_code' => (string)$row->product_code,
                        'product_name' => (string)$row->product_name,
                        'issue' => 'cost_layer_total_mismatch',
                        'item_subtotal' => round((float)$row->subtotal, 2),
                        'layer_total' => round((float)$row->layer_total_cost, 2),
                    ];
                } else {
                    $verifiedLayerCount++;
                }
            }
        }

        // 3. Batch Integrity Anomalies (negative or remaining > initial)
        $batchAnomQuery = DB::table('inventory_batches')
            ->where(function ($q) {
                $q->where('remaining_quantity', '<', -0.0001)
                  ->orWhereColumn('remaining_quantity', '>', DB::raw('initial_quantity + 0.0001'));
            });
        if ($warehouseId !== null) {
            $batchAnomQuery->where('warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $batchAnomQuery->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        $batchAnomaliesCount = $batchAnomQuery->count();

        // 4. Adjustment Accounting P&L vs Non-P&L Breakdown
        $effTypeSql = "LOWER(COALESCE(NULLIF(TRIM(sai.type), ''), NULLIF(TRIM(sa.type), ''), 'addition'))";
        $itemValSql = "ABS(COALESCE(NULLIF(sai.subtotal, 0), sai.quantity * COALESCE(sai.unit_cost, 0), 0))";
        $nonPnlLower = AdjustmentReasonCatalog::nonPnlReasonCodesLower();
        $placeholders = implode(', ', array_fill(0, count($nonPnlLower), '?'));
        $isOperatingSql = "(sa.affects_pnl = 1 OR (sa.affects_pnl IS NULL AND LOWER(TRIM(COALESCE(sa.reason_code, ''))) NOT IN ({$placeholders})))";

        $adjBreakdownQuery = DB::table('stock_adjustment_items as sai')
            ->join('stock_adjustments as sa', 'sa.id', '=', 'sai.stock_adjustment_id');
        if ($warehouseId !== null) {
            $adjBreakdownQuery->where('sa.warehouse_id', $warehouseId);
        } elseif (!empty($allowedWarehouseIds)) {
            $adjBreakdownQuery->whereIn('sa.warehouse_id', $allowedWarehouseIds);
        }

        $adjRow = $adjBreakdownQuery->selectRaw("
            COALESCE(SUM(CASE WHEN {$effTypeSql} = 'subtraction' AND {$isOperatingSql} THEN {$itemValSql} ELSE 0 END), 0) as operating_loss,
            COALESCE(SUM(CASE WHEN {$effTypeSql} = 'addition' AND {$isOperatingSql} THEN {$itemValSql} ELSE 0 END), 0) as operating_gain,
            COALESCE(SUM(CASE WHEN {$effTypeSql} = 'addition' AND NOT {$isOperatingSql} THEN {$itemValSql} ELSE 0 END), 0) as non_pnl_additions,
            COALESCE(SUM(CASE WHEN {$effTypeSql} = 'subtraction' AND NOT {$isOperatingSql} THEN {$itemValSql} ELSE 0 END), 0) as non_pnl_subtractions
        ", array_merge($nonPnlLower, $nonPnlLower, $nonPnlLower, $nonPnlLower))->first();

        $operatingLoss = round((float)($adjRow->operating_loss ?? 0.0), 2);
        $operatingGain = round((float)($adjRow->operating_gain ?? 0.0), 2);
        $nonPnlAdditions = round((float)($adjRow->non_pnl_additions ?? 0.0), 2);
        $nonPnlSubtractions = round((float)($adjRow->non_pnl_subtractions ?? 0.0), 2);

        $skuDiscrepancies = $valuation['sku_discrepancies'];
        $qtyDiscrepancyCount = count(array_filter($skuDiscrepancies, fn ($d) => abs($d['qty_variance']) > 0.0001));

        $isBalanced = ($qtyDiscrepancyCount === 0)
            && empty($masterVsBalanceDrift)
            && ($layerCostMismatchCount === 0)
            && ($batchAnomaliesCount === 0);

        return [
            'status_health' => $isBalanced ? 'reconciled' : 'discrepancies_found',
            'is_balanced' => $isBalanced,
            'warehouse_id' => $warehouseId,
            'valuation_summary' => [
                'total_book_qty' => $valuation['total_book_qty'],
                'total_fifo_qty' => $valuation['total_fifo_qty'],
                'total_unbatched_qty' => $valuation['total_unbatched_qty'],
                'in_transit_qty' => $valuation['in_transit_qty'] ?? 0.0,
                'in_transit_valuation' => $valuation['in_transit_valuation'] ?? 0.0,
                'total_inventory_valuation_with_in_transit' => $valuation['total_inventory_valuation_with_in_transit'] ?? $valuation['effective_valuation'],
                'master_valuation' => $valuation['master_valuation'],
                'fifo_batch_valuation' => $valuation['fifo_batch_valuation'],
                'effective_valuation' => $valuation['effective_valuation'],
                'retail_valuation' => $valuation['retail_valuation'],
                'valuation_variance' => $valuation['valuation_variance'],
            ],
            'adjustment_accounting_summary' => [
                'operating_pnl_loss' => $operatingLoss,
                'operating_pnl_gain' => $operatingGain,
                'net_pnl_impact' => round($operatingGain - $operatingLoss, 2),
                'non_pnl_additions' => $nonPnlAdditions,
                'non_pnl_subtractions' => $nonPnlSubtractions,
                'net_non_pnl_capital' => round($nonPnlAdditions - $nonPnlSubtractions, 2),
            ],
            'audit_checks' => [
                'sku_qty_discrepancy_count' => $qtyDiscrepancyCount,
                'master_vs_balance_drift_count' => count($masterVsBalanceDrift),
                'verified_subtraction_layers_count' => $verifiedLayerCount,
                'missing_legacy_layers_count' => $missingLayerCount,
                'cost_layer_mismatch_count' => $layerCostMismatchCount,
                'batch_anomalies_count' => $batchAnomaliesCount,
            ],
            'sku_discrepancies' => array_slice($skuDiscrepancies, 0, 100),
            'master_vs_balance_drift' => array_slice($masterVsBalanceDrift, 0, 50),
            'layer_alerts' => array_slice($layerAlerts, 0, 50),
        ];
    }
}
