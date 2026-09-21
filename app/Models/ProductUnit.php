<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    protected $appends = [
        'products_count',
        'sale_units_count',
        'purchase_units_count',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    public function saleUnits()
    {
        return $this->hasMany(SaleUnit::class, 'base_unit_id');
    }

    public function purchaseUnits()
    {
        return $this->hasMany(PurchaseUnit::class, 'base_unit_id');
    }

    public function getProductsCountAttribute(): int
    {
        if (array_key_exists('products_count', $this->attributes)) {
            return (int) $this->attributes['products_count'];
        }
        return $this->products()->count();
    }

    public function getSaleUnitsCountAttribute(): int
    {
        if (array_key_exists('sale_units_count', $this->attributes)) {
            return (int) $this->attributes['sale_units_count'];
        }
        return $this->saleUnits()->count();
    }

    public function getPurchaseUnitsCountAttribute(): int
    {
        if (array_key_exists('purchase_units_count', $this->attributes)) {
            return (int) $this->attributes['purchase_units_count'];
        }
        return $this->purchaseUnits()->count();
    }
}
