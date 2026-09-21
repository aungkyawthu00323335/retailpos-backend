<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseUnit extends Model
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
        'purchases_count',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'base_unit_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'purchase_unit_id');
    }

    public function productUnitLinks()
    {
        return $this->hasMany(ProductUnitLink::class, 'unit_id')->where('unit_type', 'purchase');
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class, 'unit_id');
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

    public function getPurchasesCountAttribute(): int
    {
        if (array_key_exists('purchases_count', $this->attributes)) {
            return (int) $this->attributes['purchases_count'];
        }
        return $this->purchaseItems()->count();
    }
}
