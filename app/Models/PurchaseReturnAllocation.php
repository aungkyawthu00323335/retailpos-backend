<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_return_id',
        'purchase_id',
        'supplier_id',
        'amount',
        'allocation_type',
        'released_at',
    ];

    protected $casts = [
        'purchase_return_id' => 'integer',
        'purchase_id' => 'integer',
        'supplier_id' => 'integer',
        'amount' => 'float',
        'released_at' => 'datetime',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}
