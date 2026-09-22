<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_variant_id',
        'unit_id',
        'unit_name',
        'conversion_rate',
        'product_code',
        'product_name',
        'variant_name',
        'unit_cost',
        'base_unit_cost',
        'quantity',
        'base_quantity',
        'tax',
        'discount',
        'subtotal',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'base_unit_cost' => 'float',
        'quantity' => 'float',
        'base_quantity' => 'float',
        'conversion_rate' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'subtotal' => 'float',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
