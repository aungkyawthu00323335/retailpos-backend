<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_variant_id',
        'unit_id',
        'unit_name',
        'conversion_rate',
        'product_code',
        'product_name',
        'variant_name',
        'unit_price',
        'base_unit_price',
        'quantity',
        'base_quantity',
        'tax',
        'discount',
        'subtotal',
        'base_unit_cost',
        'total_cogs',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'base_unit_price' => 'float',
        'quantity' => 'float',
        'base_quantity' => 'float',
        'conversion_rate' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'subtotal' => 'float',
        'base_unit_cost' => 'float',
        'total_cogs' => 'float',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function costLayers()
    {
        return $this->hasMany(SaleItemCostLayer::class, 'sale_item_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }
}
