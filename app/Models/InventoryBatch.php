<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'purchase_item_id',
        'batch_number',
        'source_type',
        'source_reference',
        'received_date',
        'unit_cost',
        'initial_quantity',
        'remaining_quantity',
        'status',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'initial_quantity' => 'float',
        'remaining_quantity' => 'float',
        'received_date' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(SaleItemCostLayer::class, 'inventory_batch_id');
    }

    public function adjustmentCostLayers(): HasMany
    {
        return $this->hasMany(AdjustmentItemCostLayer::class, 'inventory_batch_id');
    }
}
