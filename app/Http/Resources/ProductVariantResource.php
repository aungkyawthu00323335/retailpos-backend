<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
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

        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        $stockQty = 0.0;
        if (array_key_exists('warehouse_stock', $this->resource->getAttributes()) && $this->warehouse_stock !== null) {
            $stockQty = (float) $this->warehouse_stock;
        } elseif ($this->relationLoaded('stockBalances')) {
            if ($warehouseId !== null) {
                $stockQty = (float) $this->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity');
            } else {
                $stockQty = (float) $this->stockBalances->sum('quantity');
            }
        }

        $base = [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'cost_price' => $canViewCost ? (float) $this->cost_price : null,
            'selling_price' => (float) $this->selling_price,
            'wholesale_price' => (float) ($this->wholesale_price ?? 0.0),
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'image_url' => $this->image_url,
            'stock_quantity' => $stockQty,
            'values' => $this->relationLoaded('values') && $this->values ? $this->values->map(function ($val) {
                return [
                    'attribute_name' => $val->attribute_name,
                    'attribute_value' => $val->attribute_value,
                ];
            }) : [],
        ];

        if ($isCompactList) {
            return $base;
        }

        return array_merge($base, [
            'retail_price' => (float) ($this->selling_price ?? 0.0),
            'retail_prices' => $this->retail_prices ?? ($this->selling_price ? [(float)$this->selling_price] : []),
            'selling_prices' => $this->retail_prices ?? ($this->selling_price ? [(float)$this->selling_price] : []),
            'wholesale_prices' => $this->wholesale_prices ?? ($this->wholesale_price ? [(float)$this->wholesale_price] : []),
            'imageUrl' => $this->image_url,
            'stockQuantity' => $stockQty,
            'warehouse_stocks' => $this->relationLoaded('stockBalances')
                ? $this->stockBalances->map(fn ($sb) => [
                    'warehouse_id' => (int)$sb->warehouse_id,
                    'quantity' => (float)$sb->quantity,
                ])->values()
                : [],
        ]);
    }
}