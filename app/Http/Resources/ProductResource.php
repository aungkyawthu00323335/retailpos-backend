<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = \App\Models\User::resolveFromRequest($request);
        $canViewCost = $user ? $user->hasPermission('products.view_cost') : false;

        $primaryImgUrl = (string)($this->image_url ?? '');
        if ($this->relationLoaded('primaryImage') && $this->primaryImage) {
            $primaryImgUrl = $this->primaryImage->url;
        } elseif ($this->relationLoaded('images')) {
            $primaryImg = $this->images->firstWhere('is_primary', true);
            if ($primaryImg) {
                $primaryImgUrl = $primaryImg->url;
            }
        }

        $unitLinks = $this->relationLoaded('unitLinks')
            ? $this->unitLinks->where('is_active', true)->values()
            : collect();
        $serializedUnitLinks = $unitLinks->map(function ($link) {
            return [
                'id' => is_array($link) ? ($link['id'] ?? null) : $link->id,
                'product_variant_id' => is_array($link) ? ($link['product_variant_id'] ?? null) : $link->product_variant_id,
                'unit_type' => is_array($link) ? ($link['unit_type'] ?? '') : $link->unit_type,
                'unit_id' => is_array($link) ? ($link['unit_id'] ?? null) : $link->unit_id,
                'unit_name' => is_array($link) ? ($link['unit_name'] ?? '') : $link->unit_name,
                'conversion_rate' => (float)(is_array($link) ? ($link['conversion_rate'] ?? 1) : $link->conversion_rate),
                'is_default' => (bool)(is_array($link) ? ($link['is_default'] ?? false) : $link->is_default),
                'is_active' => (bool)(is_array($link) ? ($link['is_active'] ?? true) : $link->is_active),
            ];
        })->values();

        // Details/Edit loads the relation and receives the actual links. The
        // list endpoint omits it to avoid transferring two links per row.
        if ($this->relationLoaded('unitLinks') && $serializedUnitLinks->isEmpty()) {
            $serializedUnitLinks = collect([
                [
                    'id' => null,
                    'product_variant_id' => null,
                    'unit_type' => 'purchase',
                    'unit_id' => $this->purchase_unit_id,
                    'unit_name' => $this->purchase_unit ?: ($this->unit_name ?: 'Piece'),
                    'conversion_rate' => 1.0,
                    'is_default' => true,
                    'is_active' => true,
                ],
                [
                    'id' => null,
                    'product_variant_id' => null,
                    'unit_type' => 'sale',
                    'unit_id' => $this->sale_unit_id,
                    'unit_name' => $this->sale_unit ?: ($this->unit_name ?: 'Piece'),
                    'conversion_rate' => 1.0,
                    'is_default' => true,
                    'is_active' => true,
                ],
            ]);
        }

        return [
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
            'unit_links' => $this->when(
                $this->relationLoaded('unitLinks'),
                fn () => $serializedUnitLinks,
            ),
            'cost_price' => $canViewCost ? (float) ($this->cost_price ?: $this->purchase_price) : null,
            'selling_price' => (float) ($this->selling_price ?: $this->retail_price),
            'purchase_price' => $canViewCost ? (float) ($this->purchase_price ?: $this->cost_price) : null,
            'retail_price' => (float) ($this->retail_price ?: $this->selling_price),
            'retail_prices' => $this->retail_prices ?? (($this->retail_price || $this->selling_price) ? [(float)($this->retail_price ?: $this->selling_price)] : []),
            'selling_prices' => $this->retail_prices ?? (($this->retail_price || $this->selling_price) ? [(float)($this->retail_price ?: $this->selling_price)] : []),
            'wholesale_price' => (float) ($this->wholesale_price ?? 0.0),
            'wholesale_prices' => $this->wholesale_prices ?? ($this->wholesale_price ? [(float)$this->wholesale_price] : []),
            'stock_quantity' => (float) $this->stock_quantity,
            'alert_quantity' => (float) ($this->alert_quantity ?: $this->stock_alert),
            'stock_alert' => (float) ($this->stock_alert ?: $this->alert_quantity),
            'has_variants' => (bool) $this->has_variants,
            'variants_count' => array_key_exists('variants_count', $this->attributes ?? [])
                ? (int) ($this->attributes['variants_count'] ?? 0)
                : ($this->relationLoaded('variants') ? $this->variants->count() : 0),
            'variants' => $this->whenLoaded(
                'variants',
                fn () => ProductVariantResource::collection($this->variants),
            ),
            'images' => $this->whenLoaded(
                'images',
                fn () => $this->images->map(function ($img) {
                    return [
                        'id' => $img->id,
                        'url' => $img->url,
                        'is_primary' => (bool) $img->is_primary,
                        'sort_order' => $img->sort_order,
                    ];
                }),
            ),
            'image_url' => $primaryImgUrl,
            'primary_image_url' => $primaryImgUrl,
            'warehouse_stocks' => $this->relationLoaded('stockBalances') && $this->stockBalances->isNotEmpty()
                ? $this->stockBalances->map(function ($sb) {
                    static $outletsMap = null;
                    if ($outletsMap === null) {
                        $outletsMap = \App\Models\Outlet::pluck('name', 'id')->toArray();
                    }
                    $variantsMap = $this->relationLoaded('variants')
                        ? $this->variants->pluck('name', 'id')->toArray()
                        : [];
                    return [
                        'warehouse_id' => (int)$sb->warehouse_id,
                        'warehouse_name' => $outletsMap[$sb->warehouse_id] ?? ('Warehouse #' . $sb->warehouse_id),
                        'product_variant_id' => $sb->product_variant_id ? (int)$sb->product_variant_id : null,
                        'variant_name' => $sb->product_variant_id ? ($variantsMap[$sb->product_variant_id] ?? '') : '',
                        'quantity' => (float)$sb->quantity,
                    ];
                })->values()
                : ((float)$this->stock_quantity > 0
                    ? [
                        (function () {
                            static $defOutletId = null;
                            static $defOutletName = null;
                            if ($defOutletId === null) {
                                $def = \App\Models\Outlet::where('is_active', true)->orderBy('id')->first();
                                $defOutletId = (int)($def?->id ?? 1);
                                $defOutletName = (string)($def?->name ?? 'Main Branch Outlet');
                            }
                            return [
                                'warehouse_id' => $defOutletId,
                                'warehouse_name' => $defOutletName,
                                'product_variant_id' => null,
                                'quantity' => (float)$this->stock_quantity,
                            ];
                        })()
                    ]
                    : []
                ),
            'note' => $this->note ?? $this->description,
            'description' => $this->description ?? $this->note,
            'status' => $this->status ?? ($this->is_active ? 'Active' : 'Inactive'),
            'is_active' => (bool) $this->is_active,
            'is_bot_available' => (bool) $this->is_bot_available,
            'bot_price' => $this->bot_price ? (float)$this->bot_price : null,
            'added_by' => $this->added_by,
            'total_dependencies' => (int)($this->total_dependencies ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
