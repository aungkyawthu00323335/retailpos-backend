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
        'transfer_time',
        'items_count',
        'total_quantity',
        'shipping_charge',
        'status', // Sent, Pending, Completed
        'notes',
        'created_by',
        'user_id',
        'user_role',
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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
