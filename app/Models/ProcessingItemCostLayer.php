<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores exact FIFO inventory batch cost layers depleted by manufacturing/processing inputs.
 */
class ProcessingItemCostLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_id',
        'processing_item_id',
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
        'processing_id' => 'integer',
        'processing_item_id' => 'integer',
        'inventory_batch_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'is_fallback_layer' => 'boolean',
    ];

    public function processing(): BelongsTo
    {
        return $this->belongsTo(Processing::class, 'processing_id');
    }

    public function processingItem(): BelongsTo
    {
        return $this->belongsTo(ProcessingItem::class, 'processing_item_id');
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
