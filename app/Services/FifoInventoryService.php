<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\SaleItemCostLayer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FifoInventoryService
{
    /**
     * Record a new inventory batch into the FIFO ledger
     */
    public function recordBatch(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQuantity,
        float $baseUnitCost,
        string $sourceType = 'purchase',
        ?string $sourceReference = null,
        ?string $receivedDate = null,
        ?int $purchaseItemId = null,
        ?int $createdBy = null,
        ?string $notes = null
    ): InventoryBatch {
        $baseQuantity = round(max(0.0, $baseQuantity), 4);
        $baseUnitCost = round(max(0.0, $baseUnitCost), 4);
        $rxDate = $receivedDate ? Carbon::parse($receivedDate) : now();

        $batchNumber = 'LOT-' . $rxDate->format('Ymd') . '-' . $productId . ($variantId ? "-V{$variantId}" : '') . '-' . rand(100, 999);

        return InventoryBatch::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_item_id' => $purchaseItemId,
            'batch_number' => $batchNumber,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'received_date' => $rxDate,
            'unit_cost' => $baseUnitCost,
            'initial_quantity' => $baseQuantity,
            'remaining_quantity' => $baseQuantity,
            'status' => $baseQuantity > 0 ? 'active' : 'exhausted',
            'created_by' => $createdBy ?? auth()->id(),
            'notes' => $notes,
        ]);
    }

    /**
     * Deplete stock on a First-In, First-Out (FIFO) basis and compute exact COGS
     */
    public function depleteFifo(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $neededBaseQty,
        int $saleId,
        int|callable $saleItemId,
        ?string $saleDate = null,
        float $fallbackCost = 0.0
    ): array {
        $neededBaseQty = round(max(0.0, $neededBaseQty), 4);
        if ($neededBaseQty <= 0) {
            if (is_callable($saleItemId)) {
                $saleItemId(0.0, 0.0);
            }
            return [
                'total_cogs' => 0.0,
                'avg_unit_cost' => 0.0,
                'layers' => [],
                'exhausted_fully' => true,
            ];
        }

        $txDate = $saleDate ? Carbon::parse($saleDate) : now();
        $remainingNeeded = $neededBaseQty;
        $totalCogs = 0.0;
        $layers = [];
        $pendingLayers = [];

        // Query active batches ordered by received_date ASC, id ASC (True FIFO)
        $batchQuery = InventoryBatch::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0.0001)
            ->where('status', 'active');

        if ($variantId !== null && $variantId > 0) {
            $batchQuery->where('product_variant_id', $variantId);
        } else {
            $batchQuery->whereNull('product_variant_id');
        }

        $batches = $batchQuery->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingNeeded <= 0.0001) {
                break;
            }

            $avail = (float)$batch->remaining_quantity;
            $takeQty = min($avail, $remainingNeeded);
            $batchCost = (float)$batch->unit_cost;
            $layerCost = round($takeQty * $batchCost, 4);

            $pendingLayers[] = [
                'inventory_batch_id' => $batch->id,
                'base_quantity' => $takeQty,
                'unit_cost' => $batchCost,
                'total_cost' => $layerCost,
                'batch' => $batch,
                'take_qty' => $takeQty,
                'avail' => $avail,
            ];

            $totalCogs += $layerCost;
            $remainingNeeded = round($remainingNeeded - $takeQty, 4);
        }

        // If batches did not satisfy all needed quantity (e.g. unallocated opening or negative stock)
        if ($remainingNeeded > 0.0001) {
            $unitCost = $fallbackCost > 0 ? $fallbackCost : 0.0;
            $unallocatedCost = round($remainingNeeded * $unitCost, 4);

            $pendingLayers[] = [
                'inventory_batch_id' => null,
                'base_quantity' => $remainingNeeded,
                'unit_cost' => $unitCost,
                'total_cost' => $unallocatedCost,
                'batch' => null,
                'take_qty' => 0,
                'avail' => 0,
            ];

            $totalCogs += $unallocatedCost;
        }

        $totalCogs = round($totalCogs, 2);
        $avgUnitCost = $neededBaseQty > 0 ? round($totalCogs / $neededBaseQty, 4) : 0.0;

        // If $saleItemId is a callback, execute it now to create/return the SaleItem with final COGS
        $resolvedSaleItemId = is_callable($saleItemId)
            ? (int)$saleItemId($avgUnitCost, $totalCogs)
            : (int)$saleItemId;

        foreach ($pendingLayers as $pLayer) {
            if ($pLayer['batch']) {
                $batch = $pLayer['batch'];
                $newRemaining = max(0.0, round($pLayer['avail'] - $pLayer['take_qty'], 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->save();
            }

            $layer = SaleItemCostLayer::create([
                'sale_id' => $saleId,
                'sale_item_id' => $resolvedSaleItemId,
                'inventory_batch_id' => $pLayer['inventory_batch_id'],
                'base_quantity' => $pLayer['base_quantity'],
                'unit_cost' => $pLayer['unit_cost'],
                'total_cost' => $pLayer['total_cost'],
                'date' => $txDate,
                'created_at' => now(),
            ]);

            $layers[] = $layer;
        }

        return [
            'total_cogs' => $totalCogs,
            'avg_unit_cost' => $avgUnitCost,
            'layers' => $layers,
            'exhausted_fully' => $remainingNeeded <= 0.0001,
        ];
    }

    /**
     * Deplete FIFO batches for stock shrinkage / damage adjustment write-offs
     */
    public function depleteForAdjustment(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQty,
        string $referenceNo,
        float $fallbackCost = 0.0
    ): float {
        $neededBaseQty = round(max(0.0, $baseQty), 4);
        if ($neededBaseQty <= 0) return 0.0;

        $batchQuery = InventoryBatch::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0.0001)
            ->where('status', 'active');

        if ($variantId !== null && $variantId > 0) {
            $batchQuery->where('product_variant_id', $variantId);
        } else {
            $batchQuery->whereNull('product_variant_id');
        }

        $batches = $batchQuery->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $remainingNeeded = $neededBaseQty;
        $totalLossCost = 0.0;

        foreach ($batches as $batch) {
            if ($remainingNeeded <= 0.0001) break;

            $avail = (float)$batch->remaining_quantity;
            $takeQty = min($avail, $remainingNeeded);
            $batchCost = (float)$batch->unit_cost;
            $totalLossCost += ($takeQty * $batchCost);
            $remainingNeeded -= $takeQty;

            $newRemaining = max(0.0, round($avail - $takeQty, 4));
            $batch->remaining_quantity = $newRemaining;
            if ($newRemaining <= 0.0001) {
                $batch->status = 'exhausted';
            }
            $batch->save();
        }

        if ($remainingNeeded > 0.0001) {
            $totalLossCost += ($remainingNeeded * $fallbackCost);
        }

        return round($totalLossCost, 2);
    }

    /**
     * Reverse a sale's FIFO depletion (for sale deletion / cancellation)
     */
    public function reverseSaleFifo(Sale $sale): void
    {
        $costLayers = SaleItemCostLayer::where('sale_id', $sale->id)->get();
        if ($costLayers->isEmpty()) {
            return;
        }

        $batchIds = $costLayers->pluck('inventory_batch_id')->filter()->unique()->values()->all();
        if (!empty($batchIds)) {
            $batches = InventoryBatch::whereIn('id', $batchIds)->lockForUpdate()->get()->keyBy('id');
            foreach ($costLayers as $layer) {
                if ($layer->inventory_batch_id && isset($batches[$layer->inventory_batch_id])) {
                    $batch = $batches[$layer->inventory_batch_id];
                    $batch->remaining_quantity = round((float)$batch->remaining_quantity + (float)$layer->base_quantity, 4);
                    $batch->status = 'active';
                }
            }
            foreach ($batches as $batch) {
                if ($batch->isDirty()) {
                    $batch->save();
                }
            }
        }

        SaleItemCostLayer::where('sale_id', $sale->id)->delete();
    }

    /**
     * Rollback FIFO batches for a stock adjustment (used during update or destroy)
     */
    public function rollbackAdjustmentFifo(StockAdjustment $adjustment): void
    {
        $adjustment->loadMissing('items');
        $warehouseId = $adjustment->warehouse_id;
        if (!$warehouseId) {
            $outlet = \App\Models\Outlet::where('name', $adjustment->warehouse_name)->first();
            $warehouseId = $outlet ? $outlet->id : 1;
        }

        foreach ($adjustment->items as $item) {
            $itemType = $item->type ?: $adjustment->type;
            $productId = (int)$item->product_id;
            $variantId = $item->product_variant_id ? (int)$item->product_variant_id : null;
            $baseQty = (float)$item->base_quantity;

            if ($itemType === 'Addition') {
                $deficit = $baseQty;

                // Find and cancel/remove the active/remaining batch created by this adjustment addition
                $batchQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where('source_type', 'adjustment_addition')
                    ->where(function ($q) use ($adjustment, $item) {
                        $q->where('source_reference', "{$adjustment->reference_no}#{$item->id}")
                          ->orWhere('source_reference', $adjustment->reference_no);
                    })
                    ->where(function ($q) {
                        $q->where('status', 'active')
                          ->orWhere('remaining_quantity', '>', 0);
                    });

                if ($variantId !== null && $variantId > 0) {
                    $batchQuery->where('product_variant_id', $variantId);
                } else {
                    $batchQuery->whereNull('product_variant_id');
                }

                $batch = $batchQuery->lockForUpdate()->first();
                if ($batch) {
                    $availInBatch = (float)$batch->remaining_quantity;
                    $deductFromBatch = min($availInBatch, $baseQty);
                    $deficit = round(max(0.0, $baseQty - $deductFromBatch), 4);
                    $batch->delete();
                }

                // If some or all of the addition batch was already consumed by sales,
                // deplete the remaining deficit from other active batches in the warehouse
                // so total batch quantities deducted strictly equal $baseQty (matching StockBalance).
                if ($deficit > 0.0001) {
                    $otherBatchesQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->where('remaining_quantity', '>', 0.0001)
                        ->where('status', 'active');
                    if ($variantId !== null && $variantId > 0) {
                        $otherBatchesQuery->where('product_variant_id', $variantId);
                    } else {
                        $otherBatchesQuery->whereNull('product_variant_id');
                    }
                    $otherBatches = $otherBatchesQuery->orderBy('received_date', 'asc')
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    $remainingDeficit = $deficit;
                    foreach ($otherBatches as $otherBatch) {
                        if ($remainingDeficit <= 0.0001) break;
                        $take = min((float)$otherBatch->remaining_quantity, $remainingDeficit);
                        $otherBatch->remaining_quantity = max(0.0, round((float)$otherBatch->remaining_quantity - $take, 4));
                        if ($otherBatch->remaining_quantity <= 0.0001) {
                            $otherBatch->status = 'exhausted';
                        }
                        $otherBatch->save();
                        $remainingDeficit -= $take;
                    }
                }
            } elseif ($itemType === 'Subtraction') {
                // Restore depleted inventory by first restoring into previously depleted batches
                // (prioritizing batches matching the item's base_unit_cost to preserve exact FIFO valuation),
                // and only creating a fallback batch for any remainder.
                if ($baseQty > 0.0001) {
                    $targetUnitCost = (float)$item->base_unit_cost;
                    $depletedBatchesQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->whereColumn('remaining_quantity', '<', 'initial_quantity')
                        ->where(function ($q) {
                            $q->whereNull('notes')
                              ->orWhere('notes', 'not like', '%[Rollback cancelled]%');
                        });
                    if ($variantId !== null && $variantId > 0) {
                        $depletedBatchesQuery->where('product_variant_id', $variantId);
                    } else {
                        $depletedBatchesQuery->whereNull('product_variant_id');
                    }
                    $depletedBatches = $depletedBatchesQuery
                        ->orderByRaw('CASE WHEN ABS(unit_cost - ?) < 0.01 THEN 0 ELSE 1 END', [$targetUnitCost])
                        ->orderBy('received_date', 'desc')
                        ->orderBy('id', 'desc')
                        ->lockForUpdate()
                        ->get();

                    $qtyToRestore = $baseQty;
                    foreach ($depletedBatches as $b) {
                        if ($qtyToRestore <= 0.0001) break;
                        $room = round((float)$b->initial_quantity - (float)$b->remaining_quantity, 4);
                        if ($room <= 0.0001) continue;

                        $restoreAmt = min($room, $qtyToRestore);
                        $b->remaining_quantity = round((float)$b->remaining_quantity + $restoreAmt, 4);
                        if ($b->remaining_quantity > 0.0001 && $b->status === 'exhausted') {
                            $b->status = 'active';
                        }
                        $b->save();
                        $qtyToRestore -= $restoreAmt;
                    }

                    if ($qtyToRestore > 0.0001) {
                        $this->recordBatch(
                            warehouseId: (int)$warehouseId,
                            productId: $productId,
                            variantId: $variantId,
                            baseQuantity: $qtyToRestore,
                            baseUnitCost: (float)$item->base_unit_cost,
                            sourceType: 'adjustment_reversal',
                            sourceReference: (string)$adjustment->reference_no,
                            receivedDate: (string)$adjustment->date,
                            notes: 'Restoration rollback of adjustment subtraction ' . $adjustment->reference_no
                        );
                    }
                }
            }
        }
    }

    /**
     * Deplete FIFO batches when returning inventory to a supplier.
     * Prioritizes batches originating from the specific purchase reference if provided,
     * without generating false sale_item_cost_layer records.
     */
    public function depleteForPurchaseReturn(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQty,
        string $returnReference,
        ?string $purchaseReference = null,
        float $fallbackCost = 0.0
    ): float {
        $neededBaseQty = round(max(0.0, $baseQty), 4);
        if ($neededBaseQty <= 0) return 0.0;

        $totalReturnCost = 0.0;
        $remainingNeeded = $neededBaseQty;

        // 1. If purchase reference is provided, first prioritize matching batches from this specific purchase
        if (!empty($purchaseReference)) {
            $cleanPurchaseRef = preg_replace('/^#\s*/', '', trim($purchaseReference));
            $specificQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where(function ($typeQ) {
                    $typeQ->where('source_type', 'purchase')
                          ->orWhereNull('source_type');
                })
                ->where(function ($q) use ($cleanPurchaseRef, $purchaseReference) {
                    $q->where('source_reference', $cleanPurchaseRef)
                      ->orWhere('source_reference', $purchaseReference)
                      ->orWhere('purchase_reference_no', $cleanPurchaseRef)
                      ->orWhere('purchase_reference_no', $purchaseReference);
                })
                ->where('remaining_quantity', '>', 0.0001)
                ->where('status', 'active');

            if ($variantId !== null && $variantId > 0) {
                $specificQuery->where('product_variant_id', $variantId);
            } else {
                $specificQuery->whereNull('product_variant_id');
            }

            $specificBatches = $specificQuery->orderBy('received_date', 'asc')->orderBy('id', 'asc')->lockForUpdate()->get();

            foreach ($specificBatches as $batch) {
                if ($remainingNeeded <= 0.0001) break;

                $avail = (float)$batch->remaining_quantity;
                $takeQty = min($avail, $remainingNeeded);
                $batchCost = (float)$batch->unit_cost;
                $totalReturnCost += ($takeQty * $batchCost);
                $remainingNeeded -= $takeQty;

                $newRemaining = max(0.0, round($avail - $takeQty, 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->notes = trim(($batch->notes ? $batch->notes . ' | ' : '') . "Returned {$takeQty} in PR #{$returnReference}");
                $batch->save();
            }
        }

        // 2. If quantity still needed (or no specific purchase reference was given), deplete oldest FIFO batches
        if ($remainingNeeded > 0.0001) {
            $fallbackQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('remaining_quantity', '>', 0.0001)
                ->where('status', 'active');

            if ($variantId !== null && $variantId > 0) {
                $fallbackQuery->where('product_variant_id', $variantId);
            } else {
                $fallbackQuery->whereNull('product_variant_id');
            }

            $fallbackBatches = $fallbackQuery->orderBy('received_date', 'asc')->orderBy('id', 'asc')->lockForUpdate()->get();

            foreach ($fallbackBatches as $batch) {
                if ($remainingNeeded <= 0.0001) break;

                $avail = (float)$batch->remaining_quantity;
                $takeQty = min($avail, $remainingNeeded);
                $batchCost = (float)$batch->unit_cost;
                $totalReturnCost += ($takeQty * $batchCost);
                $remainingNeeded -= $takeQty;

                $newRemaining = max(0.0, round($avail - $takeQty, 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->notes = trim(($batch->notes ? $batch->notes . ' | ' : '') . "Returned {$takeQty} in PR #{$returnReference}");
                $batch->save();
            }
        }

        if ($remainingNeeded > 0.0001) {
            $totalReturnCost += ($remainingNeeded * $fallbackCost);
        }

        return round($totalReturnCost, 2);
    }
}
