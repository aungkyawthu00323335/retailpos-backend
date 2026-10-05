<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'cost_price',
        'selling_price',
        'retail_prices',
        'wholesale_price',
        'wholesale_prices',
        'is_active',
        'sort_order',
        'image_url',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'retail_prices' => 'array',
        'wholesale_price' => 'decimal:2',
        'wholesale_prices' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function values()
    {
        return $this->hasMany(ProductVariantValue::class);
    }

    public function attributeValues()
    {
        return $this->hasMany(ProductVariantValue::class);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class, 'product_variant_id');
    }
}