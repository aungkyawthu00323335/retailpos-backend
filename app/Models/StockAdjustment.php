<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    public const LIST_META_CACHE_KEY = 'adj_list_unfiltered_meta_v2';
    public const LIST_CACHE_VERSION_KEY = 'adj_list_cache_version';

    public static function listCacheVersion(): string
    {
        return '1';
    }

    public static function invalidateListCache(): void
    {
        // Zero-cache mode: stock adjustments list always queries live DB.
    }

    protected $fillable = [
        'reference_no',
        'warehouse_id',
        'warehouse_name',
        'date',
        'total_products',
        'total_quantity',
        'net_quantity',
        'gross_quantity',
        'total_value',
        'type', // Addition, Subtraction, Mixed
        'reason_code',
        'accounting_category',
        'affects_pnl',
        'notes',
        'user_id',
        'user_name',
        'created_by',
        'user_role',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'user_id' => 'integer',
        'total_products' => 'integer',
        'total_quantity' => 'float',
        'net_quantity' => 'float',
        'gross_quantity' => 'float',
        'total_value' => 'float',
        'affects_pnl' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }

    public function costLayers()
    {
        return $this->hasMany(AdjustmentItemCostLayer::class, 'stock_adjustment_id');
    }
}
