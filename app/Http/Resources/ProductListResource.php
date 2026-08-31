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
    public function toArray(Request $request): array
    {
        $imageUrl = (string)($this->image_url ?? '');
        if ($this->relationLoaded('primaryImage') && $this->primaryImage) {
            $imageUrl = $this->primaryImage->url;
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
            'cost_price' => (float)($this->cost_price ?: $this->purchase_price),
            'selling_price' => (float)($this->selling_price ?: $this->retail_price),
            'purchase_price' => (float)($this->purchase_price ?: $this->cost_price),
            'retail_price' => (float)($this->retail_price ?: $this->selling_price),
            'retail_prices' => $this->retail_prices ?? [],
            'wholesale_price' => (float)($this->wholesale_price ?? 0),
            'wholesale_prices' => $this->wholesale_prices ?? [],
            'stock_quantity' => (float)$this->stock_quantity,
            'alert_quantity' => (float)($this->alert_quantity ?: $this->stock_alert),
            'stock_alert' => (float)($this->stock_alert ?: $this->alert_quantity),
            'low_qty' => (float)($this->low_qty ?: $this->stock_alert),
            'has_variants' => (bool)$this->has_variants,
            'image_url' => $imageUrl,
            'primary_image_url' => $imageUrl,
            'status' => $this->status ?? ($this->is_active ? 'Active' : 'Inactive'),
            'is_active' => (bool)$this->is_active,
            'added_by' => $this->added_by,
        ];
    }
}
