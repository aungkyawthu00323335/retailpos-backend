<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnCostLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_return_id',
        'inventory_batch_id',
        'source_batch_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'base_quantity',
        'unit_cost',
        'total_cost',
        'is_fallback_layer',
    ];

    protected $casts = [
        'sale_return_id' => 'integer',
        'inventory_batch_id' => 'integer',
        'source_batch_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'base_quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'is_fallback_layer' => 'boolean',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'source_batch_id');
    }
}
