<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'from_warehouse',
        'to_warehouse',
        'date',
        'items_count',
        'total_quantity',
        'shipping_charge',
        'status', // Sent, Pending, Completed
        'notes',
        'created_by',
    ];

    protected $casts = [
        'items_count' => 'integer',
        'total_quantity' => 'float',
        'shipping_charge' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }
}
