<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'code',
        'sku',
        'barcode',
        'barcode_symbology',
        'name',
        'alternative_name',
        'category_id',
        'category_name',
        'sub_category_id',
        'sub_category_name',
        'brand_id',
        'brand_name',
        'base_unit_id',
        'unit_name',
        'purchase_unit_id',
        'purchase_unit',
        'sale_unit_id',
        'sale_unit',
        'supplier_id',
        'supplier_name',
        'cost_price',
        'selling_price',
        'purchase_price',
        'retail_price',
        'retail_prices',
        'wholesale_price',
        'wholesale_prices',
        'dine_in_price',
        'take_away_price',
        'delivery_price',
        'stock_quantity',
        'alert_quantity',
        'stock_alert',
        'low_qty',
        'has_variants',
        'image_url',
        'description',
        'note',
        'status',
        'is_active',
        'created_by',
        'updated_by',
        'added_by',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'retail_prices' => 'array',
        'wholesale_price' => 'decimal:2',
        'wholesale_prices' => 'array',
        'stock_quantity' => 'decimal:4',
        'alert_quantity' => 'decimal:4',
        'stock_alert' => 'decimal:4',
        'low_qty' => 'decimal:4',
        'has_variants' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'total_dependencies',
    ];

    protected static function booted()
    {
        static::creating(function ($product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
            if (empty($product->sku) && !empty($product->code)) {
                $product->sku = $product->code;
            }
            if (empty($product->code) && !empty($product->sku)) {
                $product->code = $product->sku;
            }
            if (empty($product->cost_price) && !empty($product->purchase_price)) {
                $product->cost_price = $product->purchase_price;
            }
            if (empty($product->purchase_price) && !empty($product->cost_price)) {
                $product->purchase_price = $product->cost_price;
            }
            if (empty($product->selling_price) && !empty($product->retail_price)) {
                $product->selling_price = $product->retail_price;
            }
            if (empty($product->retail_price) && !empty($product->selling_price)) {
                $product->retail_price = $product->selling_price;
            }
        });
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order', 'asc');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order', 'asc');
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function unitLinks()
    {
        return $this->hasMany(ProductUnitLink::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'base_unit_id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class, 'product_id');
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class, 'product_id');
    }

    public function stockAdjustmentItems()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'product_id');
    }

    public function stockTransferItems()
    {
        return $this->hasMany(StockTransferItem::class, 'product_id');
    }

    public function getTotalDependenciesAttribute(): int
    {
        return (int) (
            ($this->sale_items_count ?? $this->saleItems()->count()) +
            ($this->purchase_items_count ?? $this->purchaseItems()->count()) +
            ($this->stock_adjustment_items_count ?? $this->stockAdjustmentItems()->count())
        );
    }
}

