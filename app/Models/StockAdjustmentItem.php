<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $invalidate = static fn () => StockAdjustment::invalidateListCache();
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'product_variant_id',
        'product_code',
        'product_name',
        'variant_name',
        'unit_id',
        'unit_name',
        'conversion_rate',
        'quantity',
        'base_quantity',
        'type', // Addition, Subtraction
        'unit_cost',
        'base_unit_cost',
        'subtotal',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'unit_id' => 'integer',
        'conversion_rate' => 'float',
        'quantity' => 'float',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'base_unit_cost' => 'float',
        'subtotal' => 'float',
    ];

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function costLayers()
    {
        return $this->hasMany(AdjustmentItemCostLayer::class, 'stock_adjustment_item_id');
    }
}
