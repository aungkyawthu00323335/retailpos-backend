<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUnitLink;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;

class ProductUnitConversionService
{
    public function syncProductLinks(Product $product, array $links, array $createdVariants = []): void
    {
        // The current create/edit form submits product-level links. Keep
        // existing variant-specific links in that case; otherwise an edit of
        // the product name or price would silently erase variation mappings.
        $hasVariantScopedLinks = collect($links)->contains(function ($rawLink) {
            return is_array($rawLink) && (
                !empty($rawLink['product_variant_id']) ||
                trim((string)($rawLink['variant_sku'] ?? '')) !== ''
            );
        });

        $linksToDeactivate = $product->unitLinks();
        if (!$hasVariantScopedLinks) {
            $linksToDeactivate->whereNull('product_variant_id');
        }
        $linksToDeactivate->update(['is_active' => false, 'is_default' => false]);

        foreach ($links as $rawLink) {
            if (!is_array($rawLink)) {
                continue;
            }

            $type = strtolower(trim((string)($rawLink['unit_type'] ?? '')));
            if (!in_array($type, ['purchase', 'sale'], true)) {
                throw new InvalidArgumentException('Unit type must be purchase or sale.');
            }

            $name = trim((string)($rawLink['unit_name'] ?? ''));
            $rate = (float)($rawLink['conversion_rate'] ?? 0);
            if ($name === '' || $rate <= 0) {
                throw new InvalidArgumentException('Every product unit link needs a name and a positive conversion rate.');
            }

            $variantId = Arr::get($rawLink, 'product_variant_id');
            if (!$variantId && !empty($rawLink['variant_sku'])) {
                $variant = $createdVariants[(string)$rawLink['variant_sku']] ?? null;
                $variantId = $variant?->id;
            }
            $variantId = $variantId ? (int)$variantId : null;

            if ($variantId && !isset($createdVariants[(string)($rawLink['variant_sku'] ?? '')])) {
                $belongsToProduct = $product->variants()->whereKey($variantId)->exists();
                if (!$belongsToProduct) {
                    throw new InvalidArgumentException('Product variant does not belong to this product.');
                }
            }

            $unitId = Arr::get($rawLink, 'unit_id');
            $unitId = $unitId !== null && $unitId !== '' ? (int)$unitId : null;

            $query = ProductUnitLink::where('product_id', $product->id)
                ->where('unit_type', $type)
                ->where('unit_name', $name);
            $variantId === null ? $query->whereNull('product_variant_id') : $query->where('product_variant_id', $variantId);
            $unitId === null ? $query->whereNull('unit_id') : $query->where('unit_id', $unitId);

            $link = $query->first() ?: new ProductUnitLink();
            $link->fill([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'unit_type' => $type,
                'unit_id' => $unitId,
                'unit_name' => $name,
                'conversion_rate' => $rate,
                'is_default' => (bool)($rawLink['is_default'] ?? false),
                'is_active' => (bool)($rawLink['is_active'] ?? true),
            ]);
            $link->save();

            if ($link->is_default) {
                ProductUnitLink::where('product_id', $product->id)
                    ->where('unit_type', $type)
                    ->when($variantId === null, fn($q) => $q->whereNull('product_variant_id'))
                    ->when($variantId !== null, fn($q) => $q->where('product_variant_id', $variantId))
                    ->where($link->getKeyName(), '!=', $link->id)
                    ->update(['is_default' => false]);
            }
        }

        $this->ensureDefaults($product);
    }

    public function ensureDefaults(Product $product): void
    {
        foreach (['purchase', 'sale'] as $type) {
            $query = $product->unitLinks()->where('unit_type', $type)->where('is_active', true);
            if (!$query->where('is_default', true)->exists()) {
                $query->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        }
    }

    public function resolve(
        Product $product,
        string $type,
        ?int $unitId = null,
        ?string $unitName = null,
        ?int $variantId = null
    ): array {
        $type = strtolower(trim($type));
        if ($type === 'base') {
            return [
                'id' => null,
                'unit_id' => $product->base_unit_id,
                'unit_name' => $product->unit_name ?: 'Piece',
                'conversion_rate' => 1.0,
                'unit_type' => 'base',
                'product_variant_id' => $variantId,
            ];
        }
        if (!in_array($type, ['purchase', 'sale'], true)) {
            throw new InvalidArgumentException('Unit type must be purchase or sale.');
        }

        $hasPreloaded = $product->relationLoaded('unitLinks');

        if ($hasPreloaded) {
            $allLinks = $product->unitLinks;
            $links = $allLinks->filter(function ($l) use ($type, $variantId) {
                if (!$l->is_active || strtolower((string)$l->unit_type) !== $type) {
                    return false;
                }
                if ($variantId === null) {
                    return $l->product_variant_id === null;
                }
                return $l->product_variant_id === $variantId || $l->product_variant_id === null;
            })->sort(function ($a, $b) {
                $aNull = $a->product_variant_id === null ? 1 : 0;
                $bNull = $b->product_variant_id === null ? 1 : 0;
                if ($aNull !== $bNull) return $aNull <=> $bNull;
                if ($a->is_default !== $b->is_default) return $b->is_default <=> $a->is_default;
                return $a->id <=> $b->id;
            });
        } else {
            $links = ProductUnitLink::where('product_id', $product->id)
                ->where('unit_type', $type)
                ->where('is_active', true)
                ->when(
                    $variantId === null,
                    fn($q) => $q->whereNull('product_variant_id'),
                    function ($q) use ($variantId) {
                        $q->where(function ($variantQuery) use ($variantId) {
                            $variantQuery->where('product_variant_id', $variantId)->orWhereNull('product_variant_id');
                        });
                    }
                )
                ->orderByRaw('CASE WHEN product_variant_id IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get();
        }

        $match = $links->first(function (ProductUnitLink $link) use ($unitId, $unitName) {
            if ($unitId !== null && (int)$link->unit_id === $unitId) {
                return true;
            }
            return $unitId === null && $unitName !== null && strcasecmp(trim($link->unit_name), trim($unitName)) === 0;
        });

        // If not found in the requested unit_type, search other active unit links for this product
        if (!$match && ($unitId !== null || ($unitName !== null && trim($unitName) !== ''))) {
            if ($hasPreloaded) {
                $crossLinks = $allLinks->filter(function ($l) use ($variantId) {
                    if (!$l->is_active) return false;
                    if ($variantId === null) {
                        return $l->product_variant_id === null;
                    }
                    return $l->product_variant_id === $variantId || $l->product_variant_id === null;
                })->sort(function ($a, $b) {
                    $aNull = $a->product_variant_id === null ? 1 : 0;
                    $bNull = $b->product_variant_id === null ? 1 : 0;
                    if ($aNull !== $bNull) return $aNull <=> $bNull;
                    if ($a->is_default !== $b->is_default) return $b->is_default <=> $a->is_default;
                    return $a->id <=> $b->id;
                });
            } else {
                $crossLinks = ProductUnitLink::where('product_id', $product->id)
                    ->where('is_active', true)
                    ->when(
                        $variantId === null,
                        fn($q) => $q->whereNull('product_variant_id'),
                        function ($q) use ($variantId) {
                            $q->where(function ($variantQuery) use ($variantId) {
                                $variantQuery->where('product_variant_id', $variantId)->orWhereNull('product_variant_id');
                            });
                        }
                    )
                    ->orderByRaw('CASE WHEN product_variant_id IS NULL THEN 1 ELSE 0 END')
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->get();
            }

            $match = $crossLinks->first(function (ProductUnitLink $link) use ($unitId, $unitName) {
                if ($unitId !== null && (int)$link->unit_id === $unitId) {
                    return true;
                }
                return $unitId === null && $unitName !== null && strcasecmp(trim($link->unit_name), trim($unitName)) === 0;
            });
        }

        if (($unitId !== null || ($unitName !== null && trim($unitName) !== '')) && !$match) {
            $trimmedName = $unitName !== null ? trim($unitName) : '';
            if (
                ($unitId !== null && $product->base_unit_id !== null && (int)$product->base_unit_id === $unitId) ||
                ($unitId !== null && $product->unit_id !== null && (int)$product->unit_id === $unitId) ||
                ($trimmedName !== '' && (
                    strcasecmp(trim((string)$product->unit_name), $trimmedName) === 0 ||
                    strcasecmp(trim((string)$product->purchase_unit), $trimmedName) === 0 ||
                    strcasecmp(trim((string)$product->sale_unit), $trimmedName) === 0
                ))
            ) {
                return [
                    'id' => null,
                    'unit_id' => $product->base_unit_id ?: $product->unit_id,
                    'unit_name' => $trimmedName !== '' ? $trimmedName : ($product->unit_name ?: 'Piece'),
                    'conversion_rate' => 1.0,
                    'unit_type' => 'base',
                    'product_variant_id' => $variantId,
                ];
            }
            if ($links->isNotEmpty()) {
                throw new InvalidArgumentException('The selected unit is not linked to this product.');
            }
        }

        if (!$match) {
            $match = $links->firstWhere('is_default', true) ?: $links->first();
        }

        if ($match) {
            return [
                'id' => $match->id,
                'unit_id' => $match->unit_id,
                'unit_name' => $match->unit_name,
                'conversion_rate' => (float)$match->conversion_rate,
                'unit_type' => $match->unit_type,
                'product_variant_id' => $match->product_variant_id,
            ];
        }

        $fallbackName = $type === 'purchase' ? ($product->purchase_unit ?: $product->unit_name) : ($product->sale_unit ?: $product->unit_name);
        $fallbackId = $type === 'purchase' ? $product->purchase_unit_id : $product->sale_unit_id;

        return [
            'id' => null,
            'unit_id' => $fallbackId,
            'unit_name' => $fallbackName ?: 'Piece',
            'conversion_rate' => 1.0,
            'unit_type' => $type,
            'product_variant_id' => $variantId,
        ];
    }

    public function baseQuantity(float $quantity, float $conversionRate): float
    {
        if ($quantity < 0 || $conversionRate <= 0) {
            throw new InvalidArgumentException('Quantity must be non-negative and conversion rate must be positive.');
        }

        return round($quantity * $conversionRate, 4);
    }

    public function basePrice(float $unitPrice, float $conversionRate): float
    {
        if ($unitPrice < 0 || $conversionRate <= 0) {
            throw new InvalidArgumentException('Price must be non-negative and conversion rate must be positive.');
        }

        return round($unitPrice / $conversionRate, 8);
    }

    public function warehouseId(?int $warehouseId, ?string $warehouseName): ?int
    {
        $hasOutlets = Outlet::query()->exists();

        if ($warehouseId !== null && $warehouseId > 0) {
            if (!$hasOutlets) {
                return $warehouseId;
            }
            return Outlet::whereKey($warehouseId)->exists() ? $warehouseId : null;
        }

        $name = trim((string)$warehouseName);
        if ($name === '') {
            return $hasOutlets ? Outlet::orderBy('id')->value('id') : null;
        }

        if (!$hasOutlets) {
            return null;
        }

        $warehouse = Outlet::where('name', $name)->first()
            ?? Outlet::where('name', 'LIKE', '%' . $name . '%')->first();

        return $warehouse?->id;
    }

    public function applyStock(
        Product $product,
        ?int $warehouseId,
        ?int $variantId,
        float $baseQuantity,
        string $direction,
        string $movementType,
        string $referenceType,
        string $referenceId,
        ?string $note = null,
        bool $notifyRestock = true,
        bool $allowOverdraft = false,
        bool $syncAggregates = true,
        ?float $unitCost = null,
        ?string $movementDate = null
    ): float {
        if (!$warehouseId) {
            throw new RuntimeException('A valid warehouse is required for stock movement.');
        }
        if ($baseQuantity < 0) {
            throw new InvalidArgumentException('Base quantity must be non-negative.');
        }

        Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        $query = StockBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id);
        $variantId === null ? $query->whereNull('product_variant_id') : $query->where('product_variant_id', $variantId);
        $balance = $query->lockForUpdate()->first();

        if (!$balance) {
            $hasExistingBalances = StockBalance::where('product_id', $product->id)->exists();
            $initial = $variantId === null && !$hasExistingBalances ? (float)$product->stock_quantity : 0.0;
            $balance = StockBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity' => $initial,
            ]);
        }

        $current = (float)$balance->quantity;
        $next = strtolower($direction) === 'out' ? $current - $baseQuantity : $current + $baseQuantity;
        if ($next < -0.0001) {
            if (!$allowOverdraft) {
                throw new RuntimeException('Insufficient stock in the selected warehouse.');
            }
            $next = round($next, 4);
        } else {
            $next = max(0.0, round($next, 4));
        }
        $balance->update(['quantity' => $next]);

        $movementTimestamp = now();
        if ($movementDate !== null && trim($movementDate) !== '') {
            try {
                $movementTimestamp = Carbon::parse($movementDate)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
                $movementTimestamp = now();
            }
        }

        $movementPayload = [
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'quantity_in' => strtolower($direction) === 'in' ? $baseQuantity : 0.0,
            'quantity_out' => strtolower($direction) === 'out' ? $baseQuantity : 0.0,
            'balance_after' => $next,
            'created_by' => auth()->id() ?? 1,
            'note' => $note,
            'created_at' => $movementTimestamp,
        ];
        if ($unitCost !== null && app(PurchaseAccountingService::class)->schemaHasColumn('stock_movements', 'unit_cost')) {
            $movementPayload['unit_cost'] = round(max(0.0, $unitCost), 4);
        }
        StockMovement::create($movementPayload);

        if ($syncAggregates) {
            $aggregate = (float)StockBalance::where('product_id', $product->id)->sum('quantity');
            Product::whereKey($product->id)->update(['stock_quantity' => $aggregate, 'updated_at' => now()]);

            // 🛡️ Sync variant-level stock_quantity on the product_variants table if column exists
            if ($variantId) {
                if (app(PurchaseAccountingService::class)->schemaHasColumn('product_variants', 'stock_quantity')) {
                    $variantAggregate = (float)StockBalance::where('product_id', $product->id)
                        ->where('product_variant_id', $variantId)
                        ->sum('quantity');
                    \App\Models\ProductVariant::whereKey($variantId)->update(['stock_quantity' => $variantAggregate, 'updated_at' => now()]);
                }
            }
        }

        return $next;
    }

    public function syncAggregatesForProducts(array $productIds, array $variantIds = []): void
    {
        $now = now();
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!empty($productIds)) {
            $sums = StockBalance::whereIn('product_id', $productIds)
                ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as total_qty')
                ->groupBy('product_id')
                ->pluck('total_qty', 'product_id');

            foreach ($productIds as $pid) {
                $agg = (float)($sums[$pid] ?? 0.0);
                Product::whereKey($pid)->update(['stock_quantity' => $agg, 'updated_at' => $now]);
            }
        }

        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
        if (!empty($variantIds)) {
            if (app(PurchaseAccountingService::class)->schemaHasColumn('product_variants', 'stock_quantity')) {
                $vSums = StockBalance::whereIn('product_variant_id', $variantIds)
                    ->selectRaw('product_variant_id, COALESCE(SUM(quantity), 0) as total_qty')
                    ->groupBy('product_variant_id')
                    ->pluck('total_qty', 'product_variant_id');

                foreach ($variantIds as $vid) {
                    $vAgg = (float)($vSums[$vid] ?? 0.0);
                    \App\Models\ProductVariant::whereKey($vid)->update(['stock_quantity' => $vAgg, 'updated_at' => $now]);
                }
            }
        }
    }
}
