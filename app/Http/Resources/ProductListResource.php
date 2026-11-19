<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact product row payload used by the paginated list.
 *
 * Details/Edit use ProductResource and load the complete product graph. The
 * list must not serialize variants, images, unit links or long descriptions
 * for every row.
 */
class ProductListResource extends JsonResource
{
    public static function clearStaticCache(): void
    {
        // Per-request attributes are now used on Request instances; kept as no-op for caller compatibility.
    }

    public function toArray(Request $request): array
    {
        if ($request->attributes->has('_can_view_cost')) {
            $canViewCost = (bool)$request->attributes->get('_can_view_cost');
        } else {
            $canViewCost = app(\App\Services\PurchaseAccountingService::class)->canViewCost($request);
        }

        $isCompactList = $request->boolean('compact_list')
            && !$request->boolean('is_pos')
            && !$request->filled('for_pos')
            && !$request->boolean('all_pages');

        $imageUrl = (string)($this->image_url ?? '');
        if ($this->relationLoaded('primaryImage') && $this->primaryImage) {
            $imageUrl = $this->primaryImage->url;
        }

        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        $stockQty = (float)$this->stock_quantity;
        $hasWarehouseStockAttr = $this->resource && array_key_exists('warehouse_stock', $this->resource->getAttributes());
        if ($hasWarehouseStockAttr) {
            $stockQty = (float)($this->warehouse_stock ?? 0.0);
        } elseif ($warehouseId !== null) {
            if ($this->relationLoaded('stockBalances')) {
                $stockQty = (float)$this->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity');
            } elseif ($this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
                $stockQty = (float)$this->variants->sum(function ($v) use ($warehouseId) {
                    return $v->relationLoaded('stockBalances')
                        ? $v->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity')
                        : 0.0;
                });
            }
        } elseif ($this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            $variantStockSum = $this->variants->sum(function ($v) {
                return $v->relationLoaded('stockBalances')
                    ? $v->stockBalances->sum('quantity')
                    : 0.0;
            });
            if ($variantStockSum > 0 || ($this->variants->first() && $this->variants->first()->relationLoaded('stockBalances'))) {
                $stockQty = (float)$variantStockSum;
            }
        } elseif ($this->relationLoaded('stockBalances') && $this->stockBalances->isNotEmpty()) {
            $stockQty = (float)$this->stockBalances->sum('quantity');
        }

        $base = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'alternative_name' => $this->alternative_name,
            'sku' => $this->sku ?? $this->code,
            'code' => $this->code ?? $this->sku,
            'barcode' => $this->barcode,
            'barcode_symbology' => $this->barcode_symbology ?? 'CODE128',
            'category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'sub_category_id' => $this->sub_category_id,
            'sub_category_name' => $this->sub_category_name,
            'brand_id' => $this->brand_id,
            'brand_name' => $this->brand_name,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'base_unit_id' => $this->base_unit_id,
            'unit_name' => $this->unit_name,
            'sale_unit_id' => $this->sale_unit_id,
            'sale_unit' => $this->sale_unit,
            'purchase_unit_id' => $this->purchase_unit_id,
            'purchase_unit' => $this->purchase_unit,
            'cost_price' => $canViewCost ? (float)($this->cost_price ?: $this->purchase_price) : null,
            'selling_price' => (float)($this->selling_price ?: $this->retail_price),
            'retail_prices' => $this->retail_prices ?? [],
            'wholesale_price' => (float)($this->wholesale_price ?? 0),
            'wholesale_prices' => $this->wholesale_prices ?? [],
            'stock_quantity' => $stockQty,
            'stock_alert' => (float)($this->stock_alert ?: $this->alert_quantity),
            'has_variants' => (bool)$this->has_variants,
            'variants_count' => $this->relationLoaded('variants') ? $this->variants->count() : 0,
            'variants' => $this->relationLoaded('variants') && $this->variants->isNotEmpty()
                ? ProductVariantResource::collection($this->variants)
                : [],
            'image_url' => $imageUrl,
            'status' => $this->status ?? ($this->is_active ? 'Active' : 'Inactive'),
            'is_active' => (bool)$this->is_active,
            'added_by' => $this->added_by,
            'total_dependencies' => (int)($this->total_dependencies ?? 0),
        ];

        if ($isCompactList) {
            return $base;
        }

        return array_merge($base, [
            'unit_links' => $this->relationLoaded('unitLinks')
                ? $this->unitLinks
                    ->where('is_active', true)
                    ->map(fn ($link) => [
                        'id' => $link->id,
                        'product_id' => $link->product_id,
                        'product_variant_id' => $link->product_variant_id,
                        'unit_id' => $link->unit_id,
                        'unit_name' => $link->unit_name,
                        'unit_type' => $link->unit_type,
                        'conversion_rate' => (float)$link->conversion_rate,
                        'is_default' => (bool)$link->is_default,
                        'is_active' => (bool)$link->is_active,
                    ])
                    ->values()
                    ->all()
                : [],
            'purchase_price' => $canViewCost ? (float)($this->purchase_price ?: $this->cost_price) : null,
            'retail_price' => (float)($this->retail_price ?: $this->selling_price),
            'stockQuantity' => $stockQty,
            'alert_quantity' => (float)($this->alert_quantity ?: $this->stock_alert),
            'low_qty' => (float)($this->low_qty ?: $this->stock_alert),
            'warehouse_stocks' => ($this->relationLoaded('stockBalances') && $this->stockBalances->isNotEmpty())
                ? (function () use ($request) {
                    $outletsMap = $request->attributes->get('_compact_outlets_map');
                    if ($outletsMap === null) {
                        $outletsMap = \App\Models\Outlet::pluck('name', 'id')->toArray();
                        $request->attributes->set('_compact_outlets_map', $outletsMap);
                    }
                    $variantsMap = $this->relationLoaded('variants')
                        ? $this->variants->pluck('name', 'id')->toArray()
                        : [];
                    return $this->stockBalances->map(function ($sb) use ($variantsMap, $outletsMap) {
                        return [
                            'warehouse_id' => (int)$sb->warehouse_id,
                            'warehouse_name' => $outletsMap[$sb->warehouse_id] ?? ('Warehouse #' . $sb->warehouse_id),
                            'product_variant_id' => $sb->product_variant_id ? (int)$sb->product_variant_id : null,
                            'variant_name' => $sb->product_variant_id ? ($variantsMap[$sb->product_variant_id] ?? '') : '',
                            'quantity' => (float)$sb->quantity,
                        ];
                    })->values()->all();
                })()
                : (($warehouseId === null && (float)$this->stock_quantity > 0)
                    ? [
                        (function () use ($request) {
                            $defaultOutlet = $request->attributes->get('_compact_default_outlet');
                            if ($defaultOutlet === null) {
                                $defaultOutlet = \App\Models\Outlet::where('is_active', true)->orderBy('id')->first() ?? false;
                                $request->attributes->set('_compact_default_outlet', $defaultOutlet);
                            }
                            $outletObj = $defaultOutlet ?: null;
                            return [
                                'warehouse_id' => (int)($outletObj?->id ?? 1),
                                'warehouse_name' => (string)($outletObj?->name ?? 'Main Branch Outlet'),
                                'product_variant_id' => null,
                                'variant_name' => '',
                                'quantity' => (float)$this->stock_quantity,
                            ];
                        })()
                    ]
                    : []
                ),
            'primary_image_url' => $imageUrl,
        ]);
    }
}
