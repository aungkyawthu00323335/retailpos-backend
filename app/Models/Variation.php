<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'variation_types',
        'status',
    ];

    protected $casts = [
        'variation_types' => 'array',
    ];

    protected $appends = [
        'products_count',
    ];

    public function getProductsCountAttribute()
    {
        if (!$this->name) {
            return 0;
        }
        return ProductVariantValue::where('attribute_name', $this->name)
            ->join('product_variants', 'product_variant_values.product_variant_id', '=', 'product_variants.id')
            ->distinct('product_variants.product_id')
            ->count('product_variants.product_id');
    }
}