<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_id',
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
        'unit_cost',
        'base_unit_cost',
        'subtotal_cost',
    ];

    protected $casts = [
        'processing_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'unit_id' => 'integer',
        'conversion_rate' => 'float',
        'quantity' => 'float',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'base_unit_cost' => 'float',
        'subtotal_cost' => 'float',
    ];

    public function processing()
    {
        return $this->belongsTo(Processing::class, 'processing_id');
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
