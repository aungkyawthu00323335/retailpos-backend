<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentItemCostLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_adjustment_id',
        'stock_adjustment_item_id',
        'inventory_batch_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'base_quantity',
        'unit_cost',
        'total_cost',
        'is_fallback_layer',
    ];

    protected $casts = [
        'stock_adjustment_id' => 'integer',
        'stock_adjustment_item_id' => 'integer',
        'inventory_batch_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'is_fallback_layer' => 'boolean',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function adjustmentItem(): BelongsTo
    {
        return $this->belongsTo(StockAdjustmentItem::class, 'stock_adjustment_item_id');
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
