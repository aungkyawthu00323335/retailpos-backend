<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'values' => $this->values->map(function ($val) {
                return [
                    'attribute_name' => $val->attribute_name,
                    'attribute_value' => $val->attribute_value,
                ];
            }),
        ];
    }
}