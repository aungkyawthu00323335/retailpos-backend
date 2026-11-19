<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferCostLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'stock_transfer_item_id',
        'source_inventory_batch_id',
        'destination_inventory_batch_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'product_id',
        'product_variant_id',
        'base_quantity',
        'unit_cost',
        'total_cost',
        'is_fallback_layer',
    ];

    protected $casts = [
        'stock_transfer_id' => 'integer',
        'stock_transfer_item_id' => 'integer',
        'source_inventory_batch_id' => 'integer',
        'destination_inventory_batch_id' => 'integer',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'is_fallback_layer' => 'boolean',
    ];

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function stockTransferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'source_inventory_batch_id');
    }

    public function destinationBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'destination_inventory_batch_id');
    }
}
