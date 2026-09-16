<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\SaleItemCostLayer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\ProductVariant;
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
        int $saleItemId,
        ?string $saleDate = null,
        float $fallbackCost = 0.0
    ): array {
        $neededBaseQty = round(max(0.0, $neededBaseQty), 4);
        if ($neededBaseQty <= 0) {
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

            $layer = SaleItemCostLayer::create([
                'sale_id' => $saleId,
                'sale_item_id' => $saleItemId,
                'inventory_batch_id' => $batch->id,
                'base_quantity' => $takeQty,
                'unit_cost' => $batchCost,
                'total_cost' => $layerCost,
                'date' => $txDate,
                'created_at' => now(),
            ]);

            $layers[] = $layer;
            $totalCogs += $layerCost;
            $remainingNeeded = round($remainingNeeded - $takeQty, 4);

            $newRemaining = max(0.0, round($avail - $takeQty, 4));
            $batch->remaining_quantity = $newRemaining;
            if ($newRemaining <= 0.0001) {
                $batch->status = 'exhausted';
            }
            $batch->save();
        }

        // If batches did not satisfy all needed quantity (e.g. unallocated opening or negative stock)
        if ($remainingNeeded > 0.0001) {
            $unitCost = $fallbackCost > 0 ? $fallbackCost : 0.0;
            $unallocatedCost = round($remainingNeeded * $unitCost, 4);

            $layer = SaleItemCostLayer::create([
                'sale_id' => $saleId,
                'sale_item_id' => $saleItemId,
                'inventory_batch_id' => null,
                'base_quantity' => $remainingNeeded,
                'unit_cost' => $unitCost,
                'total_cost' => $unallocatedCost,
                'date' => $txDate,
                'created_at' => now(),
            ]);

            $layers[] = $layer;
            $totalCogs += $unallocatedCost;
        }

        $totalCogs = round($totalCogs, 2);
        $avgUnitCost = $neededBaseQty > 0 ? round($totalCogs / $neededBaseQty, 4) : 0.0;

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
        foreach ($costLayers as $layer) {
            if ($layer->inventory_batch_id) {
                $batch = InventoryBatch::find($layer->inventory_batch_id);
                if ($batch) {
                    $batch->remaining_quantity = round((float)$batch->remaining_quantity + (float)$layer->base_quantity, 4);
                    $batch->status = 'active';
                    $batch->save();
                }
            }
            $layer->delete();
        }
    }
}
