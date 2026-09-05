<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        $stockQty = 0.0;
        if ($this->relationLoaded('stockBalances')) {
            if ($warehouseId !== null) {
                $stockQty = (float) $this->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity');
            } else {
                $stockQty = (float) $this->stockBalances->sum('quantity');
            }
        }

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'cost_price' => (float) $this->cost_price,
            'selling_price' => (float) $this->selling_price,
            'retail_price' => (float) ($this->selling_price ?? 0.0),
            'retail_prices' => $this->retail_prices ?? ($this->selling_price ? [(float)$this->selling_price] : []),
            'selling_prices' => $this->retail_prices ?? ($this->selling_price ? [(float)$this->selling_price] : []),
            'wholesale_price' => (float) ($this->wholesale_price ?? 0.0),
            'wholesale_prices' => $this->wholesale_prices ?? ($this->wholesale_price ? [(float)$this->wholesale_price] : []),
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'stock_quantity' => $stockQty,
            'stockQuantity' => $stockQty,
            'warehouse_stocks' => $this->relationLoaded('stockBalances')
                ? $this->stockBalances->map(fn ($sb) => [
                    'warehouse_id' => (int)$sb->warehouse_id,
                    'quantity' => (float)$sb->quantity,
                ])->values()
                : [],
            'values' => $this->relationLoaded('values') && $this->values ? $this->values->map(function ($val) {
                return [
                    'attribute_name' => $val->attribute_name,
                    'attribute_value' => $val->attribute_value,
                ];
            }) : [],
        ];
    }
}