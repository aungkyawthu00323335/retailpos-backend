<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_unit_id',
        'base_unit_name',
        'description',
        'status',
    ];

    protected $appends = [
        'products_count',
        'sales_count',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'base_unit_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'sale_unit_id');
    }

    public function productUnitLinks()
    {
        return $this->hasMany(ProductUnitLink::class, 'unit_id')->where('unit_type', 'sale');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class, 'unit_id');
    }

    public function getProductsCountAttribute(): int
    {
        if (array_key_exists('products_count', $this->attributes)) {
            return (int) $this->attributes['products_count'];
        }
        $direct = $this->products()->count();
        $links = $this->productUnitLinks()->count();
        return max($direct, $links);
    }

    public function getSalesCountAttribute(): int
    {
        if (array_key_exists('sales_count', $this->attributes)) {
            return (int) $this->attributes['sales_count'];
        }
        return $this->saleItems()->count();
    }
}
