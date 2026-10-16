<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductUnitLink;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseUnit;
use App\Models\SaleUnit;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\SubCategory;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductBatchSyncService
{
    /**
     * Process batch of offline-created products in an ACID transaction with UUID idempotency,
     * foreign key resolution, branch conflict disambiguation, and GAAP/IFRS dual-timestamped opening stock.
     */
    public function syncBatch(array $products, ?string $requestBranchCode = null, ?int $requestWarehouseId = null): array
    {
        return DB::transaction(function () use ($products, $requestBranchCode, $requestWarehouseId) {
            $results = [];
            $idMap = [];
            $defaultWarehouse = Outlet::orderBy('id')->first();
            $defaultWarehouseId = $requestWarehouseId ?: ($defaultWarehouse?->id);

            foreach ($products as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $uuid = $item['uuid'] ?? (string) Str::uuid();

                // 1. Idempotency Check: Return existing server record without duplicate stock mutation
                $existing = Product::where('uuid', $uuid)->first();
                if ($existing) {
                    $results[] = [
                        'uuid' => $uuid,
                        'server_id' => $existing->id,
                        'status' => 'already_synced',
                        'is_replay' => true,
                        'sku' => $existing->sku,
                        'barcode' => $existing->barcode,
                        'disambiguated_sku' => null,
                        'disambiguated_barcode' => null,
                        'message' => 'Product already synced (idempotent replay).',
                    ];
                    $idMap[$uuid] = $existing->id;

                    continue;
                }

                // 2. Resolve Branch Code
                $branchCode = $this->resolveBranchCode($item, $requestBranchCode, $defaultWarehouseId);

                // 3. Resolve Foreign Keys
                // Category
                $categoryId = $item['category_id'] ?? null;
                $categoryName = $item['category_name'] ?? null;
                if (! $categoryId && ! empty($item['category_uuid'])) {
                    $cat = ProductCategory::where('uuid', $item['category_uuid'])->first();
                    if ($cat) {
                        $categoryId = $cat->id;
                        $categoryName = $cat->name;
                    }
                }
                $categoryName = $categoryName ?: 'General';

                // Subcategory
                $subCategoryId = $item['sub_category_id'] ?? ($item['subcategory_id'] ?? null);
                $subCategoryName = $item['sub_category_name'] ?? ($item['subcategory_name'] ?? null);
                $subCatUuid = $item['sub_category_uuid'] ?? ($item['subcategory_uuid'] ?? null);
                if (! $subCategoryId && ! empty($subCatUuid)) {
                    $sc = SubCategory::where('uuid', $subCatUuid)->first();
                    if ($sc) {
                        $subCategoryId = $sc->id;
                        $subCategoryName = $sc->name;
                    }
                }

                // Brand
                $brandId = $item['brand_id'] ?? null;
                $brandName = $item['brand_name'] ?? null;
                if (! $brandId && ! empty($item['brand_uuid'])) {
                    $brand = Brand::where('uuid', $item['brand_uuid'])->first();
                    if ($brand) {
                        $brandId = $brand->id;
                        $brandName = $brand->name;
                    }
                }
                $brandName = $brandName ?: 'Generic';

                // Supplier
                $supplierId = $item['supplier_id'] ?? null;
                $supplierName = $item['supplier_name'] ?? null;
                if (! $supplierId && ! empty($item['supplier_uuid'])) {
                    $supplier = Supplier::where('uuid', $item['supplier_uuid'])->first();
                    if ($supplier) {
                        $supplierId = $supplier->id;
                        $supplierName = $supplier->name;
                    }
                }

                // Units
                $baseUnitId = $item['base_unit_id'] ?? ($item['unit_id'] ?? null);
                $unitName = $item['unit_name'] ?? null;
                $unitUuid = $item['base_unit_uuid'] ?? ($item['unit_uuid'] ?? null);
                if (! $baseUnitId && ! empty($unitUuid)) {
                    $u = ProductUnit::where('uuid', $unitUuid)->first();
                    if ($u) {
                        $baseUnitId = $u->id;
                        $unitName = $u->name;
                    }
                }
                $unitName = $unitName ?: 'Piece';

                $saleUnitId = $item['sale_unit_id'] ?? null;
                $saleUnitName = $item['sale_unit'] ?? null;
                if (! $saleUnitId && ! empty($item['sale_unit_uuid'])) {
                    $su = SaleUnit::where('uuid', $item['sale_unit_uuid'])->first();
                    if ($su) {
                        $saleUnitId = $su->id;
                        $saleUnitName = $su->name;
                    }
                }
                $saleUnitName = $saleUnitName ?: $unitName;

                $purchaseUnitId = $item['purchase_unit_id'] ?? null;
                $purchaseUnitName = $item['purchase_unit'] ?? null;
                if (! $purchaseUnitId && ! empty($item['purchase_unit_uuid'])) {
                    $pu = PurchaseUnit::where('uuid', $item['purchase_unit_uuid'])->first();
                    if ($pu) {
                        $purchaseUnitId = $pu->id;
                        $purchaseUnitName = $pu->name;
                    }
                }
                $purchaseUnitName = $purchaseUnitName ?: $unitName;

                $warehouseId = ! empty($item['warehouse_id']) ? (int) $item['warehouse_id'] : $defaultWarehouseId;

                // 4. SKU & Barcode Collision Disambiguation
                $rawSku = trim($item['sku'] ?? ($item['code'] ?? ''));
                if ($rawSku === '') {
                    $rawSku = 'PRD-'.strtoupper(Str::random(6));
                }

                $disambiguatedSku = null;
                $disambiguatedBarcode = null;
                $isDisambiguated = false;
                $auditNotices = [];

                if (Product::where('sku', $rawSku)->orWhere('code', $rawSku)->exists()) {
                    $candidateSku = "{$rawSku}-{$branchCode}";
                    $seq = 2;
                    while (Product::where('sku', $candidateSku)->orWhere('code', $candidateSku)->exists()) {
                        $candidateSku = "{$rawSku}-{$branchCode}-{$seq}";
                        $seq++;
                    }
                    $notice = "[Conflict Disambiguated]: Original SKU '{$rawSku}' collided with existing cloud record. Branch disambiguation applied: '{$candidateSku}'.";
                    Log::warning($notice." (UUID: {$uuid}, Branch: {$branchCode})");
                    $auditNotices[] = $notice;
                    $disambiguatedSku = $candidateSku;
                    $rawSku = $candidateSku;
                    $isDisambiguated = true;
                }

                $rawBarcode = isset($item['barcode']) ? trim((string) $item['barcode']) : null;
                if (! empty($rawBarcode)) {
                    if (Product::where('barcode', $rawBarcode)->exists()) {
                        $candidateBarcode = "{$rawBarcode}-{$branchCode}";
                        $seq = 2;
                        while (Product::where('barcode', $candidateBarcode)->exists()) {
                            $candidateBarcode = "{$rawBarcode}-{$branchCode}-{$seq}";
                            $seq++;
                        }
                        $notice = "[Conflict Disambiguated]: Original Barcode '{$item['barcode']}' collided with existing cloud record. Branch disambiguation applied: '{$candidateBarcode}'.";
                        Log::warning($notice." (UUID: {$uuid}, Branch: {$branchCode})");
                        $auditNotices[] = $notice;
                        $disambiguatedBarcode = $candidateBarcode;
                        $rawBarcode = $candidateBarcode;
                        $isDisambiguated = true;
                    }
                }

                // 5. Dual Timestamps
                $createdAtLocal = $item['created_at_local'] ?? ($item['created_at'] ?? now()->toDateTimeString());
                $syncedAt = now()->toDateTimeString();

                $costPrice = isset($item['cost_price']) ? (float) $item['cost_price'] : (isset($item['purchase_price']) ? (float) $item['purchase_price'] : 0.0);
                $sellingPrice = isset($item['selling_price']) ? (float) $item['selling_price'] : (isset($item['price']) ? (float) $item['price'] : (isset($item['retail_price']) ? (float) $item['retail_price'] : 0.0));
                $stockAlert = isset($item['stock_alert']) ? (float) $item['stock_alert'] : (isset($item['alert_quantity']) ? (float) $item['alert_quantity'] : 10.0);

                $note = trim(($item['note'] ?? '')."\n".implode("\n", $auditNotices));

                // 6. Persist Product Model
                $product = new Product;
                $product->forceFill([
                    'uuid' => $uuid,
                    'code' => $rawSku,
                    'sku' => $rawSku,
                    'barcode' => $rawBarcode,
                    'barcode_symbology' => $item['barcode_symbology'] ?? 'CODE128',
                    'name' => $item['name'] ?? 'Unnamed Product',
                    'alternative_name' => $item['alternative_name'] ?? null,
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'sub_category_id' => $subCategoryId,
                    'sub_category_name' => $subCategoryName,
                    'brand_id' => $brandId,
                    'brand_name' => $brandName,
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierName,
                    'base_unit_id' => $baseUnitId,
                    'unit_name' => $unitName,
                    'sale_unit_id' => $saleUnitId,
                    'sale_unit' => $saleUnitName,
                    'purchase_unit_id' => $purchaseUnitId,
                    'purchase_unit' => $purchaseUnitName,
                    'cost_price' => round($costPrice, 2),
                    'purchase_price' => round($costPrice, 2),
                    'selling_price' => round($sellingPrice, 2),
                    'retail_price' => round($sellingPrice, 2),
                    'retail_prices' => ! empty($item['retail_prices']) ? $item['retail_prices'] : [$sellingPrice],
                    'wholesale_price' => isset($item['wholesale_price']) ? (float) $item['wholesale_price'] : 0.0,
                    'stock_quantity' => 0.0000,
                    'alert_quantity' => round($stockAlert, 4),
                    'stock_alert' => round($stockAlert, 4),
                    'low_qty' => round($stockAlert, 4),
                    'has_variants' => ! empty($item['has_variants']),
                    'is_active' => isset($item['is_active']) ? (bool) $item['is_active'] : true,
                    'status' => 'Active',
                    'description' => $item['description'] ?? null,
                    'note' => $note ?: null,
                    'created_at_local' => $createdAtLocal,
                    'synced_at' => $syncedAt,
                    'created_at' => $createdAtLocal,
                    'updated_at' => $syncedAt,
                ]);
                $product->save();

                // Unit links
                if ($product->base_unit_id) {
                    ProductUnitLink::firstOrCreate([
                        'product_id' => $product->id,
                        'unit_id' => $product->base_unit_id,
                        'unit_type' => 'base',
                    ], [
                        'unit_name' => $product->unit_name ?: 'Piece',
                        'conversion_rate' => 1.0,
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }
                if ($product->sale_unit_id) {
                    ProductUnitLink::firstOrCreate([
                        'product_id' => $product->id,
                        'unit_id' => $product->sale_unit_id,
                        'unit_type' => 'sale',
                    ], [
                        'unit_name' => $product->sale_unit ?: 'Piece',
                        'conversion_rate' => 1.0,
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }
                if ($product->purchase_unit_id) {
                    ProductUnitLink::firstOrCreate([
                        'product_id' => $product->id,
                        'unit_id' => $product->purchase_unit_id,
                        'unit_type' => 'purchase',
                    ], [
                        'unit_name' => $product->purchase_unit ?: 'Piece',
                        'conversion_rate' => 1.0,
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }

                // 7. Process Opening Stock (GAAP/IFRS Valuation & Dual Timestamps)
                $openingEntries = [];
                if (! empty($item['opening_stocks']) && is_array($item['opening_stocks'])) {
                    $openingEntries = $item['opening_stocks'];
                } else {
                    $qty = null;
                    if (isset($item['opening_stock']) && (float) $item['opening_stock'] > 0) {
                        $qty = (float) $item['opening_stock'];
                    } elseif (isset($item['stock_quantity']) && (float) $item['stock_quantity'] > 0) {
                        $qty = (float) $item['stock_quantity'];
                    }
                    if ($qty !== null && $qty > 0) {
                        $openingEntries[] = [
                            'warehouse_id' => $warehouseId,
                            'quantity' => $qty,
                            'cost_price' => $costPrice,
                        ];
                    }
                }

                $totalStock = 0.0000;
                foreach ($openingEntries as $entry) {
                    $qty = round((float) ($entry['quantity'] ?? $entry['base_quantity'] ?? 0), 4);
                    if ($qty <= 0) {
                        continue;
                    }

                    $wId = ! empty($entry['warehouse_id']) ? (int) $entry['warehouse_id'] : ($warehouseId ?: $defaultWarehouseId);
                    if (! $wId) {
                        $wId = Outlet::orderBy('id')->value('id');
                    }
                    $wName = $entry['warehouse_name'] ?? (Outlet::where('id', $wId)->value('name') ?: 'Main Warehouse');
                    $entryCost = round((float) ($entry['cost_price'] ?? $costPrice), 4);

                    // A. Mutate StockBalance (4-decimal precision)
                    $balance = StockBalance::where('warehouse_id', $wId)
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->first();

                    if ($balance) {
                        $balance->quantity = round((float) $balance->quantity + $qty, 4);
                        $balance->save();
                    } else {
                        $balance = new StockBalance;
                        $balance->forceFill([
                            'warehouse_id' => $wId,
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'quantity' => $qty,
                        ]);
                        $balance->save();
                    }

                    // B. Auto-create Purchase Order for Audit Trail
                    $purchase = new Purchase;
                    $purchase->forceFill([
                        'reference_no' => Purchase::generateNextNumericReference(),
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplierName ?: 'Opening Stock',
                        'warehouse_id' => $wId,
                        'warehouse_name' => $wName,
                        'date' => Carbon::parse($createdAtLocal)->format('Y-m-d'),
                        'status' => 'Received',
                        'tax_rate' => 0.0,
                        'tax_amount' => 0.0,
                        'discount' => 0.0,
                        'shipping' => 0.0,
                        'grand_total' => round($entryCost * $qty, 2),
                        'paid_amount' => round($entryCost * $qty, 2),
                        'due_amount' => 0.0,
                        'payment_status' => 'Paid',
                        'payment_type' => 'Opening Stock',
                        'notes' => 'Opening stock from offline batch sync: '.$product->name,
                        'created_by' => auth()->user()?->name ?: 'Sync Agent',
                        'created_at_local' => $createdAtLocal,
                        'synced_at' => $syncedAt,
                        'created_at' => $createdAtLocal,
                    ]);
                    $purchase->save();

                    $purchaseItem = new PurchaseItem;
                    $purchaseItem->forceFill([
                        'purchase_id' => $purchase->id,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'unit_id' => $baseUnitId,
                        'unit_name' => $unitName,
                        'conversion_rate' => 1.0,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'unit_cost' => $entryCost,
                        'base_unit_cost' => $entryCost,
                        'quantity' => $qty,
                        'base_quantity' => $qty,
                        'tax' => 0.0,
                        'discount' => 0.0,
                        'subtotal' => round($entryCost * $qty, 2),
                    ]);
                    $purchaseItem->save();

                    // C. Record Stock Movement with Dual Timestamps
                    $movement = new StockMovement;
                    $movement->forceFill([
                        'warehouse_id' => $wId,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'movement_type' => 'opening_stock',
                        'reference_type' => 'purchase',
                        'reference_id' => (string) $purchase->id,
                        'quantity_in' => $qty,
                        'quantity_out' => 0.0000,
                        'balance_after' => round((float) $balance->quantity, 4),
                        'created_by' => auth()->id(),
                        'note' => 'Initial Opening Stock from Sync Batch',
                        'created_at_local' => $createdAtLocal,
                        'synced_at' => $syncedAt,
                        'created_at' => $createdAtLocal,
                    ]);
                    $movement->save();

                    // D. Register FIFO Inventory Batch with Dual Timestamps
                    $rxDate = Carbon::parse($createdAtLocal);
                    $batchNumber = 'LOT-'.$rxDate->format('Ymd').'-'.$product->id.'-'.rand(100, 999);
                    $batch = new InventoryBatch;
                    $batch->forceFill([
                        'warehouse_id' => $wId,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'purchase_item_id' => $purchaseItem->id,
                        'batch_number' => $batchNumber,
                        'source_type' => 'opening_stock',
                        'source_reference' => 'SYNC-'.$uuid,
                        'received_date' => $rxDate,
                        'unit_cost' => $entryCost,
                        'initial_quantity' => $qty,
                        'remaining_quantity' => $qty,
                        'status' => $qty > 0 ? 'active' : 'exhausted',
                        'created_by' => auth()->id(),
                        'notes' => 'Opening Stock FIFO batch from Sync Batch',
                        'created_at_local' => $createdAtLocal,
                        'synced_at' => $syncedAt,
                        'created_at' => $createdAtLocal,
                    ]);
                    $batch->save();

                    $totalStock += $qty;
                }

                if ($totalStock > 0) {
                    $product->stock_quantity = round($totalStock, 4);
                    $product->save();
                }

                // 8. Result payload
                $status = $isDisambiguated ? 'created_disambiguated' : 'created';
                $results[] = [
                    'uuid' => $uuid,
                    'server_id' => $product->id,
                    'status' => $status,
                    'disambiguated_sku' => $disambiguatedSku,
                    'disambiguated_barcode' => $disambiguatedBarcode,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                ];
                $idMap[$uuid] = $product->id;
            }

            try {
                Cache::store('file')->flush();
            } catch (\Throwable $e) {
                // Ignore cache flush failures
            }

            return [
                'results' => $results,
                'id_map' => $idMap,
            ];
        });
    }

    /**
     * Resolve deterministic branch code for conflict disambiguation.
     */
    protected function resolveBranchCode(array $item, ?string $requestBranchCode, ?int $warehouseId): string
    {
        if (! empty($item['branch_code'])) {
            return strtoupper(trim($item['branch_code']));
        }
        if (! empty($requestBranchCode)) {
            return strtoupper(trim($requestBranchCode));
        }

        $wId = ! empty($item['warehouse_id']) ? (int) $item['warehouse_id'] : $warehouseId;
        if ($wId) {
            $outlet = Outlet::find($wId);
            if ($outlet) {
                if (! empty($outlet->code)) {
                    return strtoupper(trim($outlet->code));
                }
                $cleanName = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($outlet->name, 0, 6)));
                if (! empty($cleanName)) {
                    return $cleanName;
                }

                return "B{$outlet->id}";
            }
        }

        return 'MAIN';
    }
}
