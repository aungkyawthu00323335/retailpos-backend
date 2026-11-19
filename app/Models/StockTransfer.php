<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class StockTransfer extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $flushListCache = static function (): void {
            try {
                \App\Services\PurchaseAccountingService::bumpListPrefix('stock_transfers_list_total_v1');
            } catch (\Throwable $e) {
                // Ignore cache backend errors during mutations
            }
        };

        static::saved($flushListCache);
        static::deleted($flushListCache);
    }

    protected $fillable = [
        'reference_no',
        'from_warehouse_id',
        'from_warehouse',
        'to_warehouse_id',
        'to_warehouse',
        'date',
        'transfer_time',
        'items_count',
        'total_quantity',
        'shipping_charge',
        'status', // Sent, Pending, Completed
        'notes',
        'created_by',
        'received_by',
        'received_at',
        'receiving_notes',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'user_id' => 'integer',
        'items_count' => 'integer',
        'total_quantity' => 'float',
        'shipping_charge' => 'float',
        'received_at' => 'datetime',
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
