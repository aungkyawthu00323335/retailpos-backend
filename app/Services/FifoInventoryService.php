<?php

namespace App\Services;

use App\Models\AdjustmentItemCostLayer;
use App\Models\InventoryBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnCostLayer;
use App\Models\SaleItemCostLayer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnCostLayer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockTransfer;
use App\Models\StockTransferCostLayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        $baseUnitCost = round(max(0.0, $baseUnitCost), 8);
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
        $cutoff = ($saleDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($saleDate)))
            ? Carbon::parse($saleDate)->endOfDay()
            : $txDate;
        $cutoffStr = $cutoff->toDateTimeString();

        $remainingNeeded = $neededBaseQty;
        $totalCogs = 0.0;
        $layers = [];
        $pendingLayers = [];

        // Query all active batches ordered chronologically (prioritizing batches received <= $cutoffStr)
        // Do not hard-exclude batches after $cutoffStr to avoid bogus fallback layers on clock skew or same-day backdated sales.
        $batchQuery = InventoryBatch::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0.0001)
            ->where('status', 'active');

        if ($variantId !== null && $variantId > 0) {
            $batchQuery->where('product_variant_id', $variantId);
        } else {
            $batchQuery->whereNull('product_variant_id');
        }

        $batches = $batchQuery->orderByRaw('CASE WHEN received_date IS NULL OR received_date <= ? THEN 0 ELSE 1 END ASC', [$cutoffStr])
            ->orderBy('received_date', 'asc')
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

        $exactTotalCogs = round($totalCogs, 4);
        $totalCogs = round($exactTotalCogs, 2);
        // Align cumulative layer total_cost with 2dp COGS header when multi-layer 4dp fractional drift occurs
        if (!empty($pendingLayers)) {
            $layerSum = round(array_sum(array_column($pendingLayers, 'total_cost')), 4);
            $drift = round($totalCogs - $layerSum, 4);
            if (abs($drift) > 0.00001 && abs($drift) <= 0.05) {
                $lastIdx = count($pendingLayers) - 1;
                $pendingLayers[$lastIdx]['total_cost'] = max(0.0, round($pendingLayers[$lastIdx]['total_cost'] + $drift, 4));
            }
        }
        $avgUnitCost = $neededBaseQty > 0 ? round($totalCogs / $neededBaseQty, 4) : 0.0;

        // If $saleItemId is a callback, execute it now to create/return the SaleItem with final COGS
        $resolvedSaleItemId = is_callable($saleItemId)
            ? (int)$saleItemId($avgUnitCost, $totalCogs)
            : (int)$saleItemId;

        $insertRows = [];
        $nowTs = now();
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

            $insertRows[] = [
                'sale_id' => $saleId,
                'sale_item_id' => $resolvedSaleItemId,
                'inventory_batch_id' => $pLayer['inventory_batch_id'],
                'base_quantity' => $pLayer['base_quantity'],
                'unit_cost' => $pLayer['unit_cost'],
                'total_cost' => $pLayer['total_cost'],
                'date' => $txDate,
                'created_at' => $nowTs,
            ];
        }

        if (count($insertRows) === 1) {
            $layers[] = SaleItemCostLayer::create($insertRows[0]);
        } elseif (!empty($insertRows)) {
            SaleItemCostLayer::insert($insertRows);
            $layers = SaleItemCostLayer::where('sale_item_id', $resolvedSaleItemId)->orderBy('id')->get()->all();
        }

        return [
            'total_cogs' => $totalCogs,
            'avg_unit_cost' => $avgUnitCost,
            'layers' => $layers,
            'exhausted_fully' => $remainingNeeded <= 0.0001,
        ];
    }

    /**
     * Deplete FIFO batches for stock shrinkage / damage adjustment write-offs or transfers.
     * Populates exact cost layers so adjustment updates/deletes can restore exact source batches.
     */
    public function depleteForAdjustment(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQty,
        string $referenceNo,
        float $fallbackCost = 0.0,
        ?int $stockAdjustmentId = null,
        ?int $stockAdjustmentItemId = null,
        ?array &$recordedLayers = null,
        ?string $adjustmentDate = null
    ): float {
        $recordedLayers = [];
        $neededBaseQty = round(max(0.0, $baseQty), 4);
        if ($neededBaseQty <= 0) return 0.0;

        $baseQuery = InventoryBatch::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0.0001)
            ->where('status', 'active');

        if ($variantId !== null && $variantId > 0) {
            $baseQuery->where('product_variant_id', $variantId);
        } else {
            $baseQuery->whereNull('product_variant_id');
        }

        if ($adjustmentDate !== null && trim($adjustmentDate) !== '') {
            $cutoff = Carbon::parse($adjustmentDate)->endOfDay();
            $preCutoff = (clone $baseQuery)
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('received_date')->orWhere('received_date', '<=', $cutoff);
                })
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $preIds = $preCutoff->pluck('id')->all();
            $postCutoff = (clone $baseQuery)
                ->when(!empty($preIds), fn($q) => $q->whereNotIn('id', $preIds))
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $batches = $preCutoff->concat($postCutoff);
        } else {
            $batches = $baseQuery->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        $remainingNeeded = $neededBaseQty;
        $totalLossCost = 0.0;

        foreach ($batches as $batch) {
            if ($remainingNeeded <= 0.0001) break;

            $avail = (float)$batch->remaining_quantity;
            $takeQty = round(min($avail, $remainingNeeded), 4);
            $batchCost = round((float)$batch->unit_cost, 8);
            $layerTotal = round($takeQty * $batchCost, 4);
            $totalLossCost += $layerTotal;
            $remainingNeeded = round(max(0.0, $remainingNeeded - $takeQty), 4);

            $newRemaining = max(0.0, round($avail - $takeQty, 4));
            $batch->remaining_quantity = $newRemaining;
            if ($newRemaining <= 0.0001) {
                $batch->status = 'exhausted';
            }
            $batch->save();

            $recordedLayers[] = [
                'inventory_batch_id' => $batch->id,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'base_quantity' => $takeQty,
                'unit_cost' => $batchCost,
                'total_cost' => $layerTotal,
                'is_fallback_layer' => false,
            ];
        }

        if ($remainingNeeded > 0.0001) {
            $safeFallback = round(max(0.0, $fallbackCost), 8);
            $fallbackLayerCost = round($remainingNeeded * $safeFallback, 4);
            $totalLossCost += $fallbackLayerCost;
            $recordedLayers[] = [
                'inventory_batch_id' => null,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'base_quantity' => round($remainingNeeded, 4),
                'unit_cost' => $safeFallback,
                'total_cost' => $fallbackLayerCost,
                'is_fallback_layer' => true,
            ];
        }

        // Apply 2dp penny-drift plug to final layer so SUM(layer.total_cost) === returned loss
        $roundedLoss = round($totalLossCost, 2);
        if (!empty($recordedLayers)) {
            $lastIdx = count($recordedLayers) - 1;
            $priorSum = 0.0;
            for ($i = 0; $i < $lastIdx; $i++) {
                $priorSum += (float)$recordedLayers[$i]['total_cost'];
            }
            $pluggedLast = round(max(0.0, $roundedLoss - $priorSum), 4);
            if (abs($pluggedLast - (float)$recordedLayers[$lastIdx]['total_cost']) <= 0.05) {
                $recordedLayers[$lastIdx]['total_cost'] = $pluggedLast;
            }
        }

        if ($stockAdjustmentId !== null && $stockAdjustmentItemId !== null && !empty($recordedLayers)) {
            $this->persistAdjustmentCostLayers($stockAdjustmentId, $stockAdjustmentItemId, $recordedLayers);
        }

        return $roundedLoss;
    }

    /**
     * Persist granular FIFO cost layers for a stock adjustment subtraction item.
     */
    public function persistAdjustmentCostLayers(int $stockAdjustmentId, int $stockAdjustmentItemId, array $layers): void
    {
        if (empty($layers)) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($layers as $layer) {
            $rows[] = [
                'stock_adjustment_id' => $stockAdjustmentId,
                'stock_adjustment_item_id' => $stockAdjustmentItemId,
                'inventory_batch_id' => $layer['inventory_batch_id'] ?? null,
                'warehouse_id' => $layer['warehouse_id'] ?? null,
                'product_id' => $layer['product_id'] ?? null,
                'product_variant_id' => $layer['product_variant_id'] ?? null,
                'base_quantity' => round((float)($layer['base_quantity'] ?? 0), 4),
                'unit_cost' => round((float)($layer['unit_cost'] ?? 0), 8),
                'total_cost' => round((float)($layer['total_cost'] ?? 0), 4),
                'is_fallback_layer' => (bool)($layer['is_fallback_layer'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        AdjustmentItemCostLayer::insert($rows);
    }

    /**
     * Reverse a sale's FIFO depletion (for sale deletion / cancellation)
     */
    public function reverseSaleFifo(Sale $sale): void
    {
        $costLayers = SaleItemCostLayer::where('sale_id', $sale->id)->lockForUpdate()->get();
        if ($costLayers->isEmpty()) {
            return;
        }

        $batchIds = $costLayers->pluck('inventory_batch_id')->filter()->unique()->values()->all();
        if (!empty($batchIds)) {
            $batches = InventoryBatch::whereIn('id', $batchIds)->lockForUpdate()->get()->keyBy('id');
            foreach ($costLayers as $layer) {
                if ($layer->inventory_batch_id && isset($batches[$layer->inventory_batch_id])) {
                    $batch = $batches[$layer->inventory_batch_id];
                    $restored = round((float)$batch->remaining_quantity + (float)$layer->base_quantity, 4);
                    if ((float)$batch->initial_quantity > 0) {
                        $restored = min((float)$batch->initial_quantity, $restored);
                    }
                    $batch->remaining_quantity = $restored;
                    if ($batch->remaining_quantity > 0.0001) {
                        $batch->status = 'active';
                    }
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
     * Inspect Addition batches belonging to an adjustment to measure consumed units
     * and referencing cost layers before update or deletion.
     */
    public function inspectAdditionBatches(StockAdjustment $adjustment): array
    {
        $adjustment->loadMissing('items');
        $warehouseId = $this->resolveAdjustmentWarehouseId($adjustment);
        $claimedBatchIds = [];
        $entries = [];

        foreach ($adjustment->items as $item) {
            $itemType = strtolower(trim((string)($item->type ?: $adjustment->type)));
            if ($itemType !== 'addition' || !$item->product_id) {
                continue;
            }

            $productId = (int)$item->product_id;
            $variantId = $item->product_variant_id ? (int)$item->product_variant_id : null;

            $exactQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('source_type', 'adjustment_addition')
                ->where('source_reference', "{$adjustment->reference_no}#{$item->id}");
            if ($variantId !== null && $variantId > 0) {
                $exactQuery->where('product_variant_id', $variantId);
            } else {
                $exactQuery->whereNull('product_variant_id');
            }
            if (!empty($claimedBatchIds)) {
                $exactQuery->whereNotIn('id', $claimedBatchIds);
            }
            $batch = $exactQuery->lockForUpdate()->first();

            if (!$batch) {
                $fallbackQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where('source_type', 'adjustment_addition')
                    ->where(function ($q) use ($adjustment) {
                        $escapedAdjRef = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string)$adjustment->reference_no);
                        $q->where('source_reference', $adjustment->reference_no)
                          ->orWhereRaw("source_reference LIKE ? ESCAPE '!'", ["{$escapedAdjRef}#%"]);
                    });
                if ($variantId !== null && $variantId > 0) {
                    $fallbackQuery->where('product_variant_id', $variantId);
                } else {
                    $fallbackQuery->whereNull('product_variant_id');
                }
                if (!empty($claimedBatchIds)) {
                    $fallbackQuery->whereNotIn('id', $claimedBatchIds);
                }
                $batch = $fallbackQuery->orderBy('id', 'asc')->lockForUpdate()->first();
            }

            $consumed = 0.0;
            $hasCostLayers = false;
            if ($batch) {
                $claimedBatchIds[] = $batch->id;
                $consumed = max(0.0, round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4));
                $hasCostLayers = $this->hasDownstreamBatchConsumption((int)$batch->id);
            }

            $entries[] = [
                'item' => $item,
                'batch' => $batch,
                'consumed' => $consumed,
                'has_cost_layers' => $hasCostLayers,
            ];
        }

        return $entries;
    }

    /**
     * Assert no Addition batch from this adjustment has been consumed before destructive delete.
     */
    public function assertAdditionBatchesCanDelete(StockAdjustment $adjustment): array
    {
        $entries = $this->inspectAdditionBatches($adjustment);
        foreach ($entries as $entry) {
            $batch = $entry['batch'];
            $consumed = (float)$entry['consumed'];
            $hasCostLayers = (bool)$entry['has_cost_layers'];
            if ($batch && ($consumed > 0.0001 || $hasCostLayers)) {
                $item = $entry['item'];
                $pName = $item->product_name ?: $item->product_code;
                $dispConsumed = $consumed > 0.0001 ? $consumed : 0;
                throw new ValidationException(
                    validator: Validator::make([], []),
                    response: response()->json([
                        'status' => 'error',
                        'message' => "FIFO စာရင်းထိန်းချုပ်မှုအရ ဖျက်၍မရပါ: '{$pName}' ၏ Addition Batch မှ {$dispConsumed} ခုကို ရောင်းချ/အသုံးပြုပြီးဖြစ်သောကြောင့် ဖျက်ပါက COGS နှင့် FIFO Cost Layer များ ပျက်စီးနိုင်ပါသည်။ အစား Subtraction Adjustment အသစ်ဖြင့် ပြန်လည်ညှိနှိုင်းပါ။",
                    ], 422)
                );
            }
        }
        return $entries;
    }

    /**
     * Update an existing Addition InventoryBatch in-place so consumed FIFO quantities and layer IDs remain intact.
     */
    public function updateAdditionBatchInPlace(
        InventoryBatch $batch,
        float $consumedQty,
        float $newBaseQty,
        float $newBaseCost,
        string $referenceNo,
        int $newAdjItemId,
        ?string $receivedDate = null,
        ?string $notes = null
    ): InventoryBatch {
        $newRemaining = max(0.0, round($newBaseQty - $consumedQty, 4));
        $batch->initial_quantity = round($newBaseQty, 4);
        $batch->remaining_quantity = $newRemaining;
        $batch->status = $newRemaining > 0.0001 ? 'active' : 'exhausted';
        $batch->source_reference = "{$referenceNo}#{$newAdjItemId}";
        if ($consumedQty <= 0.0001) {
            $batch->unit_cost = round(max(0.0, $newBaseCost), 8);
            if ($receivedDate !== null) {
                $batch->received_date = Carbon::parse($receivedDate);
            }
        }
        if ($notes !== null) {
            $batch->notes = $notes;
        }
        $batch->save();
        return $batch;
    }

    /**
     * Reverse ONLY Subtraction FIFO depletions for an adjustment (exact layer restoration + legacy fallback).
     */
    public function rollbackAdjustmentSubtractions(StockAdjustment $adjustment): void
    {
        $adjustment->loadMissing('items');
        $warehouseId = $this->resolveAdjustmentWarehouseId($adjustment);

        foreach ($adjustment->items as $item) {
            $itemType = strtolower(trim((string)($item->type ?: $adjustment->type)));
            if ($itemType !== 'subtraction') {
                continue;
            }

            $productId = (int)$item->product_id;
            $variantId = $item->product_variant_id ? (int)$item->product_variant_id : null;
            $baseQty = (float)$item->base_quantity;
            if ($baseQty <= 0.0001) {
                $cRate = (float)($item->conversion_rate ?: 1.0);
                $baseQty = round((float)$item->quantity * ($cRate > 0 ? $cRate : 1.0), 4);
            }

            $layers = AdjustmentItemCostLayer::where('stock_adjustment_item_id', $item->id)
                ->orderByDesc('id')
                ->get();

            if ($layers->isNotEmpty()) {
                foreach ($layers as $layer) {
                    if (!$layer->is_fallback_layer && $layer->inventory_batch_id) {
                        $batch = InventoryBatch::lockForUpdate()->find($layer->inventory_batch_id);
                        if ($batch) {
                            $restored = round((float)$batch->remaining_quantity + (float)$layer->base_quantity, 4);
                            if ((float)$batch->initial_quantity > 0) {
                                $restored = min((float)$batch->initial_quantity, $restored);
                            }
                            $batch->remaining_quantity = $restored;
                            if ($batch->remaining_quantity > 0.0001 && $batch->status === 'exhausted') {
                                $batch->status = 'active';
                            }
                            $batch->save();
                        }
                    }
                }
                AdjustmentItemCostLayer::where('stock_adjustment_item_id', $item->id)->delete();
                continue;
            }

            // Legacy pre-migration fallback: restore into previously depleted batches matching unit_cost
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
                    $qtyToRestore = round(max(0.0, $qtyToRestore - $restoreAmt), 4);
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

    /**
     * Rollback FIFO batches for a stock adjustment on destroy (strictly protects consumed Addition batches).
     */
    public function rollbackAdjustmentFifo(StockAdjustment $adjustment): void
    {
        // Reverse intra-voucher subtractions first so Mixed vouchers whose Addition supplied
        // an intra-voucher Subtraction do not falsely trigger consumed-batch deletion locks.
        $this->rollbackAdjustmentSubtractions($adjustment);
        $additionEntries = $this->assertAdditionBatchesCanDelete($adjustment);

        foreach ($additionEntries as $entry) {
            if (!empty($entry['batch'])) {
                $entry['batch']->delete();
            }
        }
    }

    private function resolveAdjustmentWarehouseId(StockAdjustment $adjustment): int
    {
        $warehouseId = $adjustment->warehouse_id;
        if (!$warehouseId) {
            $outlet = \App\Models\Outlet::where('name', $adjustment->warehouse_name)->first();
            $warehouseId = $outlet ? $outlet->id : 1;
        }
        return (int)$warehouseId;
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
        float $fallbackCost = 0.0,
        ?int $purchaseReturnId = null,
        ?array &$recordedLayers = null
    ): float {
        $recordedLayers = [];
        $neededBaseQty = round(max(0.0, $baseQty), 4);
        if ($neededBaseQty <= 0) return 0.0;

        $totalReturnCost = 0.0;
        $remainingNeeded = $neededBaseQty;
        $hasLinkedPoBatches = false;

        // 1. If purchase reference is provided, first prioritize matching batches from this specific purchase
        if (!empty($purchaseReference)) {
            $cleanPurchaseRef = preg_replace('/^#\s*/', '', trim($purchaseReference));
            $altHyphen = str_replace('_', '-', $cleanPurchaseRef);
            $altUnderscore = str_replace('-', '_', $cleanPurchaseRef);

            $linkedPurchaseItemIds = PurchaseItem::query()
                ->whereHas('purchase', function ($pq) use ($cleanPurchaseRef, $purchaseReference, $altHyphen, $altUnderscore) {
                    $pq->where('reference_no', $cleanPurchaseRef)
                        ->orWhere('reference_no', $purchaseReference)
                        ->orWhere('reference_no', $altHyphen)
                        ->orWhere('reference_no', $altUnderscore);
                })
                ->where('product_id', $productId)
                ->when(
                    $variantId !== null && $variantId > 0,
                    fn($q) => $q->where('product_variant_id', $variantId),
                    fn($q) => $q->whereNull('product_variant_id')
                )
                ->pluck('id')
                ->all();

            $linkedBaseQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where(function ($typeQ) {
                    $typeQ->where('source_type', 'purchase')
                          ->orWhereNull('source_type');
                })
                ->where(function ($q) use ($cleanPurchaseRef, $purchaseReference, $altHyphen, $altUnderscore, $linkedPurchaseItemIds) {
                    $q->where('source_reference', $cleanPurchaseRef)
                      ->orWhere('source_reference', $purchaseReference)
                      ->orWhere('source_reference', $altHyphen)
                      ->orWhere('source_reference', $altUnderscore);
                    if (!empty($linkedPurchaseItemIds)) {
                        $q->orWhereIn('purchase_item_id', $linkedPurchaseItemIds);
                    }
                });

            if ($variantId !== null && $variantId > 0) {
                $linkedBaseQuery->where('product_variant_id', $variantId);
            } else {
                $linkedBaseQuery->whereNull('product_variant_id');
            }

            $hasLinkedPoBatches = (clone $linkedBaseQuery)->exists();
            $specificBatches = (clone $linkedBaseQuery)
                ->where('remaining_quantity', '>', 0.0001)
                ->where('status', 'active')
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($specificBatches as $batch) {
                if ($remainingNeeded <= 0.0001) break;

                $avail = (float)$batch->remaining_quantity;
                $takeQty = round(min($avail, $remainingNeeded), 4);
                $batchCost = (float)$batch->unit_cost;
                $layerTotal = round($takeQty * $batchCost, 4);
                $totalReturnCost += $layerTotal;
                $remainingNeeded = round(max(0.0, $remainingNeeded - $takeQty), 4);

                $newRemaining = max(0.0, round($avail - $takeQty, 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->notes = trim(($batch->notes ? $batch->notes . ' | ' : '') . "Returned {$takeQty} in PR #{$returnReference}");
                $batch->save();

                $recordedLayers[] = [
                    'inventory_batch_id' => $batch->id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'base_quantity' => $takeQty,
                    'unit_cost' => round($batchCost, 8),
                    'total_cost' => $layerTotal,
                    'is_fallback_layer' => false,
                ];
            }
        }

        if ($remainingNeeded > 0.0001 && !empty($purchaseReference) && $hasLinkedPoBatches) {
            $errMsg = "Insufficient remaining FIFO batch stock on linked Purchase #{$purchaseReference} (short by {$remainingNeeded} base units). Cannot deplete stock from unrelated purchases.";
            throw new ValidationException(
                validator: Validator::make([], []),
                response: response()->json([
                    'status' => 'error',
                    'message' => $errMsg,
                    'errors' => ['items' => [$errMsg]],
                ], 422)
            );
        }

        // 2. If quantity still needed (only for unlinked standalone returns or legacy pre-batch POs), deplete oldest FIFO batches
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
                $takeQty = round(min($avail, $remainingNeeded), 4);
                $batchCost = (float)$batch->unit_cost;
                $layerTotal = round($takeQty * $batchCost, 4);
                $totalReturnCost += $layerTotal;
                $remainingNeeded = round(max(0.0, $remainingNeeded - $takeQty), 4);

                $newRemaining = max(0.0, round($avail - $takeQty, 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->notes = trim(($batch->notes ? $batch->notes . ' | ' : '') . "Returned {$takeQty} in PR #{$returnReference}");
                $batch->save();

                $recordedLayers[] = [
                    'inventory_batch_id' => $batch->id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'base_quantity' => $takeQty,
                    'unit_cost' => round($batchCost, 8),
                    'total_cost' => $layerTotal,
                    'is_fallback_layer' => false,
                ];
            }
        }

        if ($remainingNeeded > 0.0001) {
            $safeFallback = round(max(0.0, $fallbackCost), 8);
            $fallbackLayerCost = round($remainingNeeded * $safeFallback, 4);
            $totalReturnCost += $fallbackLayerCost;
            $recordedLayers[] = [
                'inventory_batch_id' => null,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'base_quantity' => round($remainingNeeded, 4),
                'unit_cost' => $safeFallback,
                'total_cost' => $fallbackLayerCost,
                'is_fallback_layer' => true,
            ];
        }

        if ($purchaseReturnId !== null && !empty($recordedLayers)) {
            $this->persistPurchaseReturnCostLayers($purchaseReturnId, $recordedLayers);
        }

        return round($totalReturnCost, 4);
    }

    /**
     * Persist granular FIFO cost layers for a purchase return item.
     */
    public function persistPurchaseReturnCostLayers(int $purchaseReturnId, array $layers): void
    {
        if (empty($layers)) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($layers as $layer) {
            $rows[] = [
                'purchase_return_id' => $purchaseReturnId,
                'inventory_batch_id' => $layer['inventory_batch_id'] ?? null,
                'warehouse_id' => (int)($layer['warehouse_id'] ?? 0),
                'product_id' => (int)($layer['product_id'] ?? 0),
                'product_variant_id' => $layer['product_variant_id'] ?? null,
                'base_quantity' => round((float)($layer['base_quantity'] ?? 0), 4),
                'unit_cost' => round((float)($layer['unit_cost'] ?? 0), 8),
                'total_cost' => round((float)($layer['total_cost'] ?? 0), 4),
                'is_fallback_layer' => (bool)($layer['is_fallback_layer'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        PurchaseReturnCostLayer::insert($rows);
    }

    /**
     * Restore FIFO batches depleted by a purchase return when voided or edited.
     * Restores exact recorded PurchaseReturnCostLayer rows first, then falls back to note-stamped markers.
     */
    public function restoreForPurchaseReturn(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQty,
        string $returnReference,
        ?string $purchaseReference = null,
        float $fallbackCost = 0.0,
        ?string $receivedDate = null,
        ?int $purchaseReturnId = null
    ): void {
        $remainingToRestore = round(max(0.0, $baseQty), 4);
        if ($remainingToRestore <= 0.0001) {
            return;
        }

        $cleanReturnRef = preg_replace('/^#\s*/', '', trim($returnReference));
        $refCandidates = array_values(array_unique(array_filter([$cleanReturnRef, trim($returnReference)])));

        // 1. Exact relational cost layer restoration (primary deterministic path)
        if ($purchaseReturnId !== null) {
            $layers = PurchaseReturnCostLayer::where('purchase_return_id', $purchaseReturnId)
                ->where('product_id', $productId)
                ->when(
                    $variantId !== null && $variantId > 0,
                    fn($q) => $q->where('product_variant_id', $variantId),
                    fn($q) => $q->whereNull('product_variant_id')
                )
                ->orderByDesc('id')
                ->get();

            if ($layers->isNotEmpty()) {
                foreach ($layers as $layer) {
                    if ($remainingToRestore <= 0.0001) {
                        break;
                    }
                    $layerQty = round(min($remainingToRestore, (float)$layer->base_quantity), 4);
                    if ($layerQty <= 0.0001) {
                        continue;
                    }

                    if (!$layer->is_fallback_layer && $layer->inventory_batch_id) {
                        $batch = InventoryBatch::lockForUpdate()->find($layer->inventory_batch_id);
                        if ($batch) {
                            $restored = round((float)$batch->remaining_quantity + $layerQty, 4);
                            if ((float)$batch->initial_quantity > 0) {
                                $restored = min((float)$batch->initial_quantity, $restored);
                            }
                            $batch->remaining_quantity = $restored;
                            if ($batch->remaining_quantity > 0.0001) {
                                $batch->status = 'active';
                            }
                            if (!empty($batch->notes) && !empty($refCandidates)) {
                                $segments = array_map('trim', explode('|', (string)$batch->notes));
                                $kept = [];
                                $removedNote = false;
                                foreach ($segments as $seg) {
                                    if ($seg === '') continue;
                                    if (!$removedNote) {
                                        foreach ($refCandidates as $rc) {
                                            if (preg_match('/^Returned\s+[0-9]+(?:\.[0-9]+)?\s+in\s+PR\s+#' . preg_quote($rc, '/') . '$/i', $seg)) {
                                                $removedNote = true;
                                                continue 2;
                                            }
                                        }
                                    }
                                    $kept[] = $seg;
                                }
                                $batch->notes = !empty($kept) ? implode(' | ', $kept) : null;
                            }
                            $batch->save();
                            $remainingToRestore = round(max(0.0, $remainingToRestore - $layerQty), 4);
                        } else {
                            // Batch record missing: record void restoration batch at layer cost below
                            $this->recordBatch(
                                warehouseId:     $warehouseId,
                                productId:       $productId,
                                variantId:       $variantId,
                                baseQuantity:    $layerQty,
                                baseUnitCost:    (float)$layer->unit_cost,
                                sourceType:      'purchase_return_void',
                                sourceReference: (string)$returnReference,
                                receivedDate:    $receivedDate,
                                notes:           'Purchase return voided — stock restored'
                            );
                            $remainingToRestore = round(max(0.0, $remainingToRestore - $layerQty), 4);
                        }
                    } else {
                        // Fallback layer -> record void restoration batch at layer unit_cost
                        $this->recordBatch(
                            warehouseId:     $warehouseId,
                            productId:       $productId,
                            variantId:       $variantId,
                            baseQuantity:    $layerQty,
                            baseUnitCost:    (float)$layer->unit_cost,
                            sourceType:      'purchase_return_void',
                            sourceReference: (string)$returnReference,
                            receivedDate:    $receivedDate,
                            notes:           'Purchase return voided — stock restored'
                        );
                        $remainingToRestore = round(max(0.0, $remainingToRestore - $layerQty), 4);
                    }

                    // Consume only the restored portion of this layer so same-product multi-unit lines retain their remaining layers.
                    if ($layerQty >= (float)$layer->base_quantity - 0.0001) {
                        $layer->delete();
                    } else {
                        $newLayerQty = round((float)$layer->base_quantity - $layerQty, 4);
                        $layer->update([
                            'base_quantity' => $newLayerQty,
                            'total_cost' => round($newLayerQty * (float)$layer->unit_cost, 4),
                        ]);
                    }
                }

                if ($remainingToRestore <= 0.0001) {
                    return;
                }
            }
        }

        // 2. Legacy note-marker reversal for pre-migration returns without cost layer records

        if (!empty($refCandidates)) {
            $batchQuery = InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->whereNotNull('notes');
            if ($variantId !== null && $variantId > 0) {
                $batchQuery->where('product_variant_id', $variantId);
            } else {
                $batchQuery->whereNull('product_variant_id');
            }

            // Lock candidate batches for this SKU+warehouse and match exact stamped markers in PHP (wildcard-safe)
            $batches = $batchQuery->orderBy('id', 'desc')->lockForUpdate()->get();
            foreach ($batches as $batch) {
                if ($remainingToRestore <= 0.0001) {
                    break;
                }
                $notes = (string)($batch->notes ?? '');
                if ($notes === '') {
                    continue;
                }

                $headroom = max(0.0, round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4));
                if ($headroom <= 0.0001) {
                    continue;
                }

                $segments = array_map('trim', explode('|', $notes));
                $restoredOnBatch = 0.0;
                $updatedSegments = [];

                // Process segments in reverse order (LIFO relative to chronological append)
                for ($i = count($segments) - 1; $i >= 0; $i--) {
                    $seg = $segments[$i];
                    if ($seg === '') {
                        continue;
                    }
                    $matched = false;
                    foreach ($refCandidates as $refCand) {
                        $pattern = '/^Returned\s+([0-9]+(?:\.[0-9]+)?)\s+in\s+PR\s+#' . preg_quote($refCand, '/') . '$/i';
                        if (preg_match($pattern, $seg, $m) && $remainingToRestore > 0.0001 && $headroom > 0.0001) {
                            $segQty = (float)$m[1];
                            $restoreHere = round(min($remainingToRestore, $segQty, $headroom), 4);
                            if ($restoreHere > 0.0001) {
                                $restoredOnBatch += $restoreHere;
                                $remainingToRestore = round(max(0.0, $remainingToRestore - $restoreHere), 4);
                                $headroom = round(max(0.0, $headroom - $restoreHere), 4);
                                $leftInSeg = round($segQty - $restoreHere, 4);
                                if ($leftInSeg > 0.0001) {
                                    $formattedLeft = rtrim(rtrim(number_format($leftInSeg, 4, '.', ''), '0'), '.');
                                    $updatedSegments[] = "Returned {$formattedLeft} in PR #{$refCand}";
                                }
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (!$matched) {
                        $updatedSegments[] = $seg;
                    }
                }

                if ($restoredOnBatch > 0.0001) {
                    $batch->remaining_quantity = round((float)$batch->remaining_quantity + $restoredOnBatch, 4);
                    if ($batch->remaining_quantity > 0.0001) {
                        $batch->status = 'active';
                    }
                    $orderedSegments = array_reverse($updatedSegments);
                    $batch->notes = !empty($orderedSegments) ? implode(' | ', $orderedSegments) : null;
                    $batch->save();
                }
            }
        }

        if ($remainingToRestore > 0.0001) {
            $this->recordBatch(
                warehouseId:     $warehouseId,
                productId:       $productId,
                variantId:       $variantId,
                baseQuantity:    (float)$remainingToRestore,
                baseUnitCost:    (float)$fallbackCost,
                sourceType:      'purchase_return_void',
                sourceReference: (string)$returnReference,
                receivedDate:    $receivedDate,
                notes:           'Purchase return voided — stock restored'
            );
        }
    }

    /**
     * Assert that deleting, un-receiving, or shrinking quantities on a Received Purchase
     * does not orphan or go negative against FIFO batches already consumed by sales,
     * stock adjustments, or purchase returns.
     *
     * @param array<string, float>|null $incomingBaseQtyByKey Map of "{productId}_{variantId|0}" => new base_quantity
     */
    public function assertPurchaseBatchesCanModifyOrDelete(
        Purchase $purchase,
        ?array $incomingBaseQtyByKey = null,
        bool $isFullRemovalOrUnreceive = false
    ): ?string {
        if ($purchase->status !== 'Received') {
            return null;
        }

        $purchase->loadMissing('items');
        $warehouseId = $purchase->warehouse_id;
        $skuState = [];

        foreach ($purchase->items as $poItem) {
            $batch = InventoryBatch::where('purchase_item_id', $poItem->id)->lockForUpdate()->first();
            if (!$batch && $warehouseId) {
                $batchQuery = InventoryBatch::where('warehouse_id', (int)$warehouseId)
                    ->where('product_id', $poItem->product_id)
                    ->where('source_type', 'purchase')
                    ->where('source_reference', $purchase->reference_no);
                if (!empty($poItem->product_variant_id)) {
                    $batchQuery->where('product_variant_id', (int)$poItem->product_variant_id);
                } else {
                    $batchQuery->whereNull('product_variant_id');
                }
                $batch = $batchQuery->lockForUpdate()->first();
            }

            if (!$batch) {
                continue;
            }

            $consumed = max(0.0, round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4));
            $hasLayers = $this->hasDownstreamBatchConsumption((int)$batch->id);

            if ($isFullRemovalOrUnreceive) {
                if ($consumed > 0.0001 || $hasLayers) {
                    $pName = $poItem->product_name ?: ('Product #' . $poItem->product_id);
                    return "Cannot delete or un-receive Purchase #{$purchase->reference_no}: FIFO batch for '{$pName}' has already been consumed ({$consumed} base units). Void dependent sales, adjustments, or purchase returns first.";
                }
                continue;
            }

            if ($incomingBaseQtyByKey !== null) {
                $vKey = !empty($poItem->product_variant_id) ? (string)(int)$poItem->product_variant_id : '0';
                $skuKey = (int)$poItem->product_id . '_' . $vKey;
                if (!isset($skuState[$skuKey])) {
                    $skuState[$skuKey] = [
                        'consumed' => 0.0,
                        'has_layers' => false,
                        'name' => $poItem->product_name ?: ('Product #' . $poItem->product_id),
                    ];
                }
                $skuState[$skuKey]['consumed'] += $consumed;
                if ($hasLayers) {
                    $skuState[$skuKey]['has_layers'] = true;
                }
            }
        }

        if (!$isFullRemovalOrUnreceive && $incomingBaseQtyByKey !== null) {
            foreach ($skuState as $skuKey => $state) {
                $newBaseQty = (float)($incomingBaseQtyByKey[$skuKey] ?? 0.0);
                if (($state['consumed'] > 0.0001 || $state['has_layers']) && $newBaseQty < $state['consumed'] - 0.0001) {
                    return "Cannot reduce quantity of '{$state['name']}' below {$state['consumed']} base units because that quantity has already been sold, adjusted, or returned from this FIFO batch.";
                }
            }
        }

        return null;
    }

    /**
     * Restore inventory batches on a Completed Sale Return using exact original Sale FIFO cost layers
     * (never valuing returns at retail selling price). Tracks residual returnable layers across partial returns.
     *
     * @return array{total_cost: float, avg_unit_cost: float, cost_variance: float, layers: array<int, SaleReturnCostLayer>}
     */
    public function restoreForSaleReturn(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $baseQty,
        SaleReturn $saleReturn,
        ?Sale $linkedSale = null,
        float $productFallbackCost = 0.0
    ): array {
        $remainingToRestore = round(max(0.0, $baseQty), 4);
        if ($remainingToRestore <= 0.0001) {
            return ['total_cost' => 0.0, 'avg_unit_cost' => 0.0, 'cost_variance' => 0.0, 'layers' => []];
        }

        $createdLayers = [];
        $totalRestoredCost = 0.0;
        $saleAvgUnitCost = $productFallbackCost;
        $rxDate = $saleReturn->date ?: now()->toDateTimeString();

        // Compute remaining returnable cost layers from linked Sale (LIFO across depleted sale tranches)
        if ($linkedSale) {
            $linkedSale->loadMissing('items');
            $matchingSaleItems = $linkedSale->items->filter(function ($si) use ($productId, $variantId) {
                if ((int)$si->product_id !== $productId) return false;
                $sVar = !empty($si->product_variant_id) ? (int)$si->product_variant_id : null;
                return $sVar === ($variantId ?: null);
            });
            $sumSaleItemBase = (float)$matchingSaleItems->sum(
                fn($si) => (float)($si->base_quantity > 0
                    ? $si->base_quantity
                    : ($si->quantity * max(1.0, (float)($si->conversion_rate ?? 1.0))))
            );
            $sumSaleItemCogs = (float)$matchingSaleItems->sum('total_cogs');
            if ($sumSaleItemBase > 0.0001 && $sumSaleItemCogs > 0) {
                $saleAvgUnitCost = round($sumSaleItemCogs / $sumSaleItemBase, 4);
            }

            // Determine already-returned base quantity from active sibling SaleReturns on this Sale
            $priorRestoredQuery = DB::table('sale_return_cost_layers')
                ->join('sale_returns', 'sale_return_cost_layers.sale_return_id', '=', 'sale_returns.id')
                ->where('sale_returns.id', '!=', (int)$saleReturn->id)
                ->whereNotIn('sale_returns.status', ['Voided', 'Cancelled', 'Draft', 'Pending'])
                ->where('sale_return_cost_layers.product_id', $productId)
                ->when(
                    $variantId !== null && $variantId > 0,
                    fn($q) => $q->where('sale_return_cost_layers.product_variant_id', $variantId),
                    fn($q) => $q->whereNull('sale_return_cost_layers.product_variant_id')
                )
                ->where(function ($q) use ($linkedSale) {
                    $q->where('sale_returns.sale_id', $linkedSale->id)
                      ->orWhere('sale_returns.sale_reference_no', $linkedSale->reference_no);
                });

            $priorBySourceBatch = [];
            $priorFallbackUsed = 0.0;
            foreach ($priorRestoredQuery->select('source_batch_id', 'base_quantity', 'is_fallback_layer')->get() as $prRow) {
                if (!$prRow->is_fallback_layer && !empty($prRow->source_batch_id)) {
                    $sbId = (int)$prRow->source_batch_id;
                    $priorBySourceBatch[$sbId] = round(($priorBySourceBatch[$sbId] ?? 0.0) + (float)$prRow->base_quantity, 4);
                } else {
                    $priorFallbackUsed = round($priorFallbackUsed + (float)$prRow->base_quantity, 4);
                }
            }

            $saleItemIds = $matchingSaleItems->pluck('id')->all();
            $saleLayers = !empty($saleItemIds)
                ? SaleItemCostLayer::where('sale_id', $linkedSale->id)
                    ->whereIn('sale_item_id', $saleItemIds)
                    ->orderByDesc('id')
                    ->get()
                : collect();

            foreach ($saleLayers as $sLayer) {
                if ($remainingToRestore <= 0.0001) {
                    break;
                }
                $layerQty = round((float)$sLayer->base_quantity, 4);
                $srcBatchId = $sLayer->inventory_batch_id ? (int)$sLayer->inventory_batch_id : null;

                if ($srcBatchId !== null && isset($priorBySourceBatch[$srcBatchId]) && $priorBySourceBatch[$srcBatchId] > 0.0001) {
                    $deductPrior = min($layerQty, $priorBySourceBatch[$srcBatchId]);
                    $layerQty = round($layerQty - $deductPrior, 4);
                    $priorBySourceBatch[$srcBatchId] = round($priorBySourceBatch[$srcBatchId] - $deductPrior, 4);
                } elseif ($srcBatchId === null && $priorFallbackUsed > 0.0001) {
                    $deductPrior = min($layerQty, $priorFallbackUsed);
                    $layerQty = round($layerQty - $deductPrior, 4);
                    $priorFallbackUsed = round($priorFallbackUsed - $deductPrior, 4);
                }

                if ($layerQty <= 0.0001) {
                    continue;
                }

                $takeQty = round(min($remainingToRestore, $layerQty), 4);
                $unitCost = (float)$sLayer->unit_cost > 0 ? round((float)$sLayer->unit_cost, 8) : round($saleAvgUnitCost, 8);
                $layerTotal = round($takeQty * $unitCost, 4);

                $restoredBatch = $this->recordBatch(
                    warehouseId:     $warehouseId,
                    productId:       $productId,
                    variantId:       $variantId,
                    baseQuantity:    $takeQty,
                    baseUnitCost:    $unitCost,
                    sourceType:      'sale_return',
                    sourceReference: $saleReturn->reference_no,
                    receivedDate:    $rxDate,
                    createdBy:       $saleReturn->user_id,
                    notes:           'Returned stock from Sale #' . $linkedSale->reference_no
                );

                $createdLayers[] = SaleReturnCostLayer::create([
                    'sale_return_id'     => $saleReturn->id,
                    'inventory_batch_id' => $restoredBatch->id,
                    'source_batch_id'    => $srcBatchId,
                    'warehouse_id'       => $warehouseId,
                    'product_id'         => $productId,
                    'product_variant_id' => $variantId,
                    'base_quantity'      => $takeQty,
                    'unit_cost'          => $unitCost,
                    'total_cost'         => $layerTotal,
                    'is_fallback_layer'  => $srcBatchId === null,
                ]);

                $totalRestoredCost = round($totalRestoredCost + $layerTotal, 4);
                $remainingToRestore = round(max(0.0, $remainingToRestore - $takeQty), 4);
            }
        }

        if ($remainingToRestore > 0.0001) {
            $fallbackCost = $saleAvgUnitCost > 0 ? round($saleAvgUnitCost, 8) : round(max(0.0, $productFallbackCost), 8);
            $layerTotal = round($remainingToRestore * $fallbackCost, 4);

            $restoredBatch = $this->recordBatch(
                warehouseId:     $warehouseId,
                productId:       $productId,
                variantId:       $variantId,
                baseQuantity:    $remainingToRestore,
                baseUnitCost:    $fallbackCost,
                sourceType:      'sale_return',
                sourceReference: $saleReturn->reference_no,
                receivedDate:    $rxDate,
                createdBy:       $saleReturn->user_id,
                notes:           'Returned stock from Sale Return #' . $saleReturn->reference_no
            );

            $createdLayers[] = SaleReturnCostLayer::create([
                'sale_return_id'     => $saleReturn->id,
                'inventory_batch_id' => $restoredBatch->id,
                'source_batch_id'    => null,
                'warehouse_id'       => $warehouseId,
                'product_id'         => $productId,
                'product_variant_id' => $variantId,
                'base_quantity'      => $remainingToRestore,
                'unit_cost'          => $fallbackCost,
                'total_cost'         => $layerTotal,
                'is_fallback_layer'  => true,
            ]);

            $totalRestoredCost = round($totalRestoredCost + $layerTotal, 4);
        }

        $avgCost = $baseQty > 0.0001 ? round($totalRestoredCost / $baseQty, 8) : 0.0;
        $expectedCost = round($baseQty * $saleAvgUnitCost, 4);

        return [
            'total_cost' => round($totalRestoredCost, 4),
            'avg_unit_cost' => $avgCost,
            'cost_variance' => round($totalRestoredCost - $expectedCost, 4),
            'layers' => $createdLayers,
        ];
    }

    /**
     * Ensure no inventory batch restored by a Sale Return has already been sold, adjusted, or transferred.
     */
    public function assertSaleReturnBatchesDeletable(SaleReturn $saleReturn): ?string
    {
        $layers = SaleReturnCostLayer::where('sale_return_id', $saleReturn->id)->get();
        $batchIds = $layers->pluck('inventory_batch_id')->filter()->unique()->values()->all();

        $legacyBatchIds = InventoryBatch::where('source_type', 'sale_return')
            ->where('source_reference', $saleReturn->reference_no)
            ->pluck('id')
            ->all();

        $allBatchIds = array_values(array_unique(array_merge($batchIds, $legacyBatchIds)));
        if (empty($allBatchIds)) {
            return null;
        }

        $batches = InventoryBatch::whereIn('id', $allBatchIds)->lockForUpdate()->get();
        foreach ($batches as $batch) {
            $consumed = round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4);
            $hasDownstream = $this->hasDownstreamBatchConsumption((int)$batch->id);

            if ($consumed > 0.0001 || $hasDownstream) {
                $pName = $batch->product?->name ?? ('Product #' . $batch->product_id);
                return "Cannot reverse Sale Return #{$saleReturn->reference_no}: returned inventory batch for '{$pName}' has already been consumed ({$consumed} base units). Void dependent transactions first.";
            }
        }

        return null;
    }

    /**
     * Roll back all restored FIFO batches and layers created by a Sale Return.
     */
    public function rollbackSaleReturnFifo(SaleReturn $saleReturn): void
    {
        $layers = SaleReturnCostLayer::where('sale_return_id', $saleReturn->id)->get();
        $batchIds = $layers->pluck('inventory_batch_id')->filter()->unique()->values()->all();
        $legacyBatchIds = InventoryBatch::where('source_type', 'sale_return')
            ->where('source_reference', $saleReturn->reference_no)
            ->pluck('id')
            ->all();
        $allBatchIds = array_values(array_unique(array_merge($batchIds, $legacyBatchIds)));

        if (!empty($allBatchIds)) {
            InventoryBatch::whereIn('id', $allBatchIds)->delete();
        }

        SaleReturnCostLayer::where('sale_return_id', $saleReturn->id)->delete();
    }

    /**
     * Deplete source warehouse FIFO batches for a Stock Transfer and persist granular cost layers.
     * Optionally creates matching destination batches immediately when status is Completed.
     *
     * @return array{total_cost: float, item_costs: array<int, array{avg_unit_cost: float, total_cost: float}>}
     */
    public function depleteTransferWithLayers(
        StockTransfer $transfer,
        int $fromWarehouseId,
        int $toWarehouseId,
        bool $createDestinationBatches = false
    ): array {
        $transfer->loadMissing('items');
        $totalCost = 0.0;
        $itemCosts = [];
        $txDate = $transfer->date ?: now()->toDateTimeString();

        foreach ($transfer->items as $tItem) {
            $needed = round(max(0.0, (float)($tItem->base_quantity > 0 ? $tItem->base_quantity : $tItem->quantity)), 4);
            if ($needed <= 0.0001 || !$tItem->product_id) {
                continue;
            }

            $productId = (int)$tItem->product_id;
            $variantId = $tItem->product_variant_id ? (int)$tItem->product_variant_id : null;
            $fallbackCost = (float)($tItem->base_unit_cost > 0 ? $tItem->base_unit_cost : $tItem->unit_cost);
            if ($fallbackCost <= 0 && $tItem->product) {
                $fallbackCost = (float)($tItem->product->cost_price ?: $tItem->product->purchase_price);
            }

            $baseBatchQuery = InventoryBatch::where('warehouse_id', $fromWarehouseId)
                ->where('product_id', $productId)
                ->where('remaining_quantity', '>', 0.0001)
                ->where('status', 'active')
                ->when(
                    $variantId !== null && $variantId > 0,
                    fn($q) => $q->where('product_variant_id', $variantId),
                    fn($q) => $q->whereNull('product_variant_id')
                );

            // Prefer chronologically eligible batches (received_date <= transfer date) first
            $txCutoff = Carbon::parse($txDate)->endOfDay();
            $preBatches = (clone $baseBatchQuery)
                ->where(function ($q) use ($txCutoff) {
                    $q->whereNull('received_date')->orWhere('received_date', '<=', $txCutoff);
                })
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $preBatchIds = $preBatches->pluck('id')->all();
            $postBatches = (clone $baseBatchQuery)
                ->when(!empty($preBatchIds), fn($q) => $q->whereNotIn('id', $preBatchIds))
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $batchQuery = $preBatches->concat($postBatches);

            $remainingNeeded = $needed;
            $itemTotalCost = 0.0;

            foreach ($batchQuery as $batch) {
                if ($remainingNeeded <= 0.0001) {
                    break;
                }
                $avail = (float)$batch->remaining_quantity;
                $takeQty = round(min($avail, $remainingNeeded), 4);
                $unitCost = round((float)$batch->unit_cost, 8);
                $layerCost = round($takeQty * $unitCost, 4);

                $newRemaining = max(0.0, round($avail - $takeQty, 4));
                $batch->remaining_quantity = $newRemaining;
                if ($newRemaining <= 0.0001) {
                    $batch->status = 'exhausted';
                }
                $batch->save();

                $destBatchId = null;
                if ($createDestinationBatches) {
                    $destBatch = $this->recordBatch(
                        warehouseId:     $toWarehouseId,
                        productId:       $productId,
                        variantId:       $variantId,
                        baseQuantity:    $takeQty,
                        baseUnitCost:    $unitCost,
                        sourceType:      'transfer',
                        sourceReference: $transfer->reference_no,
                        receivedDate:    $batch->received_date ? $batch->received_date->toDateTimeString() : $txDate,
                        createdBy:       $transfer->user_id,
                        notes:           'Transfer In from ' . $transfer->from_warehouse . ' (' . $transfer->reference_no . ')'
                    );
                    $destBatchId = $destBatch->id;
                }

                StockTransferCostLayer::create([
                    'stock_transfer_id'            => $transfer->id,
                    'stock_transfer_item_id'       => $tItem->id,
                    'source_inventory_batch_id'    => $batch->id,
                    'destination_inventory_batch_id' => $destBatchId,
                    'from_warehouse_id'            => $fromWarehouseId,
                    'to_warehouse_id'              => $toWarehouseId,
                    'product_id'                   => $productId,
                    'product_variant_id'           => $variantId,
                    'base_quantity'                => $takeQty,
                    'unit_cost'                    => $unitCost,
                    'total_cost'                   => $layerCost,
                    'is_fallback_layer'            => false,
                ]);

                $itemTotalCost = round($itemTotalCost + $layerCost, 4);
                $remainingNeeded = round(max(0.0, $remainingNeeded - $takeQty), 4);
            }

            if ($remainingNeeded > 0.0001) {
                $unitCost = round(max(0.0, $fallbackCost), 8);
                $layerCost = round($remainingNeeded * $unitCost, 4);

                // Do NOT mint permanent 0.00 active destination batches for unbatched zero-cost fallback transfers
                $destBatchId = null;
                if ($createDestinationBatches && $unitCost > 0.0001) {
                    $destBatch = $this->recordBatch(
                        warehouseId:     $toWarehouseId,
                        productId:       $productId,
                        variantId:       $variantId,
                        baseQuantity:    $remainingNeeded,
                        baseUnitCost:    $unitCost,
                        sourceType:      'transfer',
                        sourceReference: $transfer->reference_no,
                        receivedDate:    $txDate,
                        createdBy:       $transfer->user_id,
                        notes:           'Transfer In from ' . $transfer->from_warehouse . ' (' . $transfer->reference_no . ')'
                    );
                    $destBatchId = $destBatch->id;
                }

                StockTransferCostLayer::create([
                    'stock_transfer_id'            => $transfer->id,
                    'stock_transfer_item_id'       => $tItem->id,
                    'source_inventory_batch_id'    => null,
                    'destination_inventory_batch_id' => $destBatchId,
                    'from_warehouse_id'            => $fromWarehouseId,
                    'to_warehouse_id'              => $toWarehouseId,
                    'product_id'                   => $productId,
                    'product_variant_id'           => $variantId,
                    'base_quantity'                => $remainingNeeded,
                    'unit_cost'                    => $unitCost,
                    'total_cost'                   => $layerCost,
                    'is_fallback_layer'            => true,
                ]);

                $itemTotalCost = round($itemTotalCost + $layerCost, 4);
            }

            $conv = (float)($tItem->conversion_rate > 0 ? $tItem->conversion_rate : 1.0);
            $avgBaseCost = $needed > 0.0001 ? round($itemTotalCost / $needed, 8) : 0.0;
            $tItem->update([
                'base_unit_cost' => $avgBaseCost,
                'unit_cost' => round($avgBaseCost * $conv, 4),
                'subtotal' => round($itemTotalCost, 2),
            ]);

            $itemCosts[$tItem->id] = [
                'avg_unit_cost' => $avgBaseCost,
                'total_cost' => round($itemTotalCost, 2),
            ];
            $totalCost = round($totalCost + $itemTotalCost, 2);
        }

        return ['total_cost' => $totalCost, 'item_costs' => $itemCosts];
    }

    /**
     * Create destination batches when an In-Transit ('Sent') transfer is received ('Completed'),
     * preserving exact source cost layers rather than flattening weighted-average cost.
     */
    public function createTransferDestinationBatches(StockTransfer $transfer, int $toWarehouseId): void
    {
        $transfer->loadMissing('items');
        $layers = StockTransferCostLayer::where('stock_transfer_id', $transfer->id)->get();

        if ($layers->isNotEmpty()) {
            foreach ($layers as $layer) {
                if (!empty($layer->destination_inventory_batch_id)) {
                    continue;
                }
                if ($layer->is_fallback_layer && (float)$layer->unit_cost <= 0.0001) {
                    continue;
                }
                $srcDate = $layer->sourceBatch?->received_date?->toDateTimeString() ?? ($transfer->date ?: now()->toDateTimeString());
                $destBatch = $this->recordBatch(
                    warehouseId:     $toWarehouseId,
                    productId:       (int)$layer->product_id,
                    variantId:       $layer->product_variant_id ? (int)$layer->product_variant_id : null,
                    baseQuantity:    (float)$layer->base_quantity,
                    baseUnitCost:    (float)$layer->unit_cost,
                    sourceType:      'transfer',
                    sourceReference: $transfer->reference_no,
                    receivedDate:    $srcDate,
                    createdBy:       $transfer->user_id,
                    notes:           'Transfer In from ' . $transfer->from_warehouse . ' (' . $transfer->reference_no . ')'
                );
                $layer->update([
                    'to_warehouse_id' => $toWarehouseId,
                    'destination_inventory_batch_id' => $destBatch->id,
                ]);
            }
            return;
        }

        // Legacy fallback when transfer was Sent before StockTransferCostLayer existed
        $txDate = $transfer->date ?: now()->toDateTimeString();
        foreach ($transfer->items as $tItem) {
            $baseQty = (float)($tItem->base_quantity > 0 ? $tItem->base_quantity : $tItem->quantity);
            if ($baseQty <= 0.0001 || !$tItem->product_id) {
                continue;
            }
            $baseUnitCost = (float)($tItem->base_unit_cost > 0 ? $tItem->base_unit_cost : $tItem->unit_cost);
            $destBatch = $this->recordBatch(
                warehouseId:     $toWarehouseId,
                productId:       (int)$tItem->product_id,
                variantId:       $tItem->product_variant_id ? (int)$tItem->product_variant_id : null,
                baseQuantity:    $baseQty,
                baseUnitCost:    $baseUnitCost,
                sourceType:      'transfer',
                sourceReference: $transfer->reference_no,
                receivedDate:    $txDate,
                createdBy:       $transfer->user_id,
                notes:           'Transfer In from ' . $transfer->from_warehouse . ' (' . $transfer->reference_no . ')'
            );

            StockTransferCostLayer::create([
                'stock_transfer_id'            => $transfer->id,
                'stock_transfer_item_id'       => $tItem->id,
                'source_inventory_batch_id'    => null,
                'destination_inventory_batch_id' => $destBatch->id,
                'from_warehouse_id'            => (int)$transfer->from_warehouse_id,
                'to_warehouse_id'              => $toWarehouseId,
                'product_id'                   => (int)$tItem->product_id,
                'product_variant_id'           => $tItem->product_variant_id ? (int)$tItem->product_variant_id : null,
                'base_quantity'                => $baseQty,
                'unit_cost'                    => $baseUnitCost,
                'total_cost'                   => round($baseQty * $baseUnitCost, 4),
                'is_fallback_layer'            => true,
            ]);
        }
    }

    /**
     * Check whether destination batches of a Completed transfer have already been consumed downstream.
     */
    public function assertTransferCanModifyOrDelete(StockTransfer $transfer): ?string
    {
        $layerDestIds = StockTransferCostLayer::where('stock_transfer_id', $transfer->id)
            ->pluck('destination_inventory_batch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $legacyDestIds = InventoryBatch::whereIn('source_type', ['transfer', 'stock_transfer'])
            ->where('source_reference', $transfer->reference_no)
            ->pluck('id')
            ->all();

        $allDestIds = array_values(array_unique(array_merge($layerDestIds, $legacyDestIds)));
        if (empty($allDestIds)) {
            return null;
        }

        $batches = InventoryBatch::whereIn('id', $allDestIds)->lockForUpdate()->get();
        foreach ($batches as $batch) {
            $consumed = round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4);
            $hasDownstream = $this->hasDownstreamBatchConsumption((int)$batch->id);

            if ($consumed > 0.0001 || $hasDownstream) {
                $pName = $batch->product?->name ?? ('Product #' . $batch->product_id);
                return "Cannot reverse Stock Transfer #{$transfer->reference_no}: destination FIFO batch for '{$pName}' has already been consumed ({$consumed} base units). Void dependent transactions first.";
            }
        }

        return null;
    }

    /**
     * Reverse a Stock Transfer's FIFO layers: delete unconsumed destination batches and restore exact source batches.
     */
    public function rollbackTransferFifo(StockTransfer $transfer, bool $onlyDestination = false): void
    {
        $layers = StockTransferCostLayer::where('stock_transfer_id', $transfer->id)->lockForUpdate()->get();

        $layerDestIds = $layers->pluck('destination_inventory_batch_id')->filter()->unique()->values()->all();
        $legacyDestIds = InventoryBatch::whereIn('source_type', ['transfer', 'stock_transfer'])
            ->where('source_reference', $transfer->reference_no)
            ->pluck('id')
            ->all();
        $allDestIds = array_values(array_unique(array_merge($layerDestIds, $legacyDestIds)));

        if (!empty($allDestIds)) {
            InventoryBatch::whereIn('id', $allDestIds)->delete();
        }

        if ($onlyDestination) {
            StockTransferCostLayer::where('stock_transfer_id', $transfer->id)
                ->update(['destination_inventory_batch_id' => null]);
            return;
        }

        if ($layers->isEmpty()) {
            // Legacy pre-layer transfer: restore source warehouse batches with headroom (LIFO reverse)
            $transfer->loadMissing('items');
            $fromWhId = (int)($transfer->from_warehouse_id ?? 0);
            if ($fromWhId > 0) {
                foreach ($transfer->items as $tItem) {
                    $needRestore = round(max(0.0, (float)($tItem->base_quantity > 0 ? $tItem->base_quantity : $tItem->quantity)), 4);
                    if ($needRestore <= 0.0001 || !$tItem->product_id) {
                        continue;
                    }
                    $vId = $tItem->product_variant_id ? (int)$tItem->product_variant_id : null;
                    $candidates = InventoryBatch::where('warehouse_id', $fromWhId)
                        ->where('product_id', (int)$tItem->product_id)
                        ->when(
                            $vId !== null && $vId > 0,
                            fn($q) => $q->where('product_variant_id', $vId),
                            fn($q) => $q->whereNull('product_variant_id')
                        )
                        ->whereColumn('remaining_quantity', '<', 'initial_quantity')
                        ->orderByDesc('received_date')
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->get();
                    foreach ($candidates as $cand) {
                        if ($needRestore <= 0.0001) {
                            break;
                        }
                        $headroom = round(max(0.0, (float)$cand->initial_quantity - (float)$cand->remaining_quantity), 4);
                        if ($headroom <= 0.0001) {
                            continue;
                        }
                        $add = min($needRestore, $headroom);
                        $cand->remaining_quantity = round((float)$cand->remaining_quantity + $add, 4);
                        if ($cand->remaining_quantity > 0.0001) {
                            $cand->status = 'active';
                        }
                        $cand->save();
                        $needRestore = round(max(0.0, $needRestore - $add), 4);
                    }
                }
            }
            return;
        }

        $sourceBatchIds = $layers->pluck('source_inventory_batch_id')->filter()->unique()->values()->all();
        if (!empty($sourceBatchIds)) {
            $batches = InventoryBatch::whereIn('id', $sourceBatchIds)->lockForUpdate()->get()->keyBy('id');
            foreach ($layers as $layer) {
                if (!$layer->is_fallback_layer && $layer->source_inventory_batch_id && isset($batches[$layer->source_inventory_batch_id])) {
                    $batch = $batches[$layer->source_inventory_batch_id];
                    $restored = round((float)$batch->remaining_quantity + (float)$layer->base_quantity, 4);
                    if ((float)$batch->initial_quantity > 0) {
                        $restored = min((float)$batch->initial_quantity, $restored);
                    }
                    $batch->remaining_quantity = $restored;
                    if ($batch->remaining_quantity > 0.0001) {
                        $batch->status = 'active';
                    }
                    $batch->save();
                }
            }
        }

        StockTransferCostLayer::where('stock_transfer_id', $transfer->id)->delete();
    }

    /**
     * Unified guard checking whether an inventory batch has downstream cost-layer references.
     */
    public function hasDownstreamBatchConsumption(int $batchId): bool
    {
        if ($batchId <= 0) {
            return false;
        }

        return SaleItemCostLayer::where('inventory_batch_id', $batchId)->exists()
            || AdjustmentItemCostLayer::where('inventory_batch_id', $batchId)->exists()
            || PurchaseReturnCostLayer::where('inventory_batch_id', $batchId)->exists()
            || StockTransferCostLayer::where('source_inventory_batch_id', $batchId)->exists();
    }
}
